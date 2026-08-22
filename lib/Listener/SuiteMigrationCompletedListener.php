<?php

/**
 * Keepiq SuiteMigrationCompletedListener
 *
 * Responds to a compromise-recovery completion by unlocking dependent
 * SecretRequests and re-pointing them at the replacement suite
 * (implement-secret-requests §6.2).
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

use OCA\Keepiq\Event\SuiteMigrationCompletedEvent;
use OCA\Keepiq\Service\SecretRequestSuiteLockService;
use OCP\EventDispatcher\Event;
use OCP\EventDispatcher\IEventListener;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * Unlock + re-suite SecretRequests when compromise recovery completes.
 *
 * @implements IEventListener<SuiteMigrationCompletedEvent>
 *
 * @spec openspec/changes/implement-secret-requests/tasks.md#task-6.2
 */
class SuiteMigrationCompletedListener implements IEventListener {
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
		if (($event instanceof SuiteMigrationCompletedEvent) === false) {
			return;
		}

		try {
			$unlocked = $this->secretRequestService->unlockAndUpdateSuite(
				$event->getOldSuiteId(),
				$event->getNewSuiteId()
			);
			$this->logger->info(
				'Keepiq: unlocked SecretRequests after compromise recovery',
				[
					'oldSuiteId' => $event->getOldSuiteId(),
					'newSuiteId' => $event->getNewSuiteId(),
					'unlocked' => $unlocked,
				]
			);
		} catch (Throwable $e) {
			$this->logger->error(
				'Keepiq: SuiteMigrationCompletedListener failed: ' . $e->getMessage(),
				['exception' => $e]
			);
		}
	}//end handle()
}//end class
