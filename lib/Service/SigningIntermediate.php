<?php

/**
 * Keepiq Signing Intermediate
 *
 * The active intermediate certificate and its decrypted private key.
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
 * The intermediate that signs leaf certificates: its PEM certificate and its
 * private key, decrypted for one issuance or re-sign run and never stored.
 *
 * @spec openspec/changes/certificate-lifecycle/specs/certificate-lifecycle/spec.md#requirement-guided-renewal
 */
final class SigningIntermediate {
	/**
	 * Constructor for SigningIntermediate.
	 *
	 * @param string $certificate The PEM certificate of the intermediate
	 * @param string $privateKeyPem The decrypted PEM private key of the intermediate
	 *
	 * @return void
	 */
	public function __construct(
		public readonly string $certificate,
		public readonly string $privateKeyPem,
	) {
	}//end __construct()
}//end class
