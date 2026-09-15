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

namespace OCA\DocumentServer\Document;

/**
 * Pins the font paths allfontsgen writes to x2t's working directory.
 *
 * allfontsgen prefixes every path it emits with its own working directory, so
 * the two files it produces carry the absolute paths of whichever machine ran
 * it. That is the build machine for an app store install, which is why the
 * paths have to be rewritten: relative to server/FileConverter/bin, which is
 * the working directory x2t, allfontsgen and allthemesgen are all given.
 *
 * Both consumers of those files load fonts by path and neither of them
 * complains when the path does not resolve:
 *
 * - sdkjs reads AllFonts.js during change replay and hands the null buffer to
 *   CFontFileLoader.LoadFontFromData, so saving a spreadsheet dies with
 *   "Cannot read property 'length' of null".
 * - x2t's PDF writer reads font_selection.bin, prints "Can't load fontfile" on
 *   stdout, writes a PDF with no glyphs in it and exits 0 (#371, #251, #287).
 *
 * Only the bundled fonts are pinned. Custom fonts live in appdata, outside the
 * app directory, and keep the absolute paths they are found at.
 *
 * This class is used both at build time (tools/pin-font-paths.php) and at
 * runtime (FontManager), so it must not depend on the server.
 */
class FontPaths {
	/**
	 * Where core-fonts sits as seen from server/FileConverter/bin.
	 */
	public const BUNDLED_PREFIX = '../../../core-fonts/';

	private const NEEDLE = '/core-fonts/';

	/**
	 * Longest path we will believe a length prefix describes. Keeps a wild
	 * int32 read out of a string that happens to contain the needle from
	 * swallowing the rest of the file.
	 */
	private const MAX_PATH = 4096;

	/**
	 * AllFonts.js is JavaScript, so its paths are plain quoted strings.
	 */
	public static function pinAllFontsJs(string $content): string {
		$pinned = preg_replace('|"[^"]*' . preg_quote(self::NEEDLE, '|') . '|', '"' . self::BUNDLED_PREFIX, $content);
		return $pinned === null ? $content : $pinned;
	}

	/**
	 * font_selection.bin is not sed-able: its strings are length-prefixed with
	 * a little-endian int32, so shortening one means rewriting its prefix. The
	 * two counts in the header count entries rather than bytes and no offset in
	 * the file is absolute, so the rest can be copied through untouched.
	 */
	public static function pinFontSelection(string $data): string {
		$out = '';
		$pos = 0;

		foreach (self::eachBundledPath($data) as [$start, $length, $path]) {
			$pinned = self::BUNDLED_PREFIX . substr($path, strpos($path, self::NEEDLE) + strlen(self::NEEDLE));
			$out .= substr($data, $pos, $start - 4 - $pos) . pack('V', strlen($pinned)) . $pinned;
			$pos = $start + $length;
		}

		return $out . substr($data, $pos);
	}

	/**
	 * The bundled font paths font_selection.bin holds, in the order they appear.
	 *
	 * @return string[]
	 */
	public static function fontSelectionPaths(string $data): array {
		$paths = [];
		foreach (self::eachBundledPath($data) as [, , $path]) {
			$paths[] = $path;
		}
		return $paths;
	}

	/**
	 * @return \Generator<array{0: int, 1: int, 2: string}> offset, length, path
	 */
	private static function eachBundledPath(string $data): \Generator {
		$pos = 0;
		while (($needleAt = strpos($data, self::NEEDLE, $pos)) !== false) {
			$start = self::stringStart($data, $needleAt, $pos);
			if ($start === null) {
				$pos = $needleAt + strlen(self::NEEDLE);
				continue;
			}

			$length = unpack('V', substr($data, $start - 4, 4))[1];
			yield [$start, $length, substr($data, $start, $length)];
			$pos = $start + $length;
		}
	}

	/**
	 * Walk back from the needle to the start of the string that contains it.
	 *
	 * The file gives us no index to look that up in, but the length prefix is a
	 * tight enough constraint on its own: the four bytes before any other
	 * offset inside the path are path characters, which read as an int32 far
	 * larger than any path.
	 *
	 * @param int $after offset before which nothing may be read, because it has
	 *                   already been copied to the output
	 */
	private static function stringStart(string $data, int $needleAt, int $after): ?int {
		$floor = max($after + 4, $needleAt - self::MAX_PATH);

		for ($i = $needleAt; $i >= $floor; $i--) {
			$length = unpack('V', substr($data, $i - 4, 4))[1];

			// must reach past the needle, and stay inside the file
			if ($length < $needleAt - $i + strlen(self::NEEDLE) || $length > self::MAX_PATH) {
				continue;
			}
			if ($i + $length > strlen($data)) {
				continue;
			}

			$path = substr($data, $i, $length);
			if (strpos($path, self::NEEDLE) === false) {
				continue;
			}
			// a path holds no control bytes; the padding and the numeric
			// fields around it are full of them, so this is what tells a real
			// string apart from a length read out of the middle of one
			if (preg_match('/[\x00-\x1f\x7f]/', $path)) {
				continue;
			}

			return $i;
		}

		return null;
	}
}
