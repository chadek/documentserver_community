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

use OCA\DocumentServer\Document\Change;
use OCA\DocumentServer\Document\ChangeStore;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\IDBConnection;
use PHPUnit\Framework\Attributes\Group;
use Test\TestCase;

/**
 * Where a batch of changes lands in the change log, and how the caller learns
 * it.
 *
 * SaveChangesCommand broadcasts the indexes of the changes it just stored, and
 * it used to work them out from a max it read *before* the insert. The store
 * reads its own max inside the transaction and retries a batch that collides,
 * so the two disagree exactly when it matters: when somebody else saved at the
 * same moment.
 */
#[Group('DB')]
class ChangeStoreTest extends TestCase {
	private const DOCUMENT = 987654;

	private ChangeStore $store;
	private IDBConnection $connection;

	protected function setUp(): void {
		parent::setUp();

		$this->connection = \OCP\Server::get(IDBConnection::class);

		$timeFactory = $this->createMock(ITimeFactory::class);
		$timeFactory->method('getTime')->willReturn(1700000000);

		$this->store = new ChangeStore($this->connection, $timeFactory);

		$this->clear();
	}

	protected function tearDown(): void {
		$this->clear();
		parent::tearDown();
	}

	private function clear(): void {
		$query = $this->connection->getQueryBuilder();
		$query->delete('documentserver_changes')
			->where($query->expr()->eq('document_id', $query->createNamedParameter(self::DOCUMENT, \PDO::PARAM_INT)));
		$query->executeStatement();
	}

	/** @return int[] the change_index of every stored change, in order */
	private function storedIndexes(): array {
		return array_map(
			fn (Change $change) => $change->getChangeIndex(),
			$this->store->getChangesForDocument(self::DOCUMENT)
		);
	}

	private function add(array $changes): int {
		return $this->store->addChangesForDocument(self::DOCUMENT, $changes, 'user', 'user');
	}

	public function testAnEmptyDocumentStartsAtZero() {
		$this->assertSame(-1, $this->store->getMaxChangeIndexForDocument(self::DOCUMENT));

		$this->assertSame(0, $this->add(['a', 'b']));
		$this->assertSame([0, 1], $this->storedIndexes());
	}

	/**
	 * The returned index is the one the batch was actually given, which is what
	 * lets the caller describe its own changes without reading the max itself.
	 */
	public function testTheReturnedIndexIsWhereTheBatchWasStored() {
		$this->assertSame(0, $this->add(['a']));
		$this->assertSame(1, $this->add(['b', 'c']));
		$this->assertSame(3, $this->add(['d']));

		$this->assertSame([0, 1, 2, 3], $this->storedIndexes());
		$this->assertSame(3, $this->store->getMaxChangeIndexForDocument(self::DOCUMENT));
	}

	/**
	 * A batch stored after somebody else's is numbered past it, and says so.
	 * This is the case a max read before the call gets wrong: it would still
	 * describe these changes as starting at 1.
	 */
	public function testABatchStoredAfterAnotherReportsItsRealPosition() {
		$staleMax = $this->store->getMaxChangeIndexForDocument(self::DOCUMENT);
		$this->assertSame(-1, $staleMax);

		// somebody else's save, between the caller reading the max and its own
		// insert
		$this->add(['theirs']);

		$firstIndex = $this->add(['ours']);

		$this->assertNotSame($staleMax + 1, $firstIndex,
			'the index a caller would have guessed from the stale max');
		$this->assertSame(1, $firstIndex);
		$this->assertSame([0, 1], $this->storedIndexes());
	}

	public function testStoringNothingDoesNotMoveTheIndex() {
		$this->add(['a']);

		$this->assertSame(1, $this->add([]));
		$this->assertSame([0], $this->storedIndexes());
	}
}
