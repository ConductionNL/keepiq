<?php

/**
 * Doriath Public Key Loader Adapter
 *
 * A thin injectable seam over phpseclib3's PublicKeyLoader, whose key-parsing
 * entry points are static factory methods with no instance API
 * (vendor/phpseclib/phpseclib/phpseclib/Crypt/PublicKeyLoader.php declares
 * `public static function load()` and `loadPrivateKey()` and the class has no
 * constructor at all).
 *
 * Wrapping it keeps CertificateAuthorityService free of a hard-wired static
 * call and gives the certificate-issuance paths — some of the hardest code in
 * the app to exercise — a collaborator a test can substitute.
 *
 * @category Support
 * @package  OCA\Doriath\Support
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

namespace OCA\Doriath\Support;

use phpseclib3\Crypt\Common\AsymmetricKey;
use phpseclib3\Crypt\Common\PrivateKey;
use phpseclib3\Crypt\PublicKeyLoader;

/**
 * Loads phpseclib3 keys through instance methods.
 *
 * @SuppressWarnings(PHPMD.StaticAccess) The two delegations below are the ONE
 * place in the app that reaches phpseclib3\Crypt\PublicKeyLoader. The library
 * exposes key parsing exclusively as static factory methods — there is no
 * instance API to call and nothing to construct — so the static access cannot
 * be removed, only confined to a documented, injectable adapter.
 */
class PublicKeyLoaderAdapter
{
    /**
     * Parse any supported key (public or private) from its PEM/DER encoding.
     *
     * @param string $key      The encoded key
     * @param string $password The passphrase, or '' when the key is unencrypted
     *
     * @return AsymmetricKey
     */
    public function load(string $key, string $password=''): AsymmetricKey
    {
        if ($password === '') {
            return PublicKeyLoader::load($key);
        }

        return PublicKeyLoader::load($key, $password);
    }//end load()

    /**
     * Parse a private key from its PEM/DER encoding.
     *
     * @param string $key      The encoded private key
     * @param string $password The passphrase, or '' when the key is unencrypted
     *
     * @return PrivateKey
     */
    public function loadPrivateKey(string $key, string $password=''): PrivateKey
    {
        if ($password === '') {
            return PublicKeyLoader::loadPrivateKey($key);
        }

        return PublicKeyLoader::loadPrivateKey($key, $password);
    }//end loadPrivateKey()
}//end class
