<?php

/**
 * Unit tests for CompromiseContainmentService.
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

use DateTime;
use OCA\Keepiq\Db\EmergencyContact;
use OCA\Keepiq\Db\EmergencyContactMapper;
use OCA\Keepiq\Db\EncryptionSuite;
use OCA\Keepiq\Db\RotationFlag;
use OCA\Keepiq\Db\Secret;
use OCA\Keepiq\Db\SecretMapper;
use OCA\Keepiq\Db\ShareTarget;
use OCA\Keepiq\Db\ShareTargetMapper;
use OCA\Keepiq\Event\EncryptionSuiteRevokedEvent;
use OCA\Keepiq\Listener\EncryptionSuiteRevokedListener;
use OCA\Keepiq\Service\CompromiseContainmentService;
use OCA\Keepiq\Service\DelegationService;
use OCA\Keepiq\Service\MigrationService;
use OCA\Keepiq\Service\NotificationService;
use OCA\Keepiq\Service\RotationPolicyService;
use OCP\AppFramework\Db\DoesNotExistException;
use OCP\Authentication\Token\IProvider;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use RuntimeException;

/**
 * Tests for CompromiseContainmentService.
 */
class CompromiseContainmentServiceTest extends TestCase {

	/**
	 * @var SecretMapper&MockObject
	 */
	private SecretMapper&MockObject $secretMapper;

	/**
	 * @var ShareTargetMapper&MockObject
	 */
	private ShareTargetMapper&MockObject $shareTargetMapper;

	/**
	 * @var EmergencyContactMapper&MockObject
	 */
	private EmergencyContactMapper&MockObject $emergencyContactMapper;

	/**
	 * @var NotificationService&MockObject
	 */
	private NotificationService&MockObject $notificationService;

	/**
	 * @var MigrationService&MockObject
	 */
	private MigrationService&MockObject $migrationService;

	/**
	 * @var IProvider&MockObject
	 */
	private IProvider&MockObject $tokenProvider;

	/**
	 * @var RotationPolicyService&MockObject
	 */
	private RotationPolicyService&MockObject $rotationService;

	/**
	 * Every notification sent, as [subject, recipient, params].
	 *
	 * @var list<array{0: string, 1: string, 2: array<string,mixed>}>
	 */
	private array $sent = [];

	/**
	 * Every secret id flagged for rotation.
	 *
	 * @var list<string>
	 */
	private array $flagged = [];

	/**
	 * The service under test.
	 *
	 * @var CompromiseContainmentService
	 */
	private CompromiseContainmentService $service;

	/**
	 * Set up the doubles.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		parent::setUp();

		$this->secretMapper = $this->createMock(SecretMapper::class);
		$this->shareTargetMapper = $this->createMock(ShareTargetMapper::class);
		$this->emergencyContactMapper = $this->createMock(EmergencyContactMapper::class);
		$this->notificationService = $this->createMock(NotificationService::class);
		$this->migrationService = $this->createMock(MigrationService::class);
		$this->tokenProvider = $this->createMock(IProvider::class);
		$this->rotationService = $this->createMock(RotationPolicyService::class);

		$this->sent = [];
		$this->notificationService->method('notify')->willReturnCallback(
			function (string $subject, string $recipientId, array $params = []): bool {
				$this->sent[] = [$subject, $recipientId, $params];
				return true;
			}
		);
		$this->flagged = [];
		$this->rotationService->method('flag')->willReturnCallback(
			function (string $secretId): RotationFlag {
				$this->flagged[] = $secretId;
				return new RotationFlag();
			}
		);
		$this->secretMapper->method('update')->willReturnArgument(0);
		$this->emergencyContactMapper->method('findByGranteeSuite')->willReturn([]);

		$this->service = new CompromiseContainmentService(
			secretMapper: $this->secretMapper,
			shareTargetMapper: $this->shareTargetMapper,
			contactMapper: $this->emergencyContactMapper,
			notificationService: $this->notificationService,
			migrationService: $this->migrationService,
			tokenProvider: $this->tokenProvider,
			logger: $this->createMock(LoggerInterface::class),
			rotationService: $this->rotationService,
		);
	}//end setUp()

	/**
	 * A secret entity.
	 *
	 * @param string $id      The secret id
	 * @param string $ownerId The owner
	 * @param string $suiteId The suite it is sealed under
	 *
	 * @return Secret
	 */
	private function secret(string $id, string $ownerId, string $suiteId = 'suite-a'): Secret {
		$secret = new Secret();
		$secret->setId($id);
		$secret->setOwnerType('user');
		$secret->setOwnerId($ownerId);
		$secret->setName($id . '-name');
		$secret->setEncryptionSuiteId($suiteId);
		return $secret;
	}//end secret()

