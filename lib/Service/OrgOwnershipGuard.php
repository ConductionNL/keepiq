<?php

/**
 * Keepiq Org Ownership Guard
 *
 * The team folder ownership policy (admin-vault-policies D4): for a user the
 * policy applies to, a secret of a covered type (default login, api_key,
 * database) may only be created in, imported into or moved into a folder
 * that has a team folder the user owns among its ancestors. Other types stay
 * personal. Every write path calls the same check.
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
use OCA\Keepiq\Db\SecretTypeMapper;
use OCA\Keepiq\Db\ShareTargetMapper;
use OCA\Keepiq\Exception\PolicyViolationException;
use OCP\AppFramework\Db\DoesNotExistException;

/**
 * Refuses a covered secret outside the user's own team folders.
 */
class OrgOwnershipGuard {
	/**
	 * The code the API returns with the refusal.
	 */
	public const CODE = 'org_ownership_required';

	/**
	 * Constructor.
	 *
	 * @param VaultPolicyService $policies The vault policies
	 * @param TeamFolderQueryService $teamFolders The ancestor team folder walk
	 * @param SecretTypeMapper $typeMapper Resolves a type id to its name
	 * @param SecretMapper|null $secretMapper The user's own secrets, for the findings list
	 * @param ShareTargetMapper|null $shareTargetMapper Tells a received copy from an own secret
	 *
	 * @return void
	 *
	 * @spec exclude Constructor wiring only.
	 */
	public function __construct(
		private VaultPolicyService $policies,
		private TeamFolderQueryService $teamFolders,
		private SecretTypeMapper $typeMapper,
		private ?SecretMapper $secretMapper = null,
		private ?ShareTargetMapper $shareTargetMapper = null,
	) {
	}//end __construct()

	/**
	 * Refuse a covered secret that would land outside an owned team folder.
	 *
	 * @param string $userId The writing user (the owner of the secret)
	 * @param string $typeId The resolved secret type id
	 * @param string|null $folderId The target folder, null for the vault root
	 *
	 * @return void
	 *
	 * @throws PolicyViolationException When the policy refuses the write
	 *
	 * @spec openspec/specs/vault-policies/spec.md#requirement-work-logins-are-kept-in-team-folders
	 */
	public function assertAllowed(string $userId, string $typeId, ?string $folderId): void {
		if ($this->policies->appliesTo(policy: VaultPolicyService::ORG_OWNERSHIP, userId: $userId) === false) {
			return;
		}

		if (in_array($this->typeName(typeId: $typeId), $this->policies->ownershipTypes(), true) === false) {
			return;
		}

		if ($this->inOwnTeamFolder(userId: $userId, folderId: $folderId) === true) {
			return;
		}

		throw new PolicyViolationException(
			policyCode: self::CODE,
			message: 'Your organisation requires this type of secret to be kept in a team folder'
		);
	}//end assertAllowed()

	/**
	 * A move or a type change must not take a covered secret out of the
	 * user's team folders; other edits are not re-checked.
	 *
	 * @param Secret $secret The secret as it will be stored
	 * @param Secret $before The secret before the update
	 * @param string $userId The writing user
	 *
	 * @return void
	 *
	 * @throws PolicyViolationException When the policy refuses the change
	 *
	 * @spec openspec/specs/vault-policies/spec.md#requirement-work-logins-are-kept-in-team-folders
	 */
	public function assertKept(Secret $secret, Secret $before, string $userId): void {
		if ($secret->getFolderId() === $before->getFolderId() && $secret->getTypeId() === $before->getTypeId()) {
			return;
		}

		$this->assertAllowed(userId: $userId, typeId: (string)$secret->getTypeId(), folderId: $secret->getFolderId());
	}//end assertKept()

	/**
	 * The user's own live secrets that break the ownership policy: covered
	 * type, outside an owned team folder, and not a copy received from
	 * someone else. Metadata only, for the health report (design D6).
	 *
	 * @param string $userId The session user
	 *
	 * @return array<int,array{id:string,name:string,typeId:string,folderId:string|null}>
	 *
	 * @spec openspec/specs/vault-policies/spec.md#requirement-users-see-personal-items-that-break-the-ownership-policy
	 */
	public function findings(string $userId): array {
		if ($this->secretMapper === null
			|| $this->policies->appliesTo(policy: VaultPolicyService::ORG_OWNERSHIP, userId: $userId) === false
		) {
			return [];
		}

		$types = $this->policies->ownershipTypes();
		$findings = [];
		foreach ($this->secretMapper->findByOwner(ownerType: 'user', ownerId: $userId, limit: 100000, state: SecretMapper::STATE_LIVE) as $secret) {
			$typeId = (string)$secret->getTypeId();
			if (in_array($this->typeName(typeId: $typeId), $types, true) === false
				|| $this->inOwnTeamFolder(userId: $userId, folderId: $secret->getFolderId()) === true
				|| $this->isReceivedCopy(secretId: (string)$secret->getId()) === true
			) {
				continue;
			}

			$findings[] = [
				'id' => (string)$secret->getId(),
				'name' => (string)$secret->getName(),
				'typeId' => $typeId,
				'folderId' => $secret->getFolderId(),
			];
		}

		return $findings;
	}//end findings()

	/**
	 * Whether a folder sits in a team folder the user owns.
	 *
	 * @param string $userId The user
	 * @param string|null $folderId The folder, null for the root
	 *
	 * @return bool
	 */
	private function inOwnTeamFolder(string $userId, ?string $folderId): bool {
		if ($folderId === null || $folderId === '') {
			return false;
		}

		foreach ($this->teamFolders->ancestorTeamFolders(folderId: $folderId) as $teamFolder) {
			if ($teamFolder->getOwnerId() === $userId) {
				return true;
			}
		}

		return false;
	}//end inOwnTeamFolder()

	/**
	 * Whether a secret row is a copy someone shared with the user.
	 *
	 * @param string $secretId The secret
	 *
	 * @return bool
	 */
	private function isReceivedCopy(string $secretId): bool {
		try {
			$this->shareTargetMapper?->findByRecipientSecret(recipientSecretId: $secretId);
		} catch (DoesNotExistException) {
			return false;
		}

		return $this->shareTargetMapper !== null;
	}//end isReceivedCopy()

	/**
	 * The name of a type id, '' when it is unknown.
	 *
	 * @param string $typeId The type id
	 *
	 * @return string
	 */
	private function typeName(string $typeId): string {
		try {
			return $this->typeMapper->findById($typeId)->getName();
		} catch (DoesNotExistException) {
			return '';
		}
	}//end typeName()
}//end class
