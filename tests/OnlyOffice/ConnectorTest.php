<?php

declare(strict_types=1);
/**
 * @copyright Copyright (c) 2026 Fabrice Meyer <meyer.fabrice@gmx.fr>
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

// The two connector shapes this has to survive, standing in for the real ones:
// the classes belong to another app, they are constructed by hand rather than
// by the container, and their constructors have changed three times across the
// versions this app is expected to run against.
namespace OCA\DocumentServer\Tests\OnlyOffice\OldStyle {
	use OCP\ICacheFactory;
	use OCP\IConfig;
	use Psr\Log\LoggerInterface;

	class AppConfig {
		public array $args;

		public function __construct(string $appName, IConfig $config, LoggerInterface $logger, ICacheFactory $cacheFactory) {
			$this->args = func_get_args();
		}
	}

	class Crypt {
		public AppConfig $appConfig;

		public function __construct(AppConfig $appConfig) {
			$this->appConfig = $appConfig;
		}
	}
}

namespace OCA\DocumentServer\Tests\OnlyOffice\NewStyle {
	use OCP\IAppConfig;
	use OCP\ICacheFactory;
	use OCP\IConfig;
	use OCP\IURLGenerator;
	use Psr\Log\LoggerInterface;

	class AppConfig {
		public array $args;

		public function __construct(
			string $appName,
			IAppConfig $appConfig,
			IConfig $config,
			IURLGenerator $urlGenerator,
			LoggerInterface $logger,
			ICacheFactory $cacheFactory,
		) {
			$this->args = func_get_args();
		}
	}

	class Crypt {
		public AppConfig $appConfig;

		public function __construct(AppConfig $appConfig) {
			$this->appConfig = $appConfig;
		}
	}
}

namespace OCA\DocumentServer\Tests\OnlyOffice {

	use OCA\DocumentServer\OnlyOffice\Connector;
	use OCP\App\IAppManager;
	use OCP\IAppConfig;
	use OCP\IConfig;
	use Psr\Container\ContainerInterface;
	use Test\TestCase;

	/**
	 * Which connector app this document server talks to, and how it builds that
	 * connector's classes.
	 *
	 * Both halves are worth pinning down. The connector is another app: its
	 * AppConfig and Crypt are constructed here by hand, and their signatures
	 * have changed three times across the versions this app has to run against
	 * - so the arguments are worked out from the constructor's parameter types
	 * rather than from a count that goes stale. And "the connector" is no
	 * longer one app: Euro-Office ships the same thing under its own id and
	 * namespace, and on an instance running that one the onlyoffice classes do
	 * not exist at all, which used to take every request down with it (#405).
	 */
	class ConnectorTest extends TestCase {
		private const OLD = __NAMESPACE__ . '\\OldStyle';
		private const NEW = __NAMESPACE__ . '\\NewStyle';

		private $appManager;
		private $config;
		private $container;

		protected function setUp(): void {
			parent::setUp();

			$this->appManager = $this->createMock(IAppManager::class);
			$this->config = $this->createMock(IConfig::class);
			$this->container = $this->createMock(ContainerInterface::class);
			// the container answers with a mock of whatever type is asked for,
			// which is all the connector's constructors want from it
			$this->container->method('get')->willReturnCallback(
				fn (string $class) => $this->createMock($class)
			);
		}

		private function connector(array $connectors, array $enabled, array $urls = []): Connector {
			$this->appManager->method('isEnabledForUser')
				->willReturnCallback(fn (string $appId) => in_array($appId, $enabled, true));
			$this->config->method('getAppValue')
				->willReturnCallback(fn (string $appId, string $key, $default = '') => $urls[$appId] ?? $default);

			return new Connector($this->appManager, $this->config, $this->container, $connectors);
		}

		public function testNoConnectorInstalled() {
			$connector = $this->connector(['old' => self::OLD], []);

			$this->assertNull($connector->getAppId());
			$this->assertFalse($connector->isAvailable());

			// not a silent null: every caller of this needs the connector's
			// settings, and there is nothing sensible to hand back
			$this->expectException(\RuntimeException::class);
			$connector->getAppConfig();
		}

		public function testPicksTheEnabledConnector() {
			$connector = $this->connector(['old' => self::OLD, 'new' => self::NEW], ['new']);

			$this->assertSame('new', $connector->getAppId());
			$this->assertInstanceOf(NewStyle\AppConfig::class, $connector->getAppConfig());
		}

		public function testPrefersTheConnectorPointedAtThisDocumentServer() {
			// both installed, one of them configured against this app: that is
			// the one whose settings describe what this server is serving
			$connector = $this->connector(
				['old' => self::OLD, 'new' => self::NEW],
				['old', 'new'],
				[
					'old' => 'https://docs.example.com/',
					'new' => 'https://cloud.example.com/apps/documentserver_community/',
				]
			);

			$this->assertSame('new', $connector->getAppId());
		}

		public function testFallsBackToTheFirstEnabledOne() {
			// neither is configured yet - the state a fresh install is in, and
			// the one AutoConfig is about to fix - so the order in the list
			// decides, which keeps the historical connector first
			$connector = $this->connector(['old' => self::OLD, 'new' => self::NEW], ['old', 'new']);

			$this->assertSame('old', $connector->getAppId());
		}

		public function testIgnoresAnEnabledAppWithoutItsClasses() {
			// an app directory that is there but incomplete: enabled, and
			// nothing this app can use. Constructing against it is the 500
			// that #405 was.
			$connector = $this->connector(
				['ghost' => 'OCA\\DocumentServer\\Tests\\NoSuchConnector', 'old' => self::OLD],
				['ghost', 'old']
			);

			$this->assertSame('old', $connector->getAppId());
		}

		public function testBuildsTheOldConstructorShape() {
			$appConfig = $this->connector(['old' => self::OLD], ['old'])->getAppConfig();

			$this->assertInstanceOf(OldStyle\AppConfig::class, $appConfig);
			// the one string any of these take is the connector's own app id
			$this->assertSame('old', $appConfig->args[0]);
			$this->assertInstanceOf(IConfig::class, $appConfig->args[1]);
			$this->assertInstanceOf(\Psr\Log\LoggerInterface::class, $appConfig->args[2]);
		}

		public function testBuildsTheNewConstructorShape() {
			// two more services and a different order: the point of reading the
			// parameter types instead of counting them
			$appConfig = $this->connector(['new' => self::NEW], ['new'])->getAppConfig();

			$this->assertSame('new', $appConfig->args[0]);
			$this->assertInstanceOf(IAppConfig::class, $appConfig->args[1]);
			$this->assertInstanceOf(IConfig::class, $appConfig->args[2]);
			$this->assertCount(6, $appConfig->args);
		}

		public function testCryptGetsTheConnectorsOwnAppConfig() {
			$connector = $this->connector(['old' => self::OLD], ['old']);

			$crypt = $connector->getCrypt();
			$this->assertInstanceOf(OldStyle\Crypt::class, $crypt);
			// the same instance, not a second one built behind its back: the
			// secret it signs with is read through it
			$this->assertSame($connector->getAppConfig(), $crypt->appConfig);
		}

		public function testTheConnectorIsBuiltOnce() {
			$connector = $this->connector(['old' => self::OLD], ['old']);

			$this->assertSame($connector->getAppConfig(), $connector->getAppConfig());
			$this->assertSame($connector->getCrypt(), $connector->getCrypt());
		}
	}
}
