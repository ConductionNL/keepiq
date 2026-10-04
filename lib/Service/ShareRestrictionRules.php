<?php

/**
 * Keepiq ShareRestrictionRules
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
use Exception;
use InvalidArgumentException;

/**
 * The rules for use-only and end dates (sharing-use-only-and-expiring-shares
 * D1, D2): reading them from a request, and combining every grant that
 * reaches one copy. Stateless.
 */
class ShareRestrictionRules {

	/**
	 * Read the two options from an untrusted request body, refusing an end
	 * date that is not in the future.
	 *
	 * @param mixed    $useOnly   The raw `useOnly` value (bool, "true", 1, null)
	 * @param mixed    $expiresAt The raw `expiresAt` value (ISO 8601 string or null)
	 * @param DateTime $now       The current time
	 *
	 * @return ShareRestriction
	 *
	 * @throws InvalidArgumentException When the date cannot be read or is not in the future
	 *
	 * @spec openspec/specs/expiring-shares/spec.md#requirement-shares-and-memberships-can-carry-an-end-date
	 */
	public function fromRequest(mixed $useOnly, mixed $expiresAt, DateTime $now): ShareRestriction {
		$flag = ($useOnly === true || $useOnly === 1 || $useOnly === '1' || $useOnly === 'true');

		if ($expiresAt === null || $expiresAt === '') {
			return new ShareRestriction(useOnly: $flag, expiresAt: null);
		}

		if (is_string($expiresAt) === false) {
			throw new InvalidArgumentException(message: 'expiresAt must be a date');
		}

		try {
			$end = new DateTime($expiresAt);
		} catch (Exception) {
			throw new InvalidArgumentException(message: 'expiresAt must be a date');
		}

		$end->setTimezone(new DateTimeZone('UTC'));
		if ($end <= $now) {
			throw new InvalidArgumentException(message: 'The end date must be in the future');
		}

		return new ShareRestriction(useOnly: $flag, expiresAt: $end);
	}//end fromRequest()

	/**
	 * Combine every grant that reaches one copy: the copy is use-only only
	 * when every grant is, and access ends at the latest end date, with a
	 * grant without an end date winning (the most generous grant wins).
	 * No grants at all gives an unrestricted result.
	 *
	 * @param array<int,ShareRestriction> $grants The grants reaching the copy
	 *
	 * @return ShareRestriction
	 *
	 * @spec openspec/specs/use-only-shares/spec.md#requirement-owners-can-share-a-secret-as-use-only
	 * @spec openspec/specs/expiring-shares/spec.md#requirement-shares-and-memberships-can-carry-an-end-date
	 */
	public function combine(array $grants): ShareRestriction {
		if ($grants === []) {
			return new ShareRestriction();
		}

		$useOnly = true;
		$latest  = null;
		$noEnd   = false;
		foreach ($grants as $grant) {
			$useOnly = ($useOnly && $grant->useOnly);
			if ($grant->expiresAt === null) {
				$noEnd = true;
				continue;
			}

			if ($latest === null || $grant->expiresAt > $latest) {
				$latest = $grant->expiresAt;
			}
		}

		if ($noEnd === true) {
			$latest = null;
		}

		return new ShareRestriction(useOnly: $useOnly, expiresAt: $latest);
	}//end combine()

}//end class
