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

namespace OCA\DocumentServer\XHRCommand;

use OCA\DocumentServer\Channel\Session;
use OCA\DocumentServer\Document\SaveHandler;
use OCA\DocumentServer\IPC\IIPCChannel;
use OCP\AppFramework\Utility\ITimeFactory;
use Psr\Log\LoggerInterface;

/**
 * Write the document out now, because the editor asked.
 *
 * This is what the connector's "Keep intermediate versions when editing"
 * setting turns into: it sets editorConfig.customization.forcesave, which
 * gives the editor a Save button, and pressing it sends forceSaveStart.
 * Without a handler the command was dropped and the button did nothing, so the
 * setting looked broken from the settings UI.
 *
 * A snapshot, not a flush: people are still typing, and consuming the change
 * list here would leave the next session to open the document looking at the
 * version from before the save - see SaveHandler::saveSnapshot().
 */
class ForceSave implements ICommandHandler {
	/** sdkjs c_oAscForceSaveTypes.Button */
	private const TYPE_BUTTON = 1;
	/** sdkjs c_oAscServerCommandErrors */
	private const ERROR_NONE = 0;
	private const ERROR_UNKNOWN = 3;
	private const ERROR_NOT_MODIFIED = 4;

	/**
	 * The shortest gap between two writes this command will ask for, in
	 * seconds.
	 *
	 * Assembling a document is a converter run, and this is the one place a
	 * client can ask for one directly: the periodic write is floored at
	 * `autosave_interval`, but a command is whatever arrives on the socket.
	 * Without a floor a client that sends forceSaveStart after every change -
	 * scripted, or looping on an error - turns each keystroke into an x2t run
	 * for as long as it keeps typing, because a stored change is exactly what
	 * stops the write being skipped as unmodified.
	 *
	 * Short enough that a person pressing the button does not notice it: it
	 * only refuses a second write within three seconds of the last one, and it
	 * refuses it the same way an unmodified document is refused, so the button
	 * ends its action rather than hanging. Nothing is lost by the refusal - the
	 * changes are in the change store either way, and the next write picks
	 * them up.
	 */
	private const MIN_INTERVAL = 3;

	private $saveHandler;
	private $timeFactory;
	private $logger;

	public function __construct(SaveHandler $saveHandler, ITimeFactory $timeFactory, LoggerInterface $logger) {
		$this->saveHandler = $saveHandler;
		$this->timeFactory = $timeFactory;
		$this->logger = $logger;
	}

	public function getType(): string {
		return 'forceSaveStart';
	}

	public function handle(array $command, Session $session, IIPCChannel $sessionChannel, IIPCChannel $documentChannel, CommandDispatcher $commandDispatcher): void {
		$documentId = $session->getDocumentId();
		// the same clock the save acks are stamped with (SaveChangesCommand's
		// unSaveLock), which is what the editor compares this against before it
		// will send another forceSaveStart
		$time = $this->timeFactory->getTime() * 1000;

		// A session that cannot edit has nothing to save, and no Save button
		// either - so this is a client that is not the editor's own, and the
		// only thing it can achieve here is a converter run. Refused the way an
		// unmodified document is: there is nothing for the editor to be told
		// about, and no error to log on every poll.
		if ($session->isReadOnly()) {
			$sessionChannel->pushMessage(json_encode([
				'type' => 'forceSaveStart',
				'messages' => [
					'code' => self::ERROR_NOT_MODIFIED,
					'time' => $time,
				],
			]));
			return;
		}

		try {
			$written = $this->saveHandler->saveSnapshotThrottled($documentId, self::MIN_INTERVAL);
			$code = $written ? self::ERROR_NONE : self::ERROR_NOT_MODIFIED;
		} catch (\Exception $e) {
			$this->logger->warning('documentserver force save failed for document {doc}: {error}', [
				'doc' => $documentId,
				'error' => $e->getMessage(),
				'exception' => $e,
			]);
			$code = self::ERROR_UNKNOWN;
		}

		// Reported after the fact rather than before: the save is synchronous
		// here, so announcing a save that started and then failing it a moment
		// later would only leave the button in the saving state for as long as
		// it takes to say so. NotModified is a state of its own to the editor,
		// and the right answer when the snapshot wrote nothing: nothing has been
		// typed since the last write, another write of the same document is
		// already running, or one finished less than MIN_INTERVAL ago. The file
		// was written moments ago in all three, and this ends the button's
		// action without claiming a save that did not happen.
		$sessionChannel->pushMessage(json_encode([
			'type' => 'forceSaveStart',
			'messages' => [
				'code' => $code,
				'time' => $time,
			],
		]));

		if ($code !== self::ERROR_NONE) {
			return;
		}

		// The second half of the reply, and it has to carry the same timestamp:
		// the client stores the one from forceSaveStart and ignores a forceSave
		// whose time does not match it.
		$sessionChannel->pushMessage(json_encode([
			'type' => 'forceSave',
			'messages' => [
				'type' => self::TYPE_BUTTON,
				'time' => $time,
				'success' => true,
			],
		]));
	}
}
