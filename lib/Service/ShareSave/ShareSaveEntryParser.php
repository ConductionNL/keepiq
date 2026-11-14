<?php

/**
 * Keepiq ShareSaveEntryParser
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

/**
 * Parses the four lists of a share-save body and the objects in it:
 *
 * - copies: `{secretId, key?, login?, additionalFields?, totp?, passkeys?}`, sparse;
 * - skipped: `{secretId, reason:'no_vault_key'}`;
 * - federated: `{shareId, certFingerprint, key, login, additionalFields, totp?, passkeys?}`, whole value,
 *   where an older client leaves out the fields it does not know;
 * - suspend: `{shareId, reason}`.
 *
 * @spec openspec/changes/sharing-atomic-share-save/specs/user-sharing/spec.md#requirement-a-share-save-names-every-copy-or-is-refused
 */
final class ShareSaveEntryParser {
	/**
	 * The four lists of a body; an absent list is empty.
	 *
	 * @param array<array-key,mixed> $params The request parameters
	 *
	 * @return ShareSaveEntries
	 *
	 * @throws ShareSaveRefusal `invalid_body`, `invalid_fingerprint` or `invalid_key`
	 *
	 * @spec openspec/changes/sharing-atomic-share-save/specs/user-sharing/spec.md#requirement-a-share-save-names-every-copy-or-is-refused
	 */
	public function entries(array $params): ShareSaveEntries {
		return new ShareSaveEntries(
			copies: $this->parseList(list: $params['copies'] ?? [], parser: $this->parseCopy(...)),
			skipped: $this->parseList(list: $params['skipped'] ?? [], parser: $this->parseSkip(...)),
			federated: $this->parseList(list: $params['federated'] ?? [], parser: $this->parseFederated(...)),
			suspend: $this->parseList(list: $params['suspend'] ?? [], parser: $this->parseSuspend(...)),
		);
	}//end entries()

	/**
	 * An object with these keys and no others; the old `encryptedKey` names
	 * are refused here.
	 *
	 * @param mixed $entry The entry
	 * @param string[] $required Keys it must have
	 * @param string[] $optional Keys it may have
	 *
	 * @return array<string,mixed>
	 *
	 * @throws ShareSaveRefusal `invalid_body`
	 *
	 * @spec openspec/changes/sharing-atomic-share-save/specs/user-sharing/spec.md#requirement-a-share-save-names-every-copy-or-is-refused
	 */
	public function entry(mixed $entry, array $required, array $optional): array {
		if (is_array($entry) === false || ($entry !== [] && array_is_list($entry) === true)) {
			throw $this->invalidBody(what: 'an entry');
		}

		$keys = array_map('strval', array_keys($entry));
		if (array_diff($required, $keys) !== [] || array_diff($keys, $required, $optional) !== []) {
			throw $this->invalidBody(what: 'the keys of an entry');
		}

		return $entry;
	}//end entry()

	/**
	 * Refuse an empty `key`: a row without ciphertext cannot be opened.
	 *
	 * @param array<string,mixed> $entry The entry
	 *
	 * @return void
	 *
	 * @throws ShareSaveRefusal `invalid_key`
	 *
	 * @spec openspec/changes/sharing-atomic-share-save/specs/user-sharing/spec.md#requirement-a-share-save-names-every-copy-or-is-refused
	 */
	public function assertKeyNotEmpty(array $entry): void {
		if (array_key_exists('key', $entry) === true && $entry['key'] === '') {
			throw new ShareSaveRefusal(error: 'invalid_key', message: 'A key may not be empty');
		}
	}//end assertKeyNotEmpty()

	/**
	 * The `invalid_body` refusal.
	 *
	 * @param string $what What was wrong
	 *
	 * @return ShareSaveRefusal
	 *
	 * @spec openspec/changes/sharing-atomic-share-save/specs/user-sharing/spec.md#requirement-a-share-save-names-every-copy-or-is-refused
	 */
	public function invalidBody(string $what): ShareSaveRefusal {
		return new ShareSaveRefusal(error: 'invalid_body', message: 'Invalid '.$what);
	}//end invalidBody()

	/**
	 * One local copy.
	 *
	 * @param mixed $entry The entry
	 *
	 * @return array<string,mixed>
	 *
	 * @throws ShareSaveRefusal `invalid_body` or `invalid_key`
	 */
	private function parseCopy(mixed $entry): array {
		$copy = $this->entry(entry: $entry, required: ['secretId'], optional: Secret::VALUE_FIELDS);
		$this->assertId(value: $copy['secretId'], what: 'copies.secretId');
		$this->assertCiphertextTypes(entry: $copy, what: 'copies');
		$this->assertKeyNotEmpty(entry: $copy);

		return $copy;
	}//end parseCopy()

