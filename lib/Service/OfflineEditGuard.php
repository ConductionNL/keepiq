<?php

/**
 * Keepiq OfflineEditGuard
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
use Exception;
use InvalidArgumentException;
use OCA\Keepiq\Db\Secret;
use OCA\Keepiq\Exception\StaleWriteException;

/**
 * The precondition an offline edit replays with: a write that names the
 * version it was made from (`baseUpdatedAt`) is refused, untouched, when the
 * secret changed since (offline-edit-queue). A write without a base is not
 * checked, so online clients behave as before.
 *
 * @spec openspec/specs/offline-edit-queue/spec.md#requirement-concurrent-server-changes-are-never-overwritten-silently
 */
class OfflineEditGuard {

	/**
	 * Check an update's base and return its data without the base key.
	 *
	 * @param Secret $secret The stored secret
	 * @param array<string,mixed> $data The update data, possibly with `baseUpdatedAt`
	 *
	 * @return array<string,mixed> The data without `baseUpdatedAt`
	 *
	 * @throws StaleWriteException When the secret changed since the base
	 * @throws InvalidArgumentException When the base is not a date
	 *
	 * @spec openspec/specs/offline-edit-queue/spec.md#requirement-concurrent-server-changes-are-never-overwritten-silently
	 */
	public function checkedUpdate(Secret $secret, array $data): array {
		$this->assertUnchangedSince(secret: $secret, baseUpdatedAt: $data['baseUpdatedAt'] ?? null);
		unset($data['baseUpdatedAt']);

		return $data;
	}//end checkedUpdate()

	/**
	 * Refuse a write based on an older version of the secret. A null or empty
	 * base means the caller did not ask for the check.
	 *
	 * @param Secret $secret The stored secret
	 * @param mixed $baseUpdatedAt The `updatedAt` the client's copy was made from
	 *
	 * @return void
	 *
	 * @throws StaleWriteException When the secret changed since
	 * @throws InvalidArgumentException When the base is not a date
	 *
	 * @spec openspec/specs/offline-edit-queue/spec.md#requirement-concurrent-server-changes-are-never-overwritten-silently
	 */
	public function assertUnchangedSince(Secret $secret, mixed $baseUpdatedAt): void {
		if ($baseUpdatedAt === null || $baseUpdatedAt === '') {
			return;
		}

		try {
			$base = new DateTime((string)$baseUpdatedAt);
		} catch (Exception) {
			throw new InvalidArgumentException(message: 'baseUpdatedAt must be a date');
		}

		$stored = $secret->getUpdatedAt();
		if ($stored === null || $stored->getTimestamp() !== $base->getTimestamp()) {
			throw new StaleWriteException(current: $secret);
		}
	}//end assertUnchangedSince()
}//end class
