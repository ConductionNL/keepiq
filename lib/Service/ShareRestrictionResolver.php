<?php

/**
 * Keepiq ShareRestrictionResolver
 *
 * @category Service
 * @package  OCA\Keepiq\Service
 *
 * @author    Conduction Development Team <dev@conductio.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git-id>
 *
 * @link https://conduction.nl
 */

declare(strict_types=1);

namespace OCA\Keepiq\Service;

use OCA\Keepiq\Db\GroupShareMapper;
use OCA\Keepiq\Db\SecretMapper;
use OCA\Keepiq\Db\ShareTarget;
use OCA\Keepiq\Db\ShareTargetMapper;
use OCP\AppFramework\Db\DoesNotExistException;
use OCP\IGroupManager;

/**
 * Materialises the effective use-only flag and access end date of every
 * grant that reaches a recipient copy onto that copy, as `secrets.use_only`
 * and `secrets.access_expires_at` (sharing-use-only-and-expiring-shares D1),
 * so every read path and client sees them without a join.
 *
 * The grants reaching one recipient for one source secret are: the direct
 * share row (when the row is direct), every group share of the source to a
 * group the recipient is in, and every team-folder membership along the
 * source's folder chain that covers the recipient. ShareRestriction::combine
 * decides: the most generous grant wins.
 */
class ShareRestrictionResolver {

	/**
	 * Constructor for ShareRestrictionResolver.
	 *
	 * @param SecretMapper           $secretMapper      The secret mapper (sources and copies)
	 * @param ShareTargetMapper      $shareTargetMapper The share-target mapper
	 * @param GroupShareMapper       $groupShareMapper  The group-share mapper
	 * @param TeamFolderQueryService $teamFolders       The team-folder read side (covering memberships)
	 * @param IGroupManager          $groupManager      The Nextcloud group manager
	 *
	 * @return void
	 *
	 * @spec exclude Constructor wiring only.
	 */
	public function __construct(
		private SecretMapper $secretMapper,
		private ShareTargetMapper $shareTargetMapper,
		private GroupShareMapper $groupShareMapper,
		private TeamFolderQueryService $teamFolders,
		private IGroupManager $groupManager,
	) {
	}//end __construct()

	/**
	 * The effective restriction of one share row's recipient copy.
	 *
	 * @param ShareTarget $target The share row
	 *
	 * @return ShareRestriction
	 *
	 * @spec openspec/changes/sharing-use-only-and-expiring-shares/tasks.md#task-1.2
	 */
	public function effectiveFor(ShareTarget $target): ShareRestriction {
		return ShareRestriction::combine(grants: $this->grantsFor(target: $target));
	}//end effectiveFor()

	/**
	 * Recompute one share row's recipient copy and write it when it changed.
	 *
	 * @param ShareTarget $target The share row
	 *
	 * @return bool Whether the copy was written
	 *
	 * @spec openspec/changes/sharing-use-only-and-expiring-shares/tasks.md#task-1.2
	 */
	public function resolveTarget(ShareTarget $target): bool {
		try {
			$copy = $this->secretMapper->findById($target->getSecretId());
		} catch (DoesNotExistException) {
			return false;
		}

		$effective = $this->effectiveFor(target: $target);
		$current   = new ShareRestriction(
			useOnly: ($copy->getUseOnly() === true),
			expiresAt: $copy->getAccessExpiresAt()
		);
		if ($effective->equals(other: $current) === true) {
			return false;
		}

		$copy->setUseOnly($effective->useOnly);
		$copy->setAccessExpiresAt($effective->expiresAt);
		$this->secretMapper->update($copy);

		return true;
	}//end resolveTarget()

	/**
	 * Recompute every recipient copy of one source secret.
	 *
	 * @param string $sourceSecretId The source secret
	 *
	 * @return int The number of copies written
	 *
	 * @spec openspec/changes/sharing-use-only-and-expiring-shares/tasks.md#task-1.2
	 */
	public function resolveSource(string $sourceSecretId): int {
		return $this->resolveTargets(targets: $this->shareTargetMapper->findBySourceSecret($sourceSecretId));
	}//end resolveSource()

	/**
	 * Recompute a list of share rows.
	 *
	 * @param array<int,ShareTarget> $targets The share rows
	 *
	 * @return int The number of copies written
	 *
	 * @spec openspec/changes/sharing-use-only-and-expiring-shares/tasks.md#task-1.2
	 */
	public function resolveTargets(array $targets): int {
		$written = 0;
		foreach ($targets as $target) {
			if ($this->resolveTarget(target: $target) === true) {
				++$written;
			}
		}

		return $written;
	}//end resolveTargets()

	/**
	 * Every grant reaching a share row's recipient for its source secret.
	 *
	 * @param ShareTarget $target The share row
	 *
	 * @return array<int,ShareRestriction>
	 */
	private function grantsFor(ShareTarget $target): array {
		$grants = [];
		if ($target->getGroupShareId() === null && $target->getTeamFolderId() === null) {
			$grants[] = new ShareRestriction(
				useOnly: ($target->getUseOnly() === true),
				expiresAt: $target->getExpiresAt()
			);
		}

		$recipient = $target->getTargetUserId();
		foreach ($this->groupShareMapper->findBySecret($target->getSourceSecretId()) as $groupShare) {
			$linked = ($groupShare->getId() === $target->getGroupShareId());
			if ($linked === true || $this->groupManager->isInGroup($recipient, $groupShare->getGroupId()) === true) {
				$grants[] = new ShareRestriction(
					useOnly: ($groupShare->getUseOnly() === true),
					expiresAt: $groupShare->getExpiresAt()
				);
			}
		}

		try {
			$source = $this->secretMapper->findById($target->getSourceSecretId());
		} catch (DoesNotExistException) {
			return $grants;
		}

		foreach ($this->teamFolders->coveringMemberships(secret: $source, userId: $recipient) as $membership) {
			$grants[] = new ShareRestriction(
				// Use-only is offered on read memberships only; a write row
				// that somehow carries the flag still lifts it.
				useOnly: ($membership->getUseOnly() === true && $membership->effectiveGrade() === 'read'),
				expiresAt: $membership->getExpiresAt()
			);
		}

		return $grants;
	}//end grantsFor()
}//end class
