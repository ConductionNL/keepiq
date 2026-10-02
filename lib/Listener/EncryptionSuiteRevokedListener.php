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
 *    when the suite is gone), except on a compromise force-revoke, which
 *    revokes them instead (keepiq#817, ADR-005).
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
	 *
	 * @spec openspec/specs/user-sharing/spec.md#requirement-encryptionsuite-revocation-share-cleanup
	 * @spec openspec/specs/user-sharing/spec.md#requirement-permanent-transfer-on-suite-revocation
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
			// The ex-recipient can no longer decrypt the copies sealed under
			// the revoked suite; sweep the ShareTargets for those copies.
			// Scoped to this suite: a compromise force-revoke during a
			// migration revokes a second suite right after this one, and an
			// unscoped sweep here removed the rows the cascade needs to find
			// the owners of the copies on that second suite (keepiq#864).
			$this->shareTargetMapper->deleteByTargetUserAndSuite(
				targetUserId: $userId,
				suiteId: $event->getSuiteId()
			);
		} catch (Throwable $exception) {
			$this->logger->warning(
				'Keepiq: EncryptionSuiteRevokedListener share-target sweep failed for '
				. $userId . ': ' . $exception->getMessage(),
				['app' => 'keepiq']
			);
		}

		if ($event->getCompromised() === true) {
			// A compromise force-revoke cuts the user's temporary delegations
			// instead of promoting them: one made from a stolen session is a
			// foothold, and promoting it would make it permanent during the
			// incident response (keepiq#817, ADR-005). Permanent ones stay.
			$this->revokeTemporaryDelegations(event: $event);
			return;
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

	/**
	 * Revoke the temporary delegations of a user whose suite was revoked as
	 * compromised. Fail-soft like the other cascade steps.
	 *
	 * @param EncryptionSuiteRevokedEvent $event The compromise revoke event
	 *
	 * @return void
	 *
	 * @spec openspec/specs/user-sharing/spec.md#requirement-permanent-transfer-on-suite-revocation
	 */
	private function revokeTemporaryDelegations(EncryptionSuiteRevokedEvent $event): void {
		try {
			$revoked = $this->delegationService->revokeTemporary(
				originalOwnerId: $event->getOwnerId(),
				revokedBy: $event->getRevokedBy()
			);
			if ($revoked > 0) {
				$this->logger->info(
					'Keepiq: revoked ' . $revoked . ' temporary delegations after the compromise revoke of '
					. $event->getSuiteId() . ' (owner=' . $event->getOwnerId() . ')',
					['app' => 'keepiq']
				);
			}
		} catch (Throwable $exception) {
			$this->logger->warning(
				'Keepiq: EncryptionSuiteRevokedListener delegation-revoke failed for '
				. $event->getOwnerId() . ': ' . $exception->getMessage(),
				['app' => 'keepiq']
			);
		}
	}//end revokeTemporaryDelegations()
}//end class
