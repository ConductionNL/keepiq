<?php

/**
 * Unit tests for VaultKeyProofService.
 *
 * Exercises the real crypto path: a genuine RSA keypair signs the service's own
 * signed-message string with RSASSA-PKCS1-v1_5 SHA-256 (openssl_sign), and the
 * service verifies it against the public key. Every negative case asserts the
 * single, detail-free KeyProofRequiredException.
 *
 * @category Test
 * @package  OCA\Keepiq\Tests\Unit\Service
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

namespace OCA\Keepiq\Tests\Unit\Service;

use OCA\Keepiq\Exception\KeyProofRequiredException;
use OCA\Keepiq\Service\VaultKeyProofService;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\ICache;
use OCP\ICacheFactory;
use OCP\IConfig;
use OCP\IMemcache;
use OCP\Security\ISecureRandom;
use PHPUnit\Framework\TestCase;

/**
 * Tests for VaultKeyProofService.
 */
class VaultKeyProofServiceTest extends TestCase {
	private VaultKeyProofService $service;
	private int $now = 1000;
	private string $privateKeyPem = '';
	private string $publicKeyPem = '';
	private string $otherPublicKeyPem = '';

	/** @var array<string,mixed> The used-nonce store behind the cache double. */
	private array $store = [];

	/** @var array<int,int> TTLs the cache was asked to keep entries for. */
	private array $ttls = [];

	private const PURPOSE = 'compromise-recovery';

	/**
	 * @return void
	 */
	protected function setUp(): void {
		parent::setUp();

		$config = $this->createMock(IConfig::class);
		$config->method('getSystemValueString')->willReturn('the-instance-secret');

		$random = $this->createMock(ISecureRandom::class);
		$random->method('generate')->willReturn('deterministic-random');

		$time = $this->createMock(ITimeFactory::class);
		$time->method('getTime')->willReturnCallback(fn () => $this->now);

		$this->service = new VaultKeyProofService(
			config: $config,
			secureRandom: $random,
			timeFactory: $time,
			cacheFactory: $this->cacheFactory(cache: $this->memcache()),
		);

		[$this->privateKeyPem, $this->publicKeyPem] = $this->makeKeypair();
		[, $this->otherPublicKeyPem] = $this->makeKeypair();
	}//end setUp()

	public function testAValidProofVerifies(): void {
		$nonce = $this->service->issueChallenge('alice', self::PURPOSE)['nonce'];
		$sig = $this->sign($this->service->signedMessage($nonce, ['pub', 'env']), $this->privateKeyPem);

		$this->expectNotToPerformAssertions();
		$this->service->verify($nonce, $sig, $this->publicKeyPem, 'alice', self::PURPOSE, ['pub', 'env']);
	}//end testAValidProofVerifies()

	public function testAProofSignedWithTheWrongKeyIsRefused(): void {
		$nonce = $this->service->issueChallenge('alice', self::PURPOSE)['nonce'];
		$sig = $this->sign($this->service->signedMessage($nonce, ['pub', 'env']), $this->privateKeyPem);

		$this->expectException(KeyProofRequiredException::class);
		$this->service->verify($nonce, $sig, $this->otherPublicKeyPem, 'alice', self::PURPOSE, ['pub', 'env']);
	}//end testAProofSignedWithTheWrongKeyIsRefused()

	public function testAnAlteredBoundValueIsRefused(): void {
		$nonce = $this->service->issueChallenge('alice', self::PURPOSE)['nonce'];
		$sig = $this->sign($this->service->signedMessage($nonce, ['pub', 'env']), $this->privateKeyPem);

		$this->expectException(KeyProofRequiredException::class);
		// Same signature, but the server sees a different bound value.
		$this->service->verify($nonce, $sig, $this->publicKeyPem, 'alice', self::PURPOSE, ['pub', 'TAMPERED']);
	}//end testAnAlteredBoundValueIsRefused()

	public function testATamperedNonceIsRefused(): void {
		$nonce = $this->service->issueChallenge('alice', self::PURPOSE)['nonce'];
		$sig = $this->sign($this->service->signedMessage($nonce, []), $this->privateKeyPem);

		$tampered = $nonce . 'x';
		$this->expectException(KeyProofRequiredException::class);
		$this->service->verify($tampered, $sig, $this->publicKeyPem, 'alice', self::PURPOSE, []);
	}//end testATamperedNonceIsRefused()

	public function testAnExpiredChallengeIsRefused(): void {
		$nonce = $this->service->issueChallenge('alice', self::PURPOSE)['nonce'];
		$sig = $this->sign($this->service->signedMessage($nonce, []), $this->privateKeyPem);

		// Advance well past the 300s TTL.
		$this->now += 10000;

		$this->expectException(KeyProofRequiredException::class);
		$this->service->verify($nonce, $sig, $this->publicKeyPem, 'alice', self::PURPOSE, []);
	}//end testAnExpiredChallengeIsRefused()

	public function testAProofForAnotherPurposeIsRefused(): void {
		$nonce = $this->service->issueChallenge('alice', self::PURPOSE)['nonce'];
		$sig = $this->sign($this->service->signedMessage($nonce, []), $this->privateKeyPem);

		$this->expectException(KeyProofRequiredException::class);
		$this->service->verify($nonce, $sig, $this->publicKeyPem, 'alice', 'update-private-key', []);
	}//end testAProofForAnotherPurposeIsRefused()

	public function testAProofForAnotherUserIsRefused(): void {
		$nonce = $this->service->issueChallenge('alice', self::PURPOSE)['nonce'];
		$sig = $this->sign($this->service->signedMessage($nonce, []), $this->privateKeyPem);

		$this->expectException(KeyProofRequiredException::class);
		$this->service->verify($nonce, $sig, $this->publicKeyPem, 'mallory', self::PURPOSE, []);
	}//end testAProofForAnotherUserIsRefused()

