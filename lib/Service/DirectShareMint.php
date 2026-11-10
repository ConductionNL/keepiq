<?php

/**
 * Keepiq Direct Share Mint
 *
 * One direct share about to be minted: the row, its resolved source and its restriction.
 *
 * @category Service
 * @package  OCA\Keepiq\Service
 *
 * @author    Conduction Development Team <dev@conductio.nl>
 * @copyright 2024 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git-id>
 *
 * @link https://conduction.nl
 */

declare(strict_types=1);

namespace OCA\Keepiq\Service;

use OCA\Keepiq\Db\Secret;

/**
 * A row that passed every refusal, with the source secret it resolved to and
 * the restriction it asked for; built by DirectShareRegistrar per row.
 *
 * @spec openspec/specs/user-sharing/spec.md#requirement-share-a-secret
 */
final class DirectShareMint {
	/**
	 * Constructor for DirectShareMint.
	 *
	 * @param DirectShareRow $share The row
	 * @param Secret $source The source secret the row resolved to
	 * @param ShareRestriction $restriction The restriction of the row
	 *
	 * @return void
	 */
	public function __construct(
		public readonly DirectShareRow $share,
		public readonly Secret $source,
		public readonly ShareRestriction $restriction,
	) {
	}//end __construct()
}//end class
