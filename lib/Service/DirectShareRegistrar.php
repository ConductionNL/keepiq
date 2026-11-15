<?php

/**
 * Keepiq Direct Share Registrar
 *
 * Bulk registration of DIRECT user-to-user shares from client-encrypted
 * blobs (bulk-actions §6.1/§7.1). For each row the server creates the
 * recipient's Secret copy from the pre-encrypted fields and links the
 * ShareTarget — idempotent, owner-scoped per item, and skip-not-fail so a
 * mixed selection never aborts the run. The server never sees plaintext
 * (ADR-003).
 *
 * Extracted from ShareService: bulk registration has its own report
 * shape, its own per-item guard vocabulary and its own recipient-copy
 * builder, none of which the single-share lifecycle uses.
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

use DateTime;
use DateTimeZone;
use InvalidArgumentException;
use OCA\Keepiq\Db\GroupShareMapper;
use OCA\Keepiq\Db\Secret;
use OCA\Keepiq\Db\SecretMapper;
use OCA\Keepiq\Db\ShareTarget;
use OCA\Keepiq\Db\ShareTargetMapper;
use OCA\Keepiq\Exception\ForbiddenException;
use OCA\Keepiq\Exception\ShareScanPendingException;
use OCP\AppFramework\Db\DoesNotExistException;
use OCP\DB\Exception as DbException;
use OCP\IDBConnection;
use Ramsey\Uuid\Uuid;
use Throwable;

/**
 * Registers batches of pre-encrypted direct shares.
 *
 * @SuppressWarnings(PHPMD.CouplingBetweenObjects) The registrar validates each
 *   row (owner, restriction, idempotency) and writes the copy, the share row,
 *   the restriction and the audit entry; each collaborator is one of those.
 * @SuppressWarnings(PHPMD.ExcessiveClassComplexity) Same cause: each refusal of a row is a status the batch reports,
 *   and the copy is minted and linked in one transaction (GHSA-m5p9-6pw7-pf3g).
 */
class DirectShareRegistrar {

	/**
	 * The share audit trail.
	 *
	 * @var ShareAuditTrail
	 */
	private ShareAuditTrail $auditTrail;

	/**
	 * Constructor for DirectShareRegistrar.
	 *
	 * @param ShareTargetMapper $mapper The share-target mapper
	 * @param ShareRowJudge $rowJudge Judged share rows (ShareRowJudge); every lookup of a share row goes through it
	 * @param SecretMapper $secretMapper The Secret mapper (owner lookups)
	 * @param RecipientSecretCopyFactory $copyFactory The recipient-copy factory
	 * @param NotificationService $notificationService The notification dispatcher
	 * @param ShareAuditTrail|null $auditTrail The share audit trail
	 * @param GroupShareMapper|null $groupShareMapper The group-share mapper (rows linked to a group share)
	 * @param ShareRestrictionResolver|null $restrictions Materialises use-only and end dates onto copies
	 * @param ShareAuthorizationService|null $authorization Owner-or-delegate check on the source secret
	 * @param IDBConnection|null $connection Wraps the copy mint and link insert in one transaction
	 *
	 * @return void
	 *
	 * @spec exclude Constructor wiring only; the registration behaviour carries the spec anchors.
	 *
	 * @SuppressWarnings(PHPMD.ExcessiveParameterList) Constructor DI list: the registrar reads and writes share rows
	 *   (the mapper and the required judge), the copies and the restrictions, and audits and notifies each share.
	 */
	public function __construct(
		private ShareTargetMapper $mapper,
		private ShareRowJudge $rowJudge,
		private SecretMapper $secretMapper,
		private RecipientSecretCopyFactory $copyFactory,
		private NotificationService $notificationService,
		?ShareAuditTrail $auditTrail = null,
		private ?GroupShareMapper $groupShareMapper = null,
		private ?ShareRestrictionResolver $restrictions = null,
		private ?ShareAuthorizationService $authorization = null,
		private ?IDBConnection $connection = null,
	) {
		$this->auditTrail = ($auditTrail ?? new ShareAuditTrail());
	}//end __construct()

