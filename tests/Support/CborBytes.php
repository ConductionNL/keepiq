<?php

/**
 * A CBOR byte string for WebAuthnFixture.
 *
 * @category Test
 * @package  OCA\Keepiq\Tests\Support
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

namespace OCA\Keepiq\Tests\Support;

/**
 * Marks raw bytes to be encoded as a CBOR byte string, not a text string.
 */
final class CborBytes {
	/**
	 * Constructor for CborBytes.
	 *
	 * @param string $bytes Raw bytes
	 */
	public function __construct(
		public readonly string $bytes,
	) {
	}//end __construct()
}//end class
