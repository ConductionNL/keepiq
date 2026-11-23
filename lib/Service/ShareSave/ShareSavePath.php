<?php

/**
 * Keepiq ShareSavePath
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
 * Who saves what: the owner saving the source (`{id}` is the source), or a
 * copy holder saving through their copy (`{id}` is a recipient copy).
 *
 * @spec openspec/changes/sharing-atomic-share-save/specs/user-sharing/spec.md#requirement-a-shared-secret-and-every-copy-save-in-one-transaction
 */
final class ShareSavePath {
	/**
	 * The source as last read, whose owner decides who writes partner shares.
	 *
	 * @var Secret
	 */
	private readonly Secret $current;

	/**
	 * Constructor.
	 *
	 * @param Secret $row The caller's own row, `{id}`
	 * @param Secret $source The source: `$row` itself on the owner path
	 * @param string|null $writesOn The audit label of the copy holder's write; null for the owner or a delegate
	 * @param Secret|null $current The source as read again inside the transaction; `$source` when not read again
	 *
	 * @return void
	 */
	public function __construct(
		public readonly Secret $row,
		public readonly Secret $source,
		public readonly ?string $writesOn = null,
		?Secret $current = null,
	) {
		$this->current = ($current ?? $source);
	}//end __construct()

	/**
	 * Whether the caller owns the source, so the save writes its partner
	 * shares; on the copy-editor path as the source was last read.
	 *
	 * @param string $userId The caller
	 *
	 * @return bool
	 *
	 * @spec openspec/changes/sharing-share-save-missed-partner-shares/specs/federated-sharing/spec.md#requirement-partner-copies-change-only-with-the-owners-share-save
	 */
	public function ownsSource(string $userId): bool {
		return $this->current->getOwnerType() === 'user' && $this->current->getOwnerId() === $userId;
	}//end ownsSource()

	/**
	 * The source's owner, as the source was last read.
	 *
	 * @return string
	 *
	 * @spec openspec/changes/sharing-share-save-missed-partner-shares/specs/federated-sharing/spec.md#requirement-a-partner-share-the-owner-does-not-own-stops-the-save
	 */
	public function sourceOwnerId(): string {
		return (string)$this->current->getOwnerId();
	}//end sourceOwnerId()

	/**
	 * Whether `{id}` is a recipient copy.
	 *
	 * @return bool
	 *
	 * @spec openspec/changes/sharing-atomic-share-save/specs/user-sharing/spec.md#requirement-a-shared-secret-and-every-copy-save-in-one-transaction
	 */
	public function isCopyEditor(): bool {
		return $this->row->getId() !== $this->source->getId();
	}//end isCopyEditor()

	/**
	 * `owner` or `copy`, as the API names the path.
	 *
	 * @return string
	 *
	 * @spec openspec/changes/sharing-atomic-share-save/specs/user-sharing/spec.md#requirement-a-share-save-names-every-copy-or-is-refused
	 */
	public function getName(): string {
		if ($this->isCopyEditor() === true) {
			return 'copy';
		}

		return 'owner';
	}//end getName()
}//end class
