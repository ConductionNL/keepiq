<?php

/**
 * Keepiq EncryptionSuiteRevokedListener
 *
 * Listens for EncryptionSuiteRevokedEvent. For a user-owned suite:
 *  - cascade-deletes ShareTargets where the suite owner was the recipient
 *    (they can no longer decrypt those copies),
 *  - promotes any temporary SecretDelegations the suite owner had created
 *    to permanent so the delegate-as-de-facto-owner survives the
 *    revocation (the original owner's Secret copies become inaccessible
 *    when the suite is gone).
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

use OCA\Keepiq\Db\ShareTargetMapper;
use OCA\Keepiq\Event\EncryptionSuiteRevokedEvent;
use OCA\Keepiq\Service\DelegationService;
use OCP\EventDispatcher\Event;
use OCP\EventDispatcher\IEventListener;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * Cascade ShareTargets + promote temporary delegations on revocation.
 *
 * @implements IEventListener<EncryptionSuiteRevokedEvent>
 *
 * @spec openspec/changes/implement-user-sharing/tasks.md#8.3
 */
class EncryptionSuiteRevokedListener implements IEventListener {
	/**
	 * Constructor.
	 *
	 * @param ShareTargetMapper $shareTargetMapper The share-target mapper
	 * @param DelegationService $delegationService The delegation service
	 * @param LoggerInterface $logger The logger
	 *
	 * @return void
	 */
	public function __construct(
		private ShareTargetMapper $shareTargetMapper,
		private DelegationService $delegationService,
		private LoggerInterface $logger,
	) {
	}//end __construct()

	/**
	 * Handle the EncryptionSuiteRevokedEvent.
	 *
	 * @param Event $event The dispatched event
	 *
	 * @return void
	 */
	public function handle(Event $event): void {
		if ($event instanceof EncryptionSuiteRevokedEvent === false) {
			return;
		}

		if ($event->getOwnerType() !== 'user') {
			// Application suites do not participate in the
			// user-sharing graph — revocation just cleans up the suite.
			return;
		}

		$userId = $event->getOwnerId();

		try {
			// The ex-recipient can no longer decrypt anything; sweep
			// every ShareTarget where they were the recipient.
			$this->shareTargetMapper->deleteByTargetUser(targetUserId: $userId);
		} catch (Throwable $exception) {
			$this->logger->warning(
				'Keepiq: EncryptionSuiteRevokedListener share-target sweep failed for '
				. $userId . ': ' . $exception->getMessage(),
				['app' => 'keepiq']
			);
		}

		try {
			$promoted = $this->delegationService->makePermanent(originalOwnerId: $userId);
			if ($promoted > 0) {
				$this->logger->info(
					'Keepiq: promoted ' . $promoted . ' delegations to permanent after revoking '
					. $event->getSuiteId() . ' (owner=' . $userId . ')',
					['app' => 'keepiq']
				);
			}
		} catch (Throwable $exception) {
			$this->logger->warning(
				'Keepiq: EncryptionSuiteRevokedListener delegation-promote failed for '
				. $userId . ': ' . $exception->getMessage(),
				['app' => 'keepiq']
			);
		}
	}//end handle()
}//end class
