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

namespace OCA\DocumentServer\Channel;

use OCA\DocumentServer\DB\QueryHelper;
use OCA\DocumentServer\IPC\IIPCFactory;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\DB\QueryBuilder\IQueryBuilder;
use OCP\IDBConnection;

class SessionManager {
	const EXPIRED_SESSION_TIMEOUT = 30;
	private $connection;
	private $timeFactory;
	private $ipcFactory;

	public function __construct(IDBConnection $connection, ITimeFactory $timeFactory, IIPCFactory $ipcFactory) {
		$this->connection = $connection;
		$this->timeFactory = $timeFactory;
		$this->ipcFactory = $ipcFactory;
	}

	public function getSessionCount(): int {
		$query = $this->connection->getQueryBuilder();

		$query->select($query->func()->count())
			->from('documentserver_sess');
		return (int)QueryHelper::fetchOne($query);
	}

	public function getSession(string $sessionId): ?Session {
		$query = $this->connection->getQueryBuilder();

		$query->select('session_id', 'document_id', 'user', 'user_original', 'last_seen', 'readonly', 'user_index', 'username')
			->from('documentserver_sess')
			->where($query->expr()->eq('session_id', $query->createNamedParameter($sessionId)));

		$row = QueryHelper::fetchRow($query);
		if ($row) {
			return Session::fromRow($row);
		} else {
			return null;
		}
	}

	private function getNextUserIndex(int $documentId): int {
		$query = $this->connection->getQueryBuilder();

		$query->select($query->createFunction('MAX(' . $query->getColumnName('user_index') . ')'))
			->from('documentserver_sess')
			->where($query->expr()->eq('document_id', $query->createNamedParameter($documentId, \PDO::PARAM_INT)));

		$index = QueryHelper::fetchOne($query);
		return ($index === false || $index === null) ? 1 : (int)$index + 1;
	}

	public function newSession(string $sessionId, int $documentId) {
		$userId = $this->getNextUserIndex($documentId);

		$query = $this->connection->getQueryBuilder();
		$now = $this->timeFactory->getTime();

		$query->insert('documentserver_sess')
			->values([
				'session_id' => $query->createNamedParameter($sessionId),
				'document_id' => $query->createNamedParameter($documentId, \PDO::PARAM_INT),
				'last_seen' => $query->createNamedParameter($now, \PDO::PARAM_INT),
				'user' => $query->createNamedParameter(""),
				'user_original' => $query->createNamedParameter(""),
				'username' => $query->createNamedParameter(""),
				'readonly' => $query->createNamedParameter(1, \PDO::PARAM_INT),
				'user_index' => $query->createNamedParameter($userId, \PDO::PARAM_INT),
			]);
		QueryHelper::executeStatement($query);
	}

	public function authenticate(Session $session, string $user, string $userOriginal, string $userName, bool $readOnly): Session {
		$query = $this->connection->getQueryBuilder();
		$now = $this->timeFactory->getTime();

		$query->update('documentserver_sess')
			->set('last_seen', $query->createNamedParameter($now, \PDO::PARAM_INT))
			->set('user', $query->createNamedParameter($user))
			->set('user_original', $query->createNamedParameter($userOriginal))
			->set('username', $query->createNamedParameter($userName))
			->set('readonly', $query->createNamedParameter($readOnly, \PDO::PARAM_INT))
			->where($query->expr()->eq('session_id', $query->createNamedParameter($session->getSessionId())));
		QueryHelper::executeStatement($query);

		return new Session(
			$session->getSessionId(),
			$session->getDocumentId(),
			$user,
			$userOriginal,
			$userName,
			$now,
			$readOnly,
			$session->getUserIndex()
		);
	}

	public function markAsSeen(string $sessionId) {
		$query = $this->connection->getQueryBuilder();

		$query->update('documentserver_sess')
			->set('last_seen', $query->createNamedParameter($this->timeFactory->getTime(), \PDO::PARAM_INT))
			->where($query->expr()->eq('session_id', $query->createNamedParameter($sessionId)));
		QueryHelper::executeStatement($query);
	}

	private function getExpiredSessions(): array {
		$query = $this->connection->getQueryBuilder();

		$cutoffTime = $this->timeFactory->getTime() -
			self::EXPIRED_SESSION_TIMEOUT;

		$query->select('session_id')
			->from('documentserver_sess')
			->where($query->expr()->lt('last_seen', $query->createNamedParameter($cutoffTime, \PDO::PARAM_INT)));
		return QueryHelper::fetchFirstColumn($query);
	}

