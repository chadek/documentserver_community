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

namespace OCA\DocumentServer\Tests\Channel;

use OCA\DocumentServer\DB\QueryHelper;
use OCA\DocumentServer\IPC\IIPCFactory;
use OCA\DocumentServer\Channel\SessionManager;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\IDBConnection;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\MockObject\MockObject;
use Test\TestCase;

#[Group('DB')]
class SessionManagerTest extends TestCase {
	/** @var IDBConnection */
	private $connection;
	/** @var ITimeFactory|MockObject */
	private $timeFactory;
	/** @var IIPCFactory|MockObject */
	private $ipcFactory;
	/** @var SessionManager */
	private $manager;

	private $time = 1;

	protected function setUp(): void {
		parent::setUp();

		$this->connection = \OCP\Server::get(IDBConnection::class);
		$this->timeFactory = $this->createMock(ITimeFactory::class);
		$this->ipcFactory = $this->createMock(IIPCFactory::class);
		$this->timeFactory->method('getTime')
			->willReturnCallback(function () {
				return $this->time;
			});

		$this->manager = new SessionManager($this->connection, $this->timeFactory, $this->ipcFactory);
	}

	public function testNewGet() {
		$this->time = 10;

		$this->assertNull($this->manager->getSession('foo'));

		$this->manager->newSession('foo', 5);

		$session = $this->manager->getSession('foo');
		$this->assertNotNull($session);

		$this->assertEquals('foo', $session->getSessionId());
		$this->assertEquals(5, $session->getDocumentId());
		$this->assertEquals('', $session->getUser());
		$this->assertEquals('', $session->getUserOriginal());
		$this->assertEquals(10, $session->getLastSeen());

		$this->manager->authenticate($session, 'user', 'original', 'name', false);
		$session = $this->manager->getSession('foo');

		$this->assertEquals('foo', $session->getSessionId());
		$this->assertEquals(5, $session->getDocumentId());
		$this->assertEquals('user', $session->getUser());
		// the display name, which is a different column and a different getter
		$this->assertEquals('name', $session->getUserName());
		$this->assertEquals('original', $session->getUserOriginal());
		$this->assertEquals(10, $session->getLastSeen());
	}

	protected function tearDown(): void {
		$query = $this->connection->getQueryBuilder();
		$query->delete('documentserver_sess');
		QueryHelper::executeStatement($query);

		parent::tearDown();
	}

	public function testLastSeen() {
		$this->time = 10;

		$this->manager->markAsSeen('foo');

		$this->time = 11;

		$this->manager->newSession('foo', 5);

		$session = $this->manager->getSession('foo');

		$this->assertEquals(11, $session->getLastSeen());

		$this->time = 12;

		$this->manager->markAsSeen('foo');

		$session = $this->manager->getSession('foo');

		$this->assertEquals(12, $session->getLastSeen());
	}

	public function testCleanSessions() {
		// relative to the timeout rather than to numbers that were right when
		// it was longer: the one that stops being seen for longer than the
		// timeout goes, the one that was seen since stays
		$timeout = SessionManager::EXPIRED_SESSION_TIMEOUT;

		$this->time = 10;
		$this->manager->newSession('foo', 5);

		$this->time = 10 + $timeout;
		$this->manager->newSession('bar', 5);

		$this->assertNotNull($this->manager->getSession('foo'));
		$this->assertNotNull($this->manager->getSession('bar'));

		// half a timeout later: foo has not been seen for one and a half, bar
		// for half
		$this->time = 10 + $timeout + intdiv($timeout, 2);
		$this->manager->cleanSessions();

		$this->assertNull($this->manager->getSession('foo'));
		$this->assertNotNull($this->manager->getSession('bar'));
	}

	public function testIsDocumentActive() {
		$this->time = 10;

		$this->assertFalse($this->manager->isDocumentActive(5));
		$this->assertFalse($this->manager->isDocumentActive(6));

		$this->manager->newSession('foo', 5);

		$this->assertTrue($this->manager->isDocumentActive(5));
		$this->assertFalse($this->manager->isDocumentActive(6));
	}

	/**
	 * A session that stopped polling stops counting as a participant straight
	 * away, rather than when the background job next deletes it.
	 *
	 * Rows only go away in cleanSessions(), which runs from cron - so between
	 * two runs the table holds every browser that was ever killed mid-edit.
	 * Counting those meant a document was never seen to be empty, and the write
	 * that happens when the last editor leaves never happened.
	 */
	public function testAnExpiredSessionIsNotAParticipant() {
		$this->time = 10;
		$this->manager->newSession('foo', 5);
		$this->assertTrue($this->manager->isDocumentActive(5));

		$this->time = 10 + SessionManager::EXPIRED_SESSION_TIMEOUT + 1;

		$this->assertFalse($this->manager->isDocumentActive(5),
			'a session nobody has polled for longer than the timeout still counts');
		$this->assertEquals([], $this->manager->getSessionsForDocument(5));
		// still there to be revived, just not a participant
		$this->assertNotNull($this->manager->getSession('foo'));
	}

	public function testPollingKeepsASessionAParticipant() {
		$this->time = 10;
		$this->manager->newSession('foo', 5);

		$this->time = 10 + SessionManager::EXPIRED_SESSION_TIMEOUT + 1;
		$this->manager->markAsSeen('foo');

		$this->assertTrue($this->manager->isDocumentActive(5));
		$this->assertCount(1, $this->manager->getSessionsForDocument(5));
	}

	/**
	 * expireSession() is how a client that said it is going away is dropped
	 * without deleting the row, so a page that turns out to still be there
	 * revives on its next poll. That only means anything if an expired session
	 * stops counting immediately.
	 */
	public function testExpiringASessionDropsItFromTheDocumentAtOnce() {
		$this->time = 10;
		$this->manager->newSession('foo', 5);
		$this->manager->newSession('bar', 5);

		$this->manager->expireSession('foo');

		$this->assertEquals(['bar'], array_map(
			fn ($session) => $session->getSessionId(),
			$this->manager->getSessionsForDocument(5)));

		// and it comes back if it was still polling after all
		$this->manager->markAsSeen('foo');
		$this->assertCount(2, $this->manager->getSessionsForDocument(5));
	}

	public function testGetSessionCount() {
		$this->time = 10;

		$this->assertEquals(0, $this->manager->getSessionCount());

		$this->manager->newSession('foo', 5);

		$this->assertEquals(1, $this->manager->getSessionCount());
	}
}
