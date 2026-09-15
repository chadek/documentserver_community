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

namespace OCA\DocumentServer\Tests\XHRCommand;

use OCA\DocumentServer\Channel\Session;
use OCA\DocumentServer\Document\ChangeStore;
use OCA\DocumentServer\Document\LockStore;
use OCA\DocumentServer\Document\SaveHandler;
use OCA\DocumentServer\IPC\IIPCChannel;
use OCA\DocumentServer\XHRCommand\CommandDispatcher;
use OCA\DocumentServer\XHRCommand\SaveChangesCommand;
use OCP\AppFramework\Utility\ITimeFactory;
use PHPUnit\Framework\MockObject\MockObject;
use Psr\Log\LoggerInterface;
use Test\TestCase;

/**
 * The two numbers in a save that sdkjs does arithmetic on.
 *
 * `changesIndex` is the client's "synced" position, and it derives absolute
 * positions from it by adding its own offsets - on the wire
 * `deleteIndex += this.changesIndex`, and locally `Changes.length = SyncIndex +
 * deleteIndex`, where a length is a count. So it has to be a count of the
 * changes stored, not the highest index among them. `deleteIndex` comes back
 * the other way and asks for changes to be dropped, which makes believing it
 * blindly expensive.
 */
class SaveChangesCommandTest extends TestCase {
	private const DOCUMENT = 42;

	/** @var ChangeStore|MockObject */
	private $changeStore;
	private SaveChangesCommand $handler;
	/** @var array the messages broadcast to the document */
	private array $broadcast = [];
	/** @var array the messages sent back to the saving session */
	private array $toSession = [];

	protected function setUp(): void {
		parent::setUp();

		$this->changeStore = $this->createMock(ChangeStore::class);
		$this->changeStore->method('addChangesForDocument')->willReturn(0);

		$lockStore = $this->createMock(LockStore::class);
		$lockStore->method('releaseLocks')->willReturn([]);

		$this->handler = new SaveChangesCommand(
			$this->changeStore,
			$lockStore,
			$this->createMock(ITimeFactory::class),
			$this->createMock(SaveHandler::class),
			$this->createMock(LoggerInterface::class)
		);
	}

	/** The document holds $count changes, numbered 0..$count-1. */
	private function storeHolds(int $count): void {
		$this->changeStore->method('getMaxChangeIndexForDocument')->willReturn($count - 1);
	}

	private function save(array $extra = []): void {
		$this->broadcast = [];
		$this->toSession = [];

		$documentChannel = $this->createMock(IIPCChannel::class);
		$documentChannel->method('pushMessage')->willReturnCallback(function (string $m) {
			$this->broadcast[] = json_decode($m, true);
		});
		$sessionChannel = $this->createMock(IIPCChannel::class);
		$sessionChannel->method('pushMessage')->willReturnCallback(function (string $m) {
			$this->toSession[] = json_decode($m, true);
		});

		$this->handler->handle(
			array_merge(['type' => 'saveChanges', 'changes' => '[]', 'releaseLocks' => false], $extra),
			new Session('sid', self::DOCUMENT, 'user', 'user', 'user', 100, false, 1),
			$sessionChannel,
			$documentChannel,
			new CommandDispatcher()
		);
	}

	private function message(array $messages, string $type): ?array {
		foreach ($messages as $message) {
			if (($message['type'] ?? null) === $type) {
				return $message;
			}
		}
		return null;
	}

	/**
	 * A count, not the highest index. Sending the max made every position the
	 * client derived one too low, and a save whose only content is a
	 * deleteIndex then asked for one change too many to be dropped.
	 */
	public function testTheClientIsToldHowManyChangesTheDocumentHolds() {
		$this->storeHolds(267);

		$this->save();

		$this->assertSame(267, $this->message($this->broadcast, 'saveChanges')['changesIndex']);
		$this->assertSame(267, $this->message($this->toSession, 'unSaveLock')['index']);
	}

	public function testAnEmptyDocumentReportsNoChanges() {
		$this->storeHolds(0);

		$this->save();

		$this->assertSame(0, $this->message($this->broadcast, 'saveChanges')['changesIndex']);
	}

	public function testAMidSaveChunkReportsTheSameCount() {
		$this->storeHolds(5);

		$this->save(['startSaveChanges' => true, 'endSaveChanges' => false]);

		$this->assertSame(5, $this->message($this->toSession, 'savePartChanges')['changesIndex']);
		$this->assertNull($this->message($this->toSession, 'unSaveLock'), 'the save is not finished');
	}

	/**
	 * -1 is sdkjs saying it has nothing to discard - it guards its own
	 * arithmetic with `-1 !== this.deleteIndex`. Read as truthy it asked for
	 * `change_index >= -1`, which is every change in the document.
	 */
	public function testMinusOneDoesNotDeleteTheWholeDocument() {
		$this->storeHolds(10);
		$this->changeStore->expects($this->never())->method('deleteChangesByIndex');

		$this->save(['deleteIndex' => -1]);
	}

	/** Most saves carry no deleteIndex at all; reading the key unguarded warned. */
	public function testAnAbsentDeleteIndexDeletesNothing() {
		$this->storeHolds(10);
		$this->changeStore->expects($this->never())->method('deleteChangesByIndex');

		$this->save();
	}

	public function testADeleteIndexIsHonouredWhenTheClientMeansIt() {
		$this->storeHolds(10);
		$this->changeStore->expects($this->once())
			->method('deleteChangesByIndex')
			->with(self::DOCUMENT, 4);

		$this->save(['deleteIndex' => 4]);
	}

	/**
	 * deleteIndex is repeated on every chunk of one save and is relative to the
	 * store as it was before the save started, so applying it again after a
	 * chunk has been stored would delete that chunk.
	 */
	public function testALaterChunkDoesNotReapplyTheDelete() {
		$this->storeHolds(10);
		$this->changeStore->expects($this->never())->method('deleteChangesByIndex');

		$this->save(['deleteIndex' => 4, 'startSaveChanges' => false, 'endSaveChanges' => true]);
	}
}
