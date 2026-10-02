<?php

/**
 * Keepiq Share Authorization Service
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

use InvalidArgumentException;
use OCA\Keepiq\Db\EncryptionSuiteMapper;
use OCA\Keepiq\Db\Secret;
use OCA\Keepiq\Db\SecretDelegationMapper;
use OCA\Keepiq\Db\SecretMapper;
use OCA\Keepiq\Db\ShareTargetMapper;
use OCP\AppFramework\Db\DoesNotExistException;
use OCP\AppFramework\Db\MultipleObjectsReturnedException;

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
	 * @param ShareTargetMapper|null $shareTargetMapper Resolves a received copy to its source (keepiq#214)
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
		private ?ShareTargetMapper $shareTargetMapper = null,
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
	 * Refuse unless $userId owns the secret or is an active delegate of it,
	 * the people whose share permits re-sharing (keepiq#214, a public link).
	 * A received copy is owned by its recipient, so a copy is judged by its
	 * SOURCE secret: a plain recipient fails, a delegate passes. A missing
	 * secret and a foreign one get the same DoesNotExistException. Fails closed
	 * when the share-target mapper is not wired.
	 *
	 * @param string $secretId The secret (source or received copy) id
	 * @param string $userId   The acting user
	 *
	 * @return void
	 *
	 * @throws DoesNotExistException When the user may not re-share the secret
	 *
	 * @spec openspec/specs/link-sharing/spec.md#requirement-who-may-create-a-link-share
	 */
	public function assertMayReshare(string $secretId, string $userId): void {
		if ($this->shareTargetMapper === null) {
			throw new DoesNotExistException('Secret not found');
		}

		try {
			$secret = $this->loadSecret(secretId: $secretId);
			$secret = $this->sourceOf(copy: $secret);
		} catch (InvalidArgumentException) {
			throw new DoesNotExistException('Secret not found');
		}

		if ($this->isOwnerOrDelegate(secret: $secret, userId: $userId) === false) {
			throw new DoesNotExistException('Secret not found');
		}
	}//end assertMayReshare()

	/**
	 * The source secret of a received copy, or the secret itself when it is
	 * not a copy.
	 *
	 * @param Secret $copy The secret that may be a received copy
	 *
	 * @return Secret
	 *
	 * @throws InvalidArgumentException When the copy's source cannot be loaded
	 */
	private function sourceOf(Secret $copy): Secret {
		try {
			$row = $this->shareTargetMapper?->findByRecipientSecret(recipientSecretId: $copy->getId());
		} catch (DoesNotExistException) {
			return $copy;
		} catch (MultipleObjectsReturnedException) {
			throw new InvalidArgumentException(message: 'Ambiguous copy');
		}

		if ($row === null) {
			throw new InvalidArgumentException(message: 'No share-target mapper');
		}

		return $this->loadSecret(secretId: $row->getSourceSecretId());
	}//end sourceOf()

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
