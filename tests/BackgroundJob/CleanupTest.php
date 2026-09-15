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

namespace OCA\DocumentServer\Tests\BackgroundJob;

use OCA\DocumentServer\BackgroundJob\Cleanup;
use OCA\DocumentServer\Channel\SessionManager;
use OCA\DocumentServer\Document\DocumentStore;
use OCA\DocumentServer\Document\LockStore;
use OCA\DocumentServer\Document\SaveHandler;
use OCA\DocumentServer\IPC\DatabaseIPCBackend;
use OCP\AppFramework\Utility\ITimeFactory;
use PHPUnit\Framework\MockObject\MockObject;
use Psr\Log\LoggerInterface;
use Test\TestCase;

/**
 * The cron job, and which of the two ways of writing a document it picks.
 *
 * A document nobody is in is flushed: written and disposed of, change list
 * consumed. A document somebody is still typing into may only be snapshotted -
 * consuming the change list under a live session strands the document, since
 * Editor.bin stays at the version it was opened at.
 */
class CleanupTest extends TestCase {
	/** @var SaveHandler|MockObject */
	private $saveHandler;
	/** @var SessionManager|MockObject */
	private $sessionManager;
	/** @var DocumentStore|MockObject */
	private $documentStore;
	private Cleanup $job;

	protected function setUp(): void {
		parent::setUp();

		$this->saveHandler = $this->createMock(SaveHandler::class);
		$this->sessionManager = $this->createMock(SessionManager::class);
		$this->documentStore = $this->createMock(DocumentStore::class);

		$this->job = new Cleanup(
			$this->createMock(ITimeFactory::class),
			$this->sessionManager,
			$this->documentStore,
			$this->saveHandler,
			$this->createMock(LockStore::class),
			$this->createMock(DatabaseIPCBackend::class),
			$this->createMock(LoggerInterface::class)
		);
	}

	private function runJob(): void {
		$run = new \ReflectionMethod(Cleanup::class, 'run');
		$run->setAccessible(true);
		$run->invoke($this->job, null);
	}

	/**
	 * Through the same interval the editing path uses, not past it. The job
	 * calling saveSnapshot() directly ignored `autosave_interval` - including
	 * the 0 that is documented as turning the periodic write off - and turned
	 * one cron pass into a converter run for every open document.
	 */
	public function testALiveDocumentIsWrittenThroughTheAutosaveInterval() {
		$this->documentStore->method('getOpenDocuments')->willReturn([1]);
		$this->sessionManager->method('isDocumentActive')->willReturn(true);

		$this->saveHandler->expects($this->once())->method('saveSnapshotIfDue')->with(1);
		$this->saveHandler->expects($this->never())->method('saveSnapshot');
		$this->saveHandler->expects($this->never())->method('flushChanges');

		$this->runJob();
	}

	public function testADocumentNobodyIsInIsFlushed() {
		$this->documentStore->method('getOpenDocuments')->willReturn([1]);
		$this->sessionManager->method('isDocumentActive')->willReturn(false);

		$this->saveHandler->expects($this->once())->method('flushChanges')->with(1);
		$this->saveHandler->expects($this->never())->method('saveSnapshotIfDue');

		$this->runJob();
	}

	/**
	 * The job is the safety net for every open document, so one it cannot write
	 * must not stop it reaching the others.
	 */
	public function testOneUnwritableDocumentDoesNotStopTheSweep() {
		$this->documentStore->method('getOpenDocuments')->willReturn([1, 2, 3]);
		$this->sessionManager->method('isDocumentActive')->willReturn(false);

		$attempted = [];
		$this->saveHandler->method('flushChanges')
			->willReturnCallback(function (int $documentId) use (&$attempted) {
				$attempted[] = $documentId;
				if ($documentId === 2) {
					throw new \Exception('will not assemble');
				}
			});

		$this->runJob();

		$this->assertSame([1, 2, 3], $attempted);
	}
}
