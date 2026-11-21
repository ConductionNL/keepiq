<?php

/**
 * Unit tests for browser-extension passkey enrolment and unlock against
 * real ceremony data.
 *
 * The service runs with the real extension ceremony, challenge store,
 * trusted origins and WebAuthn verifier over a software passkey; the
 * challenge table, the app password and the app config are in memory. The
 * verifier needs the server's 3rdparty autoloader.
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
use OCA\Keepiq\Db\WebAuthnChallengeMapper;
use OCA\Keepiq\Event\Audit\AuditEvent;
use OCA\Keepiq\Event\Audit\AuditEventTypes;
use OCA\Keepiq\Service\ExtensionAppPassword;
use OCA\Keepiq\Service\ExtensionCeremonySteps;
use OCA\Keepiq\Service\ExtensionChallengeStore;
use OCA\Keepiq\Service\ExtensionPasskeyCeremony;
use OCA\Keepiq\Service\PasskeyChallengeStore;
use OCA\Keepiq\Service\PasskeyEnrolmentCeremony;
use OCA\Keepiq\Service\PasskeyService;
use OCA\Keepiq\Service\PasskeyUnlockCeremony;
use OCA\Keepiq\Service\TrustedExtensionOrigins;
use OCA\Keepiq\Service\WebAuthnVerifier;
use OCA\Keepiq\Tests\Support\WebAuthnFixture;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\EventDispatcher\Event;
use OCP\EventDispatcher\IEventDispatcher;
use OCP\IAppConfig;
use OCP\IRequest;
use OCP\ISession;
use OCP\Security\ISecureRandom;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use Webauthn\AuthenticatorAssertionResponseValidator;

/**
 * An extension passkey is enrolled and unlocks only through verified,
 * app-password-bound ceremonies from a trusted extension origin.
 */
class ExtensionPasskeyTest extends TestCase {
	private const UID = 'alice';

	private const ORIGIN = 'chrome-extension://abcdefghijklmnopabcdefghijklmnop';

	/**
	 * The challenge rows, by id.
	 *
	 * @var array<int,array<string,mixed>>
	 */
	private array $rows = [];

	private int $nextId = 1;

	private int $now = 1000;

	/**
	 * The token id of the request's app password, or null.
	 *
	 * @var int|null
	 */
	private ?int $tokenId = 7;

	private bool $enabled = true;

	private string $origins = '["' . self::ORIGIN . '"]';

	/**
	 * The stored credentials.
	 *
	 * @var PasskeyCredential[]
	 */
	private array $credentials = [];

	/**
	 * The audit event types written.
	 *
	 * @var string[]
	 */
	private array $audited = [];

	/**
	 * The refusal reasons logged.
	 *
	 * @var string[]
	 */
	private array $refusals = [];

	private WebAuthnFixture $passkey;

	private PasskeyService $service;

