<?php

/**
 * Keepiq ExpiredGrantRemover
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

use DateTime;
use OCA\Keepiq\Db\GroupShareMapper;
use OCA\Keepiq\Db\SecretMapper;
use OCA\Keepiq\Db\ShareTarget;
use OCA\Keepiq\Db\ShareTargetMapper;
use OCA\Keepiq\Db\TeamFolderMapper;
use OCA\Keepiq\Db\TeamFolderMemberMapper;
use OCP\AppFramework\Db\DoesNotExistException;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * Removes grants whose end date has passed through the paths that already
 * own each removal (sharing-use-only-and-expiring-shares D5): a direct
 * share through ShareRevocationService, a group share through
 * GroupShareService, a team-folder membership through TeamFolderService.
 * Each removal acts as the owner of what it removes, because that is who
 * the existing guards accept. Fail-soft per grant.
 *
 * @SuppressWarnings(PHPMD.CouplingBetweenObjects) One collaborator per grant
 *   kind and per removal path; the point of the class is to reuse the
 *   existing paths rather than delete rows itself.
 */
class ExpiredGrantRemover {

	/**
	 * Constructor for ExpiredGrantRemover.
	 *
	 * @param SecretMapper             $secretMapper     The secret mapper (sources)
	 * @param ShareTargetMapper        $targetMapper     The share-target mapper
	 * @param GroupShareMapper         $groupShareMapper The group-share mapper
	 * @param TeamFolderMemberMapper   $memberMapper     The membership mapper
	 * @param TeamFolderMapper         $teamFolderMapper The team-folder mapper (owners)
	 * @param ShareRestrictionResolver $restrictions     The restriction resolver
	 * @param ShareRevocationService   $revocation       Revokes a direct share
	 * @param GroupShareService        $groupShares      Revokes a group share
	 * @param TeamFolderService        $teamFolders      Removes a membership
	 * @param LoggerInterface          $logger           The logger
	 *
	 * @return void
	 *
	 * @spec exclude Constructor wiring only.
	 *
	 * @SuppressWarnings(PHPMD.ExcessiveParameterList) Constructor DI list, see the class note.
	 */
	public function __construct(
		private SecretMapper $secretMapper,
		private ShareTargetMapper $targetMapper,
		private GroupShareMapper $groupShareMapper,
		private TeamFolderMemberMapper $memberMapper,
		private TeamFolderMapper $teamFolderMapper,
		private ShareRestrictionResolver $restrictions,
		private ShareRevocationService $revocation,
		private GroupShareService $groupShares,
		private TeamFolderService $teamFolders,
		private LoggerInterface $logger,
	) {
	}//end __construct()

	/**
	 * Remove every grant whose end date is at or before `now`.
	 *
	 * @param DateTime $now The cut-off
	 *
	 * @return int The number of grants removed
	 *
	 * @spec openspec/changes/sharing-use-only-and-expiring-shares/specs/expiring-shares/spec.md#requirement-a-background-job-removes-expired-access
	 */
	public function removeExpired(DateTime $now): int {
		$removed = 0;
		foreach ($this->targetMapper->findEndingBetween(from: null, to: $now) as $target) {
			$removed += $this->attempt(removal: fn (): bool => $this->removeDirectShare(target: $target, now: $now));
		}

		foreach ($this->groupShareMapper->findEndingBetween(from: null, to: $now) as $groupShare) {
			$removed += $this->attempt(
				removal: fn (): bool => $this->asSourceOwner(
					sourceSecretId: $groupShare->getSecretId(),
					action: fn (string $ownerId) => $this->groupShares->revokeGroupShare(
						groupShareId: $groupShare->getId(),
						userId: $ownerId
					)
				)
			);
		}

		foreach ($this->memberMapper->findEndingBetween(from: null, to: $now) as $membership) {
			$removed += $this->attempt(
				removal: function () use ($membership): bool {
					$teamFolder = $this->teamFolderMapper->findById(id: $membership->getTeamFolderId());
					$this->teamFolders->removeMember(
						teamFolderId: (string)$teamFolder->getId(),
						membershipId: $membership->getId(),
						userId: $teamFolder->getOwnerId()
					);
					return true;
				}
			);
		}

		return $removed;
	}//end removeExpired()

	/**
	 * Revoke one share row (and with it the recipient's copy) as the owner
	 * of its source.
	 *
	 * @param ShareTarget $target The share row
	 *
	 * @return bool Whether it was revoked
	 *
	 * @spec openspec/changes/sharing-use-only-and-expiring-shares/specs/expiring-shares/spec.md#requirement-a-background-job-removes-expired-access
	 */
	public function revokeTarget(ShareTarget $target): bool {
		return $this->asSourceOwner(
			sourceSecretId: $target->getSourceSecretId(),
			action: fn (string $ownerId) => $this->revocation->revokeShare(shareId: (string)$target->getId(), userId: $ownerId)
		);
	}//end revokeTarget()

	/**
	 * Revoke an expired direct share, unless another grant still gives the
	 * recipient access past `now` (then the copy stays, resolved to it).
	 *
	 * @param ShareTarget $target The expired direct share row
	 * @param DateTime    $now    The cut-off
	 *
	 * @return bool Whether it was revoked
	 */
	private function removeDirectShare(ShareTarget $target, DateTime $now): bool {
		if ($target->getGroupShareId() !== null || $target->getTeamFolderId() !== null) {
			return false;
		}

		$effective = $this->restrictions->effectiveFor(target: $target);
		if ($effective->expiresAt === null || $effective->expiresAt > $now) {
			$this->restrictions->resolveTarget(target: $target);
			return false;
		}

		return $this->revokeTarget(target: $target);
	}//end removeDirectShare()

	/**
	 * Run a removal as the owner of a source secret.
	 *
	 * @param string   $sourceSecretId The source secret
	 * @param callable $action         Receives the owner id
	 *
	 * @return bool False when the source is gone
	 */
	private function asSourceOwner(string $sourceSecretId, callable $action): bool {
		try {
			$ownerId = $this->secretMapper->findById($sourceSecretId)->getOwnerId();
		} catch (DoesNotExistException) {
			return false;
		}

		$action($ownerId);
		return true;
	}//end asSourceOwner()

	/**
	 * Run one removal fail-soft: one broken grant must not strand the rest.
	 *
	 * @param callable $removal Returns whether it removed something
	 *
	 * @return int 1 when removed, else 0
	 */
	private function attempt(callable $removal): int {
		try {
			if ($removal() === true) {
				return 1;
			}

			return 0;
		} catch (Throwable $exception) {
			$this->logger->error(
				'Keepiq: could not remove an expired grant: ' . $exception->getMessage(),
				['exception' => $exception, 'app' => 'keepiq']
			);
			return 0;
		}
	}//end attempt()
}//end class
