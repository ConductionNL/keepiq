<?php

/**
 * Doriath Share Authorization Service
 *
 * The whole authorization surface of the user-to-user share lifecycle in
 * one place: who owns a secret, who holds an active delegation on it,
 * what team-folder grade a member effectively has, and whether a
 * prospective recipient can hold a share at all.
 *
 * Extracted from ShareService so creation, revocation, the bulk
 * registrar and the sync fan-out all fall through the SAME checks
 * instead of each re-deriving them from the mappers.
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

use InvalidArgumentException;
use OCA\Doriath\Db\EncryptionSuiteMapper;
use OCA\Doriath\Db\Secret;
use OCA\Doriath\Db\SecretDelegationMapper;
use OCA\Doriath\Db\SecretMapper;
use OCP\AppFramework\Db\DoesNotExistException;

/**
 * Authorization decisions for the secret-share lifecycle.
 */
class ShareAuthorizationService {
	/**
	 * Constructor for ShareAuthorizationService.
	 *
	 * @param SecretMapper $secretMapper The Secret mapper (owner lookups)
	 * @param SecretDelegationMapper $delegationMapper The Delegation mapper (delegate authorization)
	 * @param EncryptionSuiteMapper $suiteMapper The EncryptionSuite mapper (recipient precondition)
	 * @param TeamFolderService|null $teamFolderService The team-folder service (write-grade resolution)
	 *
	 * @return void
	 *
	 * @spec exclude Constructor wiring only; the authorization rules carry the spec anchors.
	 */
	public function __construct(
		private SecretMapper $secretMapper,
		private SecretDelegationMapper $delegationMapper,
		private EncryptionSuiteMapper $suiteMapper,
		private ?TeamFolderService $teamFolderService = null,
	) {
	}//end __construct()

	/**
	 * Load a Secret by ID, surfacing missing rows as an InvalidArgumentException.
	 *
	 * @param string $secretId The secret ID
	 *
	 * @return Secret
	 *
	 * @throws InvalidArgumentException
	 *
	 * @spec openspec/specs/user-sharing/spec.md#requirement-share-a-secret
	 */
	public function loadSecret(string $secretId): Secret {
		try {
			return $this->secretMapper->findById($secretId);
		} catch (DoesNotExistException) {
			throw new InvalidArgumentException(message: 'Secret not found');
		}
	}//end loadSecret()

	/**
	 * Assert that $userId is the owner of $secret or an active delegate.
	 *
	 * @param Secret $secret The source secret
	 * @param string $userId The candidate user
	 *
	 * @return void
	 *
	 * @throws InvalidArgumentException When the user is neither owner nor delegate
	 *
	 * @spec openspec/specs/user-sharing/spec.md#requirement-share-a-secret
	 */
	public function assertOwnerOrDelegate(Secret $secret, string $userId): void {
		if ($this->isOwnerOrDelegate(secret: $secret, userId: $userId) === false) {
			throw new InvalidArgumentException(
				message: 'Not authorized to manage shares of this secret'
			);
		}
	}//end assertOwnerOrDelegate()

	/**
	 * Return true when $userId is the owner of $secret or an active delegate.
	 *
	 * @param Secret $secret The source secret
	 * @param string $userId The candidate user
	 *
	 * @return bool
	 *
	 * @spec openspec/specs/user-sharing/spec.md#requirement-share-a-secret
	 */
	public function isOwnerOrDelegate(Secret $secret, string $userId): bool {
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
	 * The caller's effective team-folder grade on a secret, or null when no
	 * team folder governs it (folder-permission-grades §2.3).
	 *
	 * @param Secret $secret The source secret
	 * @param string $userId The candidate user
	 *
	 * @return string|null The grade ('read' | 'write' | …), or null.
	 *
	 * @spec openspec/specs/folder-permission-grades/spec.md#requirement-team-folder-membership-carries-a-read-or-write-grade
	 */
	public function resolveGrade(Secret $secret, string $userId): ?string {
		return $this->teamFolderService?->resolveGrade(secret: $secret, userId: $userId);
	}//end resolveGrade()

	/**
	 * Verify the recipient has an active EncryptionSuite (without it, no
	 * one can decrypt the share-target row's encrypted Secret copy).
	 *
	 * @param string $targetUserId The recipient Nextcloud user ID
	 *
	 * @return void
	 *
	 * @throws InvalidArgumentException When the recipient has no active suite
	 *
	 * @spec openspec/specs/user-sharing/spec.md#requirement-share-a-secret
	 */
	public function assertRecipientHasActiveSuite(string $targetUserId): void {
		try {
			$this->suiteMapper->findActiveByOwner(
				ownerType: 'user',
				ownerId: $targetUserId
			);
		} catch (DoesNotExistException) {
			throw new InvalidArgumentException(
				message: 'Recipient has no active encryption suite'
			);
		}
	}//end assertRecipientHasActiveSuite()
}//end class