	/**
	 * Register a batch of DIRECT user-to-user shares from client-encrypted
	 * blobs: idempotent (an existing share reports `exists`), owner-scoped
	 * per item, and skip-not-fail for ineligible rows. Mirrors the
	 * team-folder fan-out registration.
	 *
	 * @param string $userId The sharing owner
	 * @param array<int,array<string,mixed>> $shares Rows {sourceSecretId, targetUserId, encryptedKey,
	 *   encryptedLogin?, encryptedAdditionalFields?, encryptedTotp?, encryptedPasskeys?, groupShareId?, useOnly?, expiresAt?}
	 *
	 * @return array<int,array{sourceSecretId:string,targetUserId:string,status:string,recipientSecretId?:string}>
	 *
	 * @spec openspec/specs/bulk-actions/spec.md#requirement-the-four-bulk-operations
	 * @spec openspec/specs/sharing-group/spec.md#requirement-share-with-a-group
	 * @spec openspec/changes/archive/2026-10-04-sharing-use-only-and-expiring-shares/tasks.md#task-2.1
	 */
	public function registerDirectShares(string $userId, array $shares): array {
		$report = [];
		$newRecipients = [];
		foreach ($shares as $row) {
			$entry = $this->registerDirectShare(userId: $userId, row: $row);
			$report[] = $entry;
			if ($entry['status'] === 'created') {
				$newRecipients[$entry['targetUserId']] = true;
			}
		}

		// One notification per recipient per run — not per secret.
		foreach (array_keys($newRecipients) as $recipientId) {
			$this->notificationService->notify(
				subject: 'secret_shared',
				recipientId: (string)$recipientId,
				params: ['shared_by' => $userId],
			);
		}

		return $report;
	}//end registerDirectShares()

	/**
	 * The active-suite PEM certificate of a share recipient — public key
	 * material only (needed client-side to encrypt the copy; ADR-003).
	 *
	 * @param string $targetUserId The prospective recipient
	 *
	 * @return string|null The PEM certificate (null = no active suite)
	 *
	 * @spec openspec/specs/user-sharing/spec.md#requirement-share-a-secret
	 */
	public function recipientCertificate(string $targetUserId): ?string {
		return $this->copyFactory->certificateFor(targetUserId: $targetUserId);
	}//end recipientCertificate()

	/**
	 * The active certificates of several prospective recipients.
	 *
	 * @param string[] $targetUserIds The prospective recipients
	 *
	 * @return array<string,string> PEM certificate, keyed by user ID
	 *
	 * @spec openspec/specs/user-sharing/spec.md#requirement-recipient-shareability-lookup
	 */
	public function recipientCertificates(array $targetUserIds): array {
		return $this->copyFactory->certificatesFor(targetUserIds: $targetUserIds);
	}//end recipientCertificates()

	/**
	 * Validate one bulk-share row and, when it is well-formed, register it.
	 *
	 * Skip-not-fail: every rejection is reported as a status so a mixed
	 * selection never aborts the run.
	 *
	 * @param string $userId The sharing owner
	 * @param array<string,mixed> $row One {sourceSecretId, targetUserId, encryptedKey, ...} row
	 *
	 * @return array{sourceSecretId:string,targetUserId:string,status:string,recipientSecretId?:string}
	 */
	private function registerDirectShare(string $userId, array $row): array {
		$share = new DirectShareRow(
			userId: $userId,
			sourceSecretId: (string)($row['sourceSecretId'] ?? ''),
			targetUserId: (string)($row['targetUserId'] ?? ''),
			encryptedKey: (string)($row['encryptedKey'] ?? ''),
			row: $row,
		);
		if ($share->isIncomplete() === true) {
			return $share->outcome(status: 'invalid');
		}

		if ($share->targetUserId === $userId) {
			return $share->outcome(status: 'self');
		}

		return $this->createDirectShare(share: $share);
	}//end registerDirectShare()

