<?php

/**
 * Keepiq Secret Update Run
 *
 * One owner update of a secret while SecretService applies it: the row being
 * changed, the row as it was loaded, the checked fields, the requesting user,
 * the version the write is locked against and whether a version is being
 * restored. Built by SecretService::update() and never passed outside it.
 *
 * @category Service
 * @package  OCA\Keepiq\Service
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

namespace OCA\Keepiq\Service;

use DateTime;
use OCA\Keepiq\Db\Secret;

/**
 * The state one owner update of a secret shares between its steps.
 *
 * @spec openspec/changes/add-secret-audit-trail/tasks.md#task-3.1
 */
final class SecretUpdateRun {

	/**
	 * The row as loaded, before any field is applied (secret-version-history §2.2).
	 *
	 * @var Secret
	 */
	public readonly Secret $preUpdate;

	/**
	 * The version the write is locked against; null when a share-save locked its own.
	 *
	 * @var DateTime|null
	 */
	public readonly ?DateTime $base;

	/**
	 * Capture the update. The pre-update snapshot is cloned here, before any
	 * field is applied, and the write is locked against the version just read
	 * unless a share-save passed the version it locked in already.
	 *
	 * @param Secret              $secret    The row being changed
	 * @param array<string,mixed> $data      The fields to update, checked by the offline edit guard
	 * @param string              $userId    The requesting Nextcloud user ID
	 * @param DateTime|null       $savedAt   The version a share-save locked in
	 * @param bool                $restoring Whether the data is a version being restored
	 *
	 * @return void
	 */
	public function __construct(
		public readonly Secret $secret,
		public readonly array $data,
		public readonly string $userId,
		public readonly ?DateTime $savedAt,
		public readonly bool $restoring,
	) {
		$this->preUpdate = clone $secret;
		$base = null;
		if ($savedAt === null) {
			$base = $secret->getUpdatedAt();
		}

		$this->base = $base;
	}//end __construct()

	/**
	 * Whether the update sends the given field.
	 *
	 * @param string $field The field name
	 *
	 * @return bool
	 *
	 * @spec openspec/changes/add-secret-audit-trail/tasks.md#task-3.1
	 */
	public function sends(string $field): bool {
		return array_key_exists($field, $this->data);
	}//end sends()
}//end class
