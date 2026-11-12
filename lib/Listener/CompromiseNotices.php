<?php

/**
 * Keepiq Compromise Notices
 *
 * The owners one compromise migration has told already.
 *
 * @category Listener
 * @package  OCA\Keepiq\Listener
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

namespace OCA\Keepiq\Listener;

use OCA\Keepiq\Event\SuiteMigrationCompletedEvent;

/**
 * Built by SuiteCompromiseListener per completed migration and never kept,
 * so each owner is told once however many of their secrets it touched.
 *
 * @spec openspec/specs/user-sharing/spec.md#requirement-encryptionsuite-compromise-shared-copy-migration-and-owner-notification
 */
final class CompromiseNotices {
	/**
	 * The owners told so far, as keys.
	 *
	 * @var array<string,true>
	 */
	private array $notified = [];

	/**
	 * Constructor for CompromiseNotices.
	 *
	 * @param SuiteMigrationCompletedEvent $event The completed migration
	 *
	 * @return void
	 */
	public function __construct(
		public readonly SuiteMigrationCompletedEvent $event,
	) {
	}//end __construct()

	/**
	 * Whether an owner is told already, or has no id to be told by.
	 *
	 * @param string $ownerId The owner
	 *
	 * @return bool
	 *
	 * @spec openspec/specs/user-sharing/spec.md#requirement-encryptionsuite-compromise-shared-copy-migration-and-owner-notification
	 */
	public function skips(string $ownerId): bool {
		return $ownerId === '' || isset($this->notified[$ownerId]) === true;
	}//end skips()

	/**
	 * Record that an owner is told.
	 *
	 * @param string $ownerId The owner
	 *
	 * @return void
	 *
	 * @spec openspec/specs/user-sharing/spec.md#requirement-encryptionsuite-compromise-shared-copy-migration-and-owner-notification
	 */
	public function told(string $ownerId): void {
		$this->notified[$ownerId] = true;
	}//end told()
}//end class
