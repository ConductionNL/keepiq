<?php

/**
 * Keepiq Team Folder Contribution Service
 *
 * A member with an effective `write` grade saves a NEW secret straight into a
 * team folder they do not own (admin-vault-policies D5). This is what lets a
 * member who owns no team folder comply with the ownership policy.
 *
 * The member's browser encrypts the value under the folder owner's
 * certificate (the owner row, write without read) and under every effective
 * member's certificate, the member's own included. The server authorises on
 * the grade, stores the owner row as owned by the folder owner, registers the
 * derived copies, and audits the creation with the member as actor. It never
 * decrypts anything. A `write` grade already lets the member change every
 * copy, so this adds no trust.
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
use InvalidArgumentException;
use OCA\Keepiq\Db\EncryptionSuiteMapper;
use OCA\Keepiq\Db\FolderMapper;
use OCA\Keepiq\Db\Secret;
use OCA\Keepiq\Db\SecretMapper;
use OCA\Keepiq\Db\TeamFolder;
use OCA\Keepiq\Db\TeamFolderMapper;
use OCA\Keepiq\Event\Audit\AuditEventFactory;
use OCA\Keepiq\Event\Audit\AuditEventTypes;
use OCA\Keepiq\Exception\ForbiddenException;
use OCA\Keepiq\Exception\NotFoundException;
use OCP\AppFramework\Db\DoesNotExistException;
use OCP\EventDispatcher\IEventDispatcher;
use Ramsey\Uuid\Uuid;
use Throwable;

/**
 * Stores a write-grade member's new secret in a team folder.
 *
 * @SuppressWarnings(PHPMD.CouplingBetweenObjects) The contribution joins the
 *   team folder, grade, suite, secret and share sides in one write.
 */
class TeamFolderContributionService {
	/**
	 * Constructor.
	 *
	 * @param TeamFolderMapper $teamFolderMapper The team folder mapper
	 * @param FolderMapper $folderMapper The folder subtree
	 * @param TeamFolderQueryService $queries Effective grades
	 * @param TeamFolderMembershipResolver $memberships Covered, eligible members
	 * @param TeamFolderShareService $shares Registers the member copies
	 * @param SecretMapper $secretMapper Stores the owner row
	 * @param EncryptionSuiteMapper $suiteMapper The owner's active suite
	 * @param SecretTypeService $typeService Resolves the type for the owner
	 * @param IEventDispatcher|null $eventDispatcher The audit dispatcher
	 *
	 * @return void
	 *
	 * @spec exclude Constructor wiring only.
	 */
	public function __construct(
		private TeamFolderMapper $teamFolderMapper,
		private FolderMapper $folderMapper,
		private TeamFolderQueryService $queries,
		private TeamFolderMembershipResolver $memberships,
		private TeamFolderShareService $shares,
		private SecretMapper $secretMapper,
		private EncryptionSuiteMapper $suiteMapper,
		private SecretTypeService $typeService,
		private ?IEventDispatcher $eventDispatcher = null,
	) {
	}//end __construct()

