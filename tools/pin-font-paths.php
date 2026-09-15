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

/**
 * Build-time half of FontPaths: rewrites the paths allfontsgen just wrote into
 * server/FileConverter/bin so they resolve wherever the app is installed,
 * rather than only on the machine that built it.
 *
 * Usage: php tools/pin-font-paths.php <server/FileConverter/bin>
 *
 * Not shipped - the Makefile runs it, and FontManager does the same thing on
 * an install that rebuilds its fonts.
 */

require __DIR__ . '/../lib/Document/FontPaths.php';

use OCA\DocumentServer\Document\FontPaths;

$binDir = $argv[1] ?? null;
if ($binDir === null || !is_dir($binDir)) {
	fwrite(STDERR, "usage: php tools/pin-font-paths.php <server/FileConverter/bin>\n");
	exit(1);
}

$files = [
	'AllFonts.js' => [FontPaths::class, 'pinAllFontsJs'],
	'font_selection.bin' => [FontPaths::class, 'pinFontSelection'],
];

foreach ($files as $name => $pin) {
	$path = $binDir . '/' . $name;

	$content = @file_get_contents($path);
	if ($content === false || $content === '') {
		fwrite(STDERR, "!! allfontsgen produced no $path; refusing to ship a build that cannot load fonts\n");
		exit(1);
	}

	$pinned = $pin($content);
	if (file_put_contents($path, $pinned) === false) {
		fwrite(STDERR, "!! cannot write $path\n");
		exit(1);
	}

	echo "pinned $name (" . strlen($content) . " -> " . strlen($pinned) . " bytes)\n";
}

// Both files name the same fonts, so one resolving and the other not means the
// rewrite missed a form of path rather than that a font is missing.
$paths = FontPaths::fontSelectionPaths((string)file_get_contents($binDir . '/font_selection.bin'));
if (!$paths) {
	fwrite(STDERR, "!! no bundled font paths in font_selection.bin\n");
	exit(1);
}
foreach ($paths as $path) {
	if (!is_file($binDir . '/' . $path)) {
		fwrite(STDERR, "!! pinned font path does not resolve from $binDir: $path\n");
		exit(1);
	}
}
echo "checked " . count($paths) . " bundled font paths resolve from the converter directory\n";
