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
 * @SuppressWarnings(PHPMD.BooleanArgumentFlag) `$teamWriter` is a fact the
 *   value carries for the audit, not a switch in this class's behaviour.
 *
 * @spec openspec/changes/sharing-atomic-share-save/specs/user-sharing/spec.md#requirement-a-shared-secret-and-every-copy-save-in-one-transaction
 */
final class ShareSavePath {
	/**
	 * Constructor.
	 *
	 * @param Secret $row The caller's own row, `{id}`
	 * @param Secret $source The source: `$row` itself on the owner path
	 * @param bool $teamWriter Whether the copy holder writes on a team-folder grade
	 *
	 * @return void
	 */
	public function __construct(
		public readonly Secret $row,
		public readonly Secret $source,
		public readonly bool $teamWriter = false,
	) {
	}//end __construct()

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
