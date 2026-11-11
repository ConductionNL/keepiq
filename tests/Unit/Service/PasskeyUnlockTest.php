<?php

/**
 * Unit tests for the passkey unlock against real ceremony data.
 *
 * The service runs with the real challenge store, unlock ceremony and
 * WebAuthn verifier over a software passkey. The verifier runs on the
 * WebAuthn library Nextcloud core ships, so these tests need the server's
 * 3rdparty autoloader.
 *
 * @category Test
 * @package  OCA\Keepiq\Tests\Unit\Service
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

namespace OCA\Keepiq\Tests\Unit\Service;

use InvalidArgumentException;
use OCA\Keepiq\Db\EncryptionSuite;
use OCA\Keepiq\Db\EncryptionSuiteMapper;
use OCA\Keepiq\Db\PasskeyCredential;
use OCA\Keepiq\Db\PasskeyMapper;
use OCA\Keepiq\Exception\PasskeyVerificationException;
use OCA\Keepiq\Service\PasskeyChallengeStore;
use OCA\Keepiq\Service\PasskeyEnrolmentCeremony;
use OCA\Keepiq\Service\PasskeyService;
use OCA\Keepiq\Service\PasskeyUnlockCeremony;
use OCA\Keepiq\Service\WebAuthnVerifier;
use OCA\Keepiq\Tests\Support\WebAuthnFixture;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\IRequest;
use OCP\ISession;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use Webauthn\AuthenticatorAssertionResponseValidator;

/**
 * A passkey unlock releases the envelope only after a verified assertion.
 */
class PasskeyUnlockTest extends TestCase {
	private const UID = 'alice';

	/**
	 * The session contents.
	 *
	 * @var array<string,mixed>
	 */
	private array $session = [];

	private int $now = 1000;

	/**
	 * The credentials the mapper writes back.
	 *
	 * @var PasskeyCredential[]
	 */
	private array $updated = [];

	private WebAuthnFixture $passkey;

	private PasskeyCredential $credential;

	private WebAuthnVerifier $verifier;

	private LoggerInterface&MockObject $logger;

	private PasskeyService $service;

	/**
	 * A service over an in-memory session, the real verifier and one
	 * enrolled software passkey at epoch 3.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		parent::setUp();
		if (class_exists(AuthenticatorAssertionResponseValidator::class) === false) {
			$this->markTestSkipped('Needs the WebAuthn library from the Nextcloud server (3rdparty)');
		}

		$this->verifier = new WebAuthnVerifier();
		$this->passkey = new WebAuthnFixture();
		$this->credential = $this->enrolled(passkey: $this->passkey);

		$this->logger = $this->createMock(originalClassName: LoggerInterface::class);
		$this->service = $this->service(credentials: [$this->credential]);
	}//end setUp()

	/**
	 * A credential row for the given software passkey, enrolled through the
	 * verifier so its public key is the attested one.
	 *
	 * @param WebAuthnFixture $passkey The software passkey
	 *
	 * @return PasskeyCredential
	 */
	private function enrolled(WebAuthnFixture $passkey): PasskeyCredential {
		$challenge = random_bytes(32);
		$assertionChallenge = random_bytes(32);
		$verified = $this->verifier->verifyEnrolment(
			uid: self::UID,
			rpId: WebAuthnFixture::RP_ID,
			challenge: $challenge,
			assertionChallenge: $assertionChallenge,
			attestation: $passkey->attestation($challenge),
			assertion: $passkey->assertion($assertionChallenge, self::UID, ['counter' => 4]),
		);

		$credential = new PasskeyCredential();
		$credential->setId('p1');
		$credential->setOwnerId(self::UID);
		$credential->setCredentialId($verified->credentialId);
		$credential->setPublicKey($verified->publicKey);
		$credential->setPrfSalt('salt');
		$credential->setWrappedUnlockKey('WRAPPED');
		$credential->setUnlockKeyEpoch(3);
		$credential->setSignCount($verified->counter);
		$credential->setClientKind('web');
		$credential->setStatus('active');

		return $credential;
	}//end enrolled()

