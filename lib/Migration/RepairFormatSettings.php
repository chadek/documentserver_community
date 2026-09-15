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

namespace OCA\DocumentServer\Migration;

use OCA\DocumentServer\OnlyOffice\AutoConfig;
use OCP\App\IAppManager;
use OCP\Migration\IOutput;
use OCP\Migration\IRepairStep;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;

/**
 * Hand an existing install the format matrix a fresh one now gets.
 *
 * Seeding runs from AutoConfig::autoConfig(), which only runs while the
 * connector has no document server url - so it reaches new installs and nothing
 * else. An instance configured before the seed was read from the bundled
 * package keeps what the old hardcoded list wrote: twelve editable formats,
 * three of which (doc, ppt, xls) the bundled server cannot edit at all, and
 * none of the twenty-nine it can.
 *
 * The decision of whether an install may be re-seeded is AutoConfig's, and it
 * is a conservative one: exactly the old lists and nothing else, so an admin
 * who has changed anything keeps their own settings.
 */
class RepairFormatSettings implements IRepairStep {
	private IAppManager $appManager;
	private ContainerInterface $container;
	private LoggerInterface $logger;

	/**
	 * AutoConfig is resolved in run() rather than injected, because building it
	 * needs the connector's own AppConfig class - so an instance with the
	 * connector disabled, or not installed at all, cannot construct one. That is
	 * an ordinary state for this app, and an upgrade must not fall over in it;
	 * boot() takes the same care for the same reason.
	 */
	public function __construct(IAppManager $appManager, ContainerInterface $container, LoggerInterface $logger) {
		$this->appManager = $appManager;
		$this->container = $container;
		$this->logger = $logger;
	}

	public function getName(): string {
		return 'Update the OnlyOffice format settings to what the bundled document server supports';
	}

	public function run(IOutput $output): void {
		if (!$this->appManager->isEnabledForUser('onlyoffice')) {
			// nothing to configure, and AutoConfig would not be constructible
			// against a connector that is not there
			return;
		}

		try {
			/** @var AutoConfig $autoConfig */
			$autoConfig = $this->container->get(AutoConfig::class);
			$reseeded = $autoConfig->reseedFormatsIfUntouched();
		} catch (\Throwable $e) {
			// An upgrade must not fail over a settings default. The old matrix
			// keeps working - it is a subset of what the server can do, not a
			// broken state - so this is worth a line in the log and nothing
			// more.
			$this->logger->warning('documentserver could not update the OnlyOffice format settings: {error}', [
				'error' => $e->getMessage(),
				'exception' => $e,
			]);
			return;
		}

		if ($reseeded) {
			$output->info('OnlyOffice format settings updated to what the bundled document server supports');
		}
	}
}
