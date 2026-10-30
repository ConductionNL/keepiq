<?php

/**
 * Keepiq Group Share Service
 *
 * Business logic for the GroupShare lifecycle: createGroupShare returns
 * the member fan-out the browser needs to encrypt for each eligible
 * member, revokeGroupShare cascades through every ShareTarget the group
 * fan-out produced, handleNewGroupMember notifies the secret owner so
 * they can approve the new member (the browser then registers that member's
 * server-minted copy through the bulk register-batch path), and
 * handleMemberLeave revokes only the group-derived shares (direct shares
 * stay intact).
 *
 * @category Service
 * @package  OCA\Keepiq\Service
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

namespace OCA\Keepiq\Service;

use DateTime;
use InvalidArgumentException;
use OCA\Keepiq\Db\BulkGrantShareTargetMapper;
use OCA\Keepiq\Db\EncryptionSuiteMapper;
use OCA\Keepiq\Db\GroupShare;
use OCA\Keepiq\Db\GroupShareMapper;
use OCA\Keepiq\Db\Secret;
use OCA\Keepiq\Db\SecretDelegationMapper;
use OCA\Keepiq\Db\SecretMapper;
use OCA\Keepiq\Db\ShareTarget;
use OCA\Keepiq\Db\ShareTargetMapper;
use OCA\Keepiq\Exception\ConflictException;
use OCA\Keepiq\Exception\ShareScanPendingException;
use OCA\Keepiq\Service\ShareSync\ShareWriteContext;
use OCP\AppFramework\Db\DoesNotExistException;
use OCP\AppFramework\Db\MultipleObjectsReturnedException;
use OCP\IDBConnection;
use OCP\IGroupManager;
use OCP\Share\IManager as IShareManager;
use Psr\Log\LoggerInterface;
use Ramsey\Uuid\Uuid;
use Throwable;

/**
 * Business logic for the GroupShare lifecycle.
 *
 * @SuppressWarnings(PHPMD.CouplingBetweenObjects) The service threads
 *   through five mappers + IGroupManager + the share/notification helpers
 *   so the group-fan-out flow lives in one place; splitting it would
 *   scatter the invariants over four classes.
 * @SuppressWarnings(PHPMD.ExcessiveParameterList) Constructor DI list: the
 *   Nextcloud share settings and the per-share revocation path joined the
 *   group-share flow (sharing-02); each is a single collaborator, not options.
 * @SuppressWarnings(PHPMD.ExcessiveClassComplexity) Same cause: the group-share flow in one place, including on each
 *   path holding back the rows the pending share quarantine scan judges and refusing a delegate's change of their own
 *   grant (GHSA-m5p9-6pw7-pf3g).
 */