	/**
	 * Create the recipient copy and the ShareTarget row for one validated
	 * bulk-share row, enforcing the owner guard and idempotency.
	 *
	 * @param DirectShareRow $share The validated row
	 *
	 * @return array{sourceSecretId:string,targetUserId:string,status:string,recipientSecretId?:string}
	 */
	private function createDirectShare(DirectShareRow $share): array {
		// Per-item owner guard: a foreign secret is skipped, never a
		// whole-batch failure and never an oracle. A use-only or expiring
		// copy is never a share source (D4), and a malformed restriction is
		// reported, not guessed.
		$source = $this->loadOwnedSource(sourceSecretId: $share->sourceSecretId, userId: $share->userId);
		$restriction = $this->parseRestriction(row: $share->row);
		$refusal = $this->refusalFor(source: $source, restriction: $restriction, userId: $share->userId, row: $share->row);
		if ($refusal !== null || $source === null || $restriction === null) {
			return $share->outcome(status: ($refusal ?? 'invalid'));
		}

		$mint = new DirectShareMint(share: $share, source: $source, restriction: $restriction);
		if ($this->alreadyShared(mint: $mint) === true) {
			return $share->outcome(status: 'exists');
		}

		$copy = $this->mintAndLink(mint: $mint);
		if (is_string($copy) === true) {
			return $share->outcome(status: $copy);
		}

		$this->auditTrail->recordBulkShareGranted(
			userId: $share->userId,
			sourceSecretId: $source->getId(),
			secretName: $source->getName(),
			recipientId: $share->targetUserId,
		);

		return $share->created(copy: $copy);
	}//end createDirectShare()

	/**
	 * Idempotency: a live share of the source with this recipient, of any
	 * provenance, is `exists`; a quarantined row does not count.
	 *
	 * @param DirectShareMint $mint The row and its resolved source
	 *
	 * @return bool
	 */
	private function alreadyShared(DirectShareMint $mint): bool {
		try {
			$this->mapper->findBySourceSecretAndTargetUser(
				sourceSecretId: $mint->source->getId(),
				targetUserId: $mint->share->targetUserId
			);
			return true;
		} catch (DoesNotExistException) {
			// No existing share — create below.
			return false;
		}
	}//end alreadyShared()

	/**
	 * Mint the recipient copy, link it and give it its restriction in one
	 * transaction: if the link insert fails the minted copy is rolled back
	 * with it, so a decryptable copy is never left behind with no share row to
	 * revoke it, and a use-only or expiring share never commits a full copy
	 * (GHSA-m5p9-6pw7-pf3g).
	 *
	 * @param DirectShareMint $mint The row, its resolved source and its restriction
	 *
	 * @return Secret|string The copy, or the status when neither it nor its row was written:
	 *   `copy_fields_mismatch` when the row's totp or passkeys does not mirror the source's, `no_suite`, `exists`
	 */
	private function mintAndLink(DirectShareMint $mint): Secret|string {
		// A copy without the source's TOTP seed or passkeys, or with ones it
		// does not hold, would show the recipient another value.
		if ((new CredentialFields())->notMirrored(source: $mint->source, copy: $mint->share->carriedFields()) !== []) {
			return 'copy_fields_mismatch';
		}

		$this->connection?->beginTransaction();
		try {
			$copy = $this->mintCopy(mint: $mint);
			if ($copy === null) {
				$this->connection?->rollBack();
				return 'no_suite';
			}

			$persisted = $this->mapper->insert($this->shareTargetFor(mint: $mint, copy: $copy));
			$this->restrictions?->resolveMinted(target: $persisted);
			$this->connection?->commit();
		} catch (Throwable $exception) {
			$this->connection?->rollBack();
			// Defensive guard for the unique index on secret_id: if a copy is
			// somehow already linked to a share, report it as an existing share
			// rather than a 500 — the copy minted above is gone with the rollback.
			if ($exception instanceof DbException
				&& $exception->getReason() === DbException::REASON_UNIQUE_CONSTRAINT_VIOLATION
			) {
				return 'exists';
			}

			throw $exception;
		}//end try

		return $copy;
	}//end mintAndLink()

