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

namespace OCA\DocumentServer\Document;

use Psr\Log\LoggerInterface;

class ConverterBinary {
	public const BINARY_DIRECTORY = __DIR__ . '/../../3rdparty/onlyoffice/documentserver/server/FileConverter/bin';

	/** What x2t prints on stdout, and only on stdout, for a font it cannot open. */
	private const MISSING_FONT = "Can't load fontfile ";

	private $logger;

	public function __construct(LoggerInterface $logger) {
		$this->logger = $logger;
	}

	public function run(string $param, ?string $password = null): string {
		if (!is_executable(self::BINARY_DIRECTORY . '/x2t')) {
			@chmod(self::BINARY_DIRECTORY . '/x2t', 0755);
		}

		$descriptorSpec = [
			0 => ["pipe", "r"],// stdin
			1 => ["pipe", "w"],// stdout
			2 => ["pipe", "w"] // stderr
		];

		$pipes = [];
		$cmd = './x2t ' . escapeshellarg($param);
		if ($password) {
			$password = htmlspecialchars($password, ENT_XML1, 'UTF-8');
			$cmd .= ' ' . escapeshellarg("<TaskQueueDataConvert><m_sPassword>$password</m_sPassword></TaskQueueDataConvert>");
		}
		$process = proc_open($cmd, $descriptorSpec, $pipes, self::BINARY_DIRECTORY, ["LD_LIBRARY_PATH" => "."]);

		// proc_open returns false if x2t couldn't be spawned at all (missing
		// binary, fork failure). Without this the next lines fclose/read null
		// pipes and proc_close(false) warns, and the caller silently gets no
		// output instead of a clear failure.
		if ($process === false) {
			throw new DocumentConversionException("failed to start x2t");
		}

		@fclose($pipes[0]);
		$output = @stream_get_contents($pipes[1]);
		$error = @stream_get_contents($pipes[2]);

		$status = proc_close($process);

		if ($status == 90 || $status == 91) {
			throw new PasswordRequiredException($status);
		}

		if ($error) {
			throw new DocumentConversionException($error);
		}

		// x2t can fail with a nonzero exit status while writing nothing to stderr;
		// without this the caller treats a failed conversion as success and later
		// trips over the missing Editor.bin, see #70. Stderr is checked first so its
		// (more specific) message wins, including the "Empty sFileFrom or sFileTo"
		// string that test() relies on.
		if ($status !== 0) {
			throw new DocumentConversionException("x2t exited with status $status");
		}

		$this->reportMissingFonts($output);

		return $output;
	}

	/**
	 * A font x2t could not load is a line on stdout and nothing else.
	 *
	 * It carries on and exits 0, and what comes out is a document with no
	 * glyphs in it - the whole of #371, #251 and #287, which went four years
	 * without a diagnostic because a blank PDF and a good one are the same
	 * successful conversion from here. Not fatal: one missing custom font
	 * should not fail a conversion that is otherwise fine. But it goes in the
	 * log, because nothing else in the system can tell the difference.
	 */
	private function reportMissingFonts(string $output): void {
		if (strpos($output, self::MISSING_FONT) === false) {
			return;
		}

		$missing = [];
		foreach (explode("\n", $output) as $line) {
			$at = strpos($line, self::MISSING_FONT);
			if ($at !== false) {
				$missing[trim(substr($line, $at + strlen(self::MISSING_FONT)))] = true;
			}
		}

		$this->logger->error(
			'x2t could not load ' . count($missing) . ' font(s); anything set in them converts '
			. 'with no glyphs at all. Run `occ documentserver:fonts --rebuild`. Missing: '
			. implode(', ', array_slice(array_keys($missing), 0, 5)),
			['app' => 'documentserver_community']
		);
	}

	public function test(): bool {
		try {
			$output = $this->run('');
			return strpos($output, 'OOX/binary file converter') !== false;
		} catch (\Exception $e) {
			if (trim((string)$e->getMessage()) === 'Empty sFileFrom or sFileTo') {
				return true;
			}
			$this->logger->error(
				'Error while testing x2t binary', 
				['exception' => $e, 'app' => 'documentserver_community']
			);
			return false;
		}
	}

	public function exists(): bool {
		return file_exists(self::BINARY_DIRECTORY . '/x2t');
	}
}
