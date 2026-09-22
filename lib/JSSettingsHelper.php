<?php

declare(strict_types=1);
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

namespace OCA\DocumentServer;

use OCA\DocumentServer\OnlyOffice\Connector;
use OCP\IConfig;

class JSSettingsHelper {
	private $config;
	private $connector;

	public function __construct(IConfig $config, Connector $connector) {
		$this->config = $config;
		$this->connector = $connector;
	}

	public function extend(array $settings) {
		$connectorId = $this->connector->getAppId();
		if ($connectorId === null) {
			return;
		}

		$documentServerUrl = $this->config->getAppValue($connectorId, 'DocumentServerUrl');
		if (strpos($documentServerUrl, 'apps/documentserver_community')) {
			$url = rtrim($documentServerUrl, '/') . '/3rdparty/onlyoffice/documentserver/web-apps/';
			$settings['array']['extensionParams'] = json_encode([
				'url' => $url
			]);
		}
	}
}
