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

namespace OCA\DocumentServer\OnlyOffice;

/**
 * The format matrix the bundled document server ships, as it declares it.
 *
 * The onlyoffice connector carries its own copy of the same file, but the two
 * can be different versions, and it is this one that says what the converter
 * we actually run can do. Read from the package rather than restated as a
 * list, because a list goes stale silently: the one this replaced predated
 * 9.x and named twelve editable formats where the bundled server declares
 * twenty-nine.
 */
class BundledFormats {
	public const FORMATS_FILE = __DIR__ . '/../../3rdparty/onlyoffice/documentserver/document-formats/onlyoffice-docs-formats.json';

	/** Editing that keeps everything; "lossy-edit" is editing through a conversion. */
	private const EDIT_ACTIONS = ['edit', 'lossy-edit'];

	private string $path;

	/** @var array<string, list<string>>|null extension => actions, read once */
	private ?array $actions = null;

	public function __construct(string $path = self::FORMATS_FILE) {
		$this->path = $path;
	}

	/**
	 * What the bundled server says it can do with each format, keyed by
	 * extension. Empty when the package is not there or does not parse - the
	 * callers of this treat that as "nothing known", never as "nothing
	 * supported".
	 *
	 * @return array<string, list<string>>
	 */
	public function actions(): array {
		if ($this->actions !== null) {
			return $this->actions;
		}

		$this->actions = [];

		// asked before reading, not left to the read failing: a missing package
		// is the ordinary state of a checkout that has not run `make` yet, and
		// a warning about it in the log would be noise
		if (!is_readable($this->path)) {
			return $this->actions;
		}

		$json = @file_get_contents($this->path);
		if ($json === false) {
			return $this->actions;
		}

		$formats = json_decode($json, true);
		if (!is_array($formats)) {
			return $this->actions;
		}

		foreach ($formats as $format) {
			if (!is_array($format) || !isset($format['name']) || !is_string($format['name'])) {
				continue;
			}
			$actions = $format['actions'] ?? [];
			$this->actions[$format['name']] = is_array($actions) ? array_values($actions) : [];
		}

		return $this->actions;
	}

	/**
	 * The formats the bundled server can open in an editor.
	 *
	 * @return list<string>
	 */
	public function editable(): array {
		$editable = [];
		foreach ($this->actions() as $name => $actions) {
			if (array_intersect(self::EDIT_ACTIONS, $actions)) {
				$editable[] = $name;
			}
		}
		return $editable;
	}
}