	/**
	 * Alice's user suite.
	 *
	 * @param string $id The suite id
	 *
	 * @return EncryptionSuite
	 */
	private function suite(string $id = 'suite-a'): EncryptionSuite {
		$suite = new EncryptionSuite();
		$suite->setId($id);
		$suite->setOwnerType('user');
		$suite->setOwnerId('alice');
		$suite->setStatus('revoked');
		return $suite;
	}//end suite()

	/**
	 * Notifications of one subject, keyed by recipient.
	 *
	 * @param string $subject The subject
	 *
	 * @return array<string,array<string,mixed>>
	 */
	private function sentFor(string $subject): array {
		$out = [];
		foreach ($this->sent as [$sentSubject, $recipient, $params]) {
			if ($sentSubject === $subject) {
				$out[$recipient] = $params;
			}
		}

		return $out;
	}//end sentFor()

	/**
	 * A notification that throws for the first owner does not stop the second
	 * owner's secrets from being stamped, flagged and warned, and the failure is
	 * counted for the administrator (keepiq#863).
	 *
	 * @return void
	 */
	public function testANotificationFailureDoesNotStopTheCascade(): void {
		$first = $this->secret('secret-1', 'alice');
		$second = $this->secret('secret-2', 'bob');
		$this->secretMapper->method('findByEncryptionSuiteId')->willReturn([$first, $second]);
		$this->shareTargetMapper->method('findByRecipientSecret')->willThrowException(new DoesNotExistException('not shared'));
		$this->shareTargetMapper->method('findBySourceSecret')->willReturn([]);

		$notificationService = $this->createMock(NotificationService::class);
		$notified = [];
		$notificationService->method('notify')->willReturnCallback(
			static function (string $subject, string $recipientId) use (&$notified): bool {
				if ($recipientId === 'alice') {
					throw new RuntimeException('notification backend down');
				}

				$notified[] = $recipientId;
				return true;
			}
		);
		$service = new CompromiseContainmentService(
			secretMapper: $this->secretMapper,
			shareTargetMapper: $this->shareTargetMapper,
			contactMapper: $this->emergencyContactMapper,
			notificationService: $notificationService,
			migrationService: $this->migrationService,
			tokenProvider: $this->tokenProvider,
			logger: $this->createMock(LoggerInterface::class),
			rotationService: $this->rotationService,
		);

		$tally = $service->contain($service->collect(['suite-a']), $this->suite(), 'admin');

		$this->assertNotNull($second->getPossiblyCompromisedAt());
		$this->assertContains('secret-2', $this->flagged);
		$this->assertSame(['bob'], $notified);
		$this->assertSame(1, $tally['failed']);
		$this->assertSame(2, $tally['stamped']);
	}//end testANotificationFailureDoesNotStopTheCascade()

	/**
	 * A blast-radius lookup that throws is a counted failure, not a silent
	 * empty cascade (keepiq#863).
	 *
	 * @return void
	 */
	public function testAFailedLookupIsCounted(): void {
		$this->secretMapper->method('findByEncryptionSuiteId')->willThrowException(new RuntimeException('db gone'));

		$tally = $this->service->contain($this->service->collect(['suite-a']), $this->suite(), 'admin');

		$this->assertSame(1, $tally['failed']);
	}//end testAFailedLookupIsCounted()

