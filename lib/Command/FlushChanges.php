<?php declare(strict_types=1);
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

namespace OCA\DocumentServer\Command;

use OC\Core\Command\Base;
use OCA\DocumentServer\Document\DocumentStore;
use OCA\DocumentServer\Document\SaveHandler;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

class FlushChanges extends Base {
	private $saveHandler;
	private $documentStore;

	public function __construct(
		SaveHandler $saveHandler,
		DocumentStore $documentStore
	) {
		parent::__construct();

		$this->saveHandler = $saveHandler;
		$this->documentStore = $documentStore;
	}

	protected function configure() {
		$this
			->setName('documentserver:flush')
			->setDescription('Flush all pending changes made to documents');
		parent::configure();
	}

	protected function execute(InputInterface $input, OutputInterface $output) {
		$documents = $this->documentStore->getOpenDocuments();
		foreach ($documents as $documentId) {
			$this->saveHandler->flushChanges($documentId);
		}
	}
}
