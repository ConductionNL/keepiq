<?php

/**
 * Keepiq Confirmer Copy Resolver
 *
 * Which copy a member may hand on when confirming a new team folder member
 * (admin-auto-confirm-members D2 and D3): their own live copy of the source,
 * only with an effective `write` grade on it, and only when the copy is not
 * older than the source's last key change.
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

use OCA\Keepiq\Db\Secret;
use OCA\Keepiq\Db\SecretMapper;
use OCA\Keepiq\Db\ShareTargetMapper;
use OCP\AppFramework\Db\DoesNotExistException;

/**
 * Resolves a confirmer's own current write copy of a source secret.
 */
class ConfirmerCopyResolver {
	/**
	 * Constructor.
	 *
	 * @param TeamFolderQueryService $queries Effective grades
	 * @param ShareTargetMapper $shareTargetMapper The confirmer's own copies
	 * @param SecretMapper $secretMapper Source and copy timestamps
	 *
	 * @return void
	 *
	 * @spec exclude Constructor wiring only.
	 */
	public function __construct(
		private TeamFolderQueryService $queries,
		private ShareTargetMapper $shareTargetMapper,
		private SecretMapper $secretMapper,
	) {
	}//end __construct()

	/**
	 * The id of the confirmer's own copy of a source, when they may hand it on.
	 *
	 * Null unless the confirmer's effective grade on the source is `write`
	 * and their copy is not older than the source's last key change
	 * (design D2 and D3).
	 *
	 * @param string $sourceId The source secret
	 * @param string $confirmerId The confirmer
	 *
	 * @return string|null
	 *
	 * @spec openspec/specs/team-folder-auto-confirm/spec.md#requirement-the-server-accepts-a-confirmers-row-only-when-it-is-safe
	 */
	public function currentWriteCopy(string $sourceId, string $confirmerId): ?string {
		try {
			$source = $this->secretMapper->findById(id: $sourceId);
		} catch (DoesNotExistException) {
			return null;
		}

		if ($this->queries->resolveGrade(secret: $source, userId: $confirmerId) !== 'write') {
			return null;
		}

		try {
			$shareRow = $this->shareTargetMapper->findBySourceSecretAndTargetUser(
				sourceSecretId: $sourceId,
				targetUserId: $confirmerId
			);
			$copy = $this->secretMapper->findById(id: (string)$shareRow->getSecretId());
		} catch (DoesNotExistException) {
			return null;
		}

		if ($this->copyIsCurrent(source: $source, copy: $copy) === false) {
			return null;
		}

		return $copy->getId();
	}//end currentWriteCopy()

	/**
	 * Whether a copy is at least as new as the source's last key change.
	 *
	 * @param Secret $source The source secret
	 * @param Secret $copy The confirmer's copy
	 *
	 * @return bool
	 *
	 * @spec openspec/specs/team-folder-auto-confirm/spec.md#requirement-the-server-accepts-a-confirmers-row-only-when-it-is-safe
	 */
	private function copyIsCurrent(Secret $source, Secret $copy): bool {
		$keyChangedAt = $source->getKeyUpdatedAt();
		if ($keyChangedAt === null) {
			return true;
		}

		$copyUpdatedAt = $copy->getUpdatedAt();

		return $copyUpdatedAt !== null && $copyUpdatedAt >= $keyChangedAt;
	}//end copyIsCurrent()
}//end class
