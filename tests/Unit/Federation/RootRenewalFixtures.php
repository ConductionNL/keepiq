<?php

/**
 * Real roots for the root renewal tests: a partner's previous and new Keepiq root, the previous root's signature
 * over the new one, and the roots an announcement must be refused for. Generated once per run.
 *
 * @category Tests
 * @package  OCA\Keepiq\Tests\Unit\Federation
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

namespace OCA\Keepiq\Tests\Unit\Federation;

use OCA\Keepiq\Service\CertificateIssuanceService;
use OCA\Keepiq\Service\RootRenewalSignature;

trait RootRenewalFixtures {
	/** @var array<string,array{key:\OpenSSLAsymmetricKey,pem:string}> Roots by name, made once */
	private static array $roots = [];

	/**
	 * A self-signed root, made once per name.
	 *
	 * @param string $name The root's name
	 * @param string $extensions The section of certificate-extensions.cnf: `keepiq_root` or `keepiq_leaf`
	 * @param int $days Its lifetime from now
	 *
	 * @return array{key:\OpenSSLAsymmetricKey,pem:string}
	 */
	private static function root(string $name, string $extensions = 'keepiq_root', int $days = 365): array {
		if (isset(self::$roots[$name]) === false) {
			$key = openssl_pkey_new(['private_key_bits' => 2048, 'private_key_type' => OPENSSL_KEYTYPE_RSA]);
			$csr = openssl_csr_new(['commonName' => $name], $key, ['digest_alg' => 'sha256']);
			$options = ['digest_alg' => 'sha256', 'config' => CertificateIssuanceService::OPENSSL_CONFIG, 'x509_extensions' => $extensions];
			openssl_x509_export(openssl_csr_sign($csr, null, $key, $days, $options, random_int(1, PHP_INT_MAX)), $pem);
			self::$roots[$name] = ['key' => $key, 'pem' => $pem];
		}

		return self::$roots[$name];
	}

	/**
	 * Lowercase hex SHA-256 of a certificate's DER.
	 *
	 * @param string $pem The certificate
	 *
	 * @return string
	 */
	private static function fingerprintOf(string $pem): string {
		return (string)openssl_x509_fingerprint($pem, 'sha256');
	}

	/**
	 * A renewal announcement: `$signer` signs `$new`, announced with `$previous` as the root it replaced.
	 *
	 * @param string $previous The name of the root the announcement says was replaced
	 * @param string $new The name of the announced new root
	 * @param string $renewedAt The renewal time
	 * @param string|null $signer The name of the root whose key signs, `$previous` when null
	 *
	 * @return array{previousRoot:string,newRoot:string,renewedAt:string,signature:string}
	 */
	private static function announcement(string $previous, string $new, string $renewedAt, ?string $signer = null): array {
		return [
			'previousRoot' => self::root($previous)['pem'],
			'newRoot' => self::root($new)['pem'],
			'renewedAt' => $renewedAt,
			'signature' => (string)(new RootRenewalSignature())->sign(self::root($new)['pem'], self::root($signer ?? $previous)['key']),
		];
	}
}
