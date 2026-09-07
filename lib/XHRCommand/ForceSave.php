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

		try {
			$written = $this->saveHandler->saveSnapshot($documentId);
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
		// and the right answer when the snapshot wrote nothing: either nothing
		// has been typed since the last write, or another write of the same
		// document is already running - the file holds what the button was
		// pressed for either way, and this ends the button's action without
		// claiming a save that did not happen.
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
