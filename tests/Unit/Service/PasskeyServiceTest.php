<?php

/**
 * Unit tests for PasskeyService (passkey-vault-login §5.1).
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
use OCA\Keepiq\Service\VerifiedPasskey;
use OCA\Keepiq\Service\WebAuthnVerifier;
use OCP\AppFramework\Db\DoesNotExistException;
use OCP\IRequest;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

/**
 * Tests for the passkey vault-login service.
 */
class PasskeyServiceTest extends TestCase {
	private PasskeyService $service;

	private PasskeyMapper&MockObject $mapper;

	private EncryptionSuiteMapper&MockObject $suiteMapper;

	private PasskeyChallengeStore&MockObject $challenges;

	private WebAuthnVerifier&MockObject $verifier;

	private IRequest&MockObject $request;

	private LoggerInterface&MockObject $logger;

	/**
	 * Build the service over mocked mappers with a suite at epoch 3, a
	 * pending challenge pair, and a verifier that accepts the ceremony and
	 * reports the attestation's own credential id.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		parent::setUp();
		$this->mapper = $this->createMock(originalClassName: PasskeyMapper::class);
		$this->suiteMapper = $this->createMock(originalClassName: EncryptionSuiteMapper::class);

		$suite = new EncryptionSuite();
		$suite->setUnlockKeyEpoch(3);
		$this->suiteMapper->method('findActiveByOwner')->willReturn($suite);

		$this->challenges = $this->createMock(originalClassName: PasskeyChallengeStore::class);
		$this->challenges->method('take')->willReturn(['challenge' => 'C1', 'assertionChallenge' => 'C2']);
		$this->challenges->method('issueUnlock')->willReturn('U1');
		$this->verifier = $this->createMock(originalClassName: WebAuthnVerifier::class);
		$this->verifier->method('verifyEnrolment')->willReturnCallback(
			static fn (string $uid, string $rpId, string $challenge, string $assertionChallenge, array $attestation) => new VerifiedPasskey(
				credentialId: (string)($attestation['id'] ?? ''),
				publicKey: 'PK',
				aaguid: '00112233-4455-6677-8899-aabbccddeeff',
				counter: 1,
			)
		);
		$this->request = $this->createMock(originalClassName: IRequest::class);
		$this->request->method('getServerHost')->willReturn('cloud.example.com:8443');
		$this->logger = $this->createMock(originalClassName: LoggerInterface::class);

		$this->service = $this->service(challenges: $this->challenges, verifier: $this->verifier);
	}//end setUp()

	/**
	 * The service over the given challenge store and verifier.
	 *
	 * @param PasskeyChallengeStore $challenges The challenge store
	 * @param WebAuthnVerifier $verifier The verifier
	 *
	 * @return PasskeyService
	 */
	private function service(PasskeyChallengeStore $challenges, WebAuthnVerifier $verifier): PasskeyService {
		$enrolment = new PasskeyEnrolmentCeremony(
			challenges: $challenges,
			verifier: $verifier,
			request: $this->request,
			logger: $this->logger,
		);

		return new PasskeyService(
			mapper: $this->mapper,
			suiteMapper: $this->suiteMapper,
			ceremony: $enrolment,
			unlockCeremony: new PasskeyUnlockCeremony(
				challenges: $challenges,
				verifier: $verifier,
				enrolment: $enrolment,
				logger: $this->logger,
			),
		);
	}//end service()

	/**
	 * An enrolment request whose ceremony names the given credential.
	 *
	 * @param string $credentialId The credential id
	 * @param array<string,mixed> $extra Further fields
	 *
	 * @return array<string,mixed>
	 */
	private static function enrolment(string $credentialId, array $extra = []): array {
		return $extra + [
			'credentialId' => $credentialId,
			'prfSalt' => 's',
			'wrappedUnlockKey' => 'e',
			'attestation' => ['id' => $credentialId],
			'assertion' => ['id' => $credentialId],
		];
	}//end enrolment()

