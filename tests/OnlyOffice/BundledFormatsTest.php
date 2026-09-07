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

namespace OCA\DocumentServer\Tests\OnlyOffice;

use OCA\DocumentServer\OnlyOffice\BundledFormats;
use Test\TestCase;

/**
 * Which formats the bundled document server says it can edit.
 *
 * This is what AutoConfig seeds the connector's format settings from, and it
 * replaces a hardcoded list that had gone stale without anybody noticing -
 * twelve formats where the bundled server declares twenty-nine, and three of
 * the twelve (doc, ppt, xls) it cannot edit at all. So the point of these is
 * that the answer keeps coming from the package rather than from a list.
 */
class BundledFormatsTest extends TestCase {
	/** @var string[] */
	private array $fixtures = [];

	protected function tearDown(): void {
		foreach ($this->fixtures as $path) {
			@unlink($path);
		}
		$this->fixtures = [];

		parent::tearDown();
	}

	private function fixture(string $json): BundledFormats {
		$path = tempnam(sys_get_temp_dir(), 'formats');
		file_put_contents($path, $json);
		$this->fixtures[] = $path;
		return new BundledFormats($path);
	}

	public function testEditableCoversLossyEditing() {
		// "lossy-edit" is editing through a conversion, which is exactly the
		// set the connector leaves off by default - odt, ods, csv, txt - and
		// therefore the set seeding exists to turn on.
		$formats = $this->fixture(json_encode([
			['name' => 'docx', 'actions' => ['view', 'edit', 'comment']],
			['name' => 'odt', 'actions' => ['view', 'lossy-edit']],
			['name' => 'doc', 'actions' => ['view', 'auto-convert']],
			['name' => 'vsdx', 'actions' => ['view']],
		]));

		$this->assertEquals(['docx', 'odt'], $formats->editable());
	}

	public function testAFormatWithNoActionsIsNotEditable() {
		$formats = $this->fixture(json_encode([
			['name' => 'docx', 'actions' => ['edit']],
			['name' => 'bmp'],
			['name' => 'gif', 'actions' => null],
		]));

		$this->assertEquals(['docx'], $formats->editable());
		$this->assertEquals(['docx' => ['edit'], 'bmp' => [], 'gif' => []], $formats->actions());
	}

	/**
	 * A missing or unreadable package must not read as "this server can edit
	 * nothing": AutoConfig would then seed every format off, which is worse
	 * than leaving the connector's own defaults alone.
	 */
	public function testAMissingPackageIsNotAnEmptyServer() {
		$formats = new BundledFormats('/nonexistent/onlyoffice-docs-formats.json');

		$this->assertSame([], $formats->actions());
		$this->assertSame([], $formats->editable());
	}

	public function testGarbageIsTreatedAsAMissingPackage() {
		$this->assertSame([], $this->fixture('not json at all')->actions());
		$this->assertSame([], $this->fixture('"a string"')->actions());
	}

	/**
	 * The shape of the real file, when there is one - a build machine has it,
	 * the unit-test job does not build the document server. Catches an upstream
	 * release renaming the fields this reads, which would otherwise show up as
	 * a fresh install seeding nothing.
	 */
	public function testTheBundledPackageDeclaresTheEditorsItShips() {
		if (!file_exists(BundledFormats::FORMATS_FILE)) {
			$this->markTestSkipped('the document server is not built in this checkout');
		}

		$formats = new BundledFormats();
		$editable = $formats->editable();

		// the four editors the bundled server ships, one format each
		foreach (['docx', 'xlsx', 'pptx', 'pdf'] as $format) {
			$this->assertContains($format, $editable, "$format should be editable");
		}
		// lossy-edit, the reason seeding exists
		foreach (['odt', 'ods', 'odp', 'csv', 'rtf', 'txt'] as $format) {
			$this->assertContains($format, $editable, "$format should be editable");
		}
		// known to the matrix, but view only: the old hardcoded list claimed
		// doc, ppt and xls were editable
		foreach (['doc', 'ppt', 'xls', 'vsdx'] as $format) {
			$this->assertArrayHasKey($format, $formats->actions());
			$this->assertNotContains($format, $editable, "$format should not be editable");
		}
	}
}
