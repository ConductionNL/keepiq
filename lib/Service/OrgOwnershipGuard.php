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

use OCA\Keepiq\Db\SecretTypeMapper;
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
	 *
	 * @return void
	 *
	 * @spec exclude Constructor wiring only.
	 */
	public function __construct(
		private VaultPolicyService $policies,
		private TeamFolderQueryService $teamFolders,
		private SecretTypeMapper $typeMapper,
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
	 * @spec openspec/changes/admin-vault-policies/tasks.md#4.1
	 */
	public function assertAllowed(string $userId, string $typeId, ?string $folderId): void {
		if ($this->policies->appliesTo(policy: VaultPolicyService::ORG_OWNERSHIP, userId: $userId) === false) {
			return;
		}

		if (in_array($this->typeName(typeId: $typeId), $this->policies->ownershipTypes(), true) === false) {
			return;
		}

		if ($folderId !== null && $folderId !== '') {
			foreach ($this->teamFolders->ancestorTeamFolders(folderId: $folderId) as $teamFolder) {
				if ($teamFolder->getOwnerId() === $userId) {
					return;
				}
			}
		}

		throw new PolicyViolationException(
			policyCode: self::CODE,
			message: 'Your organisation requires this type of secret to be kept in a team folder'
		);
	}//end assertAllowed()

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