	/**
	 * Mint the recipient's copy of the source from the row's ciphertexts.
	 *
	 * @param DirectShareMint $mint The row and its resolved source
	 *
	 * @return Secret|null The copy, or null when the recipient has no active suite
	 */
	private function mintCopy(DirectShareMint $mint): ?Secret {
		$row = $mint->share->row;

		return $this->copyFactory->create(
			source: $mint->source,
			targetUserId: $mint->share->targetUserId,
			encryptedKey: $mint->share->encryptedKey,
			encryptedLogin: $this->optionalString(value: ($row['encryptedLogin'] ?? null)),
			encryptedExtras: $this->optionalString(value: ($row['encryptedAdditionalFields'] ?? null)),
			encryptedTotp: $this->optionalString(value: ($row['encryptedTotp'] ?? null)),
			encryptedPasskeys: $this->optionalString(value: ($row['encryptedPasskeys'] ?? null)),
		);
	}//end mintCopy()

	/**
	 * The ShareTarget row linking the source to the recipient's copy. A
	 * group-linked row takes its restriction from the group share.
	 *
	 * @param DirectShareMint $mint The row, its resolved source and its restriction
	 * @param Secret $copy The recipient's copy
	 *
	 * @return ShareTarget
	 */
	private function shareTargetFor(DirectShareMint $mint, Secret $copy): ShareTarget {
		$entity = new ShareTarget();
		$entity->setId(Uuid::uuid4()->toString());
		$entity->setSourceSecretId($mint->source->getId());
		$entity->setTargetUserId($mint->share->targetUserId);
		$entity->setSecretId($copy->getId());
		$entity->setGroupShareId($this->optionalString(value: ($mint->share->row['groupShareId'] ?? null)));
		$entity->setCreatedBy($mint->share->userId);
		$entity->setCreatedAt(new DateTime());
		if ($entity->getGroupShareId() === null) {
			$entity->setUseOnly($mint->restriction->useOnly);
			$entity->setExpiresAt($mint->restriction->expiresAt);
		}

		return $entity;
	}//end shareTargetFor()

	/**
	 * The source secret the caller may share — one they own or hold an active
	 * delegation on — or null. An active delegate may create shares exactly as
	 * the owner can (user-sharing: Who may create a share), matching the group
	 * share path, and may name their own received copy, which resolves to its
	 * source; without the authorization service wired the check falls back
	 * to plain ownership.
	 *
	 * @param string $sourceSecretId The source secret, or a delegate's own copy of it
	 * @param string $userId The sharing owner or delegate
	 *
	 * @return Secret|null
	 *
	 * @spec openspec/specs/user-sharing/spec.md#requirement-share-a-secret
	 */
	private function loadOwnedSource(string $sourceSecretId, string $userId): ?Secret {
		try {
			$source = $this->secretMapper->findById($sourceSecretId);
		} catch (DoesNotExistException) {
			return null;
		}

		if ($this->authorization !== null) {
			$source = $this->authorization->resolveShareSource(secret: $source, userId: $userId);
			if ($this->authorization->isOwnerOrDelegate(secret: $source, userId: $userId) === false) {
				return null;
			}

			return $source;
		}

		if ($source->getOwnerType() !== 'user' || $source->getOwnerId() !== $userId) {
			return null;
		}

		return $source;
	}//end loadOwnedSource()

	/**
	 * A row's use-only flag and end date, or null when the date is refused.
	 *
	 * @param array<string,mixed> $row The row
	 *
	 * @return ShareRestriction|null
	 *
	 * @spec openspec/changes/archive/2026-10-04-sharing-use-only-and-expiring-shares/tasks.md#task-2.1
	 */
	private function parseRestriction(array $row): ?ShareRestriction {
		try {
			return (new ShareRestrictionRules())->fromRequest(
				useOnly: ($row['useOnly'] ?? false),
				expiresAt: ($row['expiresAt'] ?? null),
				now: new DateTime('now', new DateTimeZone('UTC'))
			);
		} catch (InvalidArgumentException) {
			return null;
		}
	}//end parseRestriction()