	/**
	 * The service with real ceremonies over the given credentials.
	 *
	 * @param PasskeyCredential[] $credentials The owner's active credentials
	 *
	 * @return PasskeyService
	 */
	private function service(array $credentials): PasskeyService {
		$session = $this->createMock(originalClassName: ISession::class);
		$session->method('set')->willReturnCallback(function (string $key, mixed $value): void {
			$this->session[$key] = $value;
		});
		$session->method('get')->willReturnCallback(fn (string $key): mixed => $this->session[$key] ?? null);
		$session->method('remove')->willReturnCallback(function (string $key): void {
			unset($this->session[$key]);
		});
		$clock = $this->createMock(originalClassName: ITimeFactory::class);
		$clock->method('getTime')->willReturnCallback(fn (): int => $this->now);
		$challenges = new PasskeyChallengeStore(session: $session, timeFactory: $clock);

		$request = $this->createMock(originalClassName: IRequest::class);
		$request->method('getServerHost')->willReturn(WebAuthnFixture::RP_ID . ':443');

		$mapper = $this->createMock(originalClassName: PasskeyMapper::class);
		$mapper->method('findActiveByOwner')->willReturn($credentials);
		$mapper->method('update')->willReturnCallback(function (PasskeyCredential $credential): PasskeyCredential {
			$this->updated[] = $credential;

			return $credential;
		});

		$suite = new EncryptionSuite();
		$suite->setUnlockKeyEpoch(3);
		$suiteMapper = $this->createMock(originalClassName: EncryptionSuiteMapper::class);
		$suiteMapper->method('findActiveByOwner')->willReturn($suite);

		$enrolment = new PasskeyEnrolmentCeremony(challenges: $challenges, verifier: $this->verifier, request: $request, logger: $this->logger);

		return new PasskeyService(
			mapper: $mapper,
			suiteMapper: $suiteMapper,
			ceremony: $enrolment,
			unlockCeremony: new PasskeyUnlockCeremony(
				challenges: $challenges,
				verifier: $this->verifier,
				enrolment: $enrolment,
				logger: $this->logger,
			),
		);
	}//end service()

	/**
	 * The raw unlock challenge of a login-options response.
	 *
	 * @param array<string,mixed> $options The login options
	 *
	 * @return string
	 */
	private static function challengeOf(array $options): string {
		return (string)base64_decode(strtr((string)$options['challenge'], '-_', '+/'), true);
	}//end challengeOf()

	/**
	 * Fetch login options and answer their challenge with the software
	 * passkey, with the given assertion overrides.
	 *
	 * @param array<string,mixed> $override Assertion overrides
	 *
	 * @return array<string,mixed> The unlock response
	 */
	private function unlockWith(array $override = []): array {
		$options = $this->service->loginOptions(uid: self::UID);
		$challenge = $override['challenge'] ?? self::challengeOf(options: $options);

		return $this->service->unlock(
			uid: self::UID,
			assertion: $this->passkey->assertion($challenge, self::UID, $override + ['counter' => 5]),
		);
	}//end unlockWith()

	/**
	 * Assert an unlock is refused with the generic message, logged with the
	 * failed check and the uid, and writes no counter.
	 *
	 * @param callable $unlock Runs the unlock
	 * @param string $reason The logged reason
	 *
	 * @return void
	 */
	private function assertRefused(callable $unlock, string $reason): void {
		$this->logger->expects($this->once())->method('warning')->with(
			'Passkey unlock refused for {uid}: {reason}',
			$this->callback(static fn (array $context): bool => $context['reason'] === $reason && $context['uid'] === self::UID)
		);

		try {
			$unlock();
			$this->fail('an envelope was released without a verified assertion');
		} catch (InvalidArgumentException $exception) {
			$this->assertSame('The passkey could not be verified', $exception->getMessage());
		}

		$this->assertSame([], $this->updated);
		$this->assertSame(4, $this->credential->getSignCount());
		$this->assertNull($this->credential->getLastUsedAt());
	}//end assertRefused()