	/**
	 * The service over in-memory stores and the real verifier.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		parent::setUp();
		if (class_exists(AuthenticatorAssertionResponseValidator::class) === false) {
			$this->markTestSkipped('Needs the WebAuthn library from the Nextcloud server (3rdparty)');
		}

		$this->passkey = new WebAuthnFixture();
		$this->service = $this->service();
	}//end setUp()

	/**
	 * Build the service.
	 *
	 * @return PasskeyService
	 */
	private function service(): PasskeyService {
		$appConfig = $this->createMock(originalClassName: IAppConfig::class);
		$appConfig->method('getValueBool')->willReturnCallback(fn (): bool => $this->enabled);
		$appConfig->method('getValueString')->willReturnCallback(fn (): string => $this->origins);
		$origins = new TrustedExtensionOrigins(appConfig: $appConfig);
		$verifier = new WebAuthnVerifier(extensionSteps: new ExtensionCeremonySteps(origins: $origins));

		$clock = $this->createMock(originalClassName: ITimeFactory::class);
		$clock->method('getTime')->willReturnCallback(fn (): int => $this->now);
		$random = $this->createMock(originalClassName: ISecureRandom::class);
		$random->method('generate')->willReturnCallback(static fn (int $length): string => random_bytes($length));
		$challenges = new ExtensionChallengeStore(mapper: $this->challengeTable(), random: $random, timeFactory: $clock);

		$appPassword = $this->createMock(originalClassName: ExtensionAppPassword::class);
		$appPassword->method('tokenId')->willReturnCallback(fn (): ?int => $this->tokenId);

		$request = $this->createMock(originalClassName: IRequest::class);
		$request->method('getServerHost')->willReturn(WebAuthnFixture::RP_ID . ':443');
		$logger = $this->createMock(originalClassName: LoggerInterface::class);
		$logger->method('warning')->willReturnCallback(function (string $message, array $context): void {
			$this->refusals[] = $context['reason'];
		});
		$dispatcher = $this->createMock(originalClassName: IEventDispatcher::class);
		$dispatcher->method('dispatchTyped')->willReturnCallback(function (Event $event): void {
			if ($event instanceof AuditEvent) {
				$this->audited[] = $event->getEventType();
			}
		});

		$mapper = $this->createMock(originalClassName: PasskeyMapper::class);
		$mapper->method('findActiveByOwner')->willReturnCallback(
			fn (): array => array_values(array_filter($this->credentials, static fn (PasskeyCredential $c): bool => $c->getStatus() === 'active'))
		);
		$mapper->method('findByCredentialId')->willReturn(null);
		$mapper->method('insert')->willReturnCallback(function (PasskeyCredential $credential): PasskeyCredential {
			$this->credentials[] = $credential;
			return $credential;
		});
		$mapper->method('update')->willReturnArgument(0);

		$suite = new EncryptionSuite();
		$suite->setUnlockKeyEpoch(3);
		$suiteMapper = $this->createMock(originalClassName: EncryptionSuiteMapper::class);
		$suiteMapper->method('findActiveByOwner')->willReturn($suite);

		$webChallenges = new PasskeyChallengeStore(session: $this->createMock(originalClassName: ISession::class), timeFactory: $clock);
		$enrolment = new PasskeyEnrolmentCeremony(challenges: $webChallenges, verifier: $verifier, request: $request, logger: $logger);

		return new PasskeyService(
			mapper: $mapper,
			suiteMapper: $suiteMapper,
			ceremony: $enrolment,
			unlockCeremony: new PasskeyUnlockCeremony(challenges: $webChallenges, verifier: $verifier, enrolment: $enrolment, logger: $logger),
			extension: new ExtensionPasskeyCeremony(
				appPassword: $appPassword,
				challenges: $challenges,
				origins: $origins,
				verifier: $verifier,
				web: $enrolment,
				mapper: $mapper,
				dispatcher: $dispatcher,
				logger: $logger,
			),
		);
	}//end service()

	/**
	 * An in-memory challenge table.
	 *
	 * @return WebAuthnChallengeMapper
	 */
	private function challengeTable(): WebAuthnChallengeMapper {
		$table = $this->createMock(originalClassName: WebAuthnChallengeMapper::class);
		$table->method('replace')->willReturnCallback(function (array $row): void {
			foreach ($this->rows as $id => $existing) {
				if ([$existing['uid'], $existing['token_id'], $existing['purpose']] === [$row['uid'], $row['token_id'], $row['purpose']]) {
					unset($this->rows[$id]);
				}
			}

			$this->rows[$this->nextId] = ['id' => $this->nextId] + $row;
			$this->nextId++;
		});
		$table->method('findPending')->willReturnCallback(function (string $uid, int $tokenId, string $purpose): ?array {
			foreach ($this->rows as $row) {
				if ([$row['uid'], $row['token_id'], $row['purpose']] === [$uid, $tokenId, $purpose]) {
					return $row;
				}
			}

			return null;
		});
		$table->method('deleteById')->willReturnCallback(function (int $id): int {
			$found = isset($this->rows[$id]);
			unset($this->rows[$id]);
			return (int)$found;
		});

		return $table;
	}//end challengeTable()

