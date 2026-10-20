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
use OCA\Keepiq\Db\ShareTargetMapper;
use OCA\Keepiq\Exception\ForbiddenException;
use OCA\Keepiq\Exception\NotFoundException;
use OCA\Keepiq\Service\ShareSync\ShareSyncGuards;
use OCA\Keepiq\Service\ShareSyncService;
use OCP\AppFramework\Db\DoesNotExistException;
use OCP\AppFramework\Db\MultipleObjectsReturnedException;
use OCP\AppFramework\Http;

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
	 * @param ShareTargetMapper $targets The share targets (copy to source)
	 * @param SecretMapper $secrets The secrets
	 * @param FederatedShareMapper $federatedShares Outbound partner shares
	 * @param ShareSyncService $sync Who may write for every copy
	 * @param ShareSyncGuards $guards Whether the source is writable, the live copies and their holders' vault keys
	 *
	 * @return void
	 *
	 * @spec exclude Constructor wiring only.
	 */
	public function __construct(
		private ShareTargetMapper $targets,
		private SecretMapper $secrets,
		private FederatedShareMapper $federatedShares,
		private ShareSyncService $sync,
		private ShareSyncGuards $guards,
	) {
	}//end __construct()

	/**
	 * The path of a save of the caller's own row: the owner path, or the
	 * copy-editor path when it is a recipient copy, which takes the owner,
	 * a delegate, a write grade on the source or a member of a group it is
	 * shared with.
	 *
	 * @param Secret $row The caller's row, already checked to be theirs
	 * @param string $userId The caller
	 *
	 * @return ShareSavePath
	 *
	 * @throws NotFoundException When the copy's source is gone
	 * @throws ForbiddenException When the source is a read-only or use-only copy
	 * @throws ShareSaveRefusal `not_allowed` for a copy holder without the right to write every copy
	 *
	 * @spec openspec/changes/sharing-atomic-share-save/specs/user-sharing/spec.md#requirement-a-shared-secret-and-every-copy-save-in-one-transaction
	 */
	public function resolve(Secret $row, string $userId): ShareSavePath {
		try {
			$target = $this->targets->findByRecipientSecret(recipientSecretId: $row->getId());
		} catch (DoesNotExistException) {
			return new ShareSavePath(row: $row, source: $row);
		} catch (MultipleObjectsReturnedException) {
			throw new NotFoundException(message: 'Secret not found');
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
	 * Check the copy-editor's right to write every copy again, inside the
	 * transaction after the locks, so a grade lowered after resolve() refuses
	 * the save and rolls it back. The source is read again first: moving it to
	 * another folder does not change its version, so the lock does not see it.
	 * A caller who still writes, but now another way (a delegation granted or
	 * revoked next to a write grade), saves and is audited as they write now.
	 * The owner path has nothing to check.
	 *
	 * @param ShareSavePath $path The path resolve() returned
	 * @param string $userId The caller
	 *
	 * @return ShareSavePath The path with the way the caller writes now
	 *
	 * @throws NotFoundException When the source was deleted meanwhile
	 * @throws ForbiddenException When the source became a read-only or use-only copy
	 * @throws ShareSaveRefusal `not_allowed` for a copy holder who lost the right to write every copy
	 *
	 * @spec openspec/changes/sharing-atomic-share-save/specs/user-sharing/spec.md#requirement-a-shared-secret-and-every-copy-save-in-one-transaction
	 */
	public function assertStillWriter(ShareSavePath $path, string $userId): ShareSavePath {
		if ($path->isCopyEditor() === false) {
			return $path;
		}

		try {
			$source = $this->secrets->findById($path->source->getId());
		} catch (DoesNotExistException|MultipleObjectsReturnedException) {
			throw new NotFoundException(message: 'Secret not found');
		}

		$this->assertWritable(source: $source);
		$writesOn = $this->writerOn(
			source: $source,
			userId: $userId,
			refusal: 'Your access to this secret changed while saving; reload and try again'
		);

		// The source as resolve() read it: its version is what a refusal reports.
		return new ShareSavePath(row: $path->row, source: $path->source, writesOn: $writesOn);
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
	 * Refuse lists that are not exactly the rows to write: every live copy
	 * (on the copy-editor path the source and every other copy) and, on the
	 * owner path, every active partner share of the caller. A copy may be
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
	 *
	 * @spec openspec/changes/sharing-atomic-share-save/specs/user-sharing/spec.md#requirement-a-share-save-names-every-copy-or-is-refused
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

		$namedShares = array_flip($entries->shareIds());
		$federated = [
			'required' => array_values(array_map(
				static fn (FederatedShare $share): array => [
					'shareId' => $share->getId(),
					'recipientCloudId' => $share->getRecipientCloudId(),
				],
				$required['federated']
			)),
			'missing' => array_keys(array_diff_key($required['federated'], $namedShares)),
			'unexpected' => array_keys(array_diff_key($namedShares, $required['federated'])),
		];

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
	 * @throws ShareSaveRefusal `too_many_entries` or `too_many_chunks`
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
	 * written (access ended), and the partner shares only the owner reaches.
	 *
	 * @param ShareSavePath $path The path
	 * @param string $userId The caller
	 *
	 * @return array
	 *
	 * @psalm-return Requirement
	 */
	private function requirement(ShareSavePath $path, string $userId): array {
		$source = $path->source;
		['copies' => $copies, 'ignored' => $ignored] = $this->guards->liveCopies(source: $source, leaveOut: $path->row->getId());
		if ($path->isCopyEditor() === true) {
			$copies = [$source->getId() => ['secretId' => $source->getId(), 'holder' => 'owner', 'targetUserId' => null]] + $copies;
		}

		$federated = $this->activePartnerShares(source: $source);
		if ($path->isCopyEditor() === true) {
			// Whether there are any, not how many: the copy editor never saw the partner list.
			return ['copies' => $copies, 'ignored' => $ignored, 'federated' => [], 'ownerOnly' => $federated !== []];
		}

		$federated = array_filter($federated, static fn (FederatedShare $share): bool => $share->getOwnerId() === $userId);
		return ['copies' => $copies, 'ignored' => $ignored, 'federated' => $federated, 'ownerOnly' => false];
	}//end requirement()

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
