<?php

/**
 * Keepiq Suite Mint
 *
 * The owner, the password and the key material of one seeded suite.
 *
 * @category Repair
 * @package  OCA\Keepiq\Repair
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

namespace OCA\Keepiq\Repair;

use OCP\Migration\IOutput;

/**
 * Built by SeedDevelopmentData::mintSuite() per suite and never kept: the
 * key pair and the certificate are filled in as each step succeeds.
 *
 * @spec openspec/specs/demo-vault/spec.md#requirement-a-demo-vault-is-minted-only-for-a-named-cast-account
 */
final class SuiteMint {
	/**
	 * The new private key, PEM.
	 *
	 * @var string
	 */
	public string $privateKeyPem = '';

	/**
	 * The new public key, PEM.
	 *
	 * @var string
	 */
	public string $publicKeyPem = '';

	/**
	 * The CA-signed certificate of the public key.
	 *
	 * @var string
	 */
	public string $certificate = '';

	/**
	 * Constructor for SuiteMint.
	 *
	 * @param string  $userId         The owner of the new suite
	 * @param string  $masterPassword The password that unlocks it
	 * @param IOutput $output         The output interface for progress reporting
	 *
	 * @return void
	 */
	public function __construct(
		public readonly string $userId,
		public readonly string $masterPassword,
		public readonly IOutput $output,
	) {
	}//end __construct()
}//end class
