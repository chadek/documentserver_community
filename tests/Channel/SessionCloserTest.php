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

namespace OCA\DocumentServer\Tests\Channel;

use OCA\DocumentServer\Channel\Session;
use OCA\DocumentServer\Channel\SessionCloser;
use OCA\DocumentServer\Channel\SessionManager;
use OCA\DocumentServer\Document\SaveHandler;
use OCA\DocumentServer\IPC\IIPCFactory;
use PHPUnit\Framework\MockObject\MockObject;
use Psr\Log\LoggerInterface;
use Test\TestCase;

/**
 * What happens to a document when an editor says it is leaving.
 *
 * The document must be written as soon as the last participant is gone, because
 * otherwise it waits for a background job and a browser closed at the wrong
 * moment loses the session's work (#100). The two ways a client can say it is
 * going are not the same thing, though, and swapping one for the other breaks
 * this in a way no unit test would notice on its own - which is why the first
 * two tests here say which is which, and why.
 */
class SessionCloserTest extends TestCase {
	private const DOCUMENT = 42;

	/** @var SessionManager|MockObject */
	private $sessionManager;
	/** @var SaveHandler|MockObject */
	private $saveHandler;
	private SessionCloser $closer;

	protected function setUp(): void {
		parent::setUp();

		$this->sessionManager = $this->createMock(SessionManager::class);
		$this->saveHandler = $this->createMock(SaveHandler::class);

		$this->closer = new SessionCloser(
			$this->sessionManager,
			$this->saveHandler,
			$this->createMock(IIPCFactory::class),
			$this->createMock(LoggerInterface::class)
		);
	}

	private function session(string $id = 'sid'): Session {
		return new Session($id, self::DOCUMENT, 'user', 'user', 'user', 100, false, 1);
	}

	/**
	 * Dropped, not expired - and this is not interchangeable. The poll the
	 * departing session left behind keeps running server-side for up to
	 * Channel::TIMEOUT seconds, marking it as seen every
	 * Channel::SEEN_INTERVAL, so an expired row revives itself out of its own
	 * orphaned request: the document is then never seen to be empty and is
	 * never disposed of. Deleting is what makes that marking a no-op.
	 */
	public function testALeavingSessionIsDroppedRatherThanExpired() {
		$this->sessionManager->expects($this->once())
			->method('removeSession')
			->with('sid');
		$this->sessionManager->expects($this->never())->method('expireSession');
		$this->sessionManager->method('getSessionsForDocument')->willReturn([]);

		$this->closer->sessionLeft($this->session());
	}

	/**
	 * The whole point of the beacon: the file is written there and then, rather
	 * than whenever cron next runs.
	 */
	public function testTheDocumentIsWrittenWhenTheLastParticipantLeaves() {
		$this->sessionManager->method('getSessionsForDocument')->willReturn([]);

		$this->saveHandler->expects($this->once())
			->method('flushChanges')
			->with(self::DOCUMENT);

		$this->closer->sessionLeft($this->session());
	}

	/**
	 * One of several leaving is not the document closing. Flushing consumes the
	 * change list, which is the only record of what the others have typed.
	 */
	public function testTheDocumentIsNotWrittenWhileSomebodyIsStillInIt() {
		$this->sessionManager->method('getSessionsForDocument')
			->willReturn([$this->session('other')]);

		$this->saveHandler->expects($this->never())->method('flushChanges');

		$this->closer->sessionLeft($this->session());
	}

	/**
	 * A failed save must not escape: on the beacon path there is nobody left to
	 * see it, and on the command path it would fail the command. The changes
	 * are still in the store, so the background job will try again.
	 */
	public function testAFailedSaveIsLoggedRatherThanThrown() {
		$this->sessionManager->method('getSessionsForDocument')->willReturn([]);
		$this->saveHandler->method('flushChanges')
			->willThrowException(new \Exception('x2t said no'));

		$this->closer->sessionLeft($this->session());

		$this->addToAssertionCount(1);
	}

	/**
	 * sdkjs sends `close` whenever it drops the editor to view mode - a licence
	 * verdict, rights taken away, a critical error - with the page still open.
	 * It is not a departure, so it must not write the document out and take its
	 * folder away.
	 */
	public function testStoppingCoAuthoringDoesNotDisposeOfTheDocument() {
		// expired here, deliberately: the page is still open and still polling,
		// and reviving on its next poll is exactly what should happen
		$this->sessionManager->expects($this->once())
			->method('expireSession')
			->with('sid');
		$this->sessionManager->expects($this->never())->method('removeSession');
		$this->sessionManager->method('getSessionsForDocument')->willReturn([]);

		$this->saveHandler->expects($this->never())->method('flushChanges');

		$this->closer->sessionStoppedCoAuthoring($this->session());
	}
}
