<?php

/**
 * Keepiq Delegate Copy Keeper
 *
 * Decides, on account deletion, whether the delegate a secret was handed to keeps their share row and copy linked,
 * and readies the kept row. Split out of AccountShareCleanupService.
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

use OCA\Keepiq\Db\LinkShareMapper;
use OCA\Keepiq\Db\SecretMapper;
use OCA\Keepiq\Db\ShareTarget;
use OCA\Keepiq\Db\ShareTargetMapper;
use OCP\AppFramework\Db\DoesNotExistException;

/**
 * Keeps the delegate's share row of a secret handed over on account deletion, only when nothing limits it.
 *
 * @spec openspec/changes/sharing-share-save-missed-partner-shares/specs/gdpr-compliance/spec.md#requirement-shared-secret-semantics-on-account-deletion
 */
class DelegateCopyKeeper {

	/**
	 * Constructor for DelegateCopyKeeper.
	 *
	 * @param ShareRowJudge     $rowJudge     Judges the delegate's share row
	 * @param ShareGrantReader  $grants       Every grant reaching the delegate's copy
	 * @param SecretMapper      $secretMapper Loads the delegate's copy
	 * @param ShareTargetMapper $shareMapper  Writes the kept row
	 *
	 * @return void
	 *
	 * @spec exclude Constructor wiring only.
	 */
	public function __construct(
		private ShareRowJudge $rowJudge,
		private ShareGrantReader $grants,
		private SecretMapper $secretMapper,
		private ShareTargetMapper $shareMapper,
	) {
	}//end __construct()

	/**
	 * The share row of the delegate a secret was handed to, kept linked; null when the secret was not handed over, the
	 * row judge does not trust the row, or anything limits the delegate's copy, and the copy is then detached like any
	 * other.
	 *
	 * A kept row no longer names a group share, as the group shares are removed with the rest, and names the deleted
	 * account's marker instead of the deleted user as its maker.
	 *
	 * @param string      $secretId   The secret
	 * @param string      $fromUserId The deleted user
	 * @param string|null $newOwnerId The delegate it was handed to, or null
	 *
	 * @return ShareTarget|null
	 *
	 * @spec openspec/changes/sharing-share-save-missed-partner-shares/specs/gdpr-compliance/spec.md#requirement-shared-secret-semantics-on-account-deletion
	 */
	public function keptRow(string $secretId, string $fromUserId, ?string $newOwnerId): ?ShareTarget {
		if ($newOwnerId === null) {
			return null;
		}

		$row = $this->rowJudge->forPair(sourceId: $secretId, targetUserId: $newOwnerId)->trustedRow();
		if ($row === null || $this->isUnlimited(row: $row) === false) {
			return null;
		}

		$row->setGroupShareId(null);
		if ($row->getCreatedBy() === $fromUserId) {
			$row->setCreatedBy(LinkShareMapper::DELETED_ACCOUNT_MARKER);
		}

		$this->shareMapper->update($row);

		return $row;
	}//end keptRow()

	/**
	 * Whether nothing limits the copy a share row links, judged before any grant is removed: the copy is not use-only,
	 * time-limited or read-only, the row carries no limit of its own, and every grant reaching the copy is unlimited.
	 * Once the group shares are gone the row stands as a direct share on its own fields alone, so a limit that came
	 * from anywhere else would be lifted, and one grant that is not unlimited is enough to detach.
	 *
	 * @param ShareTarget $row The delegate's trusted row
	 *
	 * @return bool
	 */
	private function isUnlimited(ShareTarget $row): bool {
		try {
			$copy = $this->secretMapper->findById($row->getSecretId());
		} catch (DoesNotExistException) {
			return false;
		}

		if ($copy->isRestrictedCopy() === true || $row->getUseOnly() === true || $row->getExpiresAt() !== null) {
			return false;
		}

		foreach ($this->grants->grantsFor(target: $row) as $grant) {
			if ($grant->isRestricted() === true) {
				return false;
			}
		}

		return true;
	}//end isUnlimited()
}//end class
