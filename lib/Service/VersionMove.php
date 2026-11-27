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
use InvalidArgumentException;

/**
 * One compare-and-set step of a row's version: from `$base` to `$savedAt`.
 *
 * The step is only a lock when it moves the row forward: with `$savedAt` at
 * `$base`, two writers could both match the base, and MySQL counts an
 * unchanged row as 0 rows changed. Both moments are held as copies, so no
 * caller or reader can move them afterwards.
 *
 * @spec openspec/specs/offline-edit-queue/spec.md#requirement-concurrent-server-changes-are-never-overwritten-silently
 */
final class VersionMove {

	/**
	 * The version the write was read at.
	 *
	 * @var DateTime
	 */
	private readonly DateTime $base;

	/**
	 * The new version, in whole seconds after `$base`.
	 *
	 * @var DateTime
	 */
	private readonly DateTime $savedAt;

	/**
	 * Constructor for VersionMove.
	 *
	 * @param DateTime $base The version the write was read at
	 * @param DateTime $savedAt The new version, after `$base`
	 *
	 * @return void
	 *
	 * @throws InvalidArgumentException When `$savedAt` is not after `$base` in whole seconds, the unit the version column stores
	 */
	public function __construct(DateTime $base, DateTime $savedAt) {
		if ($savedAt->getTimestamp() <= $base->getTimestamp()) {
			throw new InvalidArgumentException(message: 'A version move must move forward: savedAt must be after base');
		}

		$this->base = clone $base;
		$this->savedAt = clone $savedAt;
	}//end __construct()

	/**
	 * The version the write was read at, as a copy.
	 *
	 * @return DateTime
	 *
	 * @spec openspec/specs/offline-edit-queue/spec.md#requirement-concurrent-server-changes-are-never-overwritten-silently
	 */
	public function base(): DateTime {
		return clone $this->base;
	}//end base()

	/**
	 * The new version, as a copy.
	 *
	 * @return DateTime
	 *
	 * @spec openspec/specs/offline-edit-queue/spec.md#requirement-concurrent-server-changes-are-never-overwritten-silently
	 */
	public function savedAt(): DateTime {
		return clone $this->savedAt;
	}//end savedAt()
}//end class
