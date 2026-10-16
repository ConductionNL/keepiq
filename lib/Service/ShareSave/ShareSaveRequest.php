<?php

/**
 * Keepiq ShareSaveRequest
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

use DateTime;
use Exception;
use OCA\Keepiq\Service\SecretService;

/**
 * The body of `PUT /secrets/{id}/share-save`, parsed without touching the
 * database. Every shape error is a 400 with its own `error` code.
 *
 * @spec openspec/changes/sharing-atomic-share-save/specs/user-sharing/spec.md#requirement-a-shared-secret-and-every-copy-save-in-one-transaction
 */
final class ShareSaveRequest {
	/**
	 * Most entries in copies plus skipped, and in federated plus suspend, per call.
	 *
	 * @var int
	 */
	public const MAX_ENTRIES = 100;

	/**
	 * The `{id}` row's version the edit was made from.
	 *
	 * @var DateTime
	 */
	public readonly DateTime $baseUpdatedAt;

	/**
	 * The source's version, on the copy-editor path.
	 *
	 * @var DateTime|null
	 */
	public readonly ?DateTime $sourceBaseUpdatedAt;

	/**
	 * The sparse patch of the `{id}` row; null on a chunk.
	 *
	 * @var array<string,mixed>|null
	 */
	public readonly ?array $patch;

	/**
	 * The four lists.
	 *
	 * @var ShareSaveEntries
	 */
	public readonly ShareSaveEntries $entries;

	/**
	 * The stage to add to or finish.
	 *
	 * @var string|null
	 */
	public readonly ?string $stageId;

	/**
	 * Whether this call finishes the stage; null without a stage.
	 *
	 * @var bool|null
	 */
	public readonly ?bool $final;

	/**
	 * The version a restore comes from; null for an ordinary save.
	 *
	 * @var string|null
	 */
	public readonly ?string $restoredFrom;

	/**
	 * The entry parser.
	 *
	 * @var ShareSaveEntryParser
	 */
	private ShareSaveEntryParser $parser;

	/**
	 * Parse a request body.
	 *
	 * @param array<array-key,mixed> $params The request parameters
	 *
	 * @return void
	 *
	 * @throws ShareSaveRefusal A 400 naming what is wrong
	 */
	public function __construct(array $params) {
		$this->parser = new ShareSaveEntryParser();
		$base = $params['baseUpdatedAt'] ?? null;
		if ($base === null || $base === '') {
			throw new ShareSaveRefusal(error: 'base_required', message: 'baseUpdatedAt is required');
		}

		$stage = $this->parseStage(stage: $params['stage'] ?? null);
		$this->stageId = $stage[0];
		$this->final = $stage[1];

		// A chunk's patch is checked if sent, but only the final call's is applied.
		$patch = $this->parsePatch(patch: $params['patch'] ?? null, required: $this->isChunk() === false);
		if ($this->isChunk() === true) {
			$patch = null;
		}

		$this->patch = $patch;
		$this->restoredFrom = $this->parseRestoredFrom(restoredFrom: $params['restoredFrom'] ?? null);
		$this->entries = $this->parser->entries(params: $params);
		$this->assertEntryCounts();

		$this->baseUpdatedAt = $this->parseDate(value: $base);
		$this->sourceBaseUpdatedAt = $this->parseOptionalDate(value: $params['sourceBaseUpdatedAt'] ?? null);
		$this->assertConsistent(entries: $this->entries);
	}//end __construct()

	/**
	 * Whether this call only adds to a stage.
	 *
	 * @return bool
	 *
	 * @spec openspec/changes/sharing-atomic-share-save/specs/user-sharing/spec.md#requirement-a-large-share-save-is-staged-in-chunks
	 */
	public function isChunk(): bool {
		return $this->final === false;
	}//end isChunk()

	/**
	 * Refuse entries that name a row twice or, next to a patch, carry other
	 * value fields than the patch: a copy that kept a cleared field would
	 * show the recipient another value than the editor saved.
	 *
	 * @param ShareSaveEntries $entries The entries, staged ones included
	 *
	 * @return void
	 *
	 * @throws ShareSaveRefusal `copy_fields_mismatch` or `duplicate_entry`
	 *
	 * @spec openspec/changes/sharing-atomic-share-save/specs/user-sharing/spec.md#requirement-a-share-save-names-every-copy-or-is-refused
	 */
	public function assertConsistent(ShareSaveEntries $entries): void {
		if ($this->patch !== null) {
			$fields = array_values(array_intersect(ShareSaveEntryParser::VALUE_FIELDS, array_keys($this->patch)));
			foreach ($entries->copies as $copy) {
				if ($this->copyMatchesPatch(copy: $copy, fields: $fields) === false) {
					throw new ShareSaveRefusal(
						error: 'copy_fields_mismatch',
						message: 'Copy '.$copy['secretId'].' does not carry the fields the patch carries'
					);
				}
			}
		}

		$entries->assertNoDuplicates();
	}//end assertConsistent()