	/**
	 * A credential owned by alice.
	 *
	 * @param string $id The credential id
	 * @param int $epoch The unlock-key epoch it targets
	 *
	 * @return PasskeyCredential
	 */
	private function makeCredential(string $id, int $epoch = 3): PasskeyCredential {
		$credential = new PasskeyCredential();
		$credential->setId($id);
		$credential->setOwnerId('alice');
		$credential->setCredentialId('cred-' . $id);
		$credential->setPublicKey('PK');
		$credential->setPrfSalt('salt');
		$credential->setWrappedUnlockKey('envelope');
		$credential->setUnlockKeyEpoch($epoch);
		$credential->setStatus('active');

		return $credential;
	}//end makeCredential()

	/**
	 * Enrollment binds the envelope to the CURRENT suite epoch and rejects
	 * a missing envelope (§5.1 / §2.5).
	 *
	 * @return void
	 */
	public function testEnrollBindsCurrentEpoch(): void {
		$this->mapper->method('findByCredentialId')->willReturn(null);
		$this->mapper->expects($this->once())->method('insert')->willReturnArgument(0);

		$credential = $this->service->enroll(
			uid: 'alice',
			dto: self::enrolment(credentialId: 'cred-x', extra: ['label' => 'Touch ID']),
		);

		$this->assertSame(3, $credential->getUnlockKeyEpoch());
		$this->assertSame('alice', $credential->getOwnerId());
	}//end testEnrollBindsCurrentEpoch()

	/**
	 * Enrollment without a wrapped envelope is rejected (§2.5).
	 *
	 * @return void
	 */
	public function testEnrollRejectsMissingEnvelope(): void {
		$this->mapper->expects($this->never())->method('insert');

		$this->expectException(InvalidArgumentException::class);
		$this->service->enroll(uid: 'alice', dto: ['credentialId' => 'x', 'prfSalt' => 'y']);
	}//end testEnrollRejectsMissingEnvelope()

	/**
	 * A duplicate credential id is rejected (§enrollment).
	 *
	 * @return void
	 */
	public function testEnrollRejectsDuplicate(): void {
		$this->mapper->method('findByCredentialId')->willReturn($this->makeCredential('dup'));
		$this->mapper->expects($this->never())->method('insert');

		$this->expectException(InvalidArgumentException::class);
		$this->service->enroll(
			uid: 'alice',
			dto: self::enrolment(credentialId: 'cred-dup'),
		);
	}//end testEnrollRejectsDuplicate()

	/**
	 * loginOptions returns only epoch-current, verified credentials, without
	 * any envelope, and marks a trailing-epoch credential and one without a
	 * verified public key stale (§2.2 / §D4).
	 *
	 * @return void
	 */
	public function testLoginOptionsRefusesStaleEpoch(): void {
		$current = $this->makeCredential('cur', 3);
		$old = $this->makeCredential('old', 2);
		$unverified = $this->makeCredential('unv', 3);
		$unverified->setPublicKey('');
		$this->mapper->method('findActiveByOwner')->willReturn([$current, $old, $unverified]);

		// The trailing-epoch credential is marked stale (an update).
		$staled = [];
		$this->mapper->method('update')->willReturnCallback(static function (PasskeyCredential $c) use (&$staled) {
			$staled[] = [$c->getId(), $c->getStatus()];

			return $c;
		});

		$options = $this->service->loginOptions('alice');

		$this->assertCount(1, $options['credentials']);
		$this->assertSame(
			['id' => 'cur', 'credentialId' => 'cred-cur', 'prfSalt' => 'salt', 'unlockKeyEpoch' => 3],
			$options['credentials'][0]
		);
		$this->assertSame([['old', 'stale'], ['unv', 'stale']], $staled);
		$this->assertSame(3, $options['unlockKeyEpoch']);
		$this->assertSame('VTE', $options['challenge']);
		$this->assertStringNotContainsString('envelope', (string)json_encode($options));
	}//end testLoginOptionsRefusesStaleEpoch()

