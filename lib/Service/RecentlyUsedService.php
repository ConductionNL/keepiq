<?php

/**
 * Keepiq Recently Used Service
 *
 * The source of the dashboard's Recently used widget: the secrets a user read
 * last, one row per secret, only secrets that user still owns.
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

use DateTimeInterface;
use OCA\Keepiq\Db\SecretMapper;
use OCP\AppFramework\Db\DoesNotExistException;
use OCP\AppFramework\Db\MultipleObjectsReturnedException;

/**
 * Turns the secret.read audit rows into a short list of distinct, live secrets.
 *
 * @spec openspec/changes/vault-defaults-and-recently-used-widget/specs/vault-recently-used/spec.md#requirement-recently-used-on-the-dashboard
 */
class RecentlyUsedService {

	/**
	 * How many read events are scanned to find the distinct secrets. A secret
	 * opened many times in a row takes one row of the widget, not many.
	 *
	 * @var int
	 */
	public const SCAN_WINDOW = 50;

	/**
	 * How many secrets the widget shows.
	 *
	 * @var int
	 */
	public const SHOWN = 5;

	/**
	 * Constructor for RecentlyUsedService.
	 *
	 * @param AuditService $auditService The audit trail
	 * @param SecretMapper $secretMapper The secret mapper (ownership check)
	 *
	 * @return void
	 */
	public function __construct(
		private AuditService $auditService,
		private SecretMapper $secretMapper,
	) {
	}//end __construct()

	/**
	 * The secrets a user read most recently, newest first, one row per secret.
	 *
	 * A secret that no longer exists, that another user now owns or that was
	 * tombstoned is left out, so a row always opens something.
	 *
	 * @param string $userId The user
	 *
	 * @return list<array{id: string, name: string, typeId: string, lastUsedAt: string}>
	 *
	 * @spec openspec/changes/vault-defaults-and-recently-used-widget/specs/vault-recently-used/spec.md#requirement-recently-used-on-the-dashboard
	 */
	public function forUser(string $userId): array {
		$rows = [];
		$seen = [];
		foreach ($this->auditService->recentlyAccessed($userId, self::SCAN_WINDOW) as $entry) {
			$secretId = $entry->getObjectId();
			if ($secretId === null || isset($seen[$secretId]) === true) {
				continue;
			}

			$seen[$secretId] = true;
			try {
				$secret = $this->secretMapper->findById($secretId);
			} catch (DoesNotExistException|MultipleObjectsReturnedException) {
				continue;
			}

			if ($secret->getOwnerType() !== 'user'
				|| $secret->getOwnerId() !== $userId
				|| $secret->getTombstonedAt() !== null
			) {
				continue;
			}

			$rows[] = [
				'id' => $secretId,
				'name' => $secret->getName(),
				'typeId' => $secret->getTypeId(),
				'lastUsedAt' => $entry->getOccurredAt()->format(DateTimeInterface::ATOM),
			];
			if (count($rows) === self::SHOWN) {
				break;
			}
		}//end foreach

		return $rows;
	}//end forUser()
}//end class
