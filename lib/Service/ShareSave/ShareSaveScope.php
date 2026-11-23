<?php

/**
 * Keepiq ShareSaveScope
 *
 * @category Service
 * @package  OCA\Keepiq\Service\ShareSave
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

namespace OCA\Keepiq\Service\ShareSave;

use InvalidArgumentException;
use OCA\Keepiq\Db\FederatedShare;
use OCA\Keepiq\Db\FederatedShareMapper;
use OCA\Keepiq\Db\Secret;
use OCA\Keepiq\Db\SecretMapper;
use OCA\Keepiq\Exception\ForbiddenException;
use OCA\Keepiq\Exception\NotFoundException;
use OCA\Keepiq\Service\ShareRowJudge;
use OCA\Keepiq\Service\ShareRowVerdict;
use OCA\Keepiq\Service\ShareSync\ShareSyncGuards;
use OCA\Keepiq\Service\ShareSyncService;
use OCP\AppFramework\Db\DoesNotExistException;
use OCP\AppFramework\Db\MultipleObjectsReturnedException;
use OCP\AppFramework\Http;
use Psr\Log\LoggerInterface;

/**
 * Which rows a share-save must write, and whether its lists name exactly those.
 *
 * @psalm-type Requirement = array{copies:array<string,array{secretId:string,holder:string,targetUserId:?string}>,ignored:array<string,bool>,federated:array<string,FederatedShare>,ownerOnly:bool}
 *
 * @SuppressWarnings(PHPMD.CouplingBetweenObjects) Reads the sharing graph from
 *   copies, share targets and partner shares, each through its mapper.
 *
 * @spec openspec/changes/sharing-atomic-share-save/specs/user-sharing/spec.md#requirement-a-share-save-names-every-copy-or-is-refused
 */
class ShareSaveScope {

	/**
	 * Constructor.
	 *
	 * @param ShareRowJudge $rowJudge Judged share rows (ShareRowJudge); every lookup of a share row goes through it
	 * @param SecretMapper $secrets The secrets
	 * @param FederatedShareMapper $federatedShares Outbound partner shares
	 * @param ShareSyncService $sync Who may write for every copy
	 * @param ShareSyncGuards $guards Whether the source is writable, the live copies and their holders' vault keys
	 * @param LoggerInterface|null $logger Warns about partner shares that are not the secret owner's
	 *
	 * @return void
	 *
	 * @spec exclude Constructor wiring only.
	 */
	public function __construct(
		private ShareRowJudge $rowJudge,
		private SecretMapper $secrets,
		private FederatedShareMapper $federatedShares,
		private ShareSyncService $sync,
		private ShareSyncGuards $guards,
		private ?LoggerInterface $logger = null,
	) {
	}//end __construct()

	/**
	 * The path of a save of the caller's own row: the owner path, or the
	 * copy-editor path when it is a recipient copy, which takes the owner,
	 * a delegate, a write grade on the source or a member of a group it is
	 * shared with. A share row whose target does not hold the row, or that the
	 * pending quarantine scan quarantines, leads to no source: the row saves
	 * as the caller's own.
	 *
	 * @param Secret $row The caller's row, already checked to be theirs
	 * @param string $userId The caller
	 *
	 * @return ShareSavePath
	 *
	 * @throws NotFoundException When the copy's source is gone
	 * @throws ForbiddenException When the source is a read-only or use-only copy
	 * @throws ShareSaveRefusal `not_allowed` for a copy holder without the right to write every copy,
	 *   `share_scan_pending` for a share row the pending quarantine scan cannot judge
	 *
	 * @spec openspec/changes/sharing-atomic-share-save/specs/user-sharing/spec.md#requirement-a-shared-secret-and-every-copy-save-in-one-transaction
	 * @spec openspec/specs/user-sharing/spec.md#requirement-runtime-paths-follow-only-judged-share-rows
	 */
	public function resolve(Secret $row, string $userId): ShareSavePath {
		$verdict = $this->rowJudge->forCopy(copy: $row);
		if ($verdict->state === ShareRowVerdict::AMBIGUOUS) {
			throw new NotFoundException(message: 'Secret not found');
		}

		if ($verdict->state === ShareRowVerdict::UNJUDGED) {
			throw $this->scanPending();
		}

		$target = $verdict->trustedRow();
		if ($target === null) {
			return new ShareSavePath(row: $row, source: $row);
		}

		try {
			$source = $this->secrets->findById($target->getSourceSecretId());
		} catch (DoesNotExistException|MultipleObjectsReturnedException) {
			throw new NotFoundException(message: 'Secret not found');
		}

		$this->assertWritable(source: $source);
		$writesOn = $this->writerOn(
			source: $source,
			userId: $userId,
			refusal: 'Only the owner, a delegate, a write-grade member or a member of a group it is shared with saves for every copy'
		);

		return new ShareSavePath(row: $row, source: $source, writesOn: $writesOn);
	}//end resolve()

