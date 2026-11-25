<?php

/**
 * Keepiq Root Renewal Time
 *
 * The renewal time a root renewal announcement carries, ISO 8601 UTC (`2026-11-25T10:00:00Z`), and the 30 days after
 * it during which the renewal is published and can be accepted (partner-root-renewal-announcement D1, D3, D6). The
 * renewing side and the receiving side both count the 30 days here.
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
use DateTimeZone;

/**
 * Writes and reads an announced renewal time, and ends its window.
 *
 * @spec openspec/changes/partner-root-renewal-announcement/specs/federated-sharing/spec.md#requirement-an-offered-renewal-expires-after-30-days-with-reminders
 */
class RootRenewalTime {
	/**
	 * Days after the renewal time during which a renewal is announced and can be accepted.
	 *
	 * @var int
	 */
	public const WINDOW_DAYS = 30;

	/**
	 * The announced format, always in UTC.
	 *
	 * @var string
	 */
	private const FORMAT = 'Y-m-d\TH:i:s\Z';

	/**
	 * A renewal time as an announcement carries it.
	 *
	 * @param DateTime $renewedAt The renewal time, in any time zone
	 *
	 * @return string
	 *
	 * @spec openspec/changes/partner-root-renewal-announcement/specs/federated-sharing/spec.md#requirement-a-renewed-root-is-announced-to-partners
	 */
	public function format(DateTime $renewedAt): string {
		return $this->utc(time: $renewedAt)->format(self::FORMAT);
	}//end format()

	/**
	 * An announced renewal time, read strictly.
	 *
	 * @param string $renewedAt The announced time
	 *
	 * @return DateTime|null Null unless it is exactly in the announced format
	 *
	 * @spec openspec/changes/partner-root-renewal-announcement/specs/federated-sharing/spec.md#requirement-an-announced-renewal-is-offered-to-administrators-after-its-signature-checks-out
	 */
	public function parse(string $renewedAt): ?DateTime {
		$time = date_create_from_format('!' . self::FORMAT, $renewedAt, new DateTimeZone('UTC'));
		if ($time === false || $time->format(self::FORMAT) !== $renewedAt) {
			return null;
		}

		return $time;
	}//end parse()

	/**
	 * When the window of a renewal ends: 30 days after its renewal time.
	 *
	 * @param DateTime $renewedAt The renewal time
	 *
	 * @return DateTime In UTC
	 *
	 * @spec openspec/changes/partner-root-renewal-announcement/specs/federated-sharing/spec.md#requirement-an-offered-renewal-expires-after-30-days-with-reminders
	 */
	public function windowEnd(DateTime $renewedAt): DateTime {
		return $this->utc(time: $renewedAt)->modify('+' . self::WINDOW_DAYS . ' days');
	}//end windowEnd()

	/**
	 * A copy of a time in UTC.
	 *
	 * @param DateTime $time The time
	 *
	 * @return DateTime
	 */
	private function utc(DateTime $time): DateTime {
		return (clone $time)->setTimezone(new DateTimeZone('UTC'));
	}//end utc()
}//end class
