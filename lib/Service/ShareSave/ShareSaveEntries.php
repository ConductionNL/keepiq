<?php

/**
 * Keepiq ShareSaveEntries
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

/**
 * The four lists of a share-save, already parsed: local copies, copies left
 * out for want of a vault key, partner shares and partner shares to suspend.
 * ShareSaveEntryParser documents the shape of each entry.
 *
 * @spec openspec/changes/sharing-atomic-share-save/specs/user-sharing/spec.md#requirement-a-share-save-names-every-copy-or-is-refused
 */
final class ShareSaveEntries {
	/**
	 * Constructor.
	 *
	 * @param list<array<string,mixed>> $copies Local copies to write
	 * @param list<array<string,mixed>> $skipped Local copies left out
	 * @param list<array<string,mixed>> $federated Partner shares to write
	 * @param list<array<string,mixed>> $suspend Partner shares to suspend
	 *
	 * @return void
	 */
	public function __construct(
		public readonly array $copies = [],
		public readonly array $skipped = [],
		public readonly array $federated = [],
		public readonly array $suspend = [],
	) {
	}//end __construct()

	/**
	 * These entries followed by another set's.
	 *
	 * @param ShareSaveEntries $other The later entries
	 *
	 * @return ShareSaveEntries
	 *
	 * @spec openspec/changes/sharing-atomic-share-save/specs/user-sharing/spec.md#requirement-a-large-share-save-is-staged-in-chunks
	 */
	public function merge(ShareSaveEntries $other): ShareSaveEntries {
		return new ShareSaveEntries(
			copies: [...$this->copies, ...$other->copies],
			skipped: [...$this->skipped, ...$other->skipped],
			federated: [...$this->federated, ...$other->federated],
			suspend: [...$this->suspend, ...$other->suspend],
		);
	}//end merge()

	/**
	 * Entries in copies plus skipped.
	 *
	 * @return int
	 *
	 * @spec openspec/changes/sharing-atomic-share-save/specs/user-sharing/spec.md#requirement-a-large-share-save-is-staged-in-chunks
	 */
	public function copyCount(): int {
		return count($this->copies) + count($this->skipped);
	}//end copyCount()

	/**
	 * Entries in federated plus suspend.
	 *
	 * @return int
	 *
	 * @spec openspec/changes/sharing-atomic-share-save/specs/user-sharing/spec.md#requirement-a-large-share-save-is-staged-in-chunks
	 */
	public function federatedCount(): int {
		return count($this->federated) + count($this->suspend);
	}//end federatedCount()

	/**
	 * The secret ids named in copies and skipped.
	 *
	 * @return list<string>
	 *
	 * @spec openspec/changes/sharing-atomic-share-save/specs/user-sharing/spec.md#requirement-a-share-save-names-every-copy-or-is-refused
	 */
	public function copyIds(): array {
		return array_merge(array_column($this->copies, 'secretId'), array_column($this->skipped, 'secretId'));
	}//end copyIds()

	/**
	 * The share ids named in federated and suspend.
	 *
	 * @return list<string>
	 *
	 * @spec openspec/changes/sharing-atomic-share-save/specs/user-sharing/spec.md#requirement-a-share-save-names-every-copy-or-is-refused
	 */
	public function shareIds(): array {
		return array_merge(array_column($this->federated, 'shareId'), array_column($this->suspend, 'shareId'));
	}//end shareIds()

	/**
	 * Refuse an id named twice, across copies and skipped or across
	 * federated and suspend: one entry per row, or the outcome would depend
	 * on the order of the lists.
	 *
	 * @return void
	 *
	 * @throws ShareSaveRefusal `duplicate_entry`
	 *
	 * @spec openspec/changes/sharing-atomic-share-save/specs/user-sharing/spec.md#requirement-a-share-save-names-every-copy-or-is-refused
	 */
	public function assertNoDuplicates(): void {
		foreach ([$this->copyIds(), $this->shareIds()] as $ids) {
			if (count($ids) !== count(array_unique($ids))) {
				throw new ShareSaveRefusal(error: 'duplicate_entry', message: 'An entry is named twice');
			}
		}
	}//end assertNoDuplicates()

	/**
	 * The entries as stored in a stage chunk.
	 *
	 * @return string
	 *
	 * @spec openspec/changes/sharing-atomic-share-save/specs/user-sharing/spec.md#requirement-a-large-share-save-is-staged-in-chunks
	 */
	public function toJson(): string {
		return json_encode(
			[
				'copies' => $this->copies,
				'skipped' => $this->skipped,
				'federated' => $this->federated,
				'suspend' => $this->suspend,
			],
			JSON_THROW_ON_ERROR
		);
	}//end toJson()
}//end class