	/**
	 * The refusal of a save that reaches a share row the pending quarantine
	 * scan cannot judge: nothing is written until the scan has judged it.
	 *
	 * @return ShareSaveRefusal
	 */
	private function scanPending(): ShareSaveRefusal {
		return new ShareSaveRefusal(
			error: 'share_scan_pending',
			message: 'This secret waits for the share quarantine scan and cannot be saved yet; ask an administrator to check that background jobs run',
			status: Http::STATUS_CONFLICT
		);
	}//end scanPending()

	/**
	 * Check the copy-editor's right to write every copy again, inside the
	 * transaction after the locks, so a grade lowered after resolve() refuses
	 * the save and rolls it back. The source is read again first: moving it to
	 * another folder does not change its version, so the lock does not see it.
	 * A caller who still writes, but now another way (a delegation granted or
	 * revoked next to a write grade), saves and is audited as they write now.
	 * On the owner path the source is read again too, and a caller who no longer owns it (an ownership transfer
	 * committed meanwhile) is refused as a save of another user's row is.
	 *
	 * @param ShareSavePath $path The path resolve() returned
	 * @param string $userId The caller
	 *
	 * @return ShareSavePath The path with the way the caller writes now
	 *
	 * @throws NotFoundException When the source was deleted meanwhile
	 * @throws ForbiddenException When the source became a read-only or use-only copy, or another user's secret
	 * @throws ShareSaveRefusal `not_allowed` for a copy holder who lost the right to write every copy
	 *
	 * @spec openspec/changes/sharing-atomic-share-save/specs/user-sharing/spec.md#requirement-a-shared-secret-and-every-copy-save-in-one-transaction
	 */
	public function assertStillWriter(ShareSavePath $path, string $userId): ShareSavePath {
		try {
			$source = $this->secrets->findById($path->source->getId());
		} catch (DoesNotExistException|MultipleObjectsReturnedException) {
			throw new NotFoundException(message: 'Secret not found');
		}

		// The source as resolve() read it stays the path's source: its version is what a refusal reports.
		// Its owner is read again, so who writes partner shares is decided under the lock.
		if ($path->isCopyEditor() === false) {
			if ($source->getOwnerType() !== 'user' || $source->getOwnerId() !== $userId) {
				throw new ForbiddenException(message: 'Secret belongs to another user');
			}

			return new ShareSavePath(row: $path->row, source: $path->source, current: $source);
		}

		$this->assertWritable(source: $source);
		$writesOn = $this->writerOn(
			source: $source,
			userId: $userId,
			refusal: 'Your access to this secret changed while saving; reload and try again'
		);

		return new ShareSavePath(row: $path->row, source: $path->source, writesOn: $writesOn, current: $source);
	}//end assertStillWriter()

	/**
	 * Refuse a source that is itself a copy its holder may not write.
	 *
	 * @param Secret $source The source
	 *
	 * @return void
	 *
	 * @throws ForbiddenException When the source is a read-only or use-only copy
	 */
	private function assertWritable(Secret $source): void {
		try {
			$this->guards->assertWritable(source: $source);
		} catch (InvalidArgumentException $refusal) {
			throw new ForbiddenException(message: $refusal->getMessage());
		}
	}//end assertWritable()