	/**
	 * Raw bytes of unpadded base64url.
	 *
	 * @param string $value The base64url text
	 *
	 * @return string
	 */
	private static function raw(string $value): string {
		return (string)base64_decode(strtr($value, '-_', '+/'), true);
	}//end raw()

	/**
	 * The counter the proof assertion reports after the attestation's: one
	 * higher, or 0 for an authenticator that keeps none.
	 *
	 * @param int $counter The attestation counter
	 *
	 * @return int
	 */
	private static function next(int $counter): int {
		if ($counter === 0) {
			return 0;
		}

		return $counter + 1;
	}//end next()

	/**
	 * Enrol the software passkey from the extension.
	 *
	 * @param array<string,mixed> $override Overrides for both ceremonies
	 *
	 * @return PasskeyCredential
	 */
	private function enrol(array $override = []): PasskeyCredential {
		$options = $this->service->enrolmentChallenge(uid: self::UID, client: 'extension');
		$override += ['origin' => self::ORIGIN, 'counter' => 1];

		return $this->service->enroll(
			uid: self::UID,
			dto: [
				'clientKind' => 'extension',
				'rpId' => 'abcdefghijklmnopabcdefghijklmnop',
				'credentialId' => $this->passkey->credentialIdB64(),
				'prfSalt' => 'salt',
				'wrappedUnlockKey' => 'WRAPPED',
				'attestation' => $this->passkey->attestation(self::raw(value: $options['challenge']), $override),
				'assertion' => $this->passkey->assertion(
					self::raw(value: $options['assertionChallenge']),
					$options['userHandle'],
					['counter' => self::next(counter: $override['counter'])] + $override
				),
			],
		);
	}//end enrol()

	/**
	 * Fetch the extension's unlock options and answer them.
	 *
	 * @param PasskeyCredential $credential The enrolled credential
	 * @param array<string,mixed> $override Assertion overrides
	 *
	 * @return array<string,mixed>
	 */
	private function unlock(PasskeyCredential $credential, array $override = []): array {
		$options = $this->service->loginOptions(uid: self::UID, client: 'extension');

		return $this->service->unlock(
			uid: self::UID,
			assertion: $this->passkey->assertion(
				self::raw(value: (string)$options['challenge']),
				(string)$credential->getUserHandle(),
				$override + ['origin' => self::ORIGIN, 'counter' => 5]
			),
			client: 'extension',
		);
	}//end unlock()

	/**
	 * Assert a ceremony is refused with the generic message and the reason.
	 *
	 * @param callable $ceremony Runs the ceremony
	 * @param string $reason The logged reason
	 *
	 * @return void
	 */
	private function assertRefused(callable $ceremony, string $reason): void {
		try {
			$ceremony();
			$this->fail('the ceremony was accepted');
		} catch (InvalidArgumentException $exception) {
			$this->assertSame('The passkey could not be verified', $exception->getMessage());
		}

		$this->assertSame($reason, end($this->refusals));
		$this->assertContains(AuditEventTypes::PASSKEY_REFUSED, $this->audited);
	}//end assertRefused()

	/**
	 * An enrolment is verified against the server host, bound to the app
	 * password, and stored with a random user handle and its counter.
	 *
	 * @return void
	 */
	public function testEnrolsBoundToTheAppPasswordAndTheServerHost(): void {
		$options = $this->service->enrolmentChallenge(uid: self::UID, client: 'extension');
		$this->assertSame(WebAuthnFixture::RP_ID, $options['rpId']);
		$this->assertNotSame(self::UID, $options['userHandle']);
		$this->rows = [];

		$credential = $this->enrol();

		$this->assertSame('extension', $credential->getClientKind());
		$this->assertSame(WebAuthnFixture::RP_ID, $credential->getRpId());
		$this->assertSame(7, $credential->getTokenId());
		$this->assertSame(2, $credential->getSignCount());
		$this->assertNotSame(self::UID, $credential->getUserHandle());
		$this->assertSame([AuditEventTypes::PASSKEY_ENROLLED], $this->audited);
	}//end testEnrolsBoundToTheAppPasswordAndTheServerHost()

