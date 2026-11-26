<?php

/**
 * Keepiq Certificate PEM
 *
 * One certificate read strictly from PEM: exactly one `CERTIFICATE` block with only whitespace around it, whose DER is
 * exactly one certificate. Its fingerprint, public key and fields all come from that one DER, so every check made on
 * it reads the same certificate.
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
use OCA\Keepiq\Support\SuppressesDiagnostics;
use OpenSSLAsymmetricKey;
use OpenSSLCertificate;

/**
 * A strictly parsed PEM certificate.
 *
 * @spec openspec/specs/federated-sharing/spec.md#requirement-administrators-approve-and-pin-partner-instances
 * @spec openspec/changes/partner-root-renewal-announcement/specs/federated-sharing/spec.md#requirement-an-announced-renewal-is-offered-to-administrators-after-its-signature-checks-out
 */
final class CertificatePem {
	use SuppressesDiagnostics;

	/**
	 * The certificate's DER bytes.
	 *
	 * @var string
	 */
	private string $der;

	/**
	 * The certificate OpenSSL read from that DER.
	 *
	 * @var OpenSSLCertificate
	 */
	private OpenSSLCertificate $certificate;

	/**
	 * Read a PEM certificate strictly: the one block is decoded, and its DER read as a certificate from a PEM rebuilt
	 * from that DER alone.
	 *
	 * @param string $pem The PEM text
	 *
	 * @return void
	 *
	 * @throws InvalidArgumentException For anything but exactly one `CERTIFICATE` block holding exactly one certificate
	 *
	 * @spec openspec/changes/partner-root-renewal-announcement/specs/federated-sharing/spec.md#requirement-an-announced-renewal-is-offered-to-administrators-after-its-signature-checks-out
	 */
	public function __construct(string $pem) {
		$block = '/\A\s*-----BEGIN CERTIFICATE-----([A-Za-z0-9+\/=\s]+)-----END CERTIFICATE-----\s*\z/';
		$der = false;
		if (preg_match($block, $pem, $match) === 1) {
			$der = base64_decode(preg_replace('/\s+/', '', $match[1]) ?? '', true);
		}

		if ($der === false || $der === '') {
			throw new InvalidArgumentException('not_one_certificate');
		}

		$canonical = "-----BEGIN CERTIFICATE-----\n" . chunk_split(base64_encode($der), 64, "\n") . "-----END CERTIFICATE-----\n";
		$certificate = $this->withoutDiagnostics(call: static fn () => openssl_x509_read($canonical));
		// Bytes after the certificate would be hashed but not read, so the certificate must be the whole DER.
		if ($certificate === false || openssl_x509_fingerprint($certificate, 'sha256') !== hash('sha256', $der)) {
			throw new InvalidArgumentException('not_one_certificate');
		}

		$this->der = $der;
		$this->certificate = $certificate;
	}//end __construct()

	/**
	 * The certificate's DER bytes.
	 *
	 * @return string
	 *
	 * @spec openspec/changes/partner-root-renewal-announcement/specs/federated-sharing/spec.md#requirement-a-renewed-root-is-announced-to-partners
	 */
	public function der(): string {
		return $this->der;
	}//end der()

	/**
	 * Lowercase hex SHA-256 over the DER.
	 *
	 * @return string
	 *
	 * @spec openspec/specs/federated-sharing/spec.md#requirement-administrators-approve-and-pin-partner-instances
	 */
	public function fingerprint(): string {
		return hash('sha256', $this->der);
	}//end fingerprint()

	/**
	 * The certificate's public key.
	 *
	 * @return OpenSSLAsymmetricKey|null
	 *
	 * @spec openspec/changes/partner-root-renewal-announcement/specs/federated-sharing/spec.md#requirement-an-announced-renewal-is-offered-to-administrators-after-its-signature-checks-out
	 */
	public function publicKey(): ?OpenSSLAsymmetricKey {
		$certificate = $this->certificate;
		$key = $this->withoutDiagnostics(call: static fn () => openssl_pkey_get_public($certificate));
		if ($key === false) {
			return null;
		}

		return $key;
	}//end publicKey()

	/**
	 * Whether the certificate is a CA certificate valid at the given time. As the browser's chain check does
	 * (RFC 5280 4.2.1.3), a keyUsage, when there is one, must allow signing certificates.
	 *
	 * @param int $now The time, as a Unix timestamp
	 *
	 * @return bool
	 *
	 * @spec openspec/changes/partner-root-renewal-announcement/specs/federated-sharing/spec.md#requirement-an-announced-renewal-is-offered-to-administrators-after-its-signature-checks-out
	 */
	public function isCaValidAt(int $now): bool {
		$parsed = openssl_x509_parse($this->certificate);
		if (is_array($parsed) === false) {
			return false;
		}

		$constraints = (string)($parsed['extensions']['basicConstraints'] ?? '');
		$keyUsage = $parsed['extensions']['keyUsage'] ?? null;
		$from = (int)($parsed['validFrom_time_t'] ?? PHP_INT_MAX);
		$until = (int)($parsed['validTo_time_t'] ?? 0);

		return preg_match('/\bCA:TRUE\b/i', $constraints) === 1
			&& ($keyUsage === null || str_contains((string)$keyUsage, 'Certificate Sign') === true)
			&& $from <= $now && $now <= $until;
	}//end isCaValidAt()
}//end class