	/**
	 * Revoke enforces the owner guard — a stranger cannot delete another
	 * user's passkey (§5.1 IDOR).
	 *
	 * @return void
	 */
	public function testRevokeRejectsCrossOwner(): void {
		$this->mapper->method('findById')->willReturn($this->makeCredential('c1'));
		$this->mapper->expects($this->never())->method('delete');

		$this->expectException(InvalidArgumentException::class);
		$this->service->revoke(uid: 'mallory', id: 'c1');
	}//end testRevokeRejectsCrossOwner()

	/**
	 * The owner can revoke their own passkey (§5.1).
	 *
	 * @return void
	 */
	public function testRevokeByOwner(): void {
		$credential = $this->makeCredential('c1');
		$this->mapper->method('findById')->willReturn($credential);
		$this->mapper->expects($this->once())->method('delete')->with($credential);

		$this->service->revoke(uid: 'alice', id: 'c1');
	}//end testRevokeByOwner()

	/**
	 * A password change marks all the owner's envelopes stale; a
	 * compromise rotation deletes them all (§D4).
	 *
	 * @return void
	 */
	public function testStalenessAndRotationHooks(): void {
		$this->mapper->expects($this->once())->method('markOwnerStale')->with('alice');
		$this->service->markStaleOnPasswordChange('alice');

		$this->mapper->expects($this->once())->method('deleteByOwner')->with('alice');
		$this->service->deleteAllOnRotation('alice');
	}//end testStalenessAndRotationHooks()

	/**
	 * A missing credential surfaces as DoesNotExist, never a silent pass
	 * (§5.1).
	 *
	 * @return void
	 */
	public function testRevokeMissingThrows(): void {
		$this->mapper->method('findById')->willThrowException(new DoesNotExistException('gone'));

		$this->expectException(DoesNotExistException::class);
		$this->service->revoke(uid: 'alice', id: 'missing');
	}//end testRevokeMissingThrows()

	/**
	 * A credential of a given client kind and relying party.
	 *
	 * @param string $id The credential id
	 * @param string $kind web or extension
	 * @param string|null $rpId The relying party id
	 *
	 * @return PasskeyCredential
	 */
	private function makeClientCredential(string $id, string $kind, ?string $rpId): PasskeyCredential {
		$credential = $this->makeCredential($id);
		$credential->setClientKind($kind);
		$credential->setRpId($rpId);

		return $credential;
	}//end makeClientCredential()

	/**
	 * The web app never receives an extension credential, and the extension
	 * never receives a web credential or another extension's credential.
	 *
	 * @return void
	 *
	 * @spec openspec/specs/extension-biometric-unlock/spec.md#requirement-extension-credentials-are-visible-and-revocable-in-the-web-app
	 */
	public function testLoginOptionsSeparatesWebAndExtensionCredentials(): void {
		$this->mapper->method('findActiveByOwner')->willReturn([
			$this->makeClientCredential('web1', 'web', null),
			$this->makeClientCredential('ext1', 'extension', 'abcdefghijklmnop'),
			$this->makeClientCredential('ext2', 'extension', 'otherextensionid'),
		]);

		$web = $this->service->loginOptions('alice');
		$this->assertSame(['web1'], array_column($web['credentials'], 'id'));

		$extension = $this->service->loginOptions(uid: 'alice', client: 'extension', rpId: 'abcdefghijklmnop');
		$this->assertSame(['ext1'], array_column($extension['credentials'], 'id'));

		// An extension request without its relying party gets nothing.
		$noRp = $this->service->loginOptions(uid: 'alice', client: 'extension', rpId: '');
		$this->assertSame([], $noRp['credentials']);
	}//end testLoginOptionsSeparatesWebAndExtensionCredentials()

