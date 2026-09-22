<?php
/**
 * @copyright Copyright (c) 2026 Fabrice Meyer
 *
 * @license GNU AGPL version 3 or any later version
 *
 * This program is free software: you can redistribute it and/or modify
 * it under the terms of the GNU Affero General Public License as
 * published by the Free Software Foundation, either version 3 of the
 * License, or (at your option) any later version.
 *
 * This program is distributed in the hope that it will be useful,
 * but WITHOUT ANY WARRANTY; without even the implied warranty of
 * MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
 * GNU Affero General Public License for more details.
 *
 * You should have received a copy of the GNU Affero General Public License
 * along with this program.  If not, see <http://www.gnu.org/licenses/>.
 *
 */

namespace OCA\DocumentServer\OnlyOffice;

use OCP\App\IAppManager;
use OCP\IConfig;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;

/**
 * The connector app this document server is talking to.
 *
 * This app is one half of a pair. The connector owns the settings, the JWT
 * secret and the editor page; this server reads that configuration and decodes
 * the tokens the connector signs, which it does by constructing the
 * connector's own AppConfig and Crypt classes. For years there was exactly one
 * connector - "onlyoffice" - and those classes were named outright.
 *
 * Euro-Office ships the same connector under its own app id and namespace: a
 * fork, with AppConfig and Crypt carrying the same methods. On an instance
 * running that one there is no OCA\Onlyoffice\AppConfig at all, so every
 * request this app answered died in the container with "Class
 * OCA\Onlyoffice\AppConfig does not exist" - a 500 on /healthcheck, which is
 * the first thing the connector asks (#405).
 *
 * So the connector is looked up instead of named: whichever of the known ones
 * is enabled and has its classes on disk, preferring one already pointed at
 * this document server when an instance has both installed.
 */
class Connector {
	/**
	 * app id => the namespace its classes live in.
	 *
	 * Order is the tie-break when more than one is enabled and neither is
	 * configured yet, so the historical connector stays first.
	 */
	public const CONNECTORS = [
		'onlyoffice' => 'OCA\\Onlyoffice',
		'eurooffice' => 'OCA\\Eurooffice',
	];

	/** false while not looked up yet; null once looked up and there is none */
	private $appId = false;

	private $appConfig = null;
	private $crypt = null;

	/**
	 * @param array<string, string> $connectors app id => namespace, overridable
	 *                                          so the lookup can be tested
	 *                                          without a connector installed
	 */
	public function __construct(
		private IAppManager $appManager,
		private IConfig $config,
		private ContainerInterface $container,
		private array $connectors = self::CONNECTORS,
	) {
	}

	/**
	 * The app id of the connector to work with, or null when none of them is
	 * installed - which is an ordinary state for this app, not an error: the
	 * document server can be enabled first and the connector added after.
	 */
	public function getAppId(): ?string {
		if ($this->appId !== false) {
			return $this->appId;
		}

		$candidates = [];
		foreach ($this->connectors as $appId => $namespace) {
			// enabled is not the same as usable: an app directory that is
			// there but incomplete would take the container down exactly the
			// way #405 did, so the class this app needs has to be loadable
			// before the connector counts as one it can use
			if ($this->appManager->isEnabledForUser($appId) && class_exists($namespace . '\\AppConfig')) {
				$candidates[] = $appId;
			}
		}

		// An instance with both installed has one of them pointed at this
		// server and the other at something else (or at nothing yet); the
		// configured one is the one to read settings from.
		foreach ($candidates as $appId) {
			$url = $this->config->getAppValue($appId, 'DocumentServerUrl', '');
			if (strpos($url, 'apps/documentserver_community') !== false) {
				return $this->appId = $appId;
			}
		}

		return $this->appId = ($candidates[0] ?? null);
	}

	public function isAvailable(): bool {
		return $this->getAppId() !== null;
	}

	/**
	 * The connector's AppConfig: its settings, as it reads them itself.
	 *
	 * @return object an OCA\<Connector>\AppConfig
	 * @throws \RuntimeException when no connector is installed
	 */
	public function getAppConfig(): object {
		if ($this->appConfig === null) {
			$this->appConfig = $this->build($this->classFor('AppConfig'));
		}
		return $this->appConfig;
	}

	/**
	 * The connector's Crypt: the reader for the tokens in the urls it hands the
	 * editor.
	 *
	 * @return object an OCA\<Connector>\Crypt
	 * @throws \RuntimeException when no connector is installed
	 */
	public function getCrypt(): object {
		if ($this->crypt === null) {
			$appConfig = $this->getAppConfig();
			$this->crypt = $this->build($this->classFor('Crypt'), [$appConfig]);
		}
		return $this->crypt;
	}

	private function classFor(string $class): string {
		$appId = $this->getAppId();
		if ($appId === null) {
			throw new \RuntimeException('No document server connector app is enabled');
		}
		return $this->connectors[$appId] . '\\' . $class;
	}

	/**
	 * Construct one of the connector's classes without knowing its signature.
	 *
	 * These constructors are not ours and they move: the onlyoffice connector's
	 * AppConfig took (string, IConfig, LoggerInterface, ICacheFactory) up to
	 * 10.0, gained IAppConfig and IUserConfig by 10.1, and Euro-Office's has a
	 * fifth shape of its own. Counting parameters to tell those apart only
	 * lasts until the next release, so each parameter is answered by its type
	 * instead: the container resolves the services, the one string every one of
	 * them takes is the connector's app id, and anything already built - the
	 * AppConfig that Crypt wants - is handed in by the caller.
	 *
	 * @param object[] $known already-built instances to pass to a parameter
	 *                        they satisfy
	 */
	private function build(string $class, array $known = []): object {
		$constructor = (new \ReflectionClass($class))->getConstructor();
		if ($constructor === null) {
			return new $class();
		}

		$arguments = [];
		foreach ($constructor->getParameters() as $parameter) {
			$type = $parameter->getType();
			$name = $type instanceof \ReflectionNamedType ? $type->getName() : null;

			$given = $name === null ? null : $this->pick($known, $name);

			if ($name === 'string') {
				$arguments[] = $this->getAppId();
			} elseif ($given !== null) {
				$arguments[] = $given;
			} elseif ($name === LoggerInterface::class) {
				// the connector's own log lines, under the connector's app name
				$arguments[] = \OCP\Log\logger($this->getAppId());
			} elseif ($name !== null && !$type->isBuiltin()) {
				$arguments[] = $this->container->get($name);
			} elseif ($parameter->isDefaultValueAvailable()) {
				$arguments[] = $parameter->getDefaultValue();
			} else {
				throw new \RuntimeException(
					"Cannot construct $class: nothing to pass for \${$parameter->getName()}"
				);
			}
		}

		return new $class(...$arguments);
	}

	/**
	 * The one of these instances a parameter of this type would accept.
	 *
	 * By instanceof rather than by class name: a constructor is free to ask for
	 * a parent class or an interface, and the connector's AppConfig has to
	 * reach its Crypt either way - a second one built behind its back would
	 * read the same settings, but nothing here should depend on that.
	 *
	 * @param object[] $known
	 */
	private function pick(array $known, string $type): ?object {
		foreach ($known as $instance) {
			if ($instance instanceof $type) {
				return $instance;
			}
		}
		return null;
	}
}
