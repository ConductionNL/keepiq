<?php

/**
 * Keepiq UserDeletedListener
 *
 * Runs the account-deletion cascade when a Nextcloud user account is deleted,
 * so Keepiq data can never outlive its account (secret-export-gdpr D4). The
 * same cascade backs the in-app deletion flow; here the trigger is recorded as
 * 'user-deleted'.
 *
 * The cascade is idempotent and keyed by userId, so a re-fired event is safe.
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

use OCA\Keepiq\Service\AccountDeletionService;
use OCP\EventDispatcher\Event;
use OCP\EventDispatcher\IEventListener;
use OCP\User\Events\UserDeletedEvent;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * Cascades Keepiq data deletion on Nextcloud user deletion.
 *
 * @template-implements IEventListener<Event>
 */
class UserDeletedListener implements IEventListener {
	/**
	 * Constructor for UserDeletedListener.
	 *
	 * @param AccountDeletionService $deletionService The deletion-cascade service
	 * @param LoggerInterface $logger The logger
	 *
	 * @return void
	 */
	public function __construct(
		private AccountDeletionService $deletionService,
		private LoggerInterface $logger,
	) {
	}//end __construct()

	/**
	 * Handle a Nextcloud user-deletion event.
	 *
	 * @param Event $event The dispatched event
	 *
	 * @return void
	 *
	 * @spec openspec/changes/secret-export-gdpr/specs/gdpr-compliance/spec.md
	 */
	public function handle(Event $event): void {
		if (($event instanceof UserDeletedEvent) === false) {
			return;
		}

		$userId = $event->getUser()->getUID();

		try {
			$this->deletionService->deleteAllFor(userId: $userId, trigger: 'user-deleted');
		} catch (Throwable $e) {
			// Log and swallow: a failed cascade must not block NC user deletion.
			// The cascade is idempotent and can be re-run; the event is emitted
			// only on completed runs, so a partial run leaves no false audit row.
			$this->logger->error(
				'Keepiq: account-deletion cascade failed for ' . $userId . ': ' . $e->getMessage(),
				['exception' => $e]
			);
		}
	}//end handle()
}//end class