	/**
	 * A share-target lookup that throws leaves the source owner unwarned, so
	 * it is a counted failure; the copy is still stamped (keepiq#1189). The
	 * outbound lookup would fail on the same cause, so it is not attempted:
	 * one failure, counted once.
	 *
	 * @return void
	 */
	public function testAFailedSourceLookupIsCounted(): void {
		$copy = $this->secret('copy-1', 'alice', 'suite-a');
		$this->secretMapper->method('findByEncryptionSuiteId')->willReturn([$copy]);
		$this->shareTargetMapper->method('findByRecipientSecret')->willThrowException(new RuntimeException('db gone'));
		$this->shareTargetMapper->method('findBySourceSecret')->willThrowException(new RuntimeException('db gone'));

		$tally = $this->service->contain($this->service->collect(['suite-a']), $this->suite(), 'admin');

		$this->assertSame(1, $tally['failed']);
		$this->assertNotNull($copy->getPossiblyCompromisedAt());
	}//end testAFailedSourceLookupIsCounted()

	/**
	 * A secret that is not a shared copy is no failure.
	 *
	 * @return void
	 */
	public function testAnOwnSecretIsNoFailure(): void {
		$own = $this->secret('own-1', 'alice', 'suite-a');
		$this->secretMapper->method('findByEncryptionSuiteId')->willReturn([$own]);
		$this->shareTargetMapper->method('findByRecipientSecret')->willThrowException(new DoesNotExistException('not a copy'));
		$this->shareTargetMapper->method('findBySourceSecret')->willReturn([]);

		$tally = $this->service->contain($this->service->collect(['suite-a']), $this->suite(), 'admin');

		$this->assertSame(0, $tally['failed']);
	}//end testAnOwnSecretIsNoFailure()

	/**
	 * Integration-style, with the REAL revoke listener and a share-target store
	 * that honours deletes: a compromise force-revoke during a migration A to B
	 * where alice holds a copy of carol's secret on B. Collected before the two
	 * revokes, the copy still resolves to carol's source, so carol is warned
	 * and the source is stamped (keepiq#864).
	 *
	 * @return void
	 */
	public function testACopyOnTheSecondSuiteWarnsItsSourceOwner(): void {
		$own = $this->secret('own-1', 'alice', 'suite-a');
		$copy = $this->secret('copy-1', 'alice', 'suite-b');
		$source = $this->secret('src-1', 'carol', 'suite-carol');
		$secrets = ['own-1' => $own, 'copy-1' => $copy, 'src-1' => $source];

		$row = new ShareTarget();
		$row->setSourceSecretId('src-1');
		$row->setTargetUserId('alice');
		$row->setSecretId('copy-1');
		$rows = ['copy-1' => $row];

		$this->secretMapper->method('findByEncryptionSuiteId')->willReturnCallback(
			static fn (string $suiteId): array => array_values(
				array_filter($secrets, static fn (Secret $secret): bool => $secret->getEncryptionSuiteId() === $suiteId)
			)
		);
		$this->secretMapper->method('findById')->willReturnCallback(
			static fn (string $id): Secret => $secrets[$id] ?? throw new DoesNotExistException('gone')
		);
		$this->shareTargetMapper->method('findByRecipientSecret')->willReturnCallback(
			static function (string $copyId) use (&$rows): ShareTarget {
				return $rows[$copyId] ?? throw new DoesNotExistException('swept');
			}
		);
		$this->shareTargetMapper->method('findBySourceSecret')->willReturn([]);
		// Honour the sweep the real listener runs, the way the table would.
		$this->shareTargetMapper->method('deleteByTargetUserAndSuite')->willReturnCallback(
			static function (string $userId, string $suiteId) use (&$rows, $secrets): void {
				foreach ($rows as $copyId => $shareTarget) {
					if ($shareTarget->getTargetUserId() === $userId && $secrets[$copyId]->getEncryptionSuiteId() === $suiteId) {
						unset($rows[$copyId]);
					}
				}
			}
		);
		$listener = new EncryptionSuiteRevokedListener(
			shareTargetMapper: $this->shareTargetMapper,
			delegationService: $this->createMock(DelegationService::class),
			logger: $this->createMock(LoggerInterface::class),
		);

		$radius = $this->service->collect(['suite-a', 'suite-b']);
		foreach (['suite-a', 'suite-b'] as $suiteId) {
			$listener->handle(new EncryptionSuiteRevokedEvent($suiteId, 'user', 'alice', 'admin', true));
		}

		$this->service->contain($radius, $this->suite(), 'admin');

		$owners = $this->sentFor('secret_compromised');
		$this->assertArrayHasKey('carol', $owners, 'the source owner of the copy on the second suite must be warned');
		$this->assertSame('src-1', $owners['carol']['secret_id']);
		$this->assertNotNull($source->getPossiblyCompromisedAt(), 'the source must be stamped');
		$this->assertContains('src-1', $this->flagged);
	}//end testACopyOnTheSecondSuiteWarnsItsSourceOwner()