	/**
	 * Each refused extension enrolment.
	 *
	 * @return void
	 */
	public function testRefusesAnEnrolmentFromAnUntrustedOriginOrWithoutUserVerification(): void {
		$this->assertRefused(fn () => $this->enrol(['origin' => 'chrome-extension://ponmlkjihgfedcbaponmlkjihgfedcba']), 'The extension origin is not trusted');
		$this->assertRefused(fn () => $this->enrol(['origin' => WebAuthnFixture::ORIGIN]), 'The extension origin is not trusted');
		$this->assertRefused(fn () => $this->enrol(['flags' => 0x41]), 'User authentication required.');
		$this->assertSame([], $this->credentials);
	}//end testRefusesAnEnrolmentFromAnUntrustedOriginOrWithoutUserVerification()

	/**
	 * Without an app password, or with extension passkeys closed, no
	 * enrolment challenge is issued.
	 *
	 * @return array<string,array{0: int|null, 1: bool, 2: string, 3: string}>
	 */
	public static function closedEnrolments(): array {
		$trusted = '["' . self::ORIGIN . '"]';
		return [
			'no app password' => [null, true, $trusted, 'the request did not authenticate with an app password'],
			'switched off' => [7, false, $trusted, 'Extension passkeys are switched off'],
			'a broken origin setting' => [7, true, '{', 'The trusted extension origins setting is unreadable'],
		];
	}//end closedEnrolments()

	/**
	 * A closed enrolment is refused before any challenge exists.
	 *
	 * @param int|null $tokenId The app password's token id
	 * @param bool $enabled The switch
	 * @param string $origins The stored admin origins
	 * @param string $reason The logged reason
	 *
	 * @return void
	 */
	#[DataProvider('closedEnrolments')]
	public function testRefusesAClosedEnrolment(?int $tokenId, bool $enabled, string $origins, string $reason): void {
		$this->tokenId = $tokenId;
		$this->enabled = $enabled;
		$this->origins = $origins;

		$this->assertRefused(fn () => $this->service->enrolmentChallenge(uid: self::UID, client: 'extension'), $reason);
		$this->assertSame([], $this->rows);
	}//end testRefusesAClosedEnrolment()

	/**
	 * The unlock options carry no envelope, and a verified assertion
	 * releases the envelope and stores the counter.
	 *
	 * @return void
	 */
	public function testAVerifiedUnlockReleasesTheEnvelope(): void {
		$credential = $this->enrol();
		$options = $this->service->loginOptions(uid: self::UID, client: 'extension');
		$this->assertSame(WebAuthnFixture::RP_ID, $options['rpId']);
		$this->assertSame(['id', 'credentialId', 'prfSalt', 'unlockKeyEpoch'], array_keys($options['credentials'][0]));
		$this->assertStringNotContainsString('WRAPPED', (string)json_encode($options));

		$released = $this->unlock(credential: $credential);

		$this->assertSame('WRAPPED', $released['wrappedUnlockKey']);
		$this->assertSame(5, $credential->getSignCount());
		$this->assertContains(AuditEventTypes::PASSKEY_UNLOCKED, $this->audited);
	}//end testAVerifiedUnlockReleasesTheEnvelope()

	/**
	 * An authenticator that keeps no counter still unlocks.
	 *
	 * @return void
	 */
	public function testAnAuthenticatorWithoutACounterUnlocks(): void {
		$credential = $this->enrol(['counter' => 0]);

		$this->assertSame('WRAPPED', $this->unlock(credential: $credential, override: ['counter' => 0])['wrappedUnlockKey']);
	}//end testAnAuthenticatorWithoutACounterUnlocks()

