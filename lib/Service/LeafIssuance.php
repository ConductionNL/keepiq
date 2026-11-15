<?php

/**
 * Keepiq Leaf Issuance
 *
 * One leaf certificate being issued for a submitted public key.
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

/**
 * The submitted public key and common name, and the active intermediate that
 * signs them; built by CertificateIssuanceService for one issuance.
 *
 * @spec openspec/changes/retrofit-2026-05-25-doriath-coverage/tasks.md#task-1
 */
final class LeafIssuance {
	/**
	 * Constructor for LeafIssuance.
	 *
	 * @param string $publicKeyPem The PEM-encoded public key the certificate must carry
	 * @param string $commonName The common name for the certificate
	 * @param SigningIntermediate $intermediate The intermediate that signs the certificate
	 *
	 * @return void
	 */
	public function __construct(
		public readonly string $publicKeyPem,
		public readonly string $commonName,
		public readonly SigningIntermediate $intermediate,
	) {
	}//end __construct()
}//end class