	/**
	 * One copy left out.
	 *
	 * @param mixed $entry The entry
	 *
	 * @return array<string,mixed>
	 *
	 * @throws ShareSaveRefusal `invalid_body`
	 */
	private function parseSkip(mixed $entry): array {
		$skip = $this->entry(entry: $entry, required: ['secretId', 'reason'], optional: []);
		$this->assertId(value: $skip['secretId'], what: 'skipped.secretId');
		if ($skip['reason'] !== 'no_vault_key') {
			throw $this->invalidBody(what: 'skipped.reason');
		}

		return $skip;
	}//end parseSkip()

	/**
	 * One partner share; the fingerprint comes back lowercased.
	 *
	 * @param mixed $entry The entry
	 *
	 * @return array<string,mixed>
	 *
	 * @throws ShareSaveRefusal `invalid_body`, `invalid_fingerprint` or `invalid_key`
	 */
	private function parseFederated(mixed $entry): array {
		$share = $this->entry(
			entry: $entry,
			required: ['shareId', 'certFingerprint', ...Secret::LEGACY_VALUE_FIELDS],
			optional: array_values(array_diff(Secret::VALUE_FIELDS, Secret::LEGACY_VALUE_FIELDS))
		);
		$this->assertId(value: $share['shareId'], what: 'federated.shareId');
		$this->assertCiphertextTypes(entry: $share, what: 'federated');
		if (is_string($share['certFingerprint']) === false) {
			throw $this->invalidBody(what: 'federated.certFingerprint');
		}

		$share['certFingerprint'] = strtolower($share['certFingerprint']);
		if (preg_match('/^[0-9a-f]{64}$/', $share['certFingerprint']) !== 1) {
			throw new ShareSaveRefusal(error: 'invalid_fingerprint', message: 'certFingerprint must be 64 hex characters');
		}

		$this->assertKeyNotEmpty(entry: $share);

		return $share;
	}//end parseFederated()

	/**
	 * One partner share to suspend.
	 *
	 * @param mixed $entry The entry
	 *
	 * @return array<string,mixed>
	 *
	 * @throws ShareSaveRefusal `invalid_body`
	 */
	private function parseSuspend(mixed $entry): array {
		$suspend = $this->entry(entry: $entry, required: ['shareId', 'reason'], optional: []);
		$this->assertId(value: $suspend['shareId'], what: 'suspend.shareId');
		if (is_string($suspend['reason']) === false || preg_match('/^[a-z_]{1,32}$/', $suspend['reason']) !== 1) {
			throw $this->invalidBody(what: 'suspend.reason');
		}

		return $suspend;
	}//end parseSuspend()

	/**
	 * A list parameter, each entry through its parser.
	 *
	 * @param mixed $list The parameter
	 * @param callable(mixed):array<string,mixed> $parser The entry parser
	 *
	 * @return list<array<string,mixed>>
	 *
	 * @throws ShareSaveRefusal `invalid_body`
	 */
	private function parseList(mixed $list, callable $parser): array {
		if (is_array($list) === false || array_is_list($list) === false) {
			throw $this->invalidBody(what: 'a list');
		}

		return array_map($parser, $list);
	}//end parseList()

	/**
	 * Refuse an id that is no non-empty string.
	 *
	 * @param mixed $value The id
	 * @param string $what Where it was
	 *
	 * @return void
	 *
	 * @throws ShareSaveRefusal `invalid_body`
	 */
	private function assertId(mixed $value, string $what): void {
		if (is_string($value) === false || $value === '') {
			throw $this->invalidBody(what: $what);
		}
	}//end assertId()

	/**
	 * Refuse a value field of the wrong type: `key` a string, the others a
	 * string or null.
	 *
	 * @param array<string,mixed> $entry The entry
	 * @param string $what Where it was
	 *
	 * @return void
	 *
	 * @throws ShareSaveRefusal `invalid_body`
	 */
	private function assertCiphertextTypes(array $entry, string $what): void {
		foreach (Secret::VALUE_FIELDS as $field) {
			if (array_key_exists($field, $entry) === false) {
				continue;
			}

			$value = $entry[$field];
			if (is_string($value) === false && ($field === 'key' || $value !== null)) {
				throw $this->invalidBody(what: $what.'.'.$field);
			}
		}
	}//end assertCiphertextTypes()
}//end class