class GroupShareService {
	/**
	 * Constructor for GroupShareService.
	 *
	 * @param GroupShareMapper $mapper The group-share mapper
	 * @param ShareTargetMapper $shareTargetMapper The share-target mapper (per-row reads/writes)
	 * @param BulkGrantShareTargetMapper $bulkGrantMapper The share-target mapper keyed on the grant (cascade)
	 * @param SecretMapper $secretMapper The Secret mapper (owner lookup)
	 * @param EncryptionSuiteMapper $suiteMapper The suite mapper (member-eligibility filter)
	 * @param SecretDelegationMapper $delegationMapper The delegation mapper (delegate authorization)
	 * @param IGroupManager $groupManager The Nextcloud group manager
	 * @param NotificationService $notificationService The notification dispatcher
	 * @param LoggerInterface $logger The logger
	 * @param IShareManager $shareManager Nextcloud's share settings (group sharing on, own groups only)
	 * @param ShareRevocationService $revocationService Revokes one member's share and deletes its copy
	 * @param ShareRestrictionResolver|null $restrictions Materialises use-only and end dates onto copies
	 * @param ShareAuthorizationService|null $auth Whether a new member's existing copy now saves for everyone
	 * @param ShareWriteContext|null $contexts Whether a new member already saves for everyone other than through a group share
	 * @param ShareQuarantineScanner|null $quarantineScan Holds back the rows the pending scan catches or cannot judge
	 * @param ShareGrantReader|null $grants Lists the grants reaching a delegate's copy, when no authorization service is wired
	 * @param IDBConnection|null $db Makes a restriction change and a revoke all-or-nothing
	 *
	 * @return void
	 */
	public function __construct(
		private GroupShareMapper $mapper,
		private ShareTargetMapper $shareTargetMapper,
		private BulkGrantShareTargetMapper $bulkGrantMapper,
		private SecretMapper $secretMapper,
		private EncryptionSuiteMapper $suiteMapper,
		private SecretDelegationMapper $delegationMapper,
		private IGroupManager $groupManager,
		private NotificationService $notificationService,
		private LoggerInterface $logger,
		private IShareManager $shareManager,
		private ShareRevocationService $revocationService,
		private ?ShareRestrictionResolver $restrictions = null,
		private ?ShareAuthorizationService $auth = null,
		private ?ShareWriteContext $contexts = null,
		private ?ShareQuarantineScanner $quarantineScan = null,
		private ?ShareGrantReader $grants = null,
		private ?IDBConnection $db = null,
	) {
	}//end __construct()

	/**
	 * Create a group share — returns the per-member fan-out the browser
	 * needs (each entry has the recipient's user ID and PEM certificate so
	 * the browser can RSA-encrypt the source secret for that member). The
	 * owner and members without an active EncryptionSuite are filtered out
	 * server-side so the browser doesn't have to know who is "eligible".
	 *
	 * @param string $secretId The source Secret ID
	 * @param string $groupId The Nextcloud group ID
	 * @param string $userId The initiator (must be owner or delegate)
	 * @param ShareRestriction|null $restriction Use-only and end date for every member
	 *
	 * `skipped` counts the members (owner excluded) left out for want of an
	 * active EncryptionSuite, so the sharer can be told who did not get it.
	 *
	 * @return array{groupShare:GroupShare,members:array<int,array{userId:string,certificate:string}>,skipped:int}
	 *
	 * @throws InvalidArgumentException On unauthorized / missing secret / empty group
	 * @throws ShareScanPendingException When a restriction change reaches a row the pending quarantine scan cannot judge
	 *
	 * @spec openspec/changes/implement-user-sharing/tasks.md#4.2
	 * @spec openspec/specs/sharing-group/spec.md#requirement-share-with-a-group
	 * @spec openspec/changes/archive/2026-10-04-sharing-use-only-and-expiring-shares/tasks.md#task-2.1
	 */
	public function createGroupShare(
		string $secretId,
		string $groupId,
		string $userId,
		?ShareRestriction $restriction = null,
	): array {
		if ($groupId === '') {
			throw new InvalidArgumentException(message: 'groupId is required');
		}

		// A delegate names their own copy; the group share hangs on its source.
		$secret = $this->authorization()->resolveShareSource(
			secret: $this->loadSecret(secretId: $secretId),
			userId: $userId
		);
		$secretId = $secret->getId();
		$this->assertOwnerOrDelegate(secret: $secret, userId: $userId);
		$secret->assertOnwardShareable();
		$this->assertShareableSource(secret: $secret, userId: $userId);

		$group = $this->groupManager->get($groupId);
		if ($group === null) {
			throw new InvalidArgumentException(message: 'Group not found');
		}

		$this->assertGroupShareable(groupId: $groupId, userId: $userId);

		// Idempotency — one GroupShare per (secret, group). Surface the
		// existing one with a fresh member list rather than persisting a
		// duplicate.
		try {
			$existing = $this->mapper->findBySecretAndGroup(secretId: $secretId, groupId: $groupId);
		} catch (DoesNotExistException) {
			$existing = null;
		}

		if ($existing === null) {
			$row = new GroupShare();
			$row->setId(Uuid::uuid4()->toString());
			$row->setSecretId($secretId);
			$row->setGroupId($groupId);
			$row->setCreatedBy($userId);
			$row->setCreatedAt(new DateTime());
			$row->setUseOnly(($restriction?->useOnly === true));
			$row->setExpiresAt($restriction?->expiresAt);
			$existing = $this->mapper->insert($row);
		} elseif ($restriction !== null) {
			// Sharing again with the same group changes its restriction.
			$this->assertMayChangeRestriction(secret: $secret, groupShare: $existing, restriction: $restriction, userId: $userId);
			$existing = $this->changeRestriction(groupShare: $existing, restriction: $restriction);
		}

		$members = [];
		$skipped = 0;
		foreach ($group->getUsers() as $user) {
			$candidateId = $user->getUID();
			if ($candidateId === $secret->getOwnerId()) {
				continue;
			}

			try {
				$suite = $this->suiteMapper->findActiveByOwner(
					ownerType: 'user',
					ownerId: $candidateId
				);
			} catch (DoesNotExistException) {
				$skipped++;
				continue;
			}

			$members[] = [
				'userId' => $candidateId,
				'certificate' => $suite->getCertificate(),
			];
		}

		return [
			'groupShare' => $existing,
			'members' => $members,
			'skipped' => $skipped,
		];
	}//end createGroupShare()

