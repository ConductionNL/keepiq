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

use OCA\Keepiq\Db\UsedProofNonceMapper;
use OCA\Keepiq\Exception\KeyProofRequiredException;
use OCA\Keepiq\Service\VaultKeyProofService;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\DB\Exception as DbException;
use OCP\IConfig;
use OCP\Security\ISecureRandom;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

/**
 * Tests for VaultKeyProofService.
 */
class VaultKeyProofServiceTest extends TestCase {
	private VaultKeyProofService $service;
	private int $now = 1000;
	private string $privateKeyPem = '';
	private string $publicKeyPem = '';
	private string $otherPublicKeyPem = '';

	/** @var array<string,int> The used-nonce rows behind the store double, hash => expiresAt. */
	private array $store = [];

	/** @var array<int,int> The times the store was asked to sweep expired claims at. */
	private array $sweeps = [];

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
			usedNonces: $this->usedNonces(),
			logger: $this->createMock(LoggerInterface::class),
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
	 * The used nonce is kept until its challenge expires, and expired claims
	 * are swept on the way in: an expired challenge is refused on its own.
	 *
	 * @return void
	 */
	public function testAUsedNonceIsKeptUntilItsChallengeExpires(): void {
		[$nonce, $sig] = $this->prove(boundValues: []);
		$this->now += 100;
		$this->service->verify($nonce, $sig, $this->publicKeyPem, 'alice', self::PURPOSE, []);

		$this->assertSame([1300], array_values($this->store), 'a challenge issued at 1000 expires at 1300');
		$this->assertSame([1100], $this->sweeps, 'expired claims are swept at the time of use');
	}//end testAUsedNonceIsKeptUntilItsChallengeExpires()

	/**
	 * keepiq#868: single use does not depend on a memcache. The service no
	 * longer takes a cache at all, so an install with no memcache (Nextcloud's
	 * NullCache) or with APCu alone refuses a replay exactly like a cluster
	 * with Redis: the second verify() of the same proof throws "Proof already
	 * used".
	 *
	 * @return void
	 */
	public function testAReplayIsRefusedWithoutAnyMemcache(): void {
		$parameters = (new \ReflectionMethod(VaultKeyProofService::class, '__construct'))->getParameters();
		$types = array_map(static fn (\ReflectionParameter $p): string => (string)$p->getType(), $parameters);
		$this->assertNotContains('OCP\\ICacheFactory', $types, 'single use must not hang on a cache');

		[$nonce, $sig] = $this->prove(boundValues: []);
		$this->service->verify($nonce, $sig, $this->publicKeyPem, 'alice', self::PURPOSE, []);

		$this->expectException(KeyProofRequiredException::class);
		$this->expectExceptionMessage('Proof already used');
		$this->service->verify($nonce, $sig, $this->publicKeyPem, 'alice', self::PURPOSE, []);
	}//end testAReplayIsRefusedWithoutAnyMemcache()

	/**
	 * Fails closed: when the claim cannot be recorded, the proof is refused
	 * rather than let through unrecorded.
	 *
	 * @return void
	 */
	public function testAStoreFailureRefusesTheProof(): void {
		$store = $this->createMock(UsedProofNonceMapper::class);
		$store->method('claim')->willThrowException(new DbException('connection lost'));
		$logger = $this->createMock(LoggerInterface::class);
		$logger->expects($this->once())->method('error');

		$service = $this->serviceWith(usedNonces: $store, logger: $logger);
		[$nonce, $sig] = $this->prove(boundValues: []);

		$this->expectException(KeyProofRequiredException::class);
		$service->verify($nonce, $sig, $this->publicKeyPem, 'alice', self::PURPOSE, []);
	}//end testAStoreFailureRefusesTheProof()

	/**
	 * Only a fully verified proof consumes its nonce: a bad signature first
	 * must not burn the nonce for the real proof after it (#804 review).
	 *
	 * @return void
	 */
	public function testAFailedProofDoesNotConsumeItsNonce(): void {
		[$nonce, $sig] = $this->prove(boundValues: []);

		try {
			$this->service->verify($nonce, $sig, $this->otherPublicKeyPem, 'alice', self::PURPOSE, []);
			$this->fail('a proof checked against the wrong key must be refused');
		} catch (KeyProofRequiredException) {
			// Expected.
		}

		$this->assertSame([], $this->store, 'a refused proof must not consume its nonce');
		$this->service->verify($nonce, $sig, $this->publicKeyPem, 'alice', self::PURPOSE, []);
		$this->assertCount(1, $this->store);
	}//end testAFailedProofDoesNotConsumeItsNonce()

	/**
	 * A service over the given used-nonce store and logger, sharing setUp's
	 * secret, randomness and clock (so prove() proofs verify against it).
	 *
	 * @param UsedProofNonceMapper $usedNonces The used-nonce store
	 * @param LoggerInterface $logger The logger
	 *
	 * @return VaultKeyProofService
	 */
	private function serviceWith(UsedProofNonceMapper $usedNonces, LoggerInterface $logger): VaultKeyProofService {
		$config = $this->createMock(IConfig::class);
		$config->method('getSystemValueString')->willReturn('the-instance-secret');
		$random = $this->createMock(ISecureRandom::class);
		$random->method('generate')->willReturn('deterministic-random');
		$time = $this->createMock(ITimeFactory::class);
		$time->method('getTime')->willReturnCallback(fn () => $this->now);

		return new VaultKeyProofService(
			config: $config,
			secureRandom: $random,
			timeFactory: $time,
			usedNonces: $usedNonces,
			logger: $logger,
		);
	}//end serviceWith()

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
	 * A used-nonce store double that behaves like the unique index: the
	 * first claim of a hash wins, every later one returns false.
	 *
	 * @return UsedProofNonceMapper
	 */
	private function usedNonces(): UsedProofNonceMapper {
		$store = $this->createMock(UsedProofNonceMapper::class);
		$store->method('claim')->willReturnCallback(function (string $nonceHash, int $expiresAt): bool {
			if (array_key_exists($nonceHash, $this->store) === true) {
				return false;
			}

			$this->store[$nonceHash] = $expiresAt;
			return true;
		});
		$store->method('deleteExpired')->willReturnCallback(function (int $now): int {
			$this->sweeps[] = $now;
			return 0;
		});

		return $store;
	}//end usedNonces()
}//end class
