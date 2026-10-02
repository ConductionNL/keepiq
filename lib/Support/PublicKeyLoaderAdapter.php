<?php

/**
 * Keepiq Public Key Loader Adapter
 *
 * A thin injectable seam over phpseclib4's PublicKeyLoader, whose key-parsing
 * entry points are static factory methods with no instance API
 * (vendor/phpseclib/phpseclib/phpseclib/Crypt/PublicKeyLoader.php declares
 * `public static function load()` and `loadPrivateKey()` and the class has no
 * constructor at all). Since phpseclib 4 a certificate is parsed the same
 * way, through the static `File\X509::load()`, so that lives here too.
 *
 * Wrapping it keeps CertificateAuthorityService free of a hard-wired static
 * call and gives the certificate-issuance paths — some of the hardest code in
 * the app to exercise — a collaborator a test can substitute.
 *
 * @category Support
 * @package  OCA\Keepiq\Support
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

namespace OCA\Keepiq\Support;

use phpseclib4\Crypt\Common\AsymmetricKey;
use phpseclib4\Crypt\Common\PrivateKey;
use phpseclib4\Crypt\PublicKeyLoader;
use phpseclib4\File\X509;

/**
 * Loads phpseclib4 keys through instance methods.
 *
 * @SuppressWarnings(PHPMD.StaticAccess) The delegations below are the ONE
 * place in the app that reaches phpseclib4\Crypt\PublicKeyLoader and
 * phpseclib4\File\X509::load(). The library exposes key and certificate
 * parsing exclusively as static factory methods — there is no
 * instance API to call and nothing to construct — so the static access cannot
 * be removed, only confined to a documented, injectable adapter.
 */
class PublicKeyLoaderAdapter {
	/**
	 * Parse any supported key (public or private) from its PEM/DER encoding.
	 *
	 * @param string $key The encoded key
	 * @param string $password The passphrase, or '' when the key is unencrypted
	 *
	 * @return AsymmetricKey
	 */
	public function load(string $key, string $password = ''): AsymmetricKey {
		if ($password === '') {
			return PublicKeyLoader::load($key);
		}

		return PublicKeyLoader::load($key, $password);
	}//end load()

	/**
	 * Parse a private key from its PEM/DER encoding.
	 *
	 * @param string $key The encoded private key
	 * @param string $password The passphrase, or '' when the key is unencrypted
	 *
	 * @return PrivateKey
	 */
	public function loadPrivateKey(string $key, string $password = ''): PrivateKey {
		if ($password === '') {
			return PublicKeyLoader::loadPrivateKey($key);
		}

		return PublicKeyLoader::loadPrivateKey($key, $password);
	}//end loadPrivateKey()

	/**
	 * Parse an X.509 certificate from its PEM/DER encoding.
	 *
	 * @param string $certificate The encoded certificate
	 *
	 * @return X509
	 *
	 * @spec openspec/specs/certificate-lifecycle/spec.md
	 */
	public function loadCertificate(string $certificate): X509 {
		return X509::load($certificate);
	}//end loadCertificate()
}//end class