	/**
	 * Apply Nextcloud's own share settings to a group share: group sharing
	 * must be on, and when sharing is restricted to the sharer's own groups
	 * the sharer must be a member. A group outside that reach is reported as
	 * "Group not found", the same answer a missing group gets, so the reply
	 * does not confirm that a group the caller cannot see exists.
	 *
	 * @param string $groupId The target group
	 * @param string $userId The sharer
	 *
	 * @return void
	 *
	 * @throws InvalidArgumentException When the group is out of the sharer's reach
	 *
	 * @spec openspec/specs/sharing-group/spec.md#requirement-share-with-a-group
	 */
	private function assertGroupShareable(string $groupId, string $userId): void {
		if ($this->shareManager->allowGroupSharing() === false) {
			throw new InvalidArgumentException(message: 'Group sharing is disabled');
		}

		if ($this->shareManager->shareWithGroupMembersOnly() === true
			&& $this->groupManager->isInGroup($userId, $groupId) === false
		) {
			throw new InvalidArgumentException(message: 'Group not found');
		}
	}//end assertGroupShareable()

	/**
	 * Revoke a group share: revoke every member share fanned out from it
	 * through the share revocation path (which deletes the member's Secret
	 * copy, not only the ShareTarget row), then delete the GroupShare row.
	 *
	 * @param string $groupShareId The GroupShare row ID
	 * @param string $userId The Nextcloud user requesting the revoke
	 *
	 * @return void
	 *
	 * @throws InvalidArgumentException On unauthorized / not found
	 * @throws ShareScanPendingException When the pending quarantine scan cannot judge one of its rows
	 *
	 * @spec openspec/changes/implement-user-sharing/tasks.md#4.3
	 * @spec openspec/specs/sharing-group/spec.md#requirement-share-with-a-group
	 */
	public function revokeGroupShare(string $groupShareId, string $userId): void {
		try {
			$entity = $this->mapper->findById($groupShareId);
		} catch (DoesNotExistException) {
			throw new InvalidArgumentException(message: 'Group share not found');
		}

		$secret = $this->loadSecret(secretId: $entity->getSecretId());
		$this->assertOwnerOrDelegate(secret: $secret, userId: $userId);

		// Every row is judged before anything changes, so a row the pending
		// quarantine scan cannot judge refuses the revoke rather than leave
		// its copy behind with no row, and a quarantine this makes is not
		// rolled back with it. A row it quarantines goes with the group share
		// below and keeps its copy.
		$live = [];
		foreach ($this->bulkGrantMapper->findByGroupShare(groupShareId: $groupShareId) as $target) {
			if ($this->quarantineScan?->refuseUnjudged(
				row: $target,
				refusal: 'A share of this group waits for the share quarantine scan, so the group share cannot be revoked yet'
			) !== true
			) {
				$live[] = $target;
			}
		}

		$this->db?->beginTransaction();
		try {
			foreach ($live as $target) {
				$this->revokeMemberShare(target: $target, userId: $userId, groupShareId: $groupShareId);
			}

			// Anything the per-share revoke could not reach (a row whose
			// source moved, or one the pending scan quarantined) still goes
			// with the group share.
			$this->bulkGrantMapper->deleteByGroupShare(groupShareId: $groupShareId);
			$this->mapper->delete($entity);
			$this->db?->commit();
		} catch (Throwable $exception) {
			$this->db?->rollBack();
			throw $exception;
		}

		$this->logger->info(
			'Revoked group share ' . $groupShareId . ' for secret ' . $entity->getSecretId(),
			['app' => 'keepiq']
		);
	}//end revokeGroupShare()