	public function testAMissingProofIsRefused(): void {
		$this->expectException(KeyProofRequiredException::class);
		$this->service->verify('', '', $this->publicKeyPem, 'alice', self::PURPOSE, []);
	}//end testAMissingProofIsRefused()

	/**
	 * Sign a message with RSASSA-PKCS1-v1_5 SHA-256, the same scheme WebCrypto
	 * produces, and return it base64-encoded as the client would send it.
	 *
	 * @param string $message The message to sign
	 * @param string $privateKeyPem The signer's private key
	 *
	 * @return string
	 */
	private function sign(string $message, string $privateKeyPem): string {
		openssl_sign($message, $signature, $privateKeyPem, OPENSSL_ALGO_SHA256);
		return base64_encode($signature);
	}//end sign()

	/**
	 * Generate an RSA keypair, returned as [privatePem, publicPem].
	 *
	 * @return array{0:string,1:string}
	 */
	private function makeKeypair(): array {
		$res = openssl_pkey_new([
			'private_key_bits' => 2048,
			'private_key_type' => OPENSSL_KEYTYPE_RSA,
		]);
		openssl_pkey_export($res, $privatePem);
		$publicPem = openssl_pkey_get_details($res)['key'];
		return [$privatePem, $publicPem];
	}//end makeKeypair()

	/**
	 * A proof is single-use: the same nonce and signature are refused the
	 * second time (#804 review). Without this, a captured designate proof could
	 * be replayed within its lifetime to bring back a contact just revoked.
	 *
	 * @return void
	 */
	public function testAProofCanBeUsedOnlyOnce(): void {
		[$nonce, $sig] = $this->prove(boundValues: ['pub', 'env']);
		$this->service->verify($nonce, $sig, $this->publicKeyPem, 'alice', self::PURPOSE, ['pub', 'env']);

		$this->expectException(KeyProofRequiredException::class);
		$this->service->verify($nonce, $sig, $this->publicKeyPem, 'alice', self::PURPOSE, ['pub', 'env']);
	}//end testAProofCanBeUsedOnlyOnce()

	/**
	 * The used nonce is remembered for exactly the rest of its lifetime, not
	 * longer: after expiry the challenge is refused on its own anyway.
	 *
	 * @return void
	 */
	public function testAUsedNonceIsRememberedForItsRemainingLifetime(): void {
		[$nonce, $sig] = $this->prove(boundValues: []);
		$this->now += 100;
		$this->service->verify($nonce, $sig, $this->publicKeyPem, 'alice', self::PURPOSE, []);

		$this->assertSame([200], $this->ttls, 'a 300 s challenge used after 100 s is kept for the remaining 200 s');
	}//end testAUsedNonceIsRememberedForItsRemainingLifetime()

	/**
	 * A cache without atomic add() still refuses a reuse (hasKey, then set),
	 * the same pattern JwtAuthService uses for jti replay protection.
	 *
	 * @return void
	 */
	public function testAPlainCacheStillRefusesReuse(): void {
		$config = $this->createMock(IConfig::class);
		$config->method('getSystemValueString')->willReturn('the-instance-secret');
		$random = $this->createMock(ISecureRandom::class);
		$random->method('generate')->willReturn('deterministic-random');
		$time = $this->createMock(ITimeFactory::class);
		$time->method('getTime')->willReturnCallback(fn () => $this->now);

		$cache = $this->createMock(ICache::class);
		$cache->method('hasKey')->willReturnCallback(fn (string $key): bool => array_key_exists($key, $this->store));
		$cache->method('set')->willReturnCallback(function (string $key, mixed $value): bool {
			$this->store[$key] = $value;
			return true;
		});

		$service = new VaultKeyProofService(
			config: $config,
			secureRandom: $random,
			timeFactory: $time,
			cacheFactory: $this->cacheFactory(cache: $cache),
		);

		[$nonce, $sig] = $this->prove(boundValues: []);
		$service->verify($nonce, $sig, $this->publicKeyPem, 'alice', self::PURPOSE, []);

		$this->expectException(KeyProofRequiredException::class);
		$service->verify($nonce, $sig, $this->publicKeyPem, 'alice', self::PURPOSE, []);
	}//end testAPlainCacheStillRefusesReuse()

	/**
	 * Issue a challenge for alice and sign it over the bound values.
	 *
	 * @param string[] $boundValues The bound values
	 *
	 * @return array{0:string,1:string} The nonce and the signature
	 */
	private function prove(array $boundValues): array {
		$nonce = $this->service->issueChallenge('alice', self::PURPOSE)['nonce'];

		return [$nonce, $this->sign($this->service->signedMessage($nonce, $boundValues), $this->privateKeyPem)];
	}//end prove()

	/**
	 * A memcache double whose add() is atomic over an in-test array.
	 *
	 * @return IMemcache
	 */
	private function memcache(): IMemcache {
		$cache = $this->createMock(IMemcache::class);
		$cache->method('add')->willReturnCallback(function (string $key, mixed $value, int $ttl = 0): bool {
			if (array_key_exists($key, $this->store) === true) {
				return false;
			}

			$this->store[$key] = $value;
			$this->ttls[] = $ttl;
			return true;
		});

		return $cache;
	}//end memcache()

	/**
	 * A cache factory handing out the given cache.
	 *
	 * @param ICache $cache The cache to hand out
	 *
	 * @return ICacheFactory
	 */
	private function cacheFactory(ICache $cache): ICacheFactory {
		$factory = $this->createMock(ICacheFactory::class);
		$factory->method('createDistributed')->willReturn($cache);

		return $factory;
	}//end cacheFactory()
}//end class
