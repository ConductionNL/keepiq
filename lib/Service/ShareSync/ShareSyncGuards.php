<?php

/**
 * Keepiq ShareSyncGuards
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

use InvalidArgumentException;
use OCA\Keepiq\Db\EncryptionSuiteMapper;
use OCA\Keepiq\Db\Secret;
use OCA\Keepiq\Db\SecretMapper;
use OCA\Keepiq\Db\ShareTargetMapper;
use OCA\Keepiq\Exception\ForbiddenException;
use OCA\Keepiq\Service\FederatedValueFields;
use OCA\Keepiq\Service\ShareAuthorizationService;
use OCA\Keepiq\Service\ShareSave\ShareSaveEntries;
use OCA\Keepiq\Service\ShareSave\ShareSaveEntryParser;
use OCA\Keepiq\Service\ShareSave\ShareSaveRefusal;
use OCA\Keepiq\Service\ShareSyncService;

/**
 * The checks a write for every copy passes, on share-save and on /sync: the
 * source is a copy its holder may write, the live copies are named, and a copy
 * is left out only when its holder has no vault key. A member of a group the
 * secret is shared with gets all of them on /sync, refused with an
 * InvalidArgumentException.
 *
 * @spec openspec/changes/sharing-atomic-share-save/specs/user-sharing/spec.md#requirement-a-share-save-names-every-copy-or-is-refused
 */
class ShareSyncGuards {
	/**
	 * Constructor.
	 *
	 * @param ShareTargetMapper $targets The share targets (source to copies)
	 * @param SecretMapper $secrets The secrets (the copies' rows)
	 * @param EncryptionSuiteMapper $suites The suites (vault keys)
	 * @param ShareAuthorizationService $auth The share authorization service (the source read again)
	 *
	 * @return void
	 *
	 * @spec exclude Constructor wiring only.
	 */
	public function __construct(
		private ShareTargetMapper $targets,
		private SecretMapper $secrets,
		private EncryptionSuiteMapper $suites,
		private ShareAuthorizationService $auth,
	) {
	}//end __construct()

	/**
	 * Refuse a source that is a copy its holder may not write: a copy from
	 * another organisation (sharing-federated-recipients task 3.4) or a
	 * use-only copy (D4).
	 *
	 * @param Secret $source The source
	 *
	 * @return void
	 *
	 * @throws ForbiddenException When the source is a read-only copy
	 * @throws InvalidArgumentException When the source is a use-only copy
	 *
	 * @spec openspec/specs/use-only-shares/spec.md#requirement-the-server-refuses-what-it-can-enforce
	 */
	public function assertWritable(Secret $source): void {
		$source->assertNotReadOnly();
		if ($source->getUseOnly() === true) {
			throw new InvalidArgumentException(message: 'A use-only copy cannot be changed');
		}
	}//end assertWritable()

	/**
	 * Refuse a group member's sync that names no version to lock against: a
	 * member never overwrites a value they have not seen.
	 *
	 * @param string|null $writesOn What ShareSyncService::resolveSyncWriter() found
	 * @param string $expectedUpdatedAt The version the caller read
	 *
	 * @return void
	 *
	 * @throws InvalidArgumentException When a group member names no version
	 *
	 * @spec openspec/specs/sharing-group/spec.md#requirement-a-group-member-saves-for-everyone-it-is-shared-with
	 */
	public function assertBaseNamed(?string $writesOn, string $expectedUpdatedAt): void {
		if ($writesOn === ShareSyncService::GROUP_WRITE && $expectedUpdatedAt === '') {
			throw new InvalidArgumentException(message: 'expectedUpdatedAt is required when saving as a group member');
		}
	}//end assertBaseNamed()

