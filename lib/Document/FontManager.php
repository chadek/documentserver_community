<?php

declare(strict_types=1);
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

namespace OCA\DocumentServer\Document;

use OCA\DocumentServer\LocalAppData;
use OCP\Files\IAppData;
use OCP\Files\NotFoundException;
use OCP\Files\SimpleFS\ISimpleFile;
use OCP\Files\SimpleFS\ISimpleFolder;

class FontManager {
	private $appData;
	private $localAppData;

	public function __construct(
		IAppData $appData,
		LocalAppData $localAppData
	) {
		$this->appData = $appData;
		$this->localAppData = $localAppData;
	}

	public function rebuildFonts() {
		if (!is_executable(ConverterBinary::BINARY_DIRECTORY . '/../../tools/allfontsgen')) {
			@chmod(ConverterBinary::BINARY_DIRECTORY . '/../../tools/allfontsgen', 0755);
		}

		$this->localAppData->getReadLocalPath($this->getFontDir(), function (string $fontsDir) {
			// The web font directory is generated here rather than shipped -
			// it is the same 188 files as core-fonts in the form the browser
			// downloads, and together they would push the app archive over the
			// app store's size limit. allfontsgen does not create its output
			// directory, so a fresh install has nowhere to put them.
			@mkdir(ConverterBinary::BINARY_DIRECTORY . '/../../../fonts', 0755, true);

			// Absolute paths throughout: since 9.x allfontsgen no longer
			// resolves relative arguments against the working directory, and
			// with relative ones it just writes out an empty font list. And
			// resolved rather than left with the '/../..' this file reaches
			// them by, so that what ends up in the font lists, and in the
			// errors below, is a path an admin can act on.
			$binDir = realpath(ConverterBinary::BINARY_DIRECTORY) ?: ConverterBinary::BINARY_DIRECTORY;
			$documentServer = realpath($binDir . '/../../..') ?: $binDir . '/../../..';

			// Before anything runs: a rebuild that cannot write its output is
			// the failure this whole method exists to make visible, and
			// allfontsgen signals it by exiting 0 with nothing on either pipe.
			// Saying so here names the file, which "no usable font list" below
			// cannot.
			$this->assertWritable([
				$binDir . '/AllFonts.js',
				$binDir . '/font_selection.bin',
				$documentServer . '/sdkjs/common/AllFonts.js',
				$documentServer . '/fonts',
			]);

			// How a run that wrote nothing at all is told apart from one that
			// rewrote both files with identical content, which is the ordinary
			// case: allfontsgen rewrites them either way, so the mtime moves
			// either way. Two readings because neither is enough on its own -
			// a second rebuild inside the same second leaves the mtime where it
			// was, and a filesystem clock that runs behind this one (NFS) never
			// reaches $startedAt.
			$startedAt = time();
			$before = [];
			foreach ([$binDir . '/AllFonts.js', $binDir . '/font_selection.bin'] as $path) {
				clearstatcache(true, $path);
				$before[$path] = @filemtime($path);
			}

			$cmd = $binDir . '/../../tools/allfontsgen \
				--input="' . $documentServer . '/core-fonts" \
				--input="' . $fontsDir . '" \
				--allfonts-web="' . $documentServer . '/sdkjs/common/AllFonts.js" \
				--allfonts="' . $binDir . '/AllFonts.js" \
				--images="' . $documentServer . '/sdkjs/common/Images" \
				--output-web="' . $documentServer . '/fonts" \
				--selection="' . $binDir . '/font_selection.bin"';

			$descriptorSpec = [
				0 => ["pipe", "r"],// stdin
				1 => ["pipe", "w"],// stdout
				2 => ["pipe", "w"] // stderr
			];

			$pipes = [];
			// Same environment x2t is given: since 9.x the converter's shared
			// libraries ship only in FileConverter/bin (the working directory
			// here), and allfontsgen cannot load them without this.
			$process = proc_open($cmd, $descriptorSpec, $pipes, ConverterBinary::BINARY_DIRECTORY, ["LD_LIBRARY_PATH" => "."]);

			if (!$process) {
				throw new \Exception("Failed to start allfontsgen");
			}

			fclose($pipes[0]);
			$output = stream_get_contents($pipes[1]);
			$error = stream_get_contents($pipes[2]);
			fclose($pipes[1]);
			fclose($pipes[2]);
			$status = proc_close($process);

			if ($error) {
				throw new \Exception($error);
			}

			$this->finishFontList($binDir, $status, $output, $startedAt, $before);
		});
	}