	/**
	 * Refuse a call with more than MAX_ENTRIES per list pair, or a chunk
	 * with none: an empty chunk only grows the stage table.
	 *
	 * @return void
	 *
	 * @throws ShareSaveRefusal `too_many_entries` or `empty_chunk`
	 */
	private function assertEntryCounts(): void {
		if ($this->entries->copyCount() > self::MAX_ENTRIES || $this->entries->federatedCount() > self::MAX_ENTRIES) {
			throw new ShareSaveRefusal(
				error: 'too_many_entries',
				message: 'At most '.self::MAX_ENTRIES.' copies and '.self::MAX_ENTRIES.' partner shares per call'
			);
		}

		if ($this->isChunk() === true && $this->entries->copyCount() + $this->entries->federatedCount() === 0) {
			throw new ShareSaveRefusal(error: 'empty_chunk', message: 'A chunk must carry at least one entry');
		}
	}//end assertEntryCounts()

	/**
	 * The version a restore comes from. Only a call that saves may restore,
	 * and it carries the whole value, so no field of the head survives.
	 *
	 * @param mixed $restoredFrom The `restoredFrom` parameter
	 *
	 * @return string|null
	 *
	 * @throws ShareSaveRefusal `invalid_body`
	 */
	private function parseRestoredFrom(mixed $restoredFrom): ?string {
		if ($restoredFrom === null) {
			return null;
		}

		if (is_string($restoredFrom) === false || $restoredFrom === '' || $this->patch === null) {
			throw $this->parser->invalidBody(what: 'restoredFrom');
		}

		if (array_diff(ShareSaveEntryParser::VALUE_FIELDS, array_keys($this->patch)) !== []) {
			throw $this->parser->invalidBody(what: 'patch: a restore carries key, login and additionalFields');
		}

		return $restoredFrom;
	}//end parseRestoredFrom()

	/**
	 * Whether a copy carries exactly the patch's value fields, null where the
	 * patch clears one.
	 *
	 * @param array<string,mixed> $copy The copy entry
	 * @param string[] $fields The patch's value fields
	 *
	 * @return bool
	 */
	private function copyMatchesPatch(array $copy, array $fields): bool {
		$copyFields = array_values(array_intersect(ShareSaveEntryParser::VALUE_FIELDS, array_keys($copy)));
		if ($copyFields !== $fields) {
			return false;
		}

		foreach ($fields as $field) {
			if (($copy[$field] === null) !== ($this->patch[$field] === null)) {
				return false;
			}
		}

		return true;
	}//end copyMatchesPatch()

	/**
	 * The stage id and whether the call is final; both null without a stage.
	 *
	 * @param mixed $stage The `stage` parameter
	 *
	 * @return array{0:string|null,1:bool|null}
	 *
	 * @throws ShareSaveRefusal `invalid_body`
	 */
	private function parseStage(mixed $stage): array {
		if ($stage === null) {
			return [null, null];
		}

		$entry = $this->parser->entry(entry: $stage, required: ['final'], optional: ['id']);
		$stageId = $entry['id'] ?? null;
		$uuid = '/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/';
		if (is_bool($entry['final']) === false
			|| ($stageId !== null && (is_string($stageId) === false || preg_match($uuid, $stageId) !== 1))
		) {
			throw $this->parser->invalidBody(what: 'stage');
		}

		return [$stageId, $entry['final']];
	}//end parseStage()

	/**
	 * The patch: the fields `PUT /secrets/{id}` takes, with at least one value field.
	 *
	 * @param mixed $patch The `patch` parameter
	 * @param bool $required Whether the call must carry one
	 *
	 * @return array<string,mixed>|null
	 *
	 * @throws ShareSaveRefusal `invalid_body`, `no_value_change` or `invalid_key`
	 */
	private function parsePatch(mixed $patch, bool $required): ?array {
		if ($patch === null && $required === false) {
			return null;
		}

		$patch = $this->parser->entry(
			entry: $patch ?? [],
			required: [],
			optional: [...SecretService::UPDATABLE_FIELDS, 'mergedPending']
		);
		foreach ($patch as $field => $value) {
			$valid = match ($field) {
				'key' => is_string($value),
				'mergedPending' => is_int($value) && $value >= 0,
				default => $value === null || is_string($value),
			};
			if ($valid === false) {
				throw $this->parser->invalidBody(what: 'patch.'.$field);
			}
		}

		if (array_intersect(ShareSaveEntryParser::VALUE_FIELDS, array_keys($patch)) === []) {
			throw new ShareSaveRefusal(error: 'no_value_change', message: 'The patch changes no encrypted field');
		}

		$this->parser->assertKeyNotEmpty(entry: $patch);
		return $patch;
	}//end parsePatch()

	/**
	 * A date parameter, or null when absent.
	 *
	 * @param mixed $value The parameter
	 *
	 * @return DateTime|null
	 *
	 * @throws ShareSaveRefusal `invalid_body`
	 */
	private function parseOptionalDate(mixed $value): ?DateTime {
		if ($value === null || $value === '') {
			return null;
		}

		return $this->parseDate(value: $value);
	}//end parseOptionalDate()

	/**
	 * A date parameter.
	 *
	 * @param mixed $value The parameter
	 *
	 * @return DateTime
	 *
	 * @throws ShareSaveRefusal `invalid_body`
	 */
	private function parseDate(mixed $value): DateTime {
		if (is_string($value) === false) {
			throw $this->parser->invalidBody(what: 'a date');
		}

		try {
			return new DateTime($value);
		} catch (Exception) {
			throw $this->parser->invalidBody(what: 'a date');
		}
	}//end parseDate()
}//end class