	/**
	 * An owner with several affected secrets gets one notification that names
	 * the first and counts the others (keepiq#875).
	 *
	 * @return void
	 */
	public function testOneOwnerIsToldHowManySecretsAreAffected(): void {
		$this->secretMapper->method('findByEncryptionSuiteId')->willReturn(
			[$this->secret('secret-1', 'alice'), $this->secret('secret-2', 'alice'), $this->secret('secret-3', 'alice')]
		);
		$this->shareTargetMapper->method('findByRecipientSecret')->willThrowException(new DoesNotExistException('not shared'));
		$this->shareTargetMapper->method('findBySourceSecret')->willReturn([]);

		$this->service->contain($this->service->collect(['suite-a']), $this->suite(), 'admin');

		$this->assertCount(1, $this->sent);
		$params = $this->sentFor('secret_compromised')['alice'];
		$this->assertSame('secret-1-name', $params['secret_name']);
		$this->assertSame(2, $params['other_count']);
	}//end testOneOwnerIsToldHowManySecretsAreAffected()

	/**
	 * The grantor of an approved emergency grant to the compromised user is
	 * warned: the envelope escrows the grantor's private key (keepiq#872).
	 *
	 * @return void
	 */
	public function testTheGrantorOfAnApprovedEmergencyGrantIsWarned(): void {
		$this->secretMapper->method('findByEncryptionSuiteId')->willReturn([]);
		$approved = new EmergencyContact();
		$approved->setGrantorUserId('dave');
		$approved->setGranteeUserId('alice');
		$approved->setState(EmergencyContact::STATE_APPROVED);
		$merelyGranted = new EmergencyContact();
		$merelyGranted->setGrantorUserId('erin');
		$merelyGranted->setGranteeUserId('alice');
		$merelyGranted->setState(EmergencyContact::STATE_GRANTED);
		$emergencyContactMapper = $this->createMock(EmergencyContactMapper::class);
		$emergencyContactMapper->method('findByGranteeSuite')->with('suite-a')->willReturn([$approved, $merelyGranted]);
		$service = new CompromiseContainmentService(
			secretMapper: $this->secretMapper,
			shareTargetMapper: $this->shareTargetMapper,
			contactMapper: $emergencyContactMapper,
			notificationService: $this->notificationService,
			migrationService: $this->migrationService,
			tokenProvider: $this->tokenProvider,
			logger: $this->createMock(LoggerInterface::class),
			rotationService: $this->rotationService,
		);

		$service->contain($service->collect(['suite-a']), $this->suite(), 'admin');

		$grantors = $this->sentFor('emergency_grantee_compromised');
		$this->assertSame(['dave'], array_keys($grantors));
		$this->assertSame('alice', $grantors['dave']['granteeUserId']);
	}//end testTheGrantorOfAnApprovedEmergencyGrantIsWarned()

	/**
	 * The holders of copies of the compromised user's own secrets are warned
	 * about their copy (keepiq#872).
	 *
	 * @return void
	 */
	public function testRecipientsOfOutboundSharesAreWarned(): void {
		$own = $this->secret('own-1', 'alice');
		$copy = $this->secret('copy-1', 'bob', 'suite-bob');
		$this->secretMapper->method('findByEncryptionSuiteId')->willReturn([$own]);
		$this->secretMapper->method('findById')->with('copy-1')->willReturn($copy);
		$this->shareTargetMapper->method('findByRecipientSecret')->willThrowException(new DoesNotExistException('not shared'));
		$outbound = new ShareTarget();
		$outbound->setSourceSecretId('own-1');
		$outbound->setTargetUserId('bob');
		$outbound->setSecretId('copy-1');
		$this->shareTargetMapper->method('findBySourceSecret')->with('own-1')->willReturn([$outbound]);

		$this->service->contain($this->service->collect(['suite-a']), $this->suite(), 'admin');

		$recipients = $this->sentFor('shared_secret_compromised');
		$this->assertSame(['bob'], array_keys($recipients));
		$this->assertSame('copy-1', $recipients['bob']['secret_id']);
	}//end testRecipientsOfOutboundSharesAreWarned()