	/**
	 * The login options carry the challenge and credential ids, no envelope.
	 *
	 * @return void
	 */
	public function testLoginOptionsCarryNoEnvelope(): void {
		$options = $this->service->loginOptions(uid: self::UID);

		$this->assertSame(['unlockKeyEpoch', 'challenge', 'credentials'], array_keys($options));
		$this->assertSame(['id', 'credentialId', 'prfSalt', 'unlockKeyEpoch'], array_keys($options['credentials'][0]));
		$this->assertStringNotContainsString('WRAPPED', (string)json_encode($options));
		$this->assertSame(32, strlen(self::challengeOf(options: $options)));
	}//end testLoginOptionsCarryNoEnvelope()

	/**
	 * A verified assertion releases that credential's envelope and stores
	 * the new counter and the last-used time.
	 *
	 * @return void
	 */
	public function testAVerifiedAssertionReleasesTheEnvelope(): void {
		$this->logger->expects($this->never())->method('warning');

		$this->assertSame(
			['id' => 'p1', 'credentialId' => $this->passkey->credentialIdB64(), 'wrappedUnlockKey' => 'WRAPPED', 'unlockKeyEpoch' => 3],
			$this->unlockWith()
		);
		$this->assertSame([$this->credential], $this->updated);
		$this->assertSame(5, $this->credential->getSignCount());
		$this->assertNotNull($this->credential->getLastUsedAt());
	}//end testAVerifiedAssertionReleasesTheEnvelope()

	/**
	 * An authenticator that keeps no counter (always 0) still unlocks.
	 *
	 * @return void
	 */
	public function testAnAuthenticatorWithoutACounterUnlocks(): void {
		$this->credential->setSignCount(0);

		$this->assertSame('WRAPPED', $this->unlockWith(['counter' => 0])['wrappedUnlockKey']);
		$this->assertSame(0, $this->credential->getSignCount());
	}//end testAnAuthenticatorWithoutACounterUnlocks()

	/**
	 * Each broken field of the unlock assertion is refused.
	 *
	 * @return array<string,array{0: array<string,mixed>, 1: string}>
	 */
	public static function brokenAssertions(): array {
		return [
			'client data type is create' => [['type' => 'webauthn.create'], 'The client data type is not webauthn.get'],
			'challenge is not the issued one' => [['challenge' => random_bytes(32)], 'Invalid challenge.'],
			'origin is another site' => [['origin' => 'https://evil.test'], 'rpId mismatch.'],
			'origin is plain http' => [['origin' => 'http://cloud.example.com'], 'Invalid scheme. HTTPS required.'],
			'origin is a browser extension' => [['origin' => 'chrome-extension://abcdefghijklmnop'], 'Invalid scheme. HTTPS required.'],
			'rpId hash is another relying party' => [['rpId' => 'evil.test'], 'rpId hash mismatch.'],
			'user was not present' => [['flags' => 0x04], 'User was not present'],
			'signed by another key' => [['signer' => WebAuthnFixture::newKey()], 'Invalid signature.'],
			'counter did not increase' => [['counter' => 4], 'Invalid counter.'],
			'counter went backwards' => [['counter' => 3], 'Invalid counter.'],
			'another credential answered' => [['rawId' => random_bytes(16)], 'the assertion names no offered passkey'],
		];
	}//end brokenAssertions()

	/**
	 * A broken assertion is refused without an envelope.
	 *
	 * @param array<string,mixed> $override The broken field
	 * @param string $reason The check that must refuse it
	 *
	 * @return void
	 */
	#[DataProvider('brokenAssertions')]
	public function testRefusesABrokenAssertion(array $override, string $reason): void {
		$this->assertRefused(unlock: fn () => $this->unlockWith($override), reason: $reason);
	}//end testRefusesABrokenAssertion()

