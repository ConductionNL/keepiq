<?php

/**
 * Unit tests for JwkFactoryAdapter.
 *
 * @category Test
 * @package  OCA\Keepiq\Tests\Unit\Support
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

namespace OCA\Keepiq\Tests\Unit\Support;

use OCA\Keepiq\Support\JwkFactoryAdapter;
use PHPUnit\Framework\TestCase;

/**
 * Tests for JwkFactoryAdapter: a PEM key becomes the matching JWK.
 *
 * @covers \OCA\Keepiq\Support\JwkFactoryAdapter
 */
class JwkFactoryAdapterTest extends TestCase {

	/**
	 * An RSA private key becomes a private RSA JWK with the same modulus; its public half has no private exponent.
	 *
	 * @return void
	 */
	public function testAnRsaKeyBecomesTheMatchingJwk(): void {
		$key = openssl_pkey_new(['private_key_bits' => 2048, 'private_key_type' => OPENSSL_KEYTYPE_RSA]);
		openssl_pkey_export($key, $privatePem);
		$details = openssl_pkey_get_details($key);
		$modulus = rtrim(strtr(base64_encode($details['rsa']['n']), '+/', '-_'), '=');
		$adapter = new JwkFactoryAdapter();

		$private = $adapter->createFromKey($privatePem);
		$public = $adapter->createFromKey($details['key']);

		$this->assertSame('RSA', $private->get('kty'));
		$this->assertSame($modulus, $private->get('n'));
		$this->assertTrue($private->has('d'));
		$this->assertSame($modulus, $public->get('n'));
		$this->assertFalse($public->has('d'));
	}//end testAnRsaKeyBecomesTheMatchingJwk()

	/**
	 * An EC key becomes an EC JWK on its curve.
	 *
	 * @return void
	 */
	public function testAnEcKeyBecomesAnEcJwk(): void {
		$key = openssl_pkey_new(['private_key_type' => OPENSSL_KEYTYPE_EC, 'curve_name' => 'prime256v1']);
		openssl_pkey_export($key, $privatePem);

		$jwk = (new JwkFactoryAdapter())->createFromKey($privatePem);

		$this->assertSame('EC', $jwk->get('kty'));
		$this->assertSame('P-256', $jwk->get('crv'));
	}//end testAnEcKeyBecomesAnEcJwk()
}//end class
