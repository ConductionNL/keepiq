<?php

/**
 * Keepiq ShareGrantReader
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
use OCP\AppFramework\Db\DoesNotExistException;
use OCP\IGroupManager;

/**
 * Reads the grants reaching a recipient copy, each with its own use-only flag
 * and end date: the direct share row (when the row is direct), every group
 * share of the source to a group the recipient is in, and every team-folder
 * membership along the source's folder chain that covers the recipient.
 * Writes nothing.
 *
 * @spec openspec/specs/expiring-shares/spec.md#requirement-shares-and-memberships-can-carry-an-end-date
 */
class ShareGrantReader {

	/**
	 * Constructor for ShareGrantReader.
	 *
	 * @param SecretMapper           $secretMapper     The secret mapper (the source)
	 * @param GroupShareMapper       $groupShareMapper The group-share mapper
	 * @param TeamFolderQueryService $teamFolders      The team-folder read side (covering memberships)
	 * @param IGroupManager          $groupManager     The Nextcloud group manager
	 *
	 * @return void
	 *
	 * @spec exclude Constructor wiring only.
	 */
	public function __construct(
		private SecretMapper $secretMapper,
		private GroupShareMapper $groupShareMapper,
		private TeamFolderQueryService $teamFolders,
		private IGroupManager $groupManager,
	) {
	}//end __construct()

	/**
	 * Every grant reaching a share row's recipient for its source secret.
	 *
	 * @param ShareTarget $target The share row
	 *
	 * @return array<int,ShareRestriction>
	 *
	 * @spec openspec/changes/archive/2026-10-04-sharing-use-only-and-expiring-shares/tasks.md#task-1.2
	 */
	public function grantsFor(ShareTarget $target): array {
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

	/**
	 * Whether one grant reaching a share row's copy is neither use-only nor
	 * expiring. Such a grant has no end date, so it cannot have ended.
	 *
	 * @param ShareTarget $target The share row
	 *
	 * @return bool
	 *
	 * @spec openspec/specs/expiring-shares/spec.md#requirement-an-expiring-copy-cannot-be-shared-onward
	 */
	public function hasUnrestrictedGrant(ShareTarget $target): bool {
		foreach ($this->grantsFor(target: $target) as $grant) {
			if ($grant->isRestricted() === false) {
				return true;
			}
		}

		return false;
	}//end hasUnrestrictedGrant()
}//end class
