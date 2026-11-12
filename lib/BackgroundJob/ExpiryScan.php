<?php

/**
 * Keepiq Expiry Scan
 *
 * The instance-wide reminder days and the instant of one expiry scan.
 *
 * @category BackgroundJob
 * @package  OCA\Keepiq\BackgroundJob
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

namespace OCA\Keepiq\BackgroundJob;

use DateTimeInterface;

/**
 * Built once per run by ScanExpiringSecretsJob and shared by every secret
 * it scans.
 *
 * @spec openspec/changes/rotation-expiry-policies/specs/rotation-expiry-policies/spec.md
 */
final class ExpiryScan {
	/**
	 * Constructor for ExpiryScan.
	 *
	 * @param array<int,mixed>  $thresholds The instance-wide reminder days
	 * @param DateTimeInterface $now        The scan instant
	 *
	 * @return void
	 */
	public function __construct(
		public readonly array $thresholds,
		public readonly DateTimeInterface $now,
	) {
	}//end __construct()

	/**
	 * Whole days from the scan instant to an expiry, rounded down.
	 *
	 * @param DateTimeInterface $expiry The effective expiry
	 *
	 * @return int Negative once overdue
	 *
	 * @spec openspec/changes/rotation-expiry-policies/specs/rotation-expiry-policies/spec.md
	 */
	public function daysUntil(DateTimeInterface $expiry): int {
		return (int)floor(($expiry->getTimestamp() - $this->now->getTimestamp()) / 86400);
	}//end daysUntil()
}//end class