	/**
	 * List the group shares for a given source secret — only visible to
	 * the owner or an active delegate.
	 *
	 * @param string $secretId The source Secret ID
	 * @param string $userId The requesting user
	 *
	 * @return GroupShare[]
	 *
	 * @spec openspec/changes/implement-user-sharing/tasks.md#4.1
	 */
	public function getGroupSharesForSecret(string $secretId, string $userId): array {
		try {
			$secret = $this->loadSecret(secretId: $secretId);
		} catch (InvalidArgumentException) {
			return [];
		}

		if ($this->isOwnerOrDelegate(secret: $secret, userId: $userId) === false) {
			return [];
		}

		return $this->mapper->findBySecret($secretId);
	}//end getGroupSharesForSecret()

	/**
	 * Return the list of users in a group (lookup helper for the API).
	 *
	 * @param string $groupId The Nextcloud group ID
	 *
	 * @return string[] List of user IDs
	 *
	 * @spec openspec/specs/sharing-group/spec.md#requirement-share-with-a-group
	 */
	public function getGroupMembers(string $groupId): array {
		$group = $this->groupManager->get($groupId);
		if ($group === null) {
			return [];
		}

		$ids = [];
		foreach ($group->getUsers() as $user) {
			$ids[] = $user->getUID();
		}

		return $ids;
	}//end getGroupMembers()

	/**
	 * Handle a new user being added to a group — notify every secret
	 * owner whose secret is shared with that group so they can approve
	 * the new member. A new member who already holds a live copy has
	 * nothing to approve: the owner is told when the group share lets that
	 * copy save for everyone, unless the member already saves for everyone
	 * other than through a group share: as the owner or a delegate, or
	 * through a team-folder write grade, even one that comes through this
	 * group. The owner is not notified otherwise. No group share counts as
	 * already granting it, not even a share with a group the member joined in
	 * the same directory sync or through a nested group.
	 *
	 * @param string $userId The user newly added to the group
	 * @param string $groupId The group that gained the member
	 *
	 * @return int Number of notifications dispatched
	 *
	 * @spec openspec/changes/implement-user-sharing/tasks.md#4.4
	 * @spec openspec/specs/sharing-group/spec.md#requirement-a-group-member-saves-for-everyone-it-is-shared-with
	 */
	public function handleNewGroupMember(string $userId, string $groupId): int {
		$dispatched = 0;
		foreach ($this->mapper->findByGroup($groupId) as $groupShare) {
			try {
				$secret = $this->secretMapper->findById($groupShare->getSecretId());
			} catch (DoesNotExistException) {
				continue;
			}

			// Skip if the new member IS the owner.
			if ($secret->getOwnerId() === $userId) {
				continue;
			}

			$subject = 'group_member_added';
			$copyGrade = $this->auth?->copyGrade(source: $secret, userId: $userId);
			if ($copyGrade !== null) {
				if ($copyGrade !== 'write' || $groupShare->isFullAndLive() === false
					|| $this->contexts?->writesForEveryoneWithoutGroupShares(source: $secret, userId: $userId) === true
				) {
					continue;
				}

				$subject = 'group_member_gained_write';
			}

			$this->notificationService->notify(
				subject: $subject,
				recipientId: $secret->getOwnerId(),
				params: [
					'newMemberId' => $userId,
					'groupId' => $groupId,
					'secretId' => $secret->getId(),
					'secretName' => $secret->getName(),
					'groupShareId' => $groupShare->getId(),
				],
				objectType: 'secret',
				objectId: $secret->getId(),
			);
			++$dispatched;
		}//end foreach

		return $dispatched;
	}//end handleNewGroupMember()