	/**
	 * Mark a session as expired without deleting it.
	 *
	 * For a client that said it is done co-authoring while its page is still
	 * open: it stops being a participant, but if it turns out to still be
	 * polling, its next poll marks it as seen and it simply carries on. Nothing
	 * is disposed of on the strength of a message a live page can send.
	 *
	 * Backdated past the timeout rather than set to zero. Zero happens to be
	 * far enough in the past against a real clock, but it says nothing about
	 * why, and it only works as long as the clock is large - which is a thing
	 * to know rather than a thing to rely on. One second past the cutoff is the
	 * same answer to both queries that ask, and it means it.
	 */
	public function expireSession(string $sessionId): void {
		$query = $this->connection->getQueryBuilder();

		$expiredAt = $this->timeFactory->getTime() - self::EXPIRED_SESSION_TIMEOUT - 1;

		$query->update('documentserver_sess')
			->set('last_seen', $query->createNamedParameter($expiredAt, \PDO::PARAM_INT))
			->where($query->expr()->eq('session_id', $query->createNamedParameter($sessionId)));
		QueryHelper::executeStatement($query);
	}

	/**
	 * Drop a single session, for a client that said it was leaving rather than
	 * one that stopped polling.
	 *
	 * Deleted rather than expired, and the abandoned poll is why. The request
	 * that session left behind keeps running server-side for up to
	 * Channel::TIMEOUT seconds and marks it as seen every
	 * Channel::SEEN_INTERVAL while it does - so an expired row revives itself,
	 * from nothing but its own orphaned poll, and the document is not disposed
	 * of when the last editor leaves. Deleting is what makes that marking a
	 * no-op. (A client that only stopped co-authoring is a different case: its
	 * page is still there and still polling, and expireSession() is right for
	 * it precisely because reviving is what should happen.)
	 */
	public function removeSession(string $sessionId): void {
		$this->ipcFactory->cleanupChannel($sessionId);

		$query = $this->connection->getQueryBuilder();

		$query->delete('documentserver_sess')
			->where($query->expr()->eq('session_id', $query->createNamedParameter($sessionId)));
		QueryHelper::executeStatement($query);
	}

	public function cleanSessions(): int {
		$expiredSessions = $this->getExpiredSessions();

		foreach ($expiredSessions as $expiredSession) {
			$this->ipcFactory->cleanupChannel($expiredSession);
		}

		$query = $this->connection->getQueryBuilder();

		$query->delete('documentserver_sess')
			->where($query->expr()->in('session_id', $query->createNamedParameter($expiredSessions, IQueryBuilder::PARAM_STR_ARRAY)));
		return QueryHelper::executeStatement($query);
	}

	public function isDocumentActive(int $documentId): bool {
		return count($this->getSessionsForDocument($documentId)) > 0;
	}

	/**
	 * Who is currently in a document.
	 *
	 * Expired rows are left out rather than waited for. They are only deleted
	 * by cleanSessions(), which runs from the background job, so between two
	 * job runs the table holds every session that ever stopped polling - a
	 * browser that was killed, a laptop that was closed. Counting those as
	 * participants meant a document was never seen to be empty:
	 * SessionCloser::sessionLeft() would not write the file when the last
	 * editor left, and the write fell back to whenever the job next ran, which
	 * is exactly the wait #100 exists to remove. It also made expireSession()
	 * do nothing observable.
	 *
	 * The cutoff is the one cleanSessions() deletes by, so this only stops
	 * counting a session that was already on its way out. A session that is
	 * genuinely polling says so every Channel::SEEN_INTERVAL seconds, six times
	 * over inside the timeout.
	 *
	 * @param int $documentId
	 * @return Session[]
	 */
	public function getSessionsForDocument(int $documentId): array {
		$query = $this->connection->getQueryBuilder();

		$cutoffTime = $this->timeFactory->getTime() - self::EXPIRED_SESSION_TIMEOUT;

		$query->select('session_id', 'document_id', 'user', 'user_original', 'last_seen', 'readonly', 'user_index', 'username')
			->from('documentserver_sess')
			->where($query->expr()->eq('document_id', $query->createNamedParameter($documentId, \PDO::PARAM_INT)))
			->andWhere($query->expr()->gte('last_seen', $query->createNamedParameter($cutoffTime, \PDO::PARAM_INT)));

		return array_map(function (array $row) {
			return Session::fromRow($row);
		}, QueryHelper::fetchAll($query));
	}

	public function getSessionForUser(string $userId): ?Session {
		$query = $this->connection->getQueryBuilder();

		$query->select('session_id', 'document_id', 'user', 'user_original', 'last_seen', 'readonly', 'user_index', 'username')
			->from('documentserver_sess')
			->where($query->expr()->eq($query->func()->concat('user', 'user_index'), $query->createNamedParameter($userId)));

		$row = QueryHelper::fetchRow($query);
		if ($row) {
			return Session::fromRow($row);
		} else {
			return null;
		}
	}
}
