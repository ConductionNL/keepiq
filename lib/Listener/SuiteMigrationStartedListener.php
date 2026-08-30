<?php

/**
 * Keepiq SuiteMigrationStartedListener
 *
 * Responds to a compromise-recovery start by locking dependent SecretRequests
 * so the public fill page returns 423 LOCKED until the migration completes
 * (implement-secret-requests §6.1).
 *
 * @category Listener
 * @package  OCA\Keepiq\Listener
 *
 * @author    Conduction Development Team <dev@conductio.nl>
 * @copyright 2024 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git-id>
 *
 * @link https://conduction.nl
 */

declare(strict_types=1);

namespace OCA\Keepiq\Listener;

use OCA\Keepiq\Event\SuiteMigrationStartedEvent;
use OCA\Keepiq\Service\SecretRequestSuiteLockService;
use OCP\EventDispatcher\Event;
use OCP\EventDispatcher\IEventListener;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * Lock SecretRequests bound to the compromised suite when a migration starts.
 *
 * @implements IEventListener<SuiteMigrationStartedEvent>
 *
 * @spec openspec/changes/implement-secret-requests/tasks.md#task-6.1
 */
class SuiteMigrationStartedListener implements IEventListener {
	/**
	 * Constructor.
	 *
	 * @param SecretRequestSuiteLockService $secretRequestService The SecretRequest suite-lock service
	 * @param LoggerInterface $logger The logger
	 *
	 * @return void
	 */
	public function __construct(
		private SecretRequestSuiteLockService $secretRequestService,
		private LoggerInterface $logger,
	) {
	}//end __construct()

	/**
	 * Handle the event.
	 *
	 * @param Event $event The event
	 *
	 * @return void
	 *
	 * @spec openspec/specs/encryption-suites/spec.md#requirement-migration-covers-every-suite-bound-store
	 */
	public function handle(Event $event): void {
		if (($event instanceof SuiteMigrationStartedEvent) === false) {
			return;
		}

		try {
			$locked = $this->secretRequestService->lockByEncryptionSuiteId($event->getOldSuiteId());
			$this->logger->info(
				'Keepiq: locked SecretRequests for compromised suite',
				[
					'oldSuiteId' => $event->getOldSuiteId(),
					'locked' => $locked,
				]
			);
		} catch (Throwable $e) {
			$this->logger->error(
				'Keepiq: SuiteMigrationStartedListener failed: ' . $e->getMessage(),
				['exception' => $e]
			);
		}
	}//end handle()
}//end class