	/**
	 * Enrolment stores the client kind and relying party; an extension
	 * credential needs its relying party and an unknown kind is refused.
	 *
	 * @return void
	 */
	public function testEnrollStoresClientKindAndRpId(): void {
		$this->mapper->method('findByCredentialId')->willReturn(null);
		$this->mapper->method('insert')->willReturnArgument(0);

		$credential = $this->service->enroll(
			uid: 'alice',
			dto: self::enrolment(credentialId: 'c', extra: ['clientKind' => 'extension', 'rpId' => 'abcdefghijklmnop']),
		);
		$this->assertSame('extension', $credential->getClientKind());
		$this->assertSame('abcdefghijklmnop', $credential->getRpId());
		$this->assertSame('extension', $credential->jsonSerialize()['clientKind']);

		$web = $this->service->enroll(uid: 'alice', dto: self::enrolment(credentialId: 'w'));
		$this->assertSame('web', $web->getClientKind());

		foreach ([['clientKind' => 'extension'], ['clientKind' => 'desktop']] as $extra) {
			try {
				$this->service->enroll(uid: 'alice', dto: self::enrolment(credentialId: 'x', extra: $extra));
				$this->fail('enrol accepted ' . json_encode($extra));
			} catch (InvalidArgumentException) {
				$this->addToAssertionCount(1);
			}
		}
	}//end testEnrollStoresClientKindAndRpId()

	/**
	 * Golden: each refusal of an enrolment, in check order, with its message,
	 * none of them reaching the duplicate lookup.
	 *
	 * @return void
	 */
	public function testEnrollRefusesInCheckOrder(): void {
		$this->mapper->expects($this->never())->method('findByCredentialId');
		$this->mapper->expects($this->never())->method('insert');
		$this->verifier->expects($this->never())->method('verifyEnrolment');
		$base = self::enrolment(credentialId: 'c');
		$cases = [
			[['prfSalt' => ''] + $base, 'credentialId, prfSalt and wrappedUnlockKey are required'],
			[['credentialId' => '', 'clientKind' => 'desktop'] + $base, 'credentialId, prfSalt and wrappedUnlockKey are required'],
			[['clientKind' => 'desktop'] + $base, 'clientKind must be web or extension'],
			[['clientKind' => 'extension', 'rpId' => ''] + $base, 'An extension passkey needs its relying party id'],
		];
		foreach ($cases as [$dto, $message]) {
			try {
				$this->service->enroll(uid: 'alice', dto: $dto);
				$this->fail('enrolled ' . json_encode($dto));
			} catch (InvalidArgumentException $exception) {
				$this->assertSame($message, $exception->getMessage());
			}
		}
	}//end testEnrollRefusesInCheckOrder()

	/**
	 * Golden: an enrolment stores every field it was given, an empty client
	 * kind as web and empty optional fields as null, as an active credential.
	 *
	 * @return void
	 */
	public function testEnrollStoresEveryField(): void {
		$this->mapper->expects($this->once())->method('findByCredentialId')->with('alice', 'c')->willReturn(null);
		$this->mapper->method('insert')->willReturnArgument(0);

		$credential = $this->service->enroll(
			uid: 'alice',
			dto: self::enrolment(
				credentialId: 'c',
				extra: ['publicKey' => 'CLAIMED', 'aaguid' => 'CLAIMED', 'label' => '', 'transports' => 'usb,nfc', 'clientKind' => ''],
			),
		);

		$this->assertSame(
			['alice', 'c', 'PK', 's', 'e', 3, null, 'usb,nfc', '00112233-4455-6677-8899-aabbccddeeff', 'web', null, 'active', 1],
			[
				$credential->getOwnerId(), $credential->getCredentialId(), $credential->getPublicKey(), $credential->getPrfSalt(),
				$credential->getWrappedUnlockKey(), $credential->getUnlockKeyEpoch(), $credential->getLabel(), $credential->getTransports(),
				$credential->getAaguid(), $credential->getClientKind(), $credential->getRpId(), $credential->getStatus(),
				$credential->getSignCount(),
			]
		);
		$this->assertMatchesRegularExpression('/^[0-9a-f-]{36}$/', $credential->getId());
		$this->assertNotNull($credential->getCreatedAt());
	}//end testEnrollStoresEveryField()