	/**
	 * How the audit names the caller's write of the source when they write on
	 * a team-folder grade or as a member of a group it is shared with, null
	 * when they write as owner or delegate, refusing a caller who may not
	 * write it at all.
	 *
	 * @param Secret $source The source
	 * @param string $userId The caller
	 * @param string $refusal The message of the refusal
	 *
	 * @return string|null
	 *
	 * @throws ShareSaveRefusal `not_allowed` for a copy holder without the right to write every copy
	 */
	private function writerOn(Secret $source, string $userId, string $refusal): ?string {
		try {
			return $this->sync->resolveSyncWriter(source: $source, userId: $userId);
		} catch (InvalidArgumentException) {
			throw new ShareSaveRefusal(error: 'not_allowed', message: $refusal, status: Http::STATUS_FORBIDDEN);
		}
	}//end writerOn()

	/**
	 * Refuse partner entries from a caller who does not own the source: only
	 * the secret's owner writes partner shares, through the source or their
	 * own copy.
	 *
	 * @param ShareSavePath $path The path
	 * @param ShareSaveEntries $entries The request's lists
	 * @param string $userId The caller
	 *
	 * @return void
	 *
	 * @throws ShareSaveRefusal `federated_owner_only`
	 *
	 * @spec openspec/changes/sharing-share-save-missed-partner-shares/specs/federated-sharing/spec.md#requirement-partner-copies-change-only-with-the-owners-share-save
	 */
	public function assertMayNamePartners(ShareSavePath $path, ShareSaveEntries $entries, string $userId): void {
		if ($entries->federatedCount() > 0 && $path->ownsSource(userId: $userId) === false) {
			throw new ShareSaveRefusal(
				error: 'federated_owner_only',
				message: 'Only the owner writes partner shares'
			);
		}
	}//end assertMayNamePartners()

	/**
	 * Refuse lists that are not exactly the rows to write: every live copy
	 * (on the copy-editor path the source and every other copy) and, when the
	 * caller owns the source, every active partner share of it. A copy may be
	 * left out only when its holder has no vault key.
	 *
	 * @param ShareSavePath $path The path
	 * @param ShareSaveEntries $entries The lists, staged ones included
	 * @param string $userId The caller
	 *
	 * @return array The requirement the lists met
	 *
	 * @psalm-return Requirement
	 *
	 * @throws IncompleteShareSaveException With the current lists
	 * @throws ShareSaveRefusal `share_scan_pending` when the pending quarantine scan cannot judge a copy's row,
	 *   `partner_share_owner_mismatch` when a partner share to write is not the source owner's
	 *
	 * @spec openspec/changes/sharing-atomic-share-save/specs/user-sharing/spec.md#requirement-a-share-save-names-every-copy-or-is-refused
	 * @spec openspec/changes/sharing-share-save-missed-partner-shares/specs/user-sharing/spec.md#requirement-a-share-save-names-every-copy-or-is-refused
	 */
	public function assertComplete(ShareSavePath $path, ShareSaveEntries $entries, string $userId): array {
		$required = $this->requirement(path: $path, userId: $userId);

		$named = array_flip($entries->copyIds());
		$copies = [
			'required' => array_values($required['copies']),
			'missing' => array_keys(array_diff_key($required['copies'], $named)),
			'unexpected' => array_keys(array_diff_key($named, $required['copies'], $required['ignored'])),
			'skipRefused' => $this->refusedSkips(path: $path, skipped: $entries->skipped, required: $required['copies']),
		];

		$federated = $this->federatedLists(required: $required['federated'], entries: $entries);

		if ($copies['missing'] !== [] || $copies['unexpected'] !== [] || $copies['skipRefused'] !== []
			|| $federated['missing'] !== [] || $federated['unexpected'] !== []
		) {
			throw new IncompleteShareSaveException(
				path: $path->getName(),
				sourceUpdatedAt: $path->source->getUpdatedAt()?->format(DATE_ATOM),
				copies: $copies,
				federated: $federated,
			);
		}

		return $required;
	}//end assertComplete()

