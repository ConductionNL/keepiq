<?php

/**
 * Keepiq OnwardShareGuard
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

use InvalidArgumentException;
use OCA\Keepiq\Db\Secret;

/**
 * Refuses a use-only or expiring recipient copy as the source of any share
 * (sharing-use-only-and-expiring-shares D4), so neither restriction can be
 * escaped by sharing the copy onward. Every share path calls it on its
 * source before creating anything.
 */
final class OnwardShareGuard {

	/**
	 * The refusal message every path reports.
	 *
	 * @var string
	 */
	public const REFUSAL = 'A use-only or time-limited copy cannot be shared onward';

	/**
	 * Whether a secret may be the source of a share.
	 *
	 * @param Secret $source The would-be source
	 *
	 * @return bool
	 *
	 * @spec openspec/changes/sharing-use-only-and-expiring-shares/specs/use-only-shares/spec.md#requirement-the-server-refuses-what-it-can-enforce
	 */
	public static function isShareable(Secret $source): bool {
		return $source->getUseOnly() !== true && $source->getAccessExpiresAt() === null;
	}//end isShareable()

	/**
	 * Refuse a use-only or expiring copy as a share source.
	 *
	 * @param Secret $source The would-be source
	 *
	 * @return void
	 *
	 * @throws InvalidArgumentException When the source is restricted
	 *
	 * @spec openspec/changes/sharing-use-only-and-expiring-shares/specs/use-only-shares/spec.md#requirement-the-server-refuses-what-it-can-enforce
	 * @spec openspec/changes/sharing-use-only-and-expiring-shares/specs/expiring-shares/spec.md#requirement-an-expiring-copy-cannot-be-shared-onward
	 */
	public static function assertShareable(Secret $source): void {
		if (self::isShareable(source: $source) === false) {
			throw new InvalidArgumentException(message: self::REFUSAL);
		}
	}//end assertShareable()
}//end class
