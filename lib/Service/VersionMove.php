<?php

/**
 * Keepiq Version Move
 *
 * The version a locked write read a row at, and the version it moves the row to.
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

/**
 * One compare-and-set step of a row's version: from `$base` to `$savedAt`.
 *
 * @spec openspec/specs/offline-edit-queue/spec.md#requirement-concurrent-server-changes-are-never-overwritten-silently
 */
final class VersionMove {
	/**
	 * Constructor for VersionMove.
	 *
	 * @param DateTime $base The version the write was read at
	 * @param DateTime $savedAt The new version, after `$base`
	 *
	 * @return void
	 */
	public function __construct(
		public readonly DateTime $base,
		public readonly DateTime $savedAt,
	) {
	}//end __construct()
}//end class