	/**
	 * Fail unless every path is writable, or can be created.
	 *
	 * @param string[] $paths
	 */
	private function assertWritable(array $paths): void {
		foreach ($paths as $path) {
			$target = file_exists($path) ? $path : dirname($path);
			if (!is_writable($target)) {
				$user = function_exists('posix_geteuid')
					? (posix_getpwuid(posix_geteuid())['name'] ?? (string)posix_geteuid())
					: 'the web server user';
				throw new \Exception(
					"Cannot rebuild the font library: $target is not writable by $user. "
					. "Custom fonts will not be picked up, and on an install made before the "
					. "bundled font paths were pinned at build time, PDF export stays blank."
				);
			}
		}
	}

	/**
	 * Check that this run of allfontsgen produced both font lists, then pin the
	 * bundled font paths in them to x2t's working directory
	 * (server/FileConverter/bin).
	 *
	 * Everything allfontsgen gets wrong here it reports by exiting successfully
	 * with nothing on stderr: it writes no font list at all when it cannot
	 * create the file, an empty one when it cannot find the fonts, and it
	 * prefixes every path it does write with its own working directory, which
	 * stops resolving as soon as the app directory moves - or, for an app store
	 * install, the moment it leaves the machine that built it.
	 *
	 * Nothing downstream notices any of that. sdkjs hands the null buffer to
	 * CFontFileLoader.LoadFontFromData and any change replay that has to
	 * measure text - saving a spreadsheet, for one - fails with "Cannot read
	 * property 'length' of null"; x2t's PDF writer prints "Can't load fontfile"
	 * on a stream nobody reads and writes a PDF with no glyphs in it (#371,
	 * #251, #287). So the check has to happen here, and it has to be loud: the
	 * repair step turns it into a warning at `occ upgrade`, which is four years
	 * earlier than the alternative.
	 *
	 * Paths to custom fonts live outside the app directory and stay absolute.
	 */
	private function finishFontList(string $binDir, int $status, string $output, int $startedAt, array $before): void {
		$allFontsJs = $binDir . '/AllFonts.js';
		$selection = $binDir . '/font_selection.bin';

		foreach ([$allFontsJs, $selection] as $path) {
			clearstatcache(true, $path);
			$mtime = @filemtime($path);

			if ($mtime === false || @filesize($path) === 0) {
				throw new \Exception(
					"allfontsgen wrote no $path (exit status $status) " . trim($output)
				);
			}
			// A stale file left over from the build satisfies every check on
			// its contents, so the one thing worth knowing about it is that
			// this run did not write it.
			if ($mtime === $before[$path] && $mtime < $startedAt) {
				throw new \Exception(
					"allfontsgen left $path untouched (exit status $status), so the font "
					. "paths in it are still the ones it was built with " . trim($output)
				);
			}
		}

		$content = @file_get_contents($allFontsJs);
		if ($content === false || strpos($content, '/core-fonts/') === false) {
			throw new \Exception(
				"allfontsgen produced no usable font list in $allFontsJs (exit status $status) "
				. trim($output)
			);
		}
		$this->write($allFontsJs, FontPaths::pinAllFontsJs($content));

		$data = @file_get_contents($selection);
		if ($data === false) {
			throw new \Exception("Cannot read $selection");
		}
		$pinned = FontPaths::pinFontSelection($data);
		$this->write($selection, $pinned);

		// The pinning is the part with nothing else watching it: a path form
		// the rewriter does not recognise would leave x2t loading no fonts
		// exactly as before, and exiting 0 about it.
		$paths = FontPaths::fontSelectionPaths($pinned);
		if (!$paths) {
			throw new \Exception(
				"No bundled font paths in $selection (exit status $status) " . trim($output)
			);
		}
		foreach ($paths as $path) {
			if (!is_file($binDir . '/' . $path)) {
				throw new \Exception(
					"Font path in $selection does not resolve from $binDir: $path"
				);
			}
		}
	}

	private function write(string $path, string $content): void {
		if (file_put_contents($path, $content) === false) {
			throw new \Exception("Cannot write $path");
		}
	}

	private function getFontDir(): ISimpleFolder {
		try {
			return $this->appData->getFolder('fonts');
		} catch (NotFoundException $e) {
			return $this->appData->newFolder('fonts');
		}
	}

	/**
	 * @return string[]
	 */
	public function listFonts(): array {
		$dir = $this->getFontDir();
		$fonts = $dir->getDirectoryListing();
		return array_map(function (ISimpleFile $file) {
			return $file->getName();
		}, $fonts);
	}

	public function addFont(string $path) {
		if (!file_exists($path)) {
			throw new \Exception("Font not found: $path");
		}
		if (substr($path, -4) !== '.ttf') {
			throw new \Exception("Only ttf fonts are accepted");
		}

		$dir = $this->getFontDir();
		$fontFile = $dir->newFile(basename($path));
		$fontData = file_get_contents($path);
		$fontFile->putContent($fontData);
	}

	public function removeFont(string $name) {
		$dir = $this->getFontDir();
		try {
			$dir->getFile($name)->delete();
		} catch (\Exception $e) {
			throw new \Exception("Font not added: $name");
		}
	}
}
