<?php

/**
 * Keepiq Share Authorization Service
 *
 * The whole authorization surface of the user-to-user share lifecycle in
 * one place: who owns a secret, who holds an active delegation on it,
 * what team-folder grade a member effectively has, what grade a copy
 * holder's copy gives them, and whether a prospective recipient can hold
 * a share at all.
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
use OCA\Keepiq\Db\GroupShareMapper;
use OCA\Keepiq\Db\Secret;
use OCA\Keepiq\Db\SecretDelegationMapper;
use OCA\Keepiq\Db\SecretMapper;
use OCA\Keepiq\Db\ShareTargetMapper;
use OCA\Keepiq\Exception\ForbiddenException;
use OCP\AppFramework\Db\DoesNotExistException;
use OCP\AppFramework\Db\MultipleObjectsReturnedException;
use OCP\IGroupManager;

/**
 * Authorization decisions for the secret-share lifecycle.
 *
 * @SuppressWarnings(PHPMD.CouplingBetweenObjects) Every share path authorizes here, so the authorization surface stays
 *   in one place; judging a delegate by the grants reaching their own copy (GHSA-m5p9-6pw7-pf3g) reads them through
 *   ShareGrantReader.
 * @SuppressWarnings(PHPMD.ExcessiveClassComplexity) Same cause: one rule set for every share path.
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
	 * @param GroupShareMapper|null $groupShareMapper The group shares of a source
	 * @param IGroupManager|null $groupManager Whether a copy holder is in one of their groups
	 * @param ShareGrantReader|null $grants Lists the grants reaching a delegate's copy
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
		private ?GroupShareMapper $groupShareMapper = null,
		private ?IGroupManager $groupManager = null,
		private ?ShareGrantReader $grants = null,
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
	 * SOURCE secret: a plain recipient fails, a delegate passes, and a copy
	 * someone else holds is not found. A missing secret and a foreign one get
	 * the same DoesNotExistException. Fails closed when the share-target mapper
	 * is not wired. A delegate is judged on their own copy whether they name it
	 * or the source (assertMayShareOnward()).
	 *
	 * @param string $secretId The secret (source or received copy) id
	 * @param string $userId   The acting user
	 *
	 * @return void
	 *
	 * @throws DoesNotExistException When the user may not re-share the secret
	 * @throws InvalidArgumentException When the copy is use-only or expiring, or a delegate holds no single copy
	 * @throws ForbiddenException When the copy is a read-only copy from another organisation
	 *
	 * @spec openspec/specs/link-sharing/spec.md#requirement-who-may-create-a-link-share
	 * @spec openspec/specs/use-only-shares/spec.md#requirement-the-server-refuses-what-it-can-enforce
	 */
	public function assertMayReshare(string $secretId, string $userId): void {
		if ($this->shareTargetMapper === null) {
			throw new DoesNotExistException('Secret not found');
		}

		try {
			$copy   = $this->loadSecret(secretId: $secretId);
			$source = $this->sourceOf(copy: $copy);
		} catch (InvalidArgumentException) {
			throw new DoesNotExistException('Secret not found');
		}

		if ($this->isOwnerOrDelegate(secret: $source, userId: $userId) === false) {
			throw new DoesNotExistException('Secret not found');
		}

		// A delegate shares from their own copy only, as on the other paths.
		if ($copy !== $source && ($copy->getOwnerType() !== 'user' || $copy->getOwnerId() !== $userId)) {
			throw new DoesNotExistException('Secret not found');
		}

		$copy->assertOnwardShareable();
		$this->assertMayShareOnward(source: $source, userId: $userId);
	}//end assertMayReshare()

	/**
	 * The source a share made by $userId hangs on. A delegate cannot decrypt
	 * the source, so their client names their own received copy; when they
	 * hold that copy and are an active delegate of its source, the source is
	 * returned. Anything else comes back as is, so a plain recipient's copy
	 * stays a received copy and is refused as a share source.
	 *
	 * @param Secret $secret The secret the caller named
	 * @param string $userId The acting user
	 *
	 * @return Secret
	 *
	 * @spec openspec/specs/user-sharing/spec.md#requirement-share-a-secret
	 */
	public function resolveShareSource(Secret $secret, string $userId): Secret {
		if ($this->shareTargetMapper === null) {
			return $secret;
		}

		try {
			$row = $this->shareTargetMapper->findByRecipientSecret(recipientSecretId: $secret->getId());
			$source = $this->secretMapper->findById($row->getSourceSecretId());
		} catch (DoesNotExistException|MultipleObjectsReturnedException) {
			return $secret;
		}

		if ($row->getTargetUserId() !== $userId || $row->isHeldByTarget(copy: $secret) === false
			|| ($source->getOwnerType() === 'user' && $source->getOwnerId() === $userId)
			|| $this->isOwnerOrDelegate(secret: $source, userId: $userId) === false
		) {
			return $secret;
		}

		return $source;
	}//end resolveShareSource()

	/**
	 * Refuse a delegate whose own grant may not leave as a full share. The
	 * owner is never refused here. Anyone else is judged by their own copy of
	 * the source: it must be the one live copy they hold, and not use-only,
	 * expiring or read-only. No copy (revoked or expired) or two copies
	 * refuse, as there is no single grant to judge. One of the grants reaching
	 * that copy must also be unrestricted on its own: the copy combines its
	 * grants per dimension, so a full grant that ends and a use-only one that
	 * does not read as unrestricted together, and neither may mint a full,
	 * permanent copy.
	 *
	 * @param Secret $source The source secret
	 * @param string $userId The sharing owner or delegate
	 *
	 * @return void
	 *
	 * @throws InvalidArgumentException When the caller holds no single copy, or it is use-only or expiring
	 * @throws ForbiddenException When the caller's copy is a read-only copy from another organisation
	 *
	 * @spec openspec/specs/expiring-shares/spec.md#requirement-an-expiring-copy-cannot-be-shared-onward
	 */
	public function assertMayShareOnward(Secret $source, string $userId): void {
		if ($source->getOwnerType() === 'user' && $source->getOwnerId() === $userId) {
			return;
		}

		$target = null;
		$copy   = null;
		if ($this->shareTargetMapper !== null) {
			try {
				$target = $this->shareTargetMapper->findBySourceSecretAndTargetUser(
					sourceSecretId: $source->getId(),
					targetUserId: $userId
				);
				$copy = $this->secretMapper->findById($target->getSecretId());
			} catch (DoesNotExistException|MultipleObjectsReturnedException) {
				$copy = null;
			}
		}

		if ($target === null || $copy === null) {
			throw new InvalidArgumentException(message: 'You hold no single copy of this secret to share it from');
		}

		$copy->assertOnwardShareable();
		if ($this->grants?->hasUnrestrictedGrant(target: $target) === false) {
			throw new InvalidArgumentException(message: Secret::ONWARD_SHARE_REFUSAL);
		}
	}//end assertMayShareOnward()

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
	 * @spec openspec/specs/folder-permission-grades/spec.md#requirement-team-folder-membership-carries-a-read-write-or-manage-grade
	 */
	public function resolveGrade(Secret $secret, string $userId): ?string {
		return $this->teamFolderService?->resolveGrade(secret: $secret, userId: $userId);
	}//end resolveGrade()

	/**
	 * The grade the caller's own copy of a source secret gives them: `write`
	 * when they may change the copy and are a member of a group the source is
	 * shared with, however the copy reached them; `none` for any other copy;
	 * null when they hold no copy of it or its access ended. Fails closed when
	 * the mappers are not wired.
	 *
	 * @param Secret $source The source secret
	 * @param string $userId The candidate user
	 *
	 * @return string|null
	 *
	 * @spec openspec/specs/sharing-group/spec.md#requirement-a-group-member-saves-for-everyone-it-is-shared-with
	 */
	public function copyGrade(Secret $source, string $userId): ?string {
		if ($this->shareTargetMapper === null) {
			return null;
		}

		try {
			$target = $this->shareTargetMapper->findBySourceSecretAndTargetUser(
				sourceSecretId: $source->getId(),
				targetUserId: $userId
			);
			$copy = $this->secretMapper->findById($target->getSecretId());
		} catch (DoesNotExistException|MultipleObjectsReturnedException) {
			return null;
		}

		$accessEnds = $copy->getAccessExpiresAt();
		if ($accessEnds !== null && $accessEnds->getTimestamp() <= time()) {
			return null;
		}

		if ($copy->getUseOnly() === true || $copy->getReadOnly() === true
			|| $this->isInSharedGroup(source: $source, userId: $userId) === false
		) {
			return 'none';
		}

		return 'write';
	}//end copyGrade()

	/**
	 * Whether the user is a member of a group the source is shared with
	 * through a full group share that has not expired. A use-only group share
	 * does not count, even when another grant lifts the use-only flag of the
	 * copy. The copy's own group share id plays no part: a member's copy may
	 * have come first through another share, and a direct share's id is not
	 * checked against its secret.
	 *
	 * @param Secret $source The source secret
	 * @param string $userId The copy's holder
	 *
	 * @return bool
	 */
	private function isInSharedGroup(Secret $source, string $userId): bool {
		if ($this->groupShareMapper === null || $this->groupManager === null) {
			return false;
		}

		foreach ($this->groupShareMapper->findBySecret($source->getId()) as $groupShare) {
			if ($groupShare->isFullAndLive() === true
				&& $this->groupManager->isInGroup($userId, $groupShare->getGroupId()) === true
			) {
				return true;
			}
		}

		return false;
	}//end isInSharedGroup()

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
