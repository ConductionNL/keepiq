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
use OCA\Keepiq\Db\TeamFolderMember;
use OCA\Keepiq\Exception\ShareScanPendingException;
use OCA\Keepiq\Service\ShareAuthorizationService;
use OCA\Keepiq\Service\ShareRowJudge;
use OCA\Keepiq\Service\ShareRowVerdict;
use OCA\Keepiq\Service\ShareSyncService;
use OCP\AppFramework\Db\DoesNotExistException;

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
	 * @param ShareRowJudge $rowJudge Judged share rows (ShareRowJudge); every lookup of a share row goes through it
	 * @param EncryptionSuiteMapper $suites The suites (owner certificate)
	 * @param ShareAuthorizationService $auth The share authorization service
	 *
	 * @return void
	 *
	 * @spec exclude Constructor wiring only.
	 */
	public function __construct(
		private ShareRowJudge $rowJudge,
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
	 * Whether a standing from standingOn() lets the caller write every copy:
	 * as the owner or a delegate, through a team-folder write grade, or as a
	 * member of a group the source is shared with.
	 *
	 * @param array{grade:string|null, writesOn:string|null} $standing The caller's standing on a source
	 *
	 * @return bool
	 *
	 * @spec openspec/specs/sharing-group/spec.md#requirement-a-group-member-saves-for-everyone-it-is-shared-with
	 */
	public function writesForEveryone(array $standing): bool {
		return $standing['grade'] === 'owner' || $standing['writesOn'] !== null;
	}//end writesForEveryone()

	/**
	 * Whether the user writes every copy of the source other than through a
	 * group share: as the owner or a delegate, or through a team-folder write
	 * grade, even one that comes through a group. Group shares never count, as
	 * memberships can arrive together in one directory sync or through nested
	 * groups; a team-folder grade gives the write on its own, so counting it
	 * never silences a group share's alert.
	 *
	 * @param Secret $source The source secret
	 * @param string $userId The candidate user
	 *
	 * @return bool
	 *
	 * @spec openspec/specs/sharing-group/spec.md#requirement-a-group-member-saves-for-everyone-it-is-shared-with
	 */
	public function writesForEveryoneWithoutGroupShares(Secret $source, string $userId): bool {
		$standing = $this->standingOn(source: $source, userId: $userId);

		return $standing['grade'] === 'owner' || $standing['writesOn'] === ShareSyncService::TEAM_WRITE;
	}//end writesForEveryoneWithoutGroupShares()

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
	 * reaches no source through a live row of their own gets ownRowContext(),
	 * and so does one whose share row's target does not hold the row, or that
	 * the pending quarantine scan cannot judge. A row that scan quarantines is
	 * no copy, as any quarantined row.
	 *
	 * @param string $secretId The secret (source or recipient copy) UUID
	 * @param string $userId The requesting user
	 *
	 * @return array{sourceSecretId:string|null, effectiveGrade:string, ownerCertificate:string|null, sourceUpdatedAt:string|null}
	 *
	 * @throws InvalidArgumentException When the secret does not exist or the caller does not reach it
	 * @throws ShareScanPendingException When the caller's row has copies and the row naming it waits for the scan
	 *
	 * @spec openspec/specs/folder-permission-grades/spec.md#requirement-a-write-grade-member-may-update-a-folder-secret-for-all-recipients
	 * @spec openspec/specs/sharing-group/spec.md#requirement-a-group-member-saves-for-everyone-it-is-shared-with
	 * @spec openspec/specs/user-sharing/spec.md#requirement-runtime-paths-follow-only-judged-share-rows
	 */
	public function writeContext(string $secretId, string $userId): array {
		$secret = $this->auth->loadSecret(secretId: $secretId);

		// Pivot a recipient copy back to its source.
		$verdict = $this->rowJudge->forCopy(copy: $secret);
		$source = $this->sourceOf(secret: $secret, verdict: $verdict);
		if ($source === null) {
			return $this->ownRowContext(
				row: $secret,
				userId: $userId,
				unjudged: ($verdict->state === ShareRowVerdict::UNJUDGED)
			);
		}

		$grade = $this->standingOn(source: $source, userId: $userId)['grade'];
		if ($grade === null) {
			return $this->ownRowContext(row: $secret, userId: $userId, unjudged: false);
		}

		return [
			'sourceSecretId' => $source->getId(),
			'effectiveGrade' => $grade,
			'ownerCertificate' => $this->ownerCertificateFor(source: $source, grade: $grade),
			'sourceUpdatedAt' => $source->getUpdatedAt()?->format(DateTime::ATOM),
		];
	}//end writeContext()

	/**
	 * The source owner's certificate for a caller who writes the source: the
	 * owner or a write grade. Null for anyone else, and when the owner has no
	 * active suite (fan-out then skips the source row).
	 *
	 * @param Secret $source The source secret
	 * @param string $grade  The caller's grade on it
	 *
	 * @return string|null
	 *
	 * @spec openspec/specs/folder-permission-grades/spec.md#requirement-a-write-grade-member-may-update-a-folder-secret-for-all-recipients
	 */
	private function ownerCertificateFor(Secret $source, string $grade): ?string {
		if ($grade !== 'owner' && in_array($grade, TeamFolderMember::WRITE_GRADES, true) === false) {
			return null;
		}

		try {
			return $this->suites
				->findActiveByOwner(ownerType: $source->getOwnerType(), ownerId: $source->getOwnerId())
				->getCertificate();
		} catch (DoesNotExistException) {
			return null;
		}
	}//end ownerCertificateFor()

	/**
	 * The source a save of a secret goes to: the secret itself when no live
	 * share row names it as a copy, or when the pending quarantine scan
	 * quarantines that row now, as any quarantined row; the row's source when
	 * the row is trusted (ShareRowJudge). Null when the row leads to no
	 * source: two rows name the secret, the row's target does not hold it, or
	 * the pending scan cannot judge it.
	 *
	 * @param Secret          $secret  The secret the caller asked about
	 * @param ShareRowVerdict $verdict The judge's verdict on the row naming it
	 *
	 * @return Secret|null
	 *
	 * @throws InvalidArgumentException When the row's source does not exist
	 *
	 * @spec openspec/specs/user-sharing/spec.md#requirement-runtime-paths-follow-only-judged-share-rows
	 */
	private function sourceOf(Secret $secret, ShareRowVerdict $verdict): ?Secret {
		if ($verdict->state === ShareRowVerdict::NONE || $verdict->state === ShareRowVerdict::QUARANTINED) {
			// Not a copy — the secret is its own source.
			return $secret;
		}

		$shareRow = $verdict->trustedRow();
		if ($shareRow === null) {
			return null;
		}

		return $this->auth->loadSecret(secretId: $shareRow->getSourceSecretId());
	}//end sourceOf()

	/**
	 * The write context of a row whose share rows lead to no source the
	 * caller stands on: two share rows for one copy or source, a share row
	 * keyed to someone else, or one the pending quarantine scan cannot judge.
	 * The holder of a live row still gets `none`, so their client saves that
	 * row alone. The answer names no source, as the
	 * share row may point at someone else's secret. Anyone else gets the
	 * answer of an unknown id, and so does the owner of a row that is itself
	 * shared, as saving it alone would leave its copies behind; when the row
	 * naming it is one the pending scan cannot judge, its holder is told the
	 * row waits for the scan instead, as share-save tells them.
	 *
	 * @param Secret $row The secret the caller asked about
	 * @param string $userId The requesting user
	 * @param bool $unjudged Whether the row naming it is one the pending scan cannot judge
	 *
	 * @return array{sourceSecretId:null, effectiveGrade:string, ownerCertificate:null, sourceUpdatedAt:null}
	 *
	 * @throws InvalidArgumentException When the row is not the caller's, its access ended, or it has copies
	 * @throws ShareScanPendingException When the caller's row has copies and the row naming it waits for the scan
	 *
	 * @spec openspec/specs/sharing-group/spec.md#requirement-a-group-member-saves-for-everyone-it-is-shared-with
	 * @spec openspec/specs/user-sharing/spec.md#requirement-runtime-paths-follow-only-judged-share-rows
	 */
	private function ownRowContext(Secret $row, string $userId, bool $unjudged): array {
		$ends = $row->getAccessExpiresAt();
		if ($row->getOwnerType() !== 'user' || $row->getOwnerId() !== $userId
			|| ($ends !== null && $ends->getTimestamp() <= time())
		) {
			throw new InvalidArgumentException(message: 'Secret not found');
		}

		if ($this->rowJudge->rowsOfSource(sourceId: $row->getId())->all() !== []) {
			if ($unjudged === true) {
				throw $this->rowJudge->scanPending(refusal: 'This secret waits for the share quarantine scan and cannot be saved yet');
			}

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