	/**
	 * Store a member's new secret in a team folder.
	 *
	 * @param string $teamFolderId The team folder
	 * @param array<string,mixed> $data name, url, typeId, folderId, key, login,
	 *                                  additionalFields (owner ciphertext) and
	 *                                  copies (rows per member)
	 * @param string $userId The contributing member
	 *
	 * @return array{secret:Secret,copies:int}
	 *
	 * @throws NotFoundException When the team folder or target folder is unknown
	 * @throws ForbiddenException When the caller holds no write grade there
	 * @throws InvalidArgumentException On a missing name or owner ciphertext
	 *
	 * @spec openspec/specs/vault-policies/spec.md#requirement-write-grade-members-save-new-secrets-into-a-team-folder
	 */
	public function contribute(string $teamFolderId, array $data, string $userId): array {
		$teamFolder = $this->loadTeamFolder(teamFolderId: $teamFolderId);
		$folderId = $this->targetFolder(teamFolder: $teamFolder, requested: $data['folderId'] ?? null);

		// Authorise on the grade of the target folder, before anything is read
		// or written. The owner uses the ordinary secret create.
		if ($this->mayContribute(teamFolder: $teamFolder, folderId: $folderId, userId: $userId) === false) {
			throw new ForbiddenException(message: 'Only a member with write access can add a secret to this team folder');
		}

		$name = trim((string)($data['name'] ?? ''));
		$key = (string)($data['key'] ?? '');
		if ($name === '' || $key === '') {
			throw new InvalidArgumentException('A secret requires a name and a key');
		}

		$ownerId = $teamFolder->getOwnerId();
		try {
			$suite = $this->suiteMapper->findActiveByOwner(ownerType: 'user', ownerId: $ownerId);
		} catch (DoesNotExistException) {
			throw new InvalidArgumentException('The team folder owner has no active vault');
		}

		$typeId = $this->typeService->resolveTypeForSecret($data['typeId'] ?? null, $ownerId);
		$now = new DateTime();
		$secret = new Secret();
		$secret->setId(Uuid::uuid4()->toString());
		$secret->setName($name);
		$secret->setUrl($this->nullableString(value: $data['url'] ?? null));
		$secret->setTypeId($typeId);
		$secret->setFolderId($folderId);
		$secret->setKey($key);
		$secret->setLogin($this->nullableString(value: $data['login'] ?? null));
		$secret->setAdditionalFields($this->nullableString(value: $data['additionalFields'] ?? null));
		$secret->setEncryptionSuiteId($suite->getId());
		$secret->setOwnerType('user');
		$secret->setOwnerId($ownerId);
		$secret->setCreatedAt($now);
		$secret->setUpdatedAt($now);
		$secret->setKeyUpdatedAt($now);
		$this->secretMapper->insert($secret);

		try {
			$result = $this->shares->registerFanOutShares(
				teamFolder: $teamFolder,
				shares: $this->memberCopies(teamFolder: $teamFolder, secretId: $secret->getId(), copies: (array)($data['copies'] ?? [])),
				subtreeSecretIds: [$secret->getId() => true],
				userId: $userId
			);
		} catch (Throwable $exception) {
			// No owner row without its copies: undo, then report.
			$this->secretMapper->delete($secret);
			throw $exception;
		}

		$this->eventDispatcher?->dispatchTyped(
			(new AuditEventFactory())->forUser(
				actorId: $userId,
				eventType: AuditEventTypes::SECRET_CREATED,
				objectType: 'secret',
				objectId: $secret->getId(),
				objectName: $secret->getName(),
				metadata: ['typeId' => $typeId, 'folderId' => $folderId],
			)
		);

		return ['secret' => $secret, 'copies' => $result['created']];
	}//end contribute()

	/**
	 * The team folders the user may contribute to: covered by a membership
	 * row, not owned, with an effective `write` grade on the folder itself.
	 *
	 * @param string $userId The session user
	 *
	 * @return array<int,array{teamFolderId:string,folderId:string,folderName:string}>
	 *
	 * @spec openspec/specs/vault-policies/spec.md#requirement-write-grade-members-save-new-secrets-into-a-team-folder
	 */
	public function contributable(string $userId): array {
		$found = [];
		foreach ($this->memberships->membershipRowsForUser(userId: $userId) as $row) {
			$teamFolderId = (string)$row->getTeamFolderId();
			if (isset($found[$teamFolderId]) === true) {
				continue;
			}

			try {
				$teamFolder = $this->teamFolderMapper->findById(id: $teamFolderId);
			} catch (DoesNotExistException) {
				continue;
			}

			if ($this->mayContribute(teamFolder: $teamFolder, folderId: $teamFolder->getFolderId(), userId: $userId) === false) {
				continue;
			}

			$folderName = '';
			try {
				$folderName = $this->folderMapper->findById($teamFolder->getFolderId())->getName();
			} catch (DoesNotExistException) {
				// Folder vanished: keep the entry with an empty name.
			}

			$found[$teamFolderId] = [
				'teamFolderId' => $teamFolderId,
				'folderId' => $teamFolder->getFolderId(),
				'folderName' => $folderName,
			];
		}//end foreach

		return array_values($found);
	}//end contributable()

	/**
	 * What a write-grade member's browser needs to encrypt a contribution:
	 * the owner's certificate and every eligible member's certificate.
	 * Public key material only.
	 *
	 * @param string $teamFolderId The team folder
	 * @param string $userId The contributing member
	 *
	 * @return array{teamFolderId:string,folderId:string,ownerCertificate:string,recipients:array<int,array{userId:string,certificate:string}>}
	 *
	 * @throws NotFoundException When the team folder is unknown
	 * @throws ForbiddenException When the caller holds no write grade there
	 *
	 * @spec openspec/specs/vault-policies/spec.md#requirement-write-grade-members-save-new-secrets-into-a-team-folder
	 */
	public function context(string $teamFolderId, string $userId): array {
		$teamFolder = $this->loadTeamFolder(teamFolderId: $teamFolderId);
		if ($this->mayContribute(teamFolder: $teamFolder, folderId: $teamFolder->getFolderId(), userId: $userId) === false) {
			throw new ForbiddenException(message: 'Only a member with write access can add a secret to this team folder');
		}

		try {
			$ownerCertificate = (string)$this->suiteMapper
				->findActiveByOwner(ownerType: 'user', ownerId: $teamFolder->getOwnerId())
				->getCertificate();
		} catch (DoesNotExistException) {
			throw new NotFoundException(message: 'The team folder owner has no active vault');
		}

		return [
			'teamFolderId' => $teamFolder->getId(),
			'folderId' => $teamFolder->getFolderId(),
			'ownerCertificate' => $ownerCertificate,
			'recipients' => $this->memberships->eligibleRecipients(
				userIds: array_values(
					array_diff(
						$this->memberships->effectiveUsers(teamFolderId: $teamFolder->getId()),
						[$teamFolder->getOwnerId()]
					)
				)
			),
		];
	}//end context()

