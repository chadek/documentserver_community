<?php

declare(strict_types=1);

namespace OCA\DocumentServer\Migration;

use Closure;
use Doctrine\DBAL\Schema\Schema;
use OCP\Migration\IOutput;
use OCP\Migration\SimpleMigrationStep;

/**
 * Put the change index's unique constraint in an order queries can use.
 *
 * `documentserver_change_doc_id` is unique over (change_index, document_id).
 * Uniqueness does not care about the order, but every query does, and the
 * leading column is the one nothing filters on: `SELECT MAX(change_index) WHERE
 * document_id = ?` and `DELETE ... WHERE document_id = ? AND change_index >= ?`
 * both have to fall back to an index that only narrows by document and then
 * scan what it finds.
 *
 * That was affordable when the max was read once per save. It is read several
 * times per save now, plus once per snapshot, so the same pair the other way
 * round turns both into a lookup - and costs nothing, because it replaces an
 * index rather than adding one.
 */
class Version001600Date20260909120000 extends SimpleMigrationStep {
	private const TABLE = 'documentserver_changes';
	private const INDEX = 'documentserver_change_doc_id';
	private const COLUMNS = ['document_id', 'change_index'];

	public function changeSchema(IOutput $output, Closure $schemaClosure, array $options) {
		/** @var Schema $schema */
		$schema = $schemaClosure();

		if (!$schema->hasTable(self::TABLE)) {
			return null;
		}

		$table = $schema->getTable(self::TABLE);
		if (!$table->hasIndex(self::INDEX)
			|| $table->getIndex(self::INDEX)->getColumns() === self::COLUMNS) {
			return null;
		}

		$table->dropIndex(self::INDEX);
		$table->addUniqueIndex(self::COLUMNS, self::INDEX);

		return $schema;
	}
}