	/**
	 * A service whose challenge store returns the given pending pair and
	 * whose verifier is the given mock.
	 *
	 * @param array{challenge: string, assertionChallenge: string}|null $pending The pending pair
	 * @param WebAuthnVerifier&MockObject $verifier The verifier
	 *
	 * @return PasskeyService
	 */
	private function serviceWith(?array $pending, WebAuthnVerifier&MockObject $verifier): PasskeyService {
		$challenges = $this->createMock(originalClassName: PasskeyChallengeStore::class);
		$challenges->expects($this->once())->method('take')->with('alice')->willReturn($pending);

		return $this->service(challenges: $challenges, verifier: $verifier);
	}//end serviceWith()

	/**
	 * Assert an enrolment is refused with the generic message, logged with
	 * its reason, and stores nothing.
	 *
	 * @param PasskeyService $service The service
	 * @param array<string,mixed> $dto The enrolment request
	 * @param string $reason The logged reason
	 *
	 * @return void
	 */
	private function assertRefused(PasskeyService $service, array $dto, string $reason): void {
		$this->mapper->expects($this->never())->method('insert');
		$this->logger->expects($this->once())->method('warning')->with(
			$this->anything(),
			$this->callback(static fn (array $context): bool => $context['reason'] === $reason && $context['uid'] === 'alice')
		);

		try {
			$service->enroll(uid: 'alice', dto: $dto);
			$this->fail('enrolled without a verified ceremony');
		} catch (InvalidArgumentException $exception) {
			$this->assertSame('The passkey could not be verified', $exception->getMessage());
		}
	}//end assertRefused()

	/**
	 * Without a pending (unexpired, same-user) challenge the enrolment is
	 * refused before the ceremony is even looked at.
	 *
	 * @return void
	 */
	public function testEnrollRefusesWithoutAPendingChallenge(): void {
		$verifier = $this->createMock(originalClassName: WebAuthnVerifier::class);
		$verifier->expects($this->never())->method('verifyEnrolment');

		$this->assertRefused(
			service: $this->serviceWith(pending: null, verifier: $verifier),
			dto: self::enrolment(credentialId: 'c'),
			reason: 'no pending or an expired enrolment challenge',
		);
	}//end testEnrollRefusesWithoutAPendingChallenge()

	/**
	 * An enrolment without its attestation or proof assertion is refused.
	 *
	 * @return void
	 */
	public function testEnrollRefusesWithoutTheCeremony(): void {
		$verifier = $this->createMock(originalClassName: WebAuthnVerifier::class);
		$verifier->expects($this->never())->method('verifyEnrolment');

		$this->assertRefused(
			service: $this->serviceWith(pending: ['challenge' => 'C1', 'assertionChallenge' => 'C2'], verifier: $verifier),
			dto: ['credentialId' => 'c', 'prfSalt' => 's', 'wrappedUnlockKey' => 'e', 'attestation' => ['id' => 'c']],
			reason: 'attestation or assertion missing',
		);
	}//end testEnrollRefusesWithoutTheCeremony()

	/**
	 * A ceremony the verifier refuses is refused and logged with the failed
	 * check; the web relying party is the server host without its port and
	 * the verifier gets the taken challenge pair.
	 *
	 * @return void
	 */
	public function testEnrollRefusesAFailedVerification(): void {
		$verifier = $this->createMock(originalClassName: WebAuthnVerifier::class);
		$verifier->expects($this->once())->method('verifyEnrolment')
			->with('alice', 'cloud.example.com', 'C1', 'C2', ['id' => 'c'], ['id' => 'c'])
			->willThrowException(new PasskeyVerificationException('Invalid signature.'));

		$this->assertRefused(
			service: $this->serviceWith(pending: ['challenge' => 'C1', 'assertionChallenge' => 'C2'], verifier: $verifier),
			dto: self::enrolment(credentialId: 'c'),
			reason: 'Invalid signature.',
		);
	}//end testEnrollRefusesAFailedVerification()