	/**
	 * Handle a member leaving a group — revoke only the ShareTargets that
	 * were fanned out from a GroupShare for that group; direct shares to
	 * the same user are NOT touched.
	 *
	 * @param string $userId The departing user
	 * @param string $groupId The group they left
	 *
	 * @return int Number of ShareTargets revoked
	 *
	 * @spec openspec/changes/implement-user-sharing/tasks.md#4.6
	 */
	public function handleMemberLeave(string $userId, string $groupId): int {
		$revoked = 0;
		$groupShares = $this->mapper->findByGroup($groupId);
		foreach ($groupShares as $groupShare) {
			foreach ($this->bulkGrantMapper->findByGroupShare($groupShare->getId()) as $row) {
				if ($row->getTargetUserId() !== $userId) {
					continue;
				}

				if ($this->deleteLeavingCopy(row: $row) === false) {
					continue;
				}

				$this->shareTargetMapper->delete($row);
				++$revoked;
			}
		}

		return $revoked;
	}//end handleMemberLeave()

	/**
	 * Delete a departing member's copy, and say whether their share row goes
	 * too. Defence in depth: a copy not genuinely held by the row's target (a
	 * poisoned or drifted `secret_id`) is kept, so a member leaving can never
	 * delete another user's secret; the row goes. A row the pending quarantine
	 * scan quarantines stays with its copy, for an administrator to judge. A
	 * row it cannot judge goes and its copy is kept, as a group-share revoke
	 * keeps it, so the departed member gets no later sync.
	 *
	 * @param ShareTarget $row The departing member's share row
	 *
	 * @return bool Whether the row goes
	 */
	private function deleteLeavingCopy(ShareTarget $row): bool {
		try {
			$copy = $this->secretMapper->findById($row->getSecretId());
		} catch (DoesNotExistException) {
			return true;
		}

		$context = ['app' => 'keepiq', 'shareId' => $row->getId(), 'secretId' => $row->getSecretId()];
		if ($row->isHeldByTarget(copy: $copy) === false) {
			$this->logger->warning('Kept a secret not held by the departing member\'s share target', $context);
			return true;
		}

		$held = $this->quarantineScan?->heldBack(row: $row);
		if ($held === ShareQuarantineScanner::HELD_QUARANTINED) {
			$this->logger->warning('Kept the departing member\'s share row and copy the pending quarantine scan quarantined', $context);
			return false;
		}

		if ($held === ShareQuarantineScanner::HELD_UNJUDGED) {
			$this->logger->warning('Kept the copy of the departing member\'s share row the pending quarantine scan cannot judge', $context);
			return true;
		}

		$this->secretMapper->delete($copy);
		return true;
	}//end deleteLeavingCopy()

	/**
	 * Assert that $userId is the owner of $secret or an active delegate.
	 *
	 * @param Secret $secret The source secret
	 * @param string $userId The candidate user
	 *
	 * @return void
	 *
	 * @throws InvalidArgumentException When unauthorized
	 */
	private function assertOwnerOrDelegate(Secret $secret, string $userId): void {
		if ($this->isOwnerOrDelegate(secret: $secret, userId: $userId) === false) {
			throw new InvalidArgumentException(
				message: 'Not authorized to manage group shares of this secret'
			);
		}
	}//end assertOwnerOrDelegate()