	/**
	 * Whether the user may contribute to a folder of a team folder: not the
	 * owner, and an effective `write` grade on that folder.
	 *
	 * @param TeamFolder $teamFolder The team folder
	 * @param string $folderId The target folder
	 * @param string $userId The user
	 *
	 * @return bool
	 *
	 * @spec openspec/specs/vault-policies/spec.md#requirement-write-grade-members-save-new-secrets-into-a-team-folder
	 */
	private function mayContribute(TeamFolder $teamFolder, string $folderId, string $userId): bool {
		if ($teamFolder->getOwnerId() === $userId) {
			return false;
		}

		$probe = new Secret();
		$probe->setFolderId($folderId);

		return $this->queries->resolveGrade(secret: $probe, userId: $userId) === 'write';
	}//end mayContribute()

	/**
	 * The copy rows the server accepts: one per covered, enabled member with
	 * an active suite, never for the owner, for this new secret only.
	 *
	 * @param TeamFolder $teamFolder The team folder
	 * @param string $secretId The new owner row
	 * @param array<int,mixed> $copies The submitted rows
	 *
	 * @return array<int,array<string,mixed>>
	 *
	 * @spec openspec/specs/vault-policies/spec.md#requirement-write-grade-members-save-new-secrets-into-a-team-folder
	 */
	private function memberCopies(TeamFolder $teamFolder, string $secretId, array $copies): array {
		$eligible = [];
		$candidates = array_values(
			array_diff(
				$this->memberships->effectiveUsers(teamFolderId: $teamFolder->getId()),
				[$teamFolder->getOwnerId()]
			)
		);
		foreach ($this->memberships->eligibleRecipients(userIds: $candidates) as $recipient) {
			$eligible[$recipient['userId']] = true;
		}

		$rows = [];
		foreach ($copies as $copy) {
			if (is_array($copy) === false || isset($eligible[(string)($copy['targetUserId'] ?? '')]) === false) {
				continue;
			}

			$rows[] = [
				'sourceSecretId' => $secretId,
				'targetUserId' => (string)$copy['targetUserId'],
				'encryptedKey' => (string)($copy['encryptedKey'] ?? ''),
				'encryptedLogin' => ($copy['encryptedLogin'] ?? null),
				'encryptedAdditionalFields' => ($copy['encryptedAdditionalFields'] ?? null),
			];
		}

		return $rows;
	}//end memberCopies()

	/**
	 * The target folder: the team folder itself, or a folder in its subtree.
	 *
	 * @param TeamFolder $teamFolder The team folder
	 * @param mixed $requested The requested folder id
	 *
	 * @return string
	 *
	 * @throws NotFoundException When the folder is outside the team folder
	 */
	private function targetFolder(TeamFolder $teamFolder, mixed $requested): string {
		if ($requested === null || $requested === '') {
			return $teamFolder->getFolderId();
		}

		$subtree = array_map('strval', $this->folderMapper->getSubtreeIds(folderId: $teamFolder->getFolderId()));
		if (in_array((string)$requested, $subtree, true) === false) {
			throw new NotFoundException(message: 'Folder not found in this team folder');
		}

		return (string)$requested;
	}//end targetFolder()

	/**
	 * Load a team folder.
	 *
	 * @param string $teamFolderId The team folder id
	 *
	 * @return TeamFolder
	 *
	 * @throws NotFoundException When it does not exist
	 */
	private function loadTeamFolder(string $teamFolderId): TeamFolder {
		try {
			return $this->teamFolderMapper->findById(id: $teamFolderId);
		} catch (DoesNotExistException) {
			throw new NotFoundException(message: 'Team folder not found');
		}
	}//end loadTeamFolder()

	/**
	 * An empty value as null.
	 *
	 * @param mixed $value The value
	 *
	 * @return string|null
	 */
	private function nullableString(mixed $value): ?string {
		if ($value === null || $value === '') {
			return null;
		}

		return (string)$value;
	}//end nullableString()
}//end class