	/**
	 * A claimed credential id other than the attested one is refused: the
	 * envelope would be wrapped for a credential that was never proven.
	 *
	 * @return void
	 */
	public function testEnrollRefusesACredentialIdOtherThanTheAttestedOne(): void {
		$verifier = $this->createMock(originalClassName: WebAuthnVerifier::class);
		$verifier->method('verifyEnrolment')->willReturn(new VerifiedPasskey('attested', 'PK', 'aaguid', 1));

		$this->assertRefused(
			service: $this->serviceWith(pending: ['challenge' => 'C1', 'assertionChallenge' => 'C2'], verifier: $verifier),
			dto: self::enrolment(credentialId: 'claimed'),
			reason: 'credential id differs from the attested one',
		);
	}//end testEnrollRefusesACredentialIdOtherThanTheAttestedOne()

	/**
	 * An extension credential is verified against its own relying party.
	 *
	 * @return void
	 */
	public function testEnrollVerifiesAnExtensionAgainstItsRelyingParty(): void {
		$verifier = $this->createMock(originalClassName: WebAuthnVerifier::class);
		$verifier->expects($this->once())->method('verifyEnrolment')
			->with('alice', 'abcdefghijklmnop')
			->willReturn(new VerifiedPasskey('c', 'PK', 'aaguid', 1));
		$this->mapper->method('insert')->willReturnArgument(0);

		$credential = $this->serviceWith(pending: ['challenge' => 'C1', 'assertionChallenge' => 'C2'], verifier: $verifier)->enroll(
			uid: 'alice',
			dto: self::enrolment(credentialId: 'c', extra: ['clientKind' => 'extension', 'rpId' => 'abcdefghijklmnop']),
		);

		$this->assertSame('abcdefghijklmnop', $credential->getRpId());
	}//end testEnrollVerifiesAnExtensionAgainstItsRelyingParty()

	/**
	 * The challenge pair is spent by a request that fails its field checks,
	 * so a refused attempt cannot leave it open for a retry.
	 *
	 * @return void
	 */
	public function testEnrollSpendsTheChallengeOnARefusedRequest(): void {
		$verifier = $this->createMock(originalClassName: WebAuthnVerifier::class);
		$service = $this->serviceWith(pending: ['challenge' => 'C1', 'assertionChallenge' => 'C2'], verifier: $verifier);

		$this->expectException(InvalidArgumentException::class);
		$service->enroll(uid: 'alice', dto: ['credentialId' => 'c']);
	}//end testEnrollSpendsTheChallengeOnARefusedRequest()

	/**
	 * The enrolment challenge pair is issued through the store and returned
	 * base64url-encoded.
	 *
	 * @return void
	 */
	public function testEnrolmentChallengeIssuesThroughTheStore(): void {
		$challenges = $this->createMock(originalClassName: PasskeyChallengeStore::class);
		$challenges->expects($this->once())->method('issue')->with('alice')
			->willReturn(['challenge' => "\xfb\xff", 'assertionChallenge' => "\x00\x01"]);
		$service = $this->service(challenges: $challenges, verifier: $this->verifier);

		$this->assertSame(['challenge' => '-_8', 'assertionChallenge' => 'AAE'], $service->enrolmentChallenge(uid: 'alice'));
	}//end testEnrolmentChallengeIssuesThroughTheStore()

	/**
	 * A web credential is always verified against the server host, never a
	 * relying party the client names.
	 *
	 * @return void
	 */
	public function testEnrollBindsAWebCredentialToTheServerHost(): void {
		$verifier = $this->createMock(originalClassName: WebAuthnVerifier::class);
		$verifier->expects($this->once())->method('verifyEnrolment')
			->with('alice', 'cloud.example.com')
			->willReturn(new VerifiedPasskey('c', 'PK', 'aaguid', 1));
		$this->mapper->method('insert')->willReturnArgument(0);

		$this->serviceWith(pending: ['challenge' => 'C1', 'assertionChallenge' => 'C2'], verifier: $verifier)->enroll(
			uid: 'alice',
			dto: self::enrolment(credentialId: 'c', extra: ['rpId' => 'evil.test']),
		);
	}//end testEnrollBindsAWebCredentialToTheServerHost()
}//end class
