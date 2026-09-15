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

namespace OCA\DocumentServer\Tests\Document;

use OCA\DocumentServer\Document\FontPaths;
use Test\TestCase;

/**
 * Rewriting the font paths allfontsgen leaves behind.
 *
 * font_selection.bin is where blank PDF export lived (#371, #251, #287), and it
 * is not a text file: its strings are length-prefixed with a little-endian
 * int32, so a path that is replaced without its prefix being updated corrupts
 * everything after it - and x2t reports a corrupt font list the same way it
 * reports a missing font, by exiting 0 having loaded none. There is nothing
 * downstream to catch a mistake here, hence these.
 */
class FontPathsTest extends TestCase {
	/**
	 * Where the first length prefix starts in the real file, and so in this
	 * stand-in for it: two counts, the length of the magic, the magic, and one
	 * more int32.
	 */
	private const HEADER = 21;

	/**
	 * A stand-in for the real thing: the header above, then length-prefixed
	 * strings with numeric fields between them.
	 */
	private function selection(array $strings): string {
		$data = pack('V', count($strings)) . pack('V', 215) . pack('V', 5) . 'ASCW3' . pack('V', 0);
		foreach ($strings as $string) {
			$data .= pack('V', strlen($string)) . $string . pack('V', 0) . "\x00\x00";
		}
		return $data;
	}

	/** @return string[] */
	private function stringsOf(string $data, array $original): array {
		// Read the file back the way it is written, to prove the prefixes still
		// describe the strings that follow them.
		$out = [];
		$offset = self::HEADER;
		for ($i = 0; $i < count($original); $i++) {
			$length = unpack('V', substr($data, $offset, 4))[1];
			$out[] = substr($data, $offset + 4, $length);
			$offset += 4 + $length + 6;
		}
		return $out;
	}

	public function testPinsBundledPathsAndUpdatesTheirLengthPrefix(): void {
		$strings = [
			'/build/machine/server/tools/../../core-fonts/ASC.ttf',
			'Liberation Serif',
			'/build/machine/server/tools/../../core-fonts/liberation/LiberationSerif-Regular.ttf',
		];

		$pinned = FontPaths::pinFontSelection($this->selection($strings));

		$this->assertSame([
			'../../../core-fonts/ASC.ttf',
			'Liberation Serif',
			'../../../core-fonts/liberation/LiberationSerif-Regular.ttf',
		], $this->stringsOf($pinned, $strings));
	}

	public function testLeavesCustomFontPathsAlone(): void {
		// Custom fonts live in appdata, outside the app directory, so there is
		// no relative path that reaches them.
		$custom = '/var/www/html/data/appdata_abc/documentserver_community/fonts/Yrsa.ttf';
		$strings = ['/build/machine/server/tools/../../core-fonts/ASC.ttf', $custom];

		$pinned = FontPaths::pinFontSelection($this->selection($strings));

		$this->assertSame(['../../../core-fonts/ASC.ttf', $custom],
			$this->stringsOf($pinned, $strings));
	}

	public function testIsIdempotent(): void {
		$data = $this->selection(['/build/machine/server/tools/../../core-fonts/ASC.ttf']);

		$once = FontPaths::pinFontSelection($data);
		$this->assertSame($once, FontPaths::pinFontSelection($once));
	}

	public function testFindsEveryBundledPath(): void {
		$data = $this->selection([
			'/build/machine/server/tools/../../core-fonts/ASC.ttf',
			'not a path at all',
			'/build/machine/server/tools/../../core-fonts/liberation/LiberationSans-Bold.ttf',
			'/var/lib/appdata/fonts/Custom.ttf',
		]);

		$this->assertSame([
			'/build/machine/server/tools/../../core-fonts/ASC.ttf',
			'/build/machine/server/tools/../../core-fonts/liberation/LiberationSans-Bold.ttf',
		], FontPaths::fontSelectionPaths($data));
	}

	public function testLeavesEverythingElseInTheFileWhereItWas(): void {
		$strings = ['/b/server/tools/../../core-fonts/ASC.ttf'];
		$data = $this->selection($strings);

		$pinned = FontPaths::pinFontSelection($data);

		// The header counts entries, not bytes, so it is copied through; and
		// the file shrinks by exactly what came off the front of the path.
		$this->assertSame(substr($data, 0, self::HEADER), substr($pinned, 0, self::HEADER));
		$this->assertSame(
			strlen($data) - (strlen($strings[0]) - strlen('../../../core-fonts/ASC.ttf')),
			strlen($pinned)
		);
	}

	public function testPinsAllFontsJs(): void {
		$content = '["/build/machine/core-fonts/ASC.ttf","/data/appdata/fonts/Custom.ttf"]';

		$this->assertSame(
			'["../../../core-fonts/ASC.ttf","/data/appdata/fonts/Custom.ttf"]',
			FontPaths::pinAllFontsJs($content)
		);
	}

	public function testHandlesAFileWithNoBundledPaths(): void {
		$data = $this->selection(['/var/lib/appdata/fonts/Custom.ttf']);

		$this->assertSame($data, FontPaths::pinFontSelection($data));
		$this->assertSame([], FontPaths::fontSelectionPaths($data));
	}
}