	/**
	 * Share-save's guards for a member of a group the secret is shared with,
	 * run under the source lock: the source is read again and refused when it
	 * became a read-only or use-only copy, the caller is checked again, so a
	 * leave or a revoke that committed after the first check refuses the sync,
	 * and the updates must name exactly the source and every copy whose access
	 * has not ended, so no holder is left on the old value, with no empty key
	 * and no row named twice. Only those rows are written. A named row that
	 * carries no value is left out, untouched, as share-save's `skipped` list
	 * leaves it, and only when its holder has no vault key: every other holder
	 * must get the new value.
	 *
	 * @param ShareSyncService $sync Who may write the source
	 * @param Secret $source The source secret being synced
	 * @param array<int,array<string,string|null>> $updates The per-recipient blobs
	 * @param string $userId The requesting user
	 *
	 * @return array{0:string|null,1:array<string,bool>} How the audit names the write now, and the ids to write
	 *
	 * @throws InvalidArgumentException When the caller lost the right to write, or a row is missing, unexpected or valueless
	 *
	 * @spec openspec/specs/sharing-group/spec.md#requirement-a-group-member-saves-for-everyone-it-is-shared-with
	 * @spec openspec/changes/sharing-atomic-share-save/specs/user-sharing/spec.md#requirement-a-share-save-names-every-copy-or-is-refused
	 */
	public function assertGroupWrite(ShareSyncService $sync, Secret $source, array $updates, string $userId): array {
		// Read again, as share-save's assertStillWriter() does: becoming a
		// read-only or use-only copy does not move the version the lock checks.
		$current = $this->auth->loadSecret(secretId: $source->getId());
		$this->assertWritable(source: $current);

		$writesOn = $sync->resolveSyncWriter(source: $current, userId: $userId);
		$this->assertBody(updates: $updates);

		$live = $this->liveCopies(source: $source, leaveOut: null);
		$required = [$source->getId() => ['secretId' => $source->getId(), 'holder' => 'owner', 'targetUserId' => null]] + $live['copies'];

		$named = [];
		$valueless = [];
		foreach ($updates as $update) {
			$named[(string)($update['secretId'] ?? '')] = true;
			if ($this->carriesValue(update: $update) === false) {
				$valueless[(string)($update['secretId'] ?? '')] = true;
			}
		}

		if (array_diff_key($required, $named) !== [] || array_diff_key($named, $required, $live['ignored']) !== []) {
			throw new InvalidArgumentException(
				message: 'Name the source and every copy whose access has not ended, and nothing else'
			);
		}

		foreach (array_keys(array_intersect_key($valueless, $required)) as $copyId) {
			if ($this->holderHasVaultKey(source: $source, copy: $required[$copyId]) === true) {
				throw new InvalidArgumentException(
					message: 'Every holder with a vault key must get the new value'
				);
			}

			// No vault key to encrypt for: the row keeps its value. A copy keeps
			// its version too; the source's version already moved with the lock.
			unset($required[$copyId]);
		}

		return [$writesOn, array_fill_keys(array_keys($required), true)];
	}//end assertGroupWrite()

	/**
	 * The copies of a source a save for every copy writes, keyed by id: every
	 * copy whose access has not ended. The ignored ones may be named but are
	 * not written: their access ended, or their row is gone.
	 *
	 * @param Secret $source The source
	 * @param string|null $leaveOut A copy left out of both lists, as share-save leaves out the caller's own row
	 *
	 * @return array{copies:array<string,array{secretId:string,holder:string,targetUserId:?string}>,ignored:array<string,bool>}
	 *
	 * @spec openspec/changes/sharing-atomic-share-save/specs/user-sharing/spec.md#requirement-a-share-save-names-every-copy-or-is-refused
	 */
	public function liveCopies(Secret $source, ?string $leaveOut): array {
		$copies = [];
		$ignored = [];
		$targets = $this->targets->findBySourceSecret($source->getId());
		$rows = $this->secrets->findByIds(ids: array_map(static fn ($target): string => $target->getSecretId(), $targets));
		$now = time();
		foreach ($targets as $target) {
			$copyId = $target->getSecretId();
			if ($copyId === $leaveOut) {
				continue;
			}

			// A copy whose access ended, or whose row is gone, is not written.
			$row = ($rows[$copyId] ?? null);
			$ends = $row?->getAccessExpiresAt();
			if ($row === null || ($ends !== null && $ends->getTimestamp() <= $now)) {
				$ignored[$copyId] = true;
				continue;
			}

			// Defence in depth: a copy whose row does not genuinely belong to its
			// target (a poisoned or drifted `secret_id`) is dropped from the write
			// set, so a save can never overwrite another user's secret.
			if ($target->isHeldByTarget(copy: $row) === false) {
				$ignored[$copyId] = true;
				continue;
			}

			$copies[$copyId] = ['secretId' => $copyId, 'holder' => 'recipient', 'targetUserId' => $target->getTargetUserId()];
		}

		return ['copies' => $copies, 'ignored' => $ignored];
	}//end liveCopies()

	/**
	 * Whether the holder of a copy liveCopies() names, or of the source
	 * (holder `owner`), has a vault key to encrypt the new value for.
	 *
	 * @param Secret $source The source
	 * @param array{secretId:string,holder:string,targetUserId:?string} $copy The copy
	 *
	 * @return bool
	 *
	 * @spec openspec/changes/sharing-atomic-share-save/specs/user-sharing/spec.md#requirement-a-share-save-names-every-copy-or-is-refused
	 */
	public function holderHasVaultKey(Secret $source, array $copy): bool {
		$ownerType = 'user';
		$ownerId = (string)$copy['targetUserId'];
		if ($copy['holder'] === 'owner') {
			$ownerType = $source->getOwnerType();
			$ownerId = $source->getOwnerId();
		}

		return $this->suites->countActiveByOwner(ownerType: $ownerType, ownerId: $ownerId) > 0;
	}//end holderHasVaultKey()

