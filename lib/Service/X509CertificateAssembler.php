<?php

/**
 * Keepiq X.509 Certificate Assembler
 *
 * Assembles and signs X.509 certificates with phpseclib, which — unlike
 * ext-openssl's CSR path — can bind a PUBLIC-ONLY key deterministically
 * on every build. Two shapes are needed by the private CA: issuing a
 * certificate for a submitted public key, and re-signing an existing
 * certificate onto a fresh issuer while carrying its original
 * SubjectPublicKeyInfo and subject DN verbatim. This class holds no
 * CA state: every call is handed the issuer material it must sign with,
 * so it is purely the "how do we mint this DER" half of issuance.
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

use OCA\Keepiq\AppInfo\Application;
use OCA\Keepiq\Support\PublicKeyLoaderAdapter;
use phpseclib4\Crypt\RSA;
use phpseclib4\Crypt\RSA\PrivateKey;
use phpseclib4\Crypt\RSA\PublicKey;
use phpseclib4\File\X509;
use Psr\Log\LoggerInterface;
use RuntimeException;
use Throwable;

/**
 * Builds and signs X.509 certificates with phpseclib.
 */
class X509CertificateAssembler {
	/**
	 * Constructor for X509CertificateAssembler.
	 *
	 * @param LoggerInterface $logger The logger interface
	 * @param PublicKeyLoaderAdapter $keyLoader The phpseclib key loader
	 *
	 * @return void
	 *
	 * @spec exclude Constructor wiring only; no behaviour.
	 */
	public function __construct(
		private LoggerInterface $logger,
		private PublicKeyLoaderAdapter $keyLoader = new PublicKeyLoaderAdapter(),
	) {
	}//end __construct()

	/**
	 * Issue an X.509 certificate carrying an arbitrary submitted public key,
	 * signed by the intermediate — via phpseclib, which (unlike ext-openssl's
	 * CSR path) can bind a public-only key deterministically on every build.
	 *
	 * @param string $publicKeyPem The subject public key (PEM)
	 * @param array<string,string> $subjectDn Ordered map of phpseclib DN prop => value
	 * @param string $intermediateCertPem The signing intermediate certificate (PEM)
	 * @param string $intermediatePrivPem The intermediate private key (PEM, decrypted)
	 *
	 * @return string The issued certificate PEM
	 *
	 * @throws RuntimeException When issuance fails
	 *
	 * @spec openspec/specs/certificate-lifecycle/spec.md
	 */
	public function issueForPublicKey(
		string $publicKeyPem,
		array $subjectDn,
		string $intermediateCertPem,
		string $intermediatePrivPem,
	): string {
		$subjectPublic = $this->keyLoader->load($publicKeyPem);
		if ($subjectPublic instanceof PublicKey === false) {
			throw new RuntimeException('Submitted public key is not an RSA public key');
		}

		$issuerPrivate = $this->keyLoader->load($intermediatePrivPem);
		if ($issuerPrivate instanceof PrivateKey === false) {
			throw new RuntimeException('Intermediate private key could not be loaded for issuance');
		}

		// PKCS1 padding on the subject key so the SPKI carries the plain
		// rsaEncryption OID: phpseclib's PSS default would emit an
		// id-RSASSA-PSS SPKI that WebCrypto/openssl consumers reject.
		$certificate = new X509($subjectPublic->withPadding(RSA::SIGNATURE_PKCS1));
		foreach ($subjectDn as $dnProp => $dnValue) {
			$certificate->addSubjectDNProp($dnProp, $dnValue);
		}

		$certificate->setSerialNumber((string)random_int(1, PHP_INT_MAX), 10);
		$certificate->setEndDate('+365 days');
		$this->signWithIntermediate(
			certificate: $certificate,
			intermediateCertPem: $intermediateCertPem,
			issuerPrivate: $issuerPrivate,
		);

		$pem = $certificate->toString();
		if ($pem === '') {
			throw new RuntimeException('phpseclib certificate export failed');
		}

		return $pem;
	}//end issueForPublicKey()

	/**
	 * Assemble and sign a new certificate that carries the old certificate's
	 * SubjectPublicKeyInfo and subject DN verbatim, signed by the intermediate.
	 *
	 * @param string $oldCert The current PEM certificate to re-sign
	 * @param string $intermediateCert The signing intermediate certificate (PEM)
	 * @param string $intermediateKeyPem The decrypted intermediate private key (PEM)
	 * @param string|null $fallbackCn CommonName to add when the old subject has none;
	 *                                an existing commonName is always kept
	 *
	 * @return string|null The new PEM certificate, or null when signing failed.
	 *
	 * @spec openspec/specs/certificate-lifecycle/spec.md
	 */
	public function resignPreservingSubject(
		string $oldCert,
		string $intermediateCert,
		string $intermediateKeyPem,
		?string $fallbackCn = null,
	): ?string {
		try {
			$old = X509::load($oldCert);
			$oldPublic = $old->getPublicKey();
			if ($oldPublic instanceof PublicKey === false) {
				return null;
			}

			$issuerPrivate = $this->keyLoader->loadPrivateKey($intermediateKeyPem);
			if ($issuerPrivate instanceof PrivateKey === false) {
				return null;
			}

			// Same PKCS1 pin as issuance: a key read back from a certificate
			// carries phpseclib's PSS default, which would rewrite the SPKI.
			$certificate = new X509($oldPublic->withPadding(RSA::SIGNATURE_PKCS1));
			$certificate->setSubjectDN($old->getSubjectDN(X509::DN_ARRAY));
			if ($fallbackCn !== null && $fallbackCn !== '' && $old->hasSubjectDNProp('id-at-commonName') === false) {
				$certificate->addSubjectDNProp('id-at-commonName', $fallbackCn);
			}

			$certificate->setStartDate('-1 day');
			$certificate->setEndDate('+365 days');
			$certificate->setSerialNumber((string)random_int(1, PHP_INT_MAX), 10);
			$this->signWithIntermediate(
				certificate: $certificate,
				intermediateCertPem: $intermediateCert,
				issuerPrivate: $issuerPrivate,
			);

			return $certificate->toString();
		} catch (Throwable $exception) {
			$this->logger->warning(
				'Keepiq: phpseclib re-sign failed: ' . $exception->getMessage(),
				['app' => Application::APP_ID]
			);

			return null;
		}//end try
	}//end resignPreservingSubject()

	/**
	 * Sign a certificate with the intermediate: copy the intermediate's
	 * subject DN and key identifier into the issuer fields, then sign with
	 * PKCS#1 v1.5 (sha256WithRSAEncryption), for issuance and renewal alike.
	 *
	 * @param X509 $certificate The certificate to sign, signed in place
	 * @param string $intermediateCertPem The signing intermediate certificate (PEM)
	 * @param PrivateKey $issuerPrivate The intermediate's private key
	 *
	 * @return void
	 *
	 * @spec openspec/specs/certificate-lifecycle/spec.md
	 */
	private function signWithIntermediate(
		X509 $certificate,
		string $intermediateCertPem,
		PrivateKey $issuerPrivate,
	): void {
		$certificate->copySigningX509Attributes(X509::load($intermediateCertPem));
		$issuerPrivate->withPadding(RSA::SIGNATURE_PKCS1)->withHash('sha256')->sign($certificate);
	}//end signWithIntermediate()
}//end class
