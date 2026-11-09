<?php

declare(strict_types=1);

// SPDX-License-Identifier: AGPL-3.0-or-later

namespace OCA\Estadistica\Listener;

use OCA\Estadistica\AppInfo\Application;
use OCA\Estadistica\Goals\ResumenStore;
use OCP\EventDispatcher\Event;
use OCP\EventDispatcher\IEventListener;
use OCP\TaskProcessing\Events\TaskSuccessfulEvent;
use OCP\TaskProcessing\TaskTypes\TextToText;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * Where Nextcloud's TaskProcessing delivers the nota Nota asked for: this
 * app's text-to-text task, its custom id the fingerprint it was written for.
 * ResumenStore::note keeps it or drops it. A task that failed leaves no nota,
 * so only success is heard. Nothing here fails the task's own worker.
 *
 * @template-implements IEventListener<Event>
 */
class NotaListener implements IEventListener {
	public function __construct(
		private ResumenStore $resumen,
		private LoggerInterface $logger,
	) {
	}

	#[\Override]
	public function handle(Event $event): void {
		if (!$event instanceof TaskSuccessfulEvent) {
			return;
		}
		$task = $event->getTask();
		if ($task->getAppId() !== Application::APP_ID || $task->getTaskTypeId() !== TextToText::ID) {
			return;
		}
		try {
			$this->resumen->note((string)$task->getCustomId(), (string)($task->getOutput()['output'] ?? ''));
		} catch (Throwable $e) {
			$this->logger->warning('Keeping the nota of the Resumen failed', ['exception' => $e]);
		}
	}
}
