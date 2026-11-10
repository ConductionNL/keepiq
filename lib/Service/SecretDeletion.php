<?php

/**
 * Keepiq Secret Deletion
 *
 * Who deletes a user's secret and, when the delete purges it from the trash
 * (vault-trash-and-archive), why: 'owner' when its owner empties it,
 * 'retention' when the trash retention ran out. Decides the audit event the
 * delete records.
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

use OCA\Keepiq\Event\Audit\AuditEventTypes;

/**
 * The actor and purge reason of one delete of a user's secret.
 *
 * @spec openspec/specs/vault-trash-and-archive/spec.md#requirement-restoring-and-purging-trashed-secrets
 */
final class SecretDeletion {

	/**
	 * Capture the delete.
	 *
	 * @param string      $userId      The requesting Nextcloud user ID
	 * @param string|null $purgeReason 'owner' or 'retention' for a purge from the trash, null for a plain delete
	 *
	 * @return void
	 */
	public function __construct(
		public readonly string $userId,
		public readonly ?string $purgeReason,
	) {
	}//end __construct()

	/**
	 * Whether the system purges the secret because the trash retention ran out.
	 *
	 * @return bool
	 *
	 * @spec openspec/specs/vault-trash-and-archive/spec.md#requirement-restoring-and-purging-trashed-secrets
	 */
	public function byRetention(): bool {
		return $this->purgeReason === 'retention';
	}//end byRetention()

	/**
	 * The audit event type: secret.deleted, or secret.purged for a purge.
	 *
	 * @return string
	 *
	 * @spec openspec/specs/vault-trash-and-archive/spec.md#requirement-restoring-and-purging-trashed-secrets
	 */
	public function eventType(): string {
		if ($this->purgeReason === null) {
			return AuditEventTypes::SECRET_DELETED;
		}

		return AuditEventTypes::SECRET_PURGED;
	}//end eventType()

	/**
	 * The audit metadata: the purge reason, nothing for a plain delete.
	 *
	 * @return array<string,string>
	 *
	 * @spec openspec/specs/vault-trash-and-archive/spec.md#requirement-restoring-and-purging-trashed-secrets
	 */
	public function metadata(): array {
		if ($this->purgeReason === null) {
			return [];
		}

		return ['reason' => $this->purgeReason];
	}//end metadata()
}//end class