	/**
	 * The partner shares a save must name, and those it left out or added.
	 *
	 * @param array<string,FederatedShare> $required The required partner shares, keyed by id
	 * @param ShareSaveEntries             $entries  The lists, staged ones included
	 *
	 * @return array{required:list<array{shareId:string,recipientCloudId:string}>,missing:list<string>,unexpected:list<string>}
	 *
	 * @spec openspec/changes/sharing-atomic-share-save/specs/user-sharing/spec.md#requirement-a-share-save-names-every-copy-or-is-refused
	 */
	private function federatedLists(array $required, ShareSaveEntries $entries): array {
		$namedShares = array_flip($entries->shareIds());
		return [
			'required' => array_values(array_map(
				static fn (FederatedShare $share): array => [
					'shareId' => $share->getId(),
					'recipientCloudId' => $share->getRecipientCloudId(),
				],
				$required
			)),
			'missing' => array_keys(array_diff_key($required, $namedShares)),
			'unexpected' => array_keys(array_diff_key($namedShares, $required)),
		];
	}//end federatedLists()

	/**
	 * Refuse a stage that grows past the rows the save could ever name, or
	 * past the chunks those rows need, so a stage cannot hold more than the
	 * sharing graph calls for.
	 *
	 * @param ShareSavePath $path The path
	 * @param ShareSaveEntries $entries The staged lists with the new chunk
	 * @param int $chunks The stage's chunks with the new one
	 * @param string $userId The caller
	 *
	 * @return void
	 *
	 * @throws ShareSaveRefusal `too_many_entries`, `too_many_chunks`, `share_scan_pending` or `partner_share_owner_mismatch`
	 *
	 * @spec openspec/changes/sharing-atomic-share-save/specs/user-sharing/spec.md#requirement-a-large-share-save-is-staged-in-chunks
	 */
	public function assertWithinReach(ShareSavePath $path, ShareSaveEntries $entries, int $chunks, string $userId): void {
		$required = $this->requirement(path: $path, userId: $userId);
		$copyReach = count($required['copies']) + count($required['ignored']);
		$federatedReach = count($required['federated']);
		if ($entries->copyCount() > $copyReach || $entries->federatedCount() > $federatedReach) {
			throw new ShareSaveRefusal(error: 'too_many_entries', message: 'The stage names more rows than there are');
		}

		if ($chunks > (int)ceil(($copyReach + $federatedReach) / ShareSaveRequest::MAX_ENTRIES)) {
			throw new ShareSaveRefusal(error: 'too_many_chunks', message: 'The stage has more chunks than its rows need');
		}
	}//end assertWithinReach()

	/**
	 * The rows the save must name, the copies it may name but that are not
	 * written (access ended), and the partner shares only the source's owner
	 * reaches, through the source or through their own copy.
	 *
	 * @param ShareSavePath $path The path
	 * @param string $userId The caller
	 *
	 * @return array
	 *
	 * @psalm-return Requirement
	 *
	 * @throws ShareSaveRefusal `share_scan_pending` when the pending quarantine scan cannot judge a copy's row,
	 *   `partner_share_owner_mismatch` when a partner share to write is not the source owner's
	 *
	 * @spec openspec/changes/sharing-share-save-missed-partner-shares/specs/user-sharing/spec.md#requirement-a-share-save-names-every-copy-or-is-refused
	 */
	private function requirement(ShareSavePath $path, string $userId): array {
		$source = $path->source;
		['copies' => $copies, 'ignored' => $ignored, 'unjudged' => $unjudged] = $this->guards->liveCopies(source: $source, leaveOut: $path->row->getId());
		if ($unjudged !== []) {
			throw $this->scanPending();
		}

		if ($path->isCopyEditor() === true) {
			$copies = [$source->getId() => ['secretId' => $source->getId(), 'holder' => 'owner', 'targetUserId' => null]] + $copies;
		}

		$federated = $this->activePartnerShares(source: $source);
		if ($path->ownsSource(userId: $userId) === false) {
			// Whether there are any, not how many: the copy editor never saw the partner list.
			return ['copies' => $copies, 'ignored' => $ignored, 'federated' => [], 'ownerOnly' => $federated !== []];
		}

		$this->assertPartnersOwnedBySourceOwner(path: $path, federated: $federated);
		return ['copies' => $copies, 'ignored' => $ignored, 'federated' => $federated, 'ownerOnly' => false];
	}//end requirement()

