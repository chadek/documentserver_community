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
use OCA\DocumentServer\Document\DocumentStore;
use OCA\DocumentServer\IPC\IIPCChannel;
use OCA\DocumentServer\XHRCommand\ChatMessage;
use OCA\DocumentServer\XHRCommand\CommandDispatcher;
use OCP\Lock\ILockingProvider;
use PHPUnit\Framework\MockObject\MockObject;
use Psr\Log\LoggerInterface;
use Test\TestCase;

/**
 * A message from the editor's chat panel.
 *
 * The interesting part is not the happy path but the size of what gets stored:
 * the history is kept in the document's appdata and capped at a hundred
 * *messages*, with nothing said about how big one may be, and the editor's own
 * limit is a maxlength on a textarea - which is to say, no limit at all for
 * anything that is not the editor.
 */
class ChatMessageTest extends TestCase {
	private const DOCUMENT = 42;
	/** sdkjs c_oAscMaxCellOrCommentLength, the editor's own limit */
	private const MAX_LENGTH = 32767;

	/** @var DocumentStore|MockObject */
	private $documentStore;
	private ChatMessage $handler;

	protected function setUp(): void {
		parent::setUp();

		$this->documentStore = $this->createMock(DocumentStore::class);

		$this->handler = new ChatMessage(
			$this->documentStore,
			$this->createMock(ILockingProvider::class),
			$this->createMock(LoggerInterface::class)
		);
	}

	/**
	 * @return array{stored: ?array, broadcast: array} what reached the history
	 *                                                 and what was relayed
	 */
	private function send(array $command): array {
		$stored = null;
		$this->documentStore->method('addChatMessage')
			->willReturnCallback(function (int $documentId, array $message) use (&$stored) {
				$stored = $message;
			});

		$broadcast = [];
		$channel = $this->createMock(IIPCChannel::class);
		$channel->method('pushMessage')->willReturnCallback(function (string $m) use (&$broadcast) {
			$broadcast[] = json_decode($m, true);
		});

		$this->handler->handle(
			$command,
			new Session('sid', self::DOCUMENT, 'user', 'user', 'user', 100, false, 1),
			$channel,
			$channel,
			new CommandDispatcher()
		);

		return ['stored' => $stored, 'broadcast' => $broadcast];
	}

	public function testAnOrdinaryMessageIsStoredAndRelayedWhole() {
		$result = $this->send(['type' => 'message', 'message' => 'hello everyone']);

		$this->assertSame('hello everyone', $result['stored']['message']);
		$this->assertSame('hello everyone', $result['broadcast'][0]['messages'][0]['message']);
	}

	/**
	 * The length has to be capped where it is stored, not only in the browser.
	 * Otherwise a client that is not the editor can park a hundred messages of
	 * whatever the request limit allows in one document's appdata.
	 */
	public function testAnOverlongMessageIsCutToTheEditorsOwnLimit() {
		$result = $this->send(['type' => 'message', 'message' => str_repeat('a', self::MAX_LENGTH * 2)]);

		$this->assertSame(self::MAX_LENGTH, mb_strlen($result['stored']['message']));
		$this->assertSame(self::MAX_LENGTH, mb_strlen($result['broadcast'][0]['messages'][0]['message']),
			'the relayed copy is capped too, or every other participant gets the whole thing');
	}

	/**
	 * Cut by characters rather than bytes: mid-sequence truncation would put
	 * invalid UTF-8 into the history file, and json_encode() returns false on
	 * that - which would lose the whole backlog, not just one message.
	 */
	public function testCuttingAMessageLeavesValidUtf8() {
		$result = $this->send(['type' => 'message', 'message' => str_repeat('é', self::MAX_LENGTH * 2)]);

		$this->assertSame(self::MAX_LENGTH, mb_strlen($result['stored']['message']));
		$this->assertNotFalse(json_encode($result['stored']));
	}

	public function testACommandWithNoMessageIsIgnored() {
		$this->documentStore->expects($this->never())->method('addChatMessage');

		$this->assertSame([], $this->send(['type' => 'message'])['broadcast']);
		$this->assertSame([], $this->send(['type' => 'message', 'message' => ['not', 'a', 'string']])['broadcast']);
	}
}