	/**
	 * Share-save's checks of a body, on a sync's rows: no key may be empty,
	 * and no row may be named twice.
	 *
	 * @param array<int,array<string,string|null>> $updates The per-recipient blobs
	 *
	 * @return void
	 *
	 * @throws InvalidArgumentException When a key is empty or a row is named twice
	 *
	 * @spec openspec/changes/sharing-atomic-share-save/specs/user-sharing/spec.md#requirement-a-share-save-names-every-copy-or-is-refused
	 */
	private function assertBody(array $updates): void {
		$parser = new ShareSaveEntryParser();
		try {
			foreach ($updates as $update) {
				$parser->assertKeyNotEmpty(entry: $update);
			}
		} catch (ShareSaveRefusal $refusal) {
			throw new InvalidArgumentException(message: $refusal->getMessage());
		}

		$this->assertNoDuplicates(updates: $updates);
	}//end assertBody()

	/**
	 * Refuse a sync that names a row twice, whoever writes: each row is judged
	 * once, so a later entry cannot write a copy an earlier one held back.
	 *
	 * @param array<int,array<string,string|null>> $updates The per-recipient blobs
	 *
	 * @return void
	 *
	 * @throws InvalidArgumentException When a row is named twice
	 *
	 * @spec openspec/specs/user-sharing/spec.md#requirement-sync-on-update
	 */
	public function assertNoDuplicates(array $updates): void {
		try {
			(new ShareSaveEntries(copies: array_values($updates)))->assertNoDuplicates();
		} catch (ShareSaveRefusal $refusal) {
			throw new InvalidArgumentException(message: $refusal->getMessage());
		}
	}//end assertNoDuplicates()

	/**
	 * Make the `totp` and `passkeys` of a /sync follow the source, as
	 * share-save's copy check does: a row that carries one must match the
	 * source's after this sync in null-ness, and once any row carries one,
	 * every row that carries a value must. A row that leaves one out while
	 * the source holds none gets it cleared, so a copy never keeps a seed the
	 * owner removed. Rows that carry no value are left as sent.
	 *
	 * @param Secret $source The source as read
	 * @param array<int,array<string,string|null>> $updates The per-recipient blobs
	 *
	 * @return array<int,array<string,string|null>> The blobs, with the cleared fields filled in
	 *
	 * @throws InvalidArgumentException When a row does not mirror the source
	 *
	 * @spec openspec/specs/user-sharing/spec.md#requirement-sync-on-update
	 */
	public function mirroredValueFields(Secret $source, array $updates): array {
		$rows = array_filter($updates, 'is_array');
		$sourceRow = [];
		foreach ($rows as $update) {
			if ((string)($update['secretId'] ?? '') === $source->getId()) {
				$sourceRow = $update;
			}
		}

		$blobs = new FederatedValueFields();
		$held = ['totp' => $source->getTotp(), 'passkeys' => $source->getPasskeys()];
		foreach ($held as $field => $stored) {
			// The source after this sync: its own row's value when it carries one.
			$sourceHolds = $blobs->blob(value: ($sourceRow + [$field => $stored])[$field]) !== null;
			$updates = $this->mirrorField(field: $field, sourceHolds: $sourceHolds, rows: $rows, updates: $updates);
		}

		return $updates;
	}//end mirroredValueFields()

	/**
	 * Hold the rows of a /sync to the source's null-ness of one field; see mirroredValueFields().
	 *
	 * @param string $field `totp` or `passkeys`
	 * @param bool $sourceHolds Whether the source holds a value for it after this sync
	 * @param array<int,array<string,mixed>> $rows The rows that are arrays
	 * @param array<int,array<string,string|null>> $updates The per-recipient blobs
	 *
	 * @return array<int,array<string,string|null>> The blobs, with the field cleared where it is left out and the source holds none
	 *
	 * @throws InvalidArgumentException When a row does not mirror the source
	 */
	private function mirrorField(string $field, bool $sourceHolds, array $rows, array $updates): array {
		$blobs = new FederatedValueFields();
		$carried = array_filter($rows, static fn (array $update): bool => array_key_exists($field, $update)) !== [];
		foreach ($rows as $index => $update) {
			if ($this->carriesValue(update: $update) === false) {
				continue;
			}

			$present = array_key_exists($field, $update);
			if ($present === false && $sourceHolds === false) {
				$updates[$index][$field] = null;
				continue;
			}

			if ($present === false && $carried === false) {
				continue;
			}

			if (($blobs->blob(value: $update[$field] ?? null) !== null) !== $sourceHolds) {
				throw new InvalidArgumentException(
					message: 'Every row must carry the '.$field.' the source holds, and none it does not'
				);
			}
		}

		return $updates;
	}//end mirrorField()

	/**
	 * Whether an update row carries ciphertext for its copy: a non-empty key,
	 * or any other value field.
	 *
	 * @param array<string,string|null> $update The blob row
	 *
	 * @return bool
	 */
	private function carriesValue(array $update): bool {
		if (isset($update['key']) === true && $update['key'] !== '') {
			return true;
		}

		return array_intersect(array_diff(Secret::VALUE_FIELDS, ['key']), array_keys($update)) !== [];
	}//end carriesValue()
}//end class
