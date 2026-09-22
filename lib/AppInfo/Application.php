<?php
/**
 * @copyright Copyright (c) 2019 Robin Appelman <robin@icewind.nl>
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

namespace OCA\DocumentServer\AppInfo;

use OCA\DocumentServer\IPC\DatabaseIPCFactory;
use OCA\DocumentServer\IPC\IIPCFactory;
use OCA\DocumentServer\IPC\IPCFactory;
use OCA\DocumentServer\IPC\MemcacheIPCFactory;
use OCA\DocumentServer\IPC\RedisIPCFactory;
use OCA\DocumentServer\JSSettingsHelper;
use OCA\DocumentServer\OnlyOffice\AutoConfig;
use OCA\DocumentServer\OnlyOffice\BundledFormats;
use OCA\DocumentServer\OnlyOffice\Connector;
use OCA\DocumentServer\OnlyOffice\URLDecoder;
use OCP\App\IAppManager;
use OCP\AppFramework\App;
use OCP\AppFramework\Bootstrap\IBootContext;
use OCP\AppFramework\Bootstrap\IBootstrap;
use OCP\AppFramework\Bootstrap\IRegistrationContext;
use OCP\AppFramework\IAppContainer;
use OCP\Files\IRootFolder;
use OCP\IConfig;
use OCP\IURLGenerator;
use OCP\IUserSession;
use OCP\Share\IManager;
use OCP\Util;

class Application extends App implements IBootstrap {
	public function __construct(array $urlParams = []) {
		parent::__construct('documentserver_community', $urlParams);
	}

	public function register(IRegistrationContext $context): void {
		// One per request rather than one per injection: it decides which
		// connector app is in play and builds that connector's classes, and
		// both answers are worth keeping for the rest of the request.
		$context->registerService(Connector::class, function (IAppContainer $container) {
			return new Connector(
				$container->get(IAppManager::class),
				$container->get(IConfig::class),
				$container
			);
		});

		$context->registerService(IIPCFactory::class, function (IAppContainer $c) {
			$factory = new IPCFactory();
			$factory->registerBackend($c->get(DatabaseIPCFactory::class));
			$factory->registerBackend($c->get(MemcacheIPCFactory::class));
			$factory->registerBackend($c->get(RedisIPCFactory::class));

			return $factory;
		});

		$context->registerService(URLDecoder::class, function (IAppContainer $container) {
			$server = $container->getServer();

			return new URLDecoder(
				$container->get(Connector::class)->getCrypt(),
				$server->get(IUserSession::class),
				$server->get(IManager::class),
				$server->get(IRootFolder::class)
			);
		});

		// Registered rather than left to autowiring: its one constructor
		// argument is a string, and the container resolves those by parameter
		// name before falling back to the default - so a service registered
		// under the name "path" would be handed to it as a file path.
		$context->registerService(BundledFormats::class, function () {
			return new BundledFormats();
		});

		$context->registerService(AutoConfig::class, function (IAppContainer $container) {
			return new AutoConfig(
				$container->get(IURLGenerator::class),
				$container->get(Connector::class)->getAppConfig(),
				$container->get(BundledFormats::class)
			);
		});
	}

	public function boot(IBootContext $context): void {
		$context->injectFn(function (Connector $connector) {
			if ($connector->isAvailable()) {
				$this->getAutoConfig()->autoConfigIfNeeded();
				Util::connectHook('\OCP\Config', 'js', $this->getJSSettingsHelper(), 'extend');
			}
		});
	}

	private function getJSSettingsHelper(): JSSettingsHelper {
		return $this->getContainer()->get(JSSettingsHelper::class);
	}

	private function getAutoConfig(): AutoConfig {
		return $this->getContainer()->get(AutoConfig::class);
	}
}