	/**
	 * Refuse a source that may not be shared onward even though the caller is
	 * the owner or an active delegate: a received copy is never a share source
	 * (a recipient must not re-share), and a delegate whose own copy is
	 * use-only, expiring or read-only, or who holds no single copy, may not
	 * mint full group copies (GHSA-m5p9-6pw7-pf3g).
	 *
	 * @param Secret $secret The source secret
	 * @param string $userId The initiator
	 *
	 * @return void
	 *
	 * @throws InvalidArgumentException When the source is a received copy, or the delegate's copy is missing, ambiguous or restricted
	 * @throws \OCA\Keepiq\Exception\ForbiddenException When the delegate's copy is read-only
	 *
	 * @spec openspec/specs/user-sharing/spec.md#requirement-share-a-secret
	 */
	private function assertShareableSource(Secret $secret, string $userId): void {
		if ($this->isReceivedCopy(secret: $secret) === true) {
			throw new InvalidArgumentException(message: 'A received copy cannot be shared onward');
		}

		$this->authorization()->assertMayShareOnward(source: $secret, userId: $userId);
	}//end assertShareableSource()

	/**
	 * Refuse a delegate who changes the restriction of a group share that
	 * reaches them, as a delegate may not change their own grant
	 * (GHSA-m5p9-6pw7-pf3g). Sharing again unchanged is not a change.
	 *
	 * @param Secret $secret The source secret
	 * @param GroupShare $groupShare The existing group share
	 * @param ShareRestriction $restriction The restriction asked for
	 * @param string $userId The initiator
	 *
	 * @return void
	 *
	 * @throws InvalidArgumentException When a delegate in the group changes its restriction
	 *
	 * @spec openspec/specs/user-sharing/spec.md#requirement-share-a-secret
	 */
	private function assertMayChangeRestriction(
		Secret $secret,
		GroupShare $groupShare,
		ShareRestriction $restriction,
		string $userId,
	): void {
		$current = new ShareRestriction(useOnly: ($groupShare->getUseOnly() === true), expiresAt: $groupShare->getExpiresAt());
		if (($secret->getOwnerType() === 'user' && $secret->getOwnerId() === $userId)
			|| $current->equals(other: $restriction) === true
			|| $this->groupManager->isInGroup($userId, $groupShare->getGroupId()) === false
		) {
			return;
		}

		throw new InvalidArgumentException(message: 'You cannot change a group share that reaches you');
	}//end assertMayChangeRestriction()

	/**
	 * Change a group share's restriction and resolve its members' copies in
	 * one transaction. A row the pending share quarantine scan cannot judge
	 * refuses the change (GHSA-m5p9-6pw7-pf3g), as the group share would take
	 * the new restriction while that row's copy kept the old one. A row the
	 * scan quarantines leaves the group share, so the change goes on without
	 * it.
	 *
	 * @param GroupShare       $groupShare  The existing group share
	 * @param ShareRestriction $restriction The new restriction
	 *
	 * @return GroupShare
	 *
	 * @throws ShareScanPendingException When the scan cannot judge one of its rows
	 *
	 * @spec openspec/specs/user-sharing/spec.md#requirement-planted-share-rows-are-quarantined
	 */
	private function changeRestriction(GroupShare $groupShare, ShareRestriction $restriction): GroupShare {
		$refusal = 'A share of this group waits for the share quarantine scan, so its restriction cannot be changed yet';
		// Judged before the transaction, so a quarantine this makes is not
		// rolled back with a refused change.
		foreach ($this->bulkGrantMapper->findByGroupShare(groupShareId: $groupShare->getId()) as $row) {
			$this->quarantineScan?->refuseUnjudged(row: $row, refusal: $refusal);
		}

		$this->db?->beginTransaction();
		try {
			$groupShare->setUseOnly($restriction->useOnly);
			$groupShare->setExpiresAt($restriction->expiresAt);
			$updated = $this->mapper->update($groupShare);
			// Judging a row again can fail where it just succeeded, which
			// rolls the change back.
			$this->restrictions?->resolveTargetsOrRefuse(
				targets: $this->bulkGrantMapper->findByGroupShare(groupShareId: $updated->getId()),
				refusal: $refusal
			);
			$this->db?->commit();
		} catch (Throwable $exception) {
			$this->db?->rollBack();
			throw $exception;
		}

		return $updated;
	}//end changeRestriction()

