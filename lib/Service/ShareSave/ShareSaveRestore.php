<?php

/**
 * Keepiq ShareSaveRestore
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

use OCA\Keepiq\Db\Secret;
use OCA\Keepiq\Db\SecretVersion;

/**
 * What a share-save restore of a version writes for its `totp` and `passkeys`, as SecretVersionService restores
 * the head: a field the version holds as null predates the field and the head keeps its value; `''` was empty then
 * and clears the head's; a value must be in the patch.
 *
 * @spec openspec/changes/sharing-atomic-share-save/specs/user-sharing/spec.md#requirement-a-restore-reaches-every-copy-in-the-same-transaction
 */
final class ShareSaveRestore {
	/**
	 * What counts as no `totp` or `passkeys` value, as FederatedValueFields::blob() reads one.
	 *
	 * @var array<int,mixed>
	 */
	private const NO_BLOB = [null, ''];

	/**
	 * The patch and entries a restore of `$version` writes.
	 * A field the version predates, one it held empty that the head does not hold either, or one whose
	 * ciphertext is the head's own that the patch does not resend, changes nothing: it is left out of the
	 * patch and the copies, so the head and every copy keep theirs, and a partner share that carries it
	 * must match the head's in null-ness. One the version held empty that the head holds is cleared on the patch,
	 * every copy and every partner share, so a client need not send the clear. A patch that carries a value for an
	 * empty field, or none for a field the version holds, would leave the secret half restored and is refused.
	 *
	 * @param array<string,mixed> $patch The patch as sent
	 * @param SecretVersion $version The version restored
	 * @param ShareSaveEntries $entries The entries, staged ones included
	 * @param Secret $head The row restored, as read
	 *
	 * @return array{0:array<string,mixed>,1:ShareSaveEntries,2:array<string,?string>} The patch, the entries,
	 *   and the head's value of each field left out, which partner shares are held to
	 *
	 * @throws ShareSaveRefusal `invalid_body` naming the field the patch gets wrong
	 *
	 * @spec openspec/changes/sharing-atomic-share-save/specs/user-sharing/spec.md#requirement-a-restore-reaches-every-copy-in-the-same-transaction
	 */
	public function split(array $patch, SecretVersion $version, ShareSaveEntries $entries, Secret $head): array {
		$held = ['totp' => $version->getTotp(), 'passkeys' => $version->getPasskeys()];
		$headValues = ['totp' => $head->getTotp(), 'passkeys' => $head->getPasskeys()];
		$kept = [];
		$cleared = [];
		foreach ($held as $field => $value) {
			// A version that predates the field, or holds the head's own
			// ciphertext that the client did not resend, changes nothing.
			$patchHolds = in_array($patch[$field] ?? null, self::NO_BLOB, true) === false;
			if ($value === null || ($value === $headValues[$field] && $patchHolds === false)) {
				$kept[$field] = $headValues[$field];
				continue;
			}

			$clears = $value === '';
			if ($patchHolds === $clears) {
				throw new ShareSaveRefusal(
					error: 'invalid_body',
					message: 'Invalid patch: a restore of this version must carry '.$field.' as the version holds it',
					details: ['field' => $field]
				);
			}

			if ($clears === true && in_array($headValues[$field], self::NO_BLOB, true) === true) {
				$kept[$field] = null;
			} else if ($clears === true) {
				$cleared[$field] = '';
			}
		}//end foreach

		$apply = static fn (array $entry): array => array_merge(array_diff_key($entry, $kept), $cleared);
		$clearPartner = static fn (array $entry): array => $entry + array_fill_keys(array_keys($cleared), null);

		return [
			$apply($patch),
			new ShareSaveEntries(
				copies: array_map($apply, $entries->copies),
				skipped: $entries->skipped,
				federated: array_map($clearPartner, $entries->federated),
				suspend: $entries->suspend,
			),
			$kept,
		];
	}//end split()
}//end class