	/**
	 * Refuse a save that would write partner shares while one of them is not
	 * the source owner's: an ownership transfer left it behind, and only the
	 * `ReownTransferredPartnerShares` repair step hands it over.
	 *
	 * @param ShareSavePath $path The path
	 * @param array<string,FederatedShare> $federated The source's active partner shares
	 *
	 * @return void
	 *
	 * @throws ShareSaveRefusal `partner_share_owner_mismatch`
	 *
	 * @spec openspec/changes/sharing-share-save-missed-partner-shares/specs/federated-sharing/spec.md#requirement-a-partner-share-the-owner-does-not-own-stops-the-save
	 */
	private function assertPartnersOwnedBySourceOwner(ShareSavePath $path, array $federated): void {
		$ownerId = $path->sourceOwnerId();
		$foreign = array_keys(array_filter($federated, static fn (FederatedShare $share): bool => $share->getOwnerId() !== $ownerId));
		if ($foreign === []) {
			return;
		}

		$this->logger?->warning(
			'Share-save refused: partner shares of the secret are not its owner\'s; run occ maintenance:repair to hand them over',
			['app' => 'keepiq', 'secretId' => $path->source->getId(), 'shareIds' => $foreign]
		);
		throw new ShareSaveRefusal(
			error: 'partner_share_owner_mismatch',
			message: 'A partner share of this secret is not its owner\'s; an administrator must repair the partner shares first',
			status: Http::STATUS_CONFLICT
		);
	}//end assertPartnersOwnedBySourceOwner()

	/**
	 * How many active partner shares hang off a row the save writes other
	 * than the source: the caller's copy on the copy-editor path and every
	 * copy written. Such a share was made from a copy and no save writes it.
	 *
	 * @param ShareSavePath $path The path
	 * @param list<string> $copyIds The copies the save wrote
	 *
	 * @return int
	 *
	 * @spec openspec/changes/sharing-share-save-missed-partner-shares/specs/federated-sharing/spec.md#requirement-partner-shares-made-from-a-copy-are-reported
	 */
	public function partnersNotReached(ShareSavePath $path, array $copyIds): int {
		$rowIds = array_diff([$path->row->getId(), ...$copyIds], [$path->source->getId()]);
		if ($rowIds === []) {
			return 0;
		}

		return $this->federatedShares->countActiveBySourceSecrets(sourceSecretIds: array_values($rowIds));
	}//end partnersNotReached()

	/**
	 * The source's active partner shares, keyed by id.
	 *
	 * @param Secret $source The source
	 *
	 * @return array<string,FederatedShare>
	 */
	private function activePartnerShares(Secret $source): array {
		$active = [];
		foreach ($this->federatedShares->findBySourceSecret(sourceSecretId: $source->getId()) as $share) {
			if ($share->getStatus() === FederatedShare::STATUS_ACTIVE) {
				$active[$share->getId()] = $share;
			}
		}

		return $active;
	}//end activePartnerShares()

	/**
	 * The skipped copies whose holder does have a vault key: those must be written.
	 *
	 * @param ShareSavePath $path The path
	 * @param list<array{secretId:string,reason:string}> $skipped The skipped entries
	 * @param array<string,array{secretId:string,holder:string,targetUserId:?string}> $required The required copies
	 *
	 * @return list<string>
	 */
	private function refusedSkips(ShareSavePath $path, array $skipped, array $required): array {
		$refused = [];
		foreach ($skipped as $skip) {
			$copy = ($required[$skip['secretId']] ?? null);
			if ($copy !== null && $this->guards->holderHasVaultKey(source: $path->source, copy: $copy) === true) {
				$refused[] = $skip['secretId'];
			}
		}

		return $refused;
	}//end refusedSkips()
}//end class