	/**
	 * Revoke one member's share of a group share being revoked. A row the
	 * pending quarantine scan quarantines meanwhile is kept with its copy, to
	 * go with the group share; one it cannot judge refuses the whole revoke.
	 *
	 * @param ShareTarget $target       The member's share row
	 * @param string      $userId       The user revoking the group share
	 * @param string      $groupShareId The group share
	 *
	 * @return void
	 *
	 * @throws ShareScanPendingException When the scan cannot judge the row
	 */
	private function revokeMemberShare(ShareTarget $target, string $userId, string $groupShareId): void {
		try {
			$this->revocationService->revokeShare(shareId: $target->getId(), userId: $userId);
		} catch (ShareScanPendingException $exception) {
			throw $exception;
		} catch (ConflictException) {
			$this->logger->warning(
				'Kept the copy of a group share row the pending quarantine scan quarantined',
				['app' => 'keepiq', 'shareId' => $target->getId(), 'groupShareId' => $groupShareId]
			);
		}
	}//end revokeMemberShare()

	/**
	 * The share authorization service, or one built on this service's own
	 * mappers when none is wired.
	 *
	 * @return ShareAuthorizationService
	 */
	private function authorization(): ShareAuthorizationService {
		return ($this->auth ?? new ShareAuthorizationService(
			secretMapper: $this->secretMapper,
			delegationMapper: $this->delegationMapper,
			shareTargetMapper: $this->shareTargetMapper,
			groupShareMapper: $this->mapper,
			groupManager: $this->groupManager,
			grants: $this->grants,
		));
	}//end authorization()

	/**
	 * Whether the source is itself a received copy — a secret that is some share
	 * target's recipient secret. An ambiguous match counts as a copy; only a
	 * genuinely absent row reads as an original.
	 *
	 * @param Secret $secret The source secret
	 *
	 * @return bool
	 *
	 * @spec openspec/specs/user-sharing/spec.md#requirement-share-a-secret
	 */
	private function isReceivedCopy(Secret $secret): bool {
		try {
			$this->shareTargetMapper->findByRecipientSecret(recipientSecretId: $secret->getId());
		} catch (DoesNotExistException) {
			return false;
		} catch (MultipleObjectsReturnedException) {
			return true;
		}

		return true;
	}//end isReceivedCopy()

	/**
	 * Return true when $userId is the owner of $secret or an active delegate.
	 *
	 * @param Secret $secret The source secret
	 * @param string $userId The candidate user
	 *
	 * @return bool
	 */
	private function isOwnerOrDelegate(Secret $secret, string $userId): bool {
		if ($secret->getOwnerType() === 'user' && $secret->getOwnerId() === $userId) {
			return true;
		}

		try {
			$this->delegationMapper->findActiveBySecretAndUser(
				secretId: $secret->getId(),
				userId: $userId
			);
			return true;
		} catch (DoesNotExistException) {
			return false;
		}
	}//end isOwnerOrDelegate()

	/**
	 * Look up a Secret by ID, raising InvalidArgumentException on miss.
	 *
	 * @param string $secretId The Secret ID
	 *
	 * @return Secret
	 *
	 * @throws InvalidArgumentException
	 */
	private function loadSecret(string $secretId): Secret {
		try {
			return $this->secretMapper->findById($secretId);
		} catch (DoesNotExistException) {
			throw new InvalidArgumentException(message: 'Secret not found');
		}
	}//end loadSecret()
}//end class
