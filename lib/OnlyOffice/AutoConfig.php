<?php
/**
 * @copyright Copyright (c) 2020 Robin Appelman <robin@icewind.nl>
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

use OCA\Onlyoffice\AppConfig;
use OCP\IURLGenerator;

class AutoConfig {
	/**
	 * The formats OnlyOffice becomes the default opener for on a fresh install.
	 *
	 * A product choice, not a capability list: the bundled server can open far
	 * more than this, but taking .txt, .csv or .html away from Nextcloud's own
	 * handlers is not what installing a document server is asking for.
	 */
	private const DEFAULT_OPEN_FORMATS = [
		'doc',
		'docx',
		'odp',
		'ods',
		'odt',
		'pdf',
		'ppt',
		'pptx',
		'xls',
		'xlsx',
	];

	private $urlGenerator;
	private $appConfig;
	private $bundledFormats;

	public function __construct(IURLGenerator $urlGenerator, AppConfig $appConfig, BundledFormats $bundledFormats) {
		$this->urlGenerator = $urlGenerator;
		$this->appConfig = $appConfig;
		$this->bundledFormats = $bundledFormats;
	}

	public function autoConfigIfNeeded() {
		if ($this->shouldAutoConfig()) {
			$this->autoConfig();
		}
	}

	/**
	 * Check if onlyoffice is not configured and we should fill our defaults
	 *
	 * @return bool
	 */
	private function shouldAutoConfig(): bool {
		return !$this->appConfig->GetDocumentServerUrl();
	}

	/**
	 * Fill the documentserver url and other defaults
	 */
	private function autoConfig() {
		$url = substr($this->urlGenerator->linkToRouteAbsolute('documentserver_community.Static.webApps',
			['path' => '_']), 0, -strlen('/web-apps/_'));
		$this->appConfig->SetDocumentServerUrl($url);

		$this->seedSupportedFormats();
		$this->appConfig->SetSameTab(true);
	}

	/**
	 * Write the format defaults, once, while the connector is still unconfigured.
	 *
	 * A seed and nothing more: from here on the admin owns these two settings.
	 * This used to run from boot() on every request against a hardcoded list,
	 * force-disabling anything outside it - so a format the admin enabled in
	 * the settings UI was switched back off by the next page load, PDF among
	 * them, which 9.x has a dedicated editor for.
	 *
	 * Seeding still earns its keep, because the connector leaves the
	 * lossy-editable formats (odt, ods, odp, csv, rtf, txt) off by default;
	 * that was the point of doing this at all.
	 */
	private function seedSupportedFormats(): void {
		$bundled = $this->bundledFormats->actions();
		if (!$bundled) {
			// no package to ask, so nothing to say about it: leave the
			// connector's own defaults alone rather than writing every format
			// off
			return;
		}

		$editable = array_fill_keys($this->bundledFormats->editable(), true);

		// Every format the connector has an opinion about, plus anything the
		// bundled server knows that it does not: an explicit answer for each,
		// so the seed does not half-depend on which formats the connector's own
		// copy of the matrix happens to default on.
		$formats = array_unique(array_merge(
			array_keys($this->appConfig->FormatsSetting()),
			array_keys($bundled)
		));

		$defaultFormats = [];
		$editFormats = [];
		foreach ($formats as $format) {
			$known = isset($bundled[$format]);
			$editFormats[$format] = $known && isset($editable[$format]);
			// a default opener for something the bundled server cannot open
			// would just be a file that fails to load
			$defaultFormats[$format] = $known && in_array($format, self::DEFAULT_OPEN_FORMATS, true);
		}

		$this->appConfig->SetDefaultFormats($defaultFormats);
		$this->appConfig->SetEditableFormats($editFormats);
	}
}