	/**
	 * The account is contained: link shares and passkeys go through the same
	 * helper the owner's recovery uses (keepiq#858), and every session and app
	 * password is ended (keepiq#860).
	 *
	 * @return void
	 */
	public function testTheAccountIsContained(): void {
		$this->secretMapper->method('findByEncryptionSuiteId')->willReturn([]);
		$this->migrationService->expects($this->once())->method('revokeKeyMaterialOfOwner')->with('alice');
		$this->tokenProvider->expects($this->once())->method('invalidateTokensOfUser')->with('alice', null);

		$tally = $this->service->contain($this->service->collect(['suite-a']), $this->suite(), 'admin');

		$this->assertSame(0, $tally['failed']);
	}//end testTheAccountIsContained()

	/**
	 * A failure to end the sessions is counted, not swallowed.
	 *
	 * @return void
	 */
	public function testAFailureToEndTheSessionsIsCounted(): void {
		$this->secretMapper->method('findByEncryptionSuiteId')->willReturn([]);
		$this->tokenProvider->method('invalidateTokensOfUser')->willThrowException(new RuntimeException('token store down'));

		$tally = $this->service->contain($this->service->collect(['suite-a']), $this->suite(), 'admin');

		$this->assertSame(1, $tally['failed']);
	}//end testAFailureToEndTheSessionsIsCounted()

	/**
	 * An application suite has no account to contain.
	 *
	 * @return void
	 */
	public function testAnApplicationSuiteHasNoAccountToContain(): void {
		$this->secretMapper->method('findByEncryptionSuiteId')->willReturn([]);
		$suite = $this->suite();
		$suite->setOwnerType('application');
		$this->migrationService->expects($this->never())->method('revokeKeyMaterialOfOwner');
		$this->tokenProvider->expects($this->never())->method('invalidateTokensOfUser');

		$this->service->contain($this->service->collect(['suite-a']), $suite, 'admin');
	}//end testAnApplicationSuiteHasNoAccountToContain()

	/**
	 * An already-stamped secret keeps its first stamp but is still flagged.
	 *
	 * @return void
	 */
	public function testAnAlreadyStampedSecretIsNotRewritten(): void {
		$stamped = $this->secret('secret-1', 'alice');
		$when = new DateTime('2026-01-01');
		$stamped->setPossiblyCompromisedAt($when);
		$this->secretMapper->method('findByEncryptionSuiteId')->willReturn([$stamped]);
		$this->shareTargetMapper->method('findByRecipientSecret')->willThrowException(new DoesNotExistException('not shared'));
		$this->shareTargetMapper->method('findBySourceSecret')->willReturn([]);
		$this->secretMapper->expects($this->never())->method('update');

		$this->service->contain($this->service->collect(['suite-a']), $this->suite(), 'admin');

		$this->assertSame($when, $stamped->getPossiblyCompromisedAt());
		$this->assertSame(['secret-1'], $this->flagged);
	}//end testAnAlreadyStampedSecretIsNotRewritten()

	/**
	 * The owner is told how many emergency contacts the revoke deleted, and
	 * nobody is told when there were none (keepiq#876).
	 *
	 * @return void
	 */
	public function testTheOwnerIsToldTheirEmergencyAccessWasCleared(): void {
		$this->assertFalse($this->service->notifyEmergencyAccessCleared($this->suite(), 0));
		$this->assertTrue($this->service->notifyEmergencyAccessCleared($this->suite(), 3));

		$this->assertSame(['alice' => ['count' => 3]], $this->sentFor('emergency_access_cleared'));
	}//end testTheOwnerIsToldTheirEmergencyAccessWasCleared()
}//end class
