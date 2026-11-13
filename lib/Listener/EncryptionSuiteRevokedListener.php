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
	 * Per delegation step: the info line (count, suite id, owner) and the
	 * name of the step in its failure warning.
	 *
	 * @var array<string,array{0:string,1:string}>
	 */
	private const DELEGATION_STEPS = [
		'promote' => ['Keepiq: promoted %d delegations to permanent after revoking %s (owner=%s)', 'delegation-promote'],
		'revoke'  => ['Keepiq: revoked %d temporary delegations after the compromise revoke of %s (owner=%s)', 'delegation-revoke'],
	];

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

		$this->sweepShareTargets(event: $event);
		if ($event->getCompromised() === true) {
			// A compromise force-revoke cuts the user's temporary delegations
			// instead of promoting them: one made from a stolen session is a
			// foothold, and promoting it would make it permanent during the
			// incident response (keepiq#817, ADR-005). Permanent ones stay.
			$this->delegationStep(event: $event, step: 'revoke');
			return;
		}

		$this->delegationStep(event: $event, step: 'promote');
	}//end handle()

	/**
	 * Sweep the ShareTargets of the copies sealed under the revoked suite.
	 *
	 * The ex-recipient can no longer decrypt them. Scoped to this suite: a
	 * compromise force-revoke during a migration revokes a second suite right
	 * after this one, and an unscoped sweep here removed the rows the cascade
	 * needs to find the owners of the copies on that second suite
	 * (keepiq#864).
	 *
	 * @param EncryptionSuiteRevokedEvent $event The revoke event of a user suite
	 *
	 * @return void
	 */
	private function sweepShareTargets(EncryptionSuiteRevokedEvent $event): void {
		try {
			$this->shareTargetMapper->deleteByTargetUserAndSuite(
				targetUserId: $event->getOwnerId(),
				suiteId: $event->getSuiteId()
			);
		} catch (Throwable $exception) {
			$this->logger->warning(
				'Keepiq: EncryptionSuiteRevokedListener share-target sweep failed for '
				. $event->getOwnerId() . ': ' . $exception->getMessage(),
				['app' => 'keepiq']
			);
		}
	}//end sweepShareTargets()

	/**
	 * Promote the user's delegations to permanent after an ordinary revoke,
	 * or revoke the temporary ones after a compromise revoke, logging the
	 * count and a failure.
	 *
	 * @param EncryptionSuiteRevokedEvent $event The revoke event of a user suite
	 * @param string                      $step  `promote` or `revoke`, a key of DELEGATION_STEPS
	 *
	 * @return void
	 *
	 * @spec openspec/specs/user-sharing/spec.md#requirement-permanent-transfer-on-suite-revocation
	 */
	private function delegationStep(EncryptionSuiteRevokedEvent $event, string $step): void {
		[$done, $failure] = self::DELEGATION_STEPS[$step];
		try {
			$count = $this->delegationCount(event: $event, step: $step);
			if ($count > 0) {
				$this->logger->info(sprintf($done, $count, $event->getSuiteId(), $event->getOwnerId()), ['app' => 'keepiq']);
			}
		} catch (Throwable $exception) {
			$this->logger->warning(
				'Keepiq: EncryptionSuiteRevokedListener ' . $failure . ' failed for '
				. $event->getOwnerId() . ': ' . $exception->getMessage(),
				['app' => 'keepiq']
			);
		}
	}//end delegationStep()

	/**
	 * Run one delegation step.
	 *
	 * @param EncryptionSuiteRevokedEvent $event The revoke event of a user suite
	 * @param string                      $step  `promote` or `revoke`
	 *
	 * @return int The number of delegations promoted or revoked
	 */
	private function delegationCount(EncryptionSuiteRevokedEvent $event, string $step): int {
		if ($step === 'revoke') {
			return $this->delegationService->revokeTemporary(
				originalOwnerId: $event->getOwnerId(),
				revokedBy: $event->getRevokedBy()
			);
		}

		return $this->delegationService->makePermanent(originalOwnerId: $event->getOwnerId());
	}//end delegationCount()
}//end class
