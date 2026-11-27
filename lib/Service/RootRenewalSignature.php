<?php

/**
 * Keepiq Root Renewal Signature
 *
 * The signature a renewed root carries (partner-root-renewal-announcement D1): the previous root's key signs the new
 * root's DER with RSA and SHA-256, and anyone holding the previous root's certificate can verify it.
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
use OpenSSLAsymmetricKey;

/**
 * Stateless signing and checking of renewed roots.
 *
 * @spec openspec/changes/partner-root-renewal-announcement/specs/federated-sharing/spec.md#requirement-a-renewed-root-is-announced-to-partners
 */
class RootRenewalSignature {
	/**
	 * The base64 signature of the previous root's key over the new root's DER.
	 *
	 * @param string $newRootPem The new root certificate
	 * @param OpenSSLAsymmetricKey $previousKey The previous root's private key
	 *
	 * @return string|null Null when signing failed
	 *
	 * @spec openspec/changes/partner-root-renewal-announcement/specs/federated-sharing/spec.md#requirement-a-renewed-root-is-announced-to-partners
	 */
	public function sign(string $newRootPem, OpenSSLAsymmetricKey $previousKey): ?string {
		try {
			$der = (new CertificatePem(pem: $newRootPem))->der();
		} catch (InvalidArgumentException) {
			return null;
		}

		if (openssl_sign($der, $signature, $previousKey, OPENSSL_ALGO_SHA256) === false) {
			return null;
		}

		return base64_encode($signature);
	}//end sign()

	/**
	 * Whether the signature is the previous root's over the new root's DER.
	 *
	 * @param CertificatePem $newRoot The new root certificate
	 * @param CertificatePem $previousRoot The previous root certificate
	 * @param string $signature The base64 signature
	 *
	 * @return bool
	 *
	 * @spec openspec/changes/partner-root-renewal-announcement/specs/federated-sharing/spec.md#requirement-an-announced-renewal-is-offered-to-administrators-after-its-signature-checks-out
	 */
	public function verifies(CertificatePem $newRoot, CertificatePem $previousRoot, string $signature): bool {
		$raw = base64_decode($signature, true);
		$previousKey = $previousRoot->publicKey();
		if ($raw === false || $raw === '' || $previousKey === null) {
			return false;
		}

		return openssl_verify($newRoot->der(), $raw, $previousKey, OPENSSL_ALGO_SHA256) === 1;
	}//end verifies()
}//end class
