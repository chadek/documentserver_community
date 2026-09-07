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
use OCA\DocumentServer\Controller\DocumentController;
use OCA\DocumentServer\Document\SaveHandler;
use OCA\DocumentServer\IPC\IIPCChannel;
use OCA\DocumentServer\XHRCommand\CommandDispatcher;
use OCA\DocumentServer\XHRCommand\ForceSave;
use OCP\AppFramework\Utility\ITimeFactory;
use PHPUnit\Framework\MockObject\MockObject;
use Psr\Log\LoggerInterface;
use Test\TestCase;

/**
 * The Save button the connector's "Keep intermediate versions when editing"
 * setting puts in the editor.
 *
 * Its command was dropped by the dispatcher, so the button did nothing and the
 * setting looked broken. The reply is in two parts and the editor is fussy
 * about both: it stores the timestamp from forceSaveStart and ignores a
 * forceSave whose time does not match, and it will not send another
 * forceSaveStart until a save has been acknowledged since the last one.
 */
class ForceSaveTest extends TestCase {
	private const DOCUMENT = 42;

	/** @var SaveHandler|MockObject */
	private $saveHandler;
	private $now = 1700000000;
	/** @var ForceSave */
	private $handler;

	protected function setUp(): void {
		parent::setUp();

		$this->saveHandler = $this->createMock(SaveHandler::class);

		$timeFactory = $this->createMock(ITimeFactory::class);
		$timeFactory->method('getTime')->willReturnCallback(function () {
			return $this->now;
		});

		$this->handler = new ForceSave(
			$this->saveHandler,
			$timeFactory,
			$this->createMock(LoggerInterface::class)
		);
	}

	private function session(): Session {
		return new Session('sid', self::DOCUMENT, 'user', 'user', 'user', 100, false, 1);
	}

	/**
	 * @return array the messages pushed to the session's own channel, decoded
	 */
	private function pressSave(): array {
		$pushed = [];
		$sessionChannel = $this->createMock(IIPCChannel::class);
		$sessionChannel->method('pushMessage')->willReturnCallback(function (string $message) use (&$pushed) {
			$pushed[] = json_decode($message, true);
		});

		$this->handler->handle(
			['type' => 'forceSaveStart'],
			$this->session(),
			$sessionChannel,
			$this->createMock(IIPCChannel::class),
			new CommandDispatcher()
		);

		return $pushed;
	}

	/**
	 * Without this registration the command is dropped, which is the whole bug:
	 * everything below describes a handler nothing would ever call.
	 */
	public function testTheDispatcherKnowsTheCommand() {
		$this->assertContains(ForceSave::class, DocumentController::COMMAND_HANDLERS);
		$this->assertSame('forceSaveStart', $this->handler->getType());
	}

	public function testPressingSaveWritesTheDocumentAndReportsItSaved() {
		$this->saveHandler->expects($this->once())
			->method('saveSnapshot')
			->with(self::DOCUMENT)
			->willReturn(true);

		$pushed = $this->pressSave();

		$this->assertCount(2, $pushed);
		$this->assertSame('forceSaveStart', $pushed[0]['type']);
		$this->assertSame(0, $pushed[0]['messages']['code'], 'c_oAscServerCommandErrors.NoError');
		$this->assertSame('forceSave', $pushed[1]['type']);
		$this->assertSame(1, $pushed[1]['messages']['type'], 'c_oAscForceSaveTypes.Button');
		$this->assertTrue($pushed[1]['messages']['success']);

		// the editor stores the first time and ignores a forceSave that does
		// not carry the same one, so the button would hang on a mismatch
		$this->assertSame($this->now * 1000, $pushed[0]['messages']['time']);
		$this->assertSame($pushed[0]['messages']['time'], $pushed[1]['messages']['time']);
	}

	/**
	 * A snapshot writes the file and leaves the change list alone. Flushing
	 * here would consume it, and since Editor.bin stays at the version the
	 * document was opened at, the next session to open it would show the
	 * document from before the save.
	 */
	public function testPressingSaveDoesNotEndTheEditingSession() {
		$this->saveHandler->expects($this->never())->method('flushChanges');
		$this->saveHandler->method('saveSnapshot')->willReturn(true);

		$this->pressSave();
	}

	/**
	 * Nothing typed since the last write: the file already holds what the
	 * button was pressed for. NotModified is the editor's own state for that
	 * and it ends the button's action without claiming a save.
	 */
	public function testNothingToSaveIsReportedAsNotModified() {
		$this->saveHandler->method('saveSnapshot')->willReturn(false);

		$pushed = $this->pressSave();

		$this->assertCount(1, $pushed);
		$this->assertSame('forceSaveStart', $pushed[0]['type']);
		$this->assertSame(4, $pushed[0]['messages']['code'], 'c_oAscServerCommandErrors.NotModified');
	}

	/**
	 * A failure has to be answered too. An unanswered forceSaveStart leaves the
	 * button spinning until the editor's conversion timeout, and then reports a
	 * save that started and never landed.
	 */
	public function testAFailedSaveIsReportedRatherThanThrown() {
		$this->saveHandler->method('saveSnapshot')
			->willThrowException(new \Exception('x2t said no'));

		$pushed = $this->pressSave();

		$this->assertCount(1, $pushed);
		$this->assertSame('forceSaveStart', $pushed[0]['type']);
		$this->assertSame(3, $pushed[0]['messages']['code'], 'c_oAscServerCommandErrors.UnknownError');
	}
}
