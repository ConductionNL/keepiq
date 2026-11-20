<?php

/**
 * Keepiq Pending Additional Fields
 *
 * The filled extra-field blobs a secret holds back for its owner's browser.
 *
 * @category Db
 * @package  OCA\Keepiq\Db
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

namespace OCA\Keepiq\Db;

/**
 * The pending extra-field blobs of a secret, oldest first, each with the
 * schemas of the request that filled it, as its `pending_additional_fields`
 * column holds them: a JSON list of entries, each the ciphertext alone or
 * `{fields, schemas}` when the request carried schemas.
 *
 * @spec openspec/specs/vault-custom-fields/spec.md#requirement-a-request-carries-the-schemas-of-its-fields
 */
final class PendingAdditionalFields {
	/**
	 * The entries, oldest first.
	 *
	 * @var list<array{fields:string,schemas:array<string,mixed>}>
	 */
	private array $entries;

	/**
	 * Read the column. Anything that is no entry is skipped.
	 *
	 * @param string|null $column The stored column
	 *
	 * @return void
	 *
	 * @spec openspec/specs/vault-custom-fields/spec.md#requirement-a-request-carries-the-schemas-of-its-fields
	 */
	public function __construct(?string $column) {
		$decoded       = json_decode((string)$column, true);
		$this->entries = [];
		if (is_array($decoded) === true) {
			$this->entries = array_values(array_filter(array_map(self::entryOf(...), $decoded)));
		}
	}//end __construct()

	/**
	 * A copy holding other entries.
	 *
	 * @param list<array{fields:string,schemas:array<string,mixed>}> $entries The entries, oldest first
	 *
	 * @return self
	 */
	private function withEntries(array $entries): self {
		$copy          = clone $this;
		$copy->entries = $entries;
		return $copy;
	}//end withEntries()

	/**
	 * One stored entry as `{fields, schemas}`, or null when it is no entry.
	 *
	 * @param mixed $stored The stored entry
	 *
	 * @return array{fields:string,schemas:array<string,mixed>}|null
	 */
	private static function entryOf(mixed $stored): ?array {
		if (is_string($stored) === true) {
			return ['fields' => $stored, 'schemas' => []];
		}

		if (is_array($stored) === false || is_string($stored['fields'] ?? null) === false) {
			return null;
		}

		return ['fields' => $stored['fields'], 'schemas' => (array)($stored['schemas'] ?? [])];
	}//end entryOf()

	/**
	 * The column to store: null when there are no entries, and an entry
	 * without schemas as its ciphertext alone.
	 *
	 * @return string|null
	 *
	 * @spec openspec/specs/vault-custom-fields/spec.md#requirement-a-request-carries-the-schemas-of-its-fields
	 */
	public function toColumn(): ?string {
		if ($this->entries === []) {
			return null;
		}

		$stored = array_map(
			static fn (array $entry): string|array => match ($entry['schemas']) {
				[] => $entry['fields'],
				default => $entry,
			},
			$this->entries
		);
		// An encoding failure stores '', as the entity's string setter makes of false.
		return (string)json_encode($stored);
	}//end toColumn()

	/**
	 * Whether no blob is pending.
	 *
	 * @return bool
	 *
	 * @spec openspec/changes/request-first-secret-requests/specs/secrets/spec.md#requirement-unfilled-request-placeholder
	 */
	public function isEmpty(): bool {
		return $this->entries === [];
	}//end isEmpty()

	/**
	 * The pending ciphertexts, oldest first.
	 *
	 * @return list<string>
	 *
	 * @spec openspec/specs/secret-requests/spec.md#requirement-requestable-fields
	 */
	public function ciphertexts(): array {
		return array_map(static fn (array $entry): string => $entry['fields'], $this->entries);
	}//end ciphertexts()

	/**
	 * The schemas each blob's request carried, in the same order as
	 * ciphertexts(): an object by field name, empty for a blob held back
	 * without any.
	 *
	 * @return list<object>
	 *
	 * @spec openspec/specs/vault-custom-fields/spec.md#requirement-a-request-carries-the-schemas-of-its-fields
	 */
	public function schemas(): array {
		return array_map(static fn (array $entry): object => (object)$entry['schemas'], $this->entries);
	}//end schemas()

	/**
	 * These entries with one more blob, the newest.
	 *
	 * @param string              $ciphertext The filled blob
	 * @param array<string,mixed> $schemas    The request's field schemas, by name
	 *
	 * @return self
	 *
	 * @spec openspec/specs/vault-custom-fields/spec.md#requirement-a-request-carries-the-schemas-of-its-fields
	 */
	public function with(string $ciphertext, array $schemas): self {
		return $this->withEntries(entries: [...$this->entries, ['fields' => $ciphertext, 'schemas' => $schemas]]);
	}//end with()

	/**
	 * These entries without the oldest $count.
	 *
	 * @param int $count How many blobs to drop
	 *
	 * @return self
	 *
	 * @spec openspec/specs/secret-requests/spec.md#requirement-requestable-fields
	 */
	public function withoutOldest(int $count): self {
		return $this->withEntries(entries: array_slice($this->entries, $count));
	}//end withoutOldest()
}//end class
