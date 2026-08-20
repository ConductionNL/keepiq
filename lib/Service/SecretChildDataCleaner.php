<?php

/**
 * Doriath Secret Child-Data Cleaner
 *
 * Removes the data that HANGS OFF a secret rather than living in the secret
 * row itself: encrypted attachments, the attachment grants a copy holds, and
 * the version history. Every bulk secret delete in the app (folder cascade,
 * account deletion) has to run the same three steps in the same order before
 * the rows go, so they live here once instead of being repeated per caller.
 *
 * Both collaborators are optional: an instance that never enabled attachments
 * or version history wires neither, and the cleaner then becomes a no-op that
 * also skips the enumeration query.
 *
 * @category Service
 * @package  OCA\Doriath\Service
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

namespace OCA\Doriath\Service;

use OCA\Doriath\Db\SecretMapper;

/**
 * Cascades attachment, grant and version-history removal for secrets.
 */
class SecretChildDataCleaner {
	/**
	 * Constructor for SecretChildDataCleaner.
	 *
	 * @param SecretMapper $secretMapper The secret mapper
	 * @param AttachmentService|null $attachmentService The attachment service (delete cascade)
	 * @param SecretVersionService|null $versionService The version-history service (delete cascade)
	 *
	 * @return void
	 *
	 * @spec exclude Constructor wiring only.
	 */
	public function __construct(
		private SecretMapper $secretMapper,
		private ?AttachmentService $attachmentService = null,
		private ?SecretVersionService $versionService = null,
	) {
	}//end __construct()

	/**
	 * Whether any child-data cascade is wired at all. Callers use this to
	 * skip the enumeration query when there is nothing to clean.
	 *
	 * @return bool
	 *
	 * @spec exclude Predicate over injected collaborators; no spec behaviour of its own.
	 */
	public function hasCascades(): bool {
		return $this->attachmentService !== null || $this->versionService !== null;
	}//end hasCascades()

	/**
	 * Remove every attachment of a secret, every grant it holds as a copy,
	 * and its version history.
	 *
	 * @param string $secretId The secret whose child data is removed
	 *
	 * @return void
	 *
	 * @spec openspec/changes/encrypted-attachments/tasks.md#3.1
	 */
	public function purgeForSecret(string $secretId): void {
		$this->attachmentService?->deleteForSecret($secretId);
		$this->attachmentService?->deleteGrantsForSecretCopy($secretId);
		// Version-history cascade (secret-version-history §5.2).
		$this->versionService?->deleteForSecret($secretId);
	}//end purgeForSecret()

	/**
	 * Attachments cascade for a folder's direct secrets
	 * (encrypted-attachments §3.1): before a bulk secret delete, remove
	 * each secret's attachments and any grants it holds as a copy.
	 *
	 * @param string $folderId The folder whose direct secrets are deleted
	 *
	 * @return void
	 *
	 * @spec openspec/changes/encrypted-attachments/tasks.md#3.1
	 */
	public function purgeForFolder(string $folderId): void {
		if ($this->hasCascades() === false) {
			return;
		}

		foreach ($this->secretMapper->findByFolderId(folderId: $folderId) as $secret) {
			$this->purgeForSecret(secretId: $secret->getId());
		}
	}//end purgeForFolder()

	/**
	 * Attachments cascade for every secret a user still owns
	 * (encrypted-attachments §3.2), run before the owner's rows go.
	 *
	 * @param string $userId The departing user
	 *
	 * @return void
	 *
	 * @spec openspec/changes/encrypted-attachments/tasks.md#3.2
	 */
	public function purgeForOwnerUser(string $userId): void {
		if ($this->hasCascades() === false) {
			return;
		}

		foreach ($this->secretMapper->findByOwner('user', $userId, null, null, 'asc', 100000, 0) as $ownSecret) {
			$this->purgeForSecret(secretId: $ownSecret->getId());
		}
	}//end purgeForOwnerUser()
}//end class
