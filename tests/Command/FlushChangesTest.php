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

namespace OCA\DocumentServer\Tests\Command;

use OCA\DocumentServer\Channel\SessionManager;
use OCA\DocumentServer\Command\FlushChanges;
use OCA\DocumentServer\Document\DocumentStore;
use OCA\DocumentServer\Document\SaveHandler;
use PHPUnit\Framework\MockObject\MockObject;
use Psr\Log\LoggerInterface;
use Symfony\Component\Console\Tester\CommandTester;
use Test\TestCase;

/**
 * `occ documentserver:flush`, and mostly one thing about it: what a document it
 * cannot write does to the documents after it in the list.
 *
 * The command sweeps every open document, and it is the thing an admin runs
 * before a backup or from cron. Returning at the first failure meant one
 * document the converter chokes on left every document after it unwritten -
 * silently, since the exit code was all that said so. The sweep has to finish,
 * and still report that it did not go cleanly.
 */
class FlushChangesTest extends TestCase {
	/** @var SaveHandler|MockObject */
	private $saveHandler;
	/** @var DocumentStore|MockObject */
	private $documentStore;
	/** @var SessionManager|MockObject */
	private $sessionManager;
	private CommandTester $command;
	/** @var int[] the documents the sweep actually got to */
	private array $attempted = [];

	protected function setUp(): void {
		parent::setUp();

		$this->saveHandler = $this->createMock(SaveHandler::class);
		$this->documentStore = $this->createMock(DocumentStore::class);
		$this->sessionManager = $this->createMock(SessionManager::class);

		$this->command = new CommandTester(new FlushChanges(
			$this->saveHandler,
			$this->documentStore,
			$this->sessionManager,
			$this->createMock(LoggerInterface::class)
		));
	}

	/**
	 * Every document is handed to $work, in order, recording which ones the
	 * sweep got to; the ones named in $failing throw when their turn comes.
	 */
	private function documents(array $ids, string $work, array $failing = []): void {
		$this->documentStore->method('getOpenDocuments')->willReturn($ids);

		$this->saveHandler->method($work)
			->willReturnCallback(function (int $documentId) use ($failing) {
				$this->attempted[] = $documentId;
				if (in_array($documentId, $failing, true)) {
					throw new \Exception("document $documentId will not assemble");
				}
				return true;
			});
	}

	public function testASnapshotSweepFinishesPastADocumentThatWillNotAssemble() {
		$this->documents([1, 2, 3], 'saveSnapshot', [2]);

		$this->assertSame(1, $this->command->execute(['--snapshot' => true]));
		$this->assertSame([1, 2, 3], $this->attempted, 'the sweep stopped at the bad document');
		$this->assertStringContainsString('2', $this->command->getDisplay());
	}

	public function testAFlushSweepFinishesPastADocumentThatWillNotAssemble() {
		$this->documents([1, 2, 3], 'flushChanges', [2]);
		$this->sessionManager->method('isDocumentActive')->willReturn(false);

		$this->assertSame(1, $this->command->execute([]));
		$this->assertSame([1, 2, 3], $this->attempted, 'the sweep stopped at the bad document');
	}

	/**
	 * The exit codes used to be the wrong way round, which makes the command
	 * unusable from cron or a && chain.
	 */
	public function testASweepThatWroteEverythingSucceeds() {
		$this->documents([1, 2, 3], 'saveSnapshot');

		$this->assertSame(0, $this->command->execute(['--snapshot' => true]));
		$this->assertSame([1, 2, 3], $this->attempted);
	}

	/**
	 * A snapshot leaves the editing session running, which is the only safe
	 * thing to do to a document somebody is still typing into. Flushing
	 * consumes the change list, and that is the record of everything typed.
	 */
	public function testASnapshotSweepNeverFlushes() {
		$this->documentStore->method('getOpenDocuments')->willReturn([1]);

		$this->saveHandler->expects($this->once())->method('saveSnapshot')->with(1);
		$this->saveHandler->expects($this->never())->method('flushChanges');

		$this->assertSame(0, $this->command->execute(['--snapshot' => true]));
	}
}
