<?php

/**
 * Keepiq ShareWriteContext
 *
 * @category Service
 * @package  OCA\Keepiq\Service\ShareSync
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

namespace OCA\Keepiq\Service\ShareSync;

use DateTime;
use InvalidArgumentException;
use OCA\Keepiq\Db\EncryptionSuiteMapper;
use OCA\Keepiq\Db\Secret;
use OCA\Keepiq\Db\ShareTargetMapper;
use OCA\Keepiq\Db\TeamFolderMember;
use OCA\Keepiq\Service\ShareAuthorizationService;
use OCA\Keepiq\Service\ShareSyncService;
use OCP\AppFramework\Db\DoesNotExistException;
use OCP\AppFramework\Db\MultipleObjectsReturnedException;

/**
 * What a client needs to prepare a sync: the source, the caller's grade on
 * it and the owner's certificate. The grade comes from standingOn(), which
 * ShareSyncService::resolveSyncWriter() authorizes the sync on, so the grade
 * a client is shown is the one the sync checks.
 *
 * @spec openspec/specs/folder-permission-grades/spec.md#requirement-a-write-grade-member-may-update-a-folder-secret-for-all-recipients
 */
class ShareWriteContext {
	/**
	 * Constructor.
	 *
	 * @param ShareTargetMapper $targets The share targets (copy to source)
	 * @param EncryptionSuiteMapper $suites The suites (owner certificate)
	 * @param ShareAuthorizationService $auth The share authorization service
	 *
	 * @return void
	 *
	 * @spec exclude Constructor wiring only.
	 */
	public function __construct(
		private ShareTargetMapper $targets,
		private EncryptionSuiteMapper $suites,
		private ShareAuthorizationService $auth,
	) {
	}//end __construct()

	/**
	 * The caller's grade on a source and, when it lets them write every copy
	 * as someone other than the owner or a delegate, how the audit names that
	 * write: `owner` for the owner or a delegate, a team-folder write grade
	 * (ShareSyncService::TEAM_WRITE), `write` for a member of a group it is
	 * shared with who holds a live copy they may change
	 * (ShareSyncService::GROUP_WRITE), else their team-folder or copy grade.
	 * The grade is null when the caller does not reach the source.
	 *
	 * @param Secret $source The source secret
	 * @param string $userId The requesting user
	 *
	 * @return array{grade:string|null, writesOn:string|null}
	 *
	 * @spec openspec/specs/sharing-group/spec.md#requirement-a-group-member-saves-for-everyone-it-is-shared-with
	 */
	public function standingOn(Secret $source, string $userId): array {
		if ($this->auth->isOwnerOrDelegate(secret: $source, userId: $userId) === true) {
			return ['grade' => 'owner', 'writesOn' => null];
		}

		$grade = $this->auth->resolveGrade(secret: $source, userId: $userId);
		if (in_array($grade, TeamFolderMember::WRITE_GRADES, true) === true) {
			return ['grade' => $grade, 'writesOn' => ShareSyncService::TEAM_WRITE];
		}

		$copyGrade = $this->auth->copyGrade(source: $source, userId: $userId);
		if ($copyGrade === 'write') {
			return ['grade' => $copyGrade, 'writesOn' => ShareSyncService::GROUP_WRITE];
		}

		return ['grade' => ($grade ?? $copyGrade), 'writesOn' => null];
	}//end standingOn()

	/**
	 * The write context of a secret for the current user
	 * (folder-permission-grades §4): resolves a recipient copy back to
	 * its source and reports the caller's effective grade plus the
	 * owner-row material a write-grade member needs to run the
	 * re-encrypt fan-out (the owner's certificate is public key
	 * material). A member of a group the secret is shared with gets the
	 * `write` grade through their copy. Only a caller who reaches the
	 * source gets an answer: its owner, a delegate, a team-folder member
	 * with a grade on it, or the holder of a live copy of it. A caller who
	 * reaches no source through a live row of their own gets ownRowContext().
	 *
	 * @param string $secretId The secret (source or recipient copy) UUID
	 * @param string $userId The requesting user
	 *
	 * @return array{sourceSecretId:string|null, effectiveGrade:string, ownerCertificate:string|null, sourceUpdatedAt:string|null}
	 *
	 * @throws InvalidArgumentException When the secret does not exist or the caller does not reach it
	 *
	 * @spec openspec/specs/folder-permission-grades/spec.md#requirement-a-write-grade-member-may-update-a-folder-secret-for-all-recipients
	 * @spec openspec/specs/sharing-group/spec.md#requirement-a-group-member-saves-for-everyone-it-is-shared-with
	 */
	public function writeContext(string $secretId, string $userId): array {
		$secret = $this->auth->loadSecret(secretId: $secretId);

		// Pivot a recipient copy back to its source.
		$source = $secret;
		try {
			$shareRow = $this->targets->findByRecipientSecret(recipientSecretId: $secretId);
			$source = $this->auth->loadSecret(secretId: $shareRow->getSourceSecretId());
		} catch (DoesNotExistException) {
			// Not a copy — the secret is its own source.
		} catch (MultipleObjectsReturnedException) {
			return $this->ownRowContext(row: $secret, userId: $userId);
		}

		$grade = $this->standingOn(source: $source, userId: $userId)['grade'];
		if ($grade === null) {
			return $this->ownRowContext(row: $secret, userId: $userId);
		}

		$ownerCertificate = null;
		if ($grade === 'owner' || in_array($grade, TeamFolderMember::WRITE_GRADES, true) === true) {
			try {
				$ownerCertificate = $this->suites
					->findActiveByOwner(ownerType: $source->getOwnerType(), ownerId: $source->getOwnerId())
					->getCertificate();
			} catch (DoesNotExistException) {
				// Owner without an active suite — fan-out skips the source row.
			}
		}

		return [
			'sourceSecretId' => $source->getId(),
			'effectiveGrade' => $grade,
			'ownerCertificate' => $ownerCertificate,
			'sourceUpdatedAt' => $source->getUpdatedAt()?->format(DateTime::ATOM),
		];
	}//end writeContext()

	/**
	 * The write context of a row whose share rows lead to no source the
	 * caller stands on: two share rows for one copy or source, or a share row
	 * keyed to someone else. The holder of a live row still gets `none`, so
	 * their client saves that row alone. The answer names no source, as the
	 * share row may point at someone else's secret. Anyone else gets the
	 * answer of an unknown id, and so does the owner of a row that is itself
	 * shared, as saving it alone would leave its copies behind.
	 *
	 * @param Secret $row The secret the caller asked about
	 * @param string $userId The requesting user
	 *
	 * @return array{sourceSecretId:null, effectiveGrade:string, ownerCertificate:null, sourceUpdatedAt:null}
	 *
	 * @throws InvalidArgumentException When the row is not the caller's, its access ended, or it has copies
	 *
	 * @spec openspec/specs/sharing-group/spec.md#requirement-a-group-member-saves-for-everyone-it-is-shared-with
	 */
	private function ownRowContext(Secret $row, string $userId): array {
		$ends = $row->getAccessExpiresAt();
		if ($row->getOwnerType() !== 'user' || $row->getOwnerId() !== $userId
			|| ($ends !== null && $ends->getTimestamp() <= time())
			|| $this->targets->findBySourceSecret($row->getId()) !== []
		) {
			throw new InvalidArgumentException(message: 'Secret not found');
		}

		return [
			'sourceSecretId' => null,
			'effectiveGrade' => 'none',
			'ownerCertificate' => null,
			'sourceUpdatedAt' => null,
		];
	}//end ownRowContext()
}//end class