	/**
	 * The status a row is refused with, or null when it may proceed.
	 *
	 * @param Secret|null $source The caller's own source, or null
	 * @param ShareRestriction|null $restriction The parsed restriction, or null
	 * @param string $userId The sharing owner or delegate
	 * @param array<string,mixed> $row The row (its optional groupShareId)
	 *
	 * @return string|null
	 *
	 * @spec openspec/specs/use-only-shares/spec.md#requirement-the-server-refuses-what-it-can-enforce
	 * @spec openspec/specs/user-sharing/spec.md#requirement-runtime-paths-follow-only-judged-share-rows
	 */
	private function refusalFor(?Secret $source, ?ShareRestriction $restriction, string $userId, array $row): ?string {
		if ($source === null) {
			return 'not_owned';
		}

		if ($source->isRestrictedCopy() === true) {
			return 'restricted';
		}

		// Only an original may be a share source: a received copy (one that is
		// itself a share target's recipient secret) is never one, so a recipient
		// can never re-share what was shared with them (user-sharing: Share a Secret).
		// A delegate's own copy was already resolved to its source.
		$verdict = $this->rowJudge->forCopy(copy: $source);
		if ($verdict->awaitsTheScan() === true) {
			// The row naming the source waits for the scan, as on the other share paths.
			return 'share_scan_pending';
		}

		if ($verdict->countsAsCopy() === true) {
			return 'not_shareable';
		}

		// A delegate borrows the owner's authority but keeps their own copy's
		// limits: a use-only, expiring or read-only copy may not mint a full one
		// (GHSA-m5p9-6pw7-pf3g).
		$callerRefusal = $this->callerCopyRefusal(source: $source, userId: $userId);
		if ($callerRefusal !== null) {
			return $callerRefusal;
		}

		if ($restriction === null || $this->groupShareMatches(sourceSecretId: $source->getId(), row: $row) === false) {
			return 'invalid';
		}

		return null;
	}//end refusalFor()

	/**
	 * Why the caller's own grant keeps them from minting a full copy, or null
	 * when it does not. The owner is judged by the source itself and never
	 * refused this way; a delegate is judged by the copy their delegation sits
	 * on, and one with no copy or two is `restricted`
	 * (ShareAuthorizationService::assertMayShareOnward()). A delegate whose
	 * copy's row the pending quarantine scan cannot judge gets
	 * `share_scan_pending`, as when they name that copy itself.
	 *
	 * @param Secret $source The source secret
	 * @param string $userId The sharing owner or delegate
	 *
	 * @return string|null `restricted`, `share_scan_pending` or null
	 *
	 * @spec openspec/specs/expiring-shares/spec.md#requirement-an-expiring-copy-cannot-be-shared-onward
	 * @spec openspec/specs/user-sharing/spec.md#requirement-runtime-paths-follow-only-judged-share-rows
	 */
	private function callerCopyRefusal(Secret $source, string $userId): ?string {
		if ($source->getOwnerType() === 'user' && $source->getOwnerId() === $userId) {
			return null;
		}

		if ($this->authorization === null) {
			return 'restricted';
		}

		try {
			$this->authorization->assertMayShareOnward(source: $source, userId: $userId);
		} catch (ShareScanPendingException) {
			return 'share_scan_pending';
		} catch (InvalidArgumentException|ForbiddenException) {
			return 'restricted';
		}

		return null;
	}//end callerCopyRefusal()

	/**
	 * Whether a row's optional groupShareId names a group share of the SAME
	 * source secret. A row without one matches; an unknown group share, one
	 * of another secret, or no mapper to check with does not (fail closed).
	 *
	 * @param string $sourceSecretId The row's source secret, after a delegate's copy resolved to it
	 * @param array<string,mixed> $row The row
	 *
	 * @return bool
	 */
	private function groupShareMatches(string $sourceSecretId, array $row): bool {
		$groupShareId = $this->optionalString(value: ($row['groupShareId'] ?? null));
		if ($groupShareId === null) {
			return true;
		}

		if ($this->groupShareMapper === null) {
			return false;
		}

		try {
			$groupShare = $this->groupShareMapper->findById($groupShareId);
		} catch (DoesNotExistException) {
			return false;
		}

		return $groupShare->getSecretId() === $sourceSecretId;
	}//end groupShareMatches()

	/**
	 * Normalise an optional blob value to a non-empty string or null.
	 *
	 * @param mixed $value The raw value
	 *
	 * @return string|null
	 */
	private function optionalString(mixed $value): ?string {
		if (is_string($value) === true && $value !== '') {
			return $value;
		}

		return null;
	}//end optionalString()
}//end class