	/**
	 * An unlock without login options first has no challenge to answer.
	 *
	 * @return void
	 */
	public function testRefusesWithoutAPendingChallenge(): void {
		$this->assertRefused(
			unlock: fn () => $this->service->unlock(uid: self::UID, assertion: $this->passkey->assertion(random_bytes(32), self::UID, ['counter' => 5])),
			reason: 'no pending or an expired unlock challenge',
		);
	}//end testRefusesWithoutAPendingChallenge()

	/**
	 * A verified assertion cannot be presented a second time.
	 *
	 * @return void
	 */
	public function testRefusesAReplayedAssertion(): void {
		$options = $this->service->loginOptions(uid: self::UID);
		$assertion = $this->passkey->assertion(self::challengeOf(options: $options), self::UID, ['counter' => 5]);
		$this->service->unlock(uid: self::UID, assertion: $assertion);
		$this->updated = [];
		$this->credential->setSignCount(4);
		$this->credential->setLastUsedAt(null);

		$this->assertRefused(
			unlock: fn () => $this->service->unlock(uid: self::UID, assertion: $assertion),
			reason: 'no pending or an expired unlock challenge',
		);
	}//end testRefusesAReplayedAssertion()

	/**
	 * A challenge past its lifetime is refused.
	 *
	 * @return void
	 */
	public function testRefusesAnExpiredChallenge(): void {
		$options = $this->service->loginOptions(uid: self::UID);
		$this->now += PasskeyChallengeStore::LIFETIME + 1;

		$this->assertRefused(
			unlock: fn () => $this->service->unlock(
				uid: self::UID,
				assertion: $this->passkey->assertion(self::challengeOf(options: $options), self::UID, ['counter' => 5])
			),
			reason: 'no pending or an expired unlock challenge',
		);
	}//end testRefusesAnExpiredChallenge()

	/**
	 * A credential the asking client is not offered cannot unlock, even with
	 * a valid signature: an extension credential for the web client.
	 *
	 * @return void
	 */
	public function testRefusesACredentialOfAnotherClient(): void {
		$this->credential->setClientKind('extension');
		$this->credential->setRpId('abcdefghijklmnop');

		$this->assertRefused(unlock: fn () => $this->unlockWith(), reason: 'the assertion names no offered passkey');
	}//end testRefusesACredentialOfAnotherClient()

	/**
	 * An extension credential stays refused: its origin is not https.
	 *
	 * @return void
	 */
	public function testRefusesAnExtensionOrigin(): void {
		$this->credential->setClientKind('extension');
		$this->credential->setRpId('abcdefghijklmnop');
		$options = $this->service->loginOptions(uid: self::UID, client: 'extension', rpId: 'abcdefghijklmnop');
		$this->assertCount(1, $options['credentials']);

		$this->assertRefused(
			unlock: fn () => $this->service->unlock(
				uid: self::UID,
				assertion: $this->passkey->assertion(
					self::challengeOf(options: $options),
					self::UID,
					['counter' => 5, 'rpId' => 'abcdefghijklmnop', 'origin' => 'chrome-extension://abcdefghijklmnop']
				),
				client: 'extension',
				rpId: 'abcdefghijklmnop',
			),
			reason: 'Invalid scheme. HTTPS required.',
		);
	}//end testRefusesAnExtensionOrigin()

	/**
	 * The verifier refuses a credential stored without a verified public key.
	 *
	 * @return void
	 */
	public function testTheVerifierRefusesACredentialWithoutAPublicKey(): void {
		$this->expectException(PasskeyVerificationException::class);
		$this->expectExceptionMessage('The passkey has no verified public key');
		$this->verifier->verifyUnlock(
			uid: self::UID,
			rpId: WebAuthnFixture::RP_ID,
			challenge: random_bytes(32),
			assertion: $this->passkey->assertion(random_bytes(32), self::UID),
			credentialId: $this->passkey->credentialIdB64(),
			publicKey: '',
			counter: 0,
		);
	}//end testTheVerifierRefusesACredentialWithoutAPublicKey()
}//end class