	/**
	 * A counter that did not increase refuses the unlock and suspends the
	 * credential, which is then no longer offered.
	 *
	 * @return void
	 */
	public function testACounterThatWentBackSuspendsTheCredential(): void {
		$credential = $this->enrol(['counter' => 12]);

		$this->assertRefused(fn () => $this->unlock(credential: $credential, override: ['counter' => 12]), 'Invalid counter.');
		$this->assertSame('suspended', $credential->getStatus());
		$this->assertContains(AuditEventTypes::PASSKEY_SUSPENDED, $this->audited);
		$this->assertSame([], $this->service->loginOptions(uid: self::UID, client: 'extension')['credentials']);
	}//end testACounterThatWentBackSuspendsTheCredential()

	/**
	 * Another app password of the same user sees no credential and cannot
	 * unlock with it.
	 *
	 * @return void
	 */
	public function testAnotherAppPasswordCannotUseTheCredential(): void {
		$credential = $this->enrol();
		$this->tokenId = 8;

		$this->assertSame([], $this->service->loginOptions(uid: self::UID, client: 'extension')['credentials']);
		$this->assertRefused(fn () => $this->unlock(credential: $credential), 'the assertion names no passkey of this app password');
	}//end testAnotherAppPasswordCannotUseTheCredential()

	/**
	 * A web session (no app password) gets no extension challenge, no
	 * credential and no envelope.
	 *
	 * @return void
	 */
	public function testAWebSessionCannotUseTheCredential(): void {
		$credential = $this->enrol();
		$this->tokenId = null;

		$options = $this->service->loginOptions(uid: self::UID, client: 'extension');
		$this->assertNull($options['challenge']);
		$this->assertSame([], $options['credentials']);
		$this->assertRefused(
			fn () => $this->service->unlock(
				uid: self::UID,
				assertion: $this->passkey->assertion(random_bytes(32), (string)$credential->getUserHandle(), ['origin' => self::ORIGIN, 'counter' => 5]),
				client: 'extension',
			),
			'no app password, or no pending or an expired unlock challenge',
		);
	}//end testAWebSessionCannotUseTheCredential()

	/**
	 * An accepted assertion cannot be presented a second time.
	 *
	 * @return void
	 */
	public function testRefusesAReplayedAssertion(): void {
		$credential = $this->enrol();
		$options = $this->service->loginOptions(uid: self::UID, client: 'extension');
		$assertion = $this->passkey->assertion(self::raw(value: (string)$options['challenge']), (string)$credential->getUserHandle(), ['origin' => self::ORIGIN, 'counter' => 5]);
		$this->service->unlock(uid: self::UID, assertion: $assertion, client: 'extension');

		$this->assertRefused(
			fn () => $this->service->unlock(uid: self::UID, assertion: $assertion, client: 'extension'),
			'no app password, or no pending or an expired unlock challenge',
		);
	}//end testRefusesAReplayedAssertion()

	/**
	 * Removing the origin from the trusted list stops its credential at the
	 * next unlock.
	 *
	 * @return void
	 */
	public function testRemovingTheOriginStopsTheNextUnlock(): void {
		$credential = $this->enrol();
		$this->origins = '[]';

		$this->assertRefused(fn () => $this->unlock(credential: $credential), 'The extension origin is not trusted');
	}//end testRemovingTheOriginStopsTheNextUnlock()

	/**
	 * Closing extension passkeys after enrolment offers no challenge at all.
	 *
	 * @return void
	 */
	public function testSwitchingOffOffersNoChallenge(): void {
		$this->enrol();
		$this->enabled = false;

		$options = $this->service->loginOptions(uid: self::UID, client: 'extension');
		$this->assertNull($options['challenge']);
		$this->assertSame([], $options['credentials']);
	}//end testSwitchingOffOffersNoChallenge()

	/**
	 * An unlock without user verification is refused.
	 *
	 * @return void
	 */
	public function testRefusesAnUnlockWithoutUserVerification(): void {
		$credential = $this->enrol();

		$this->assertRefused(fn () => $this->unlock(credential: $credential, override: ['flags' => 0x01]), 'User authentication required.');
	}//end testRefusesAnUnlockWithoutUserVerification()
}//end class
