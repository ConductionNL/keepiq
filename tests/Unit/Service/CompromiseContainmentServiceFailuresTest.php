<?php

/**
 * Unit tests for the failure paths of CompromiseContainmentService.
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

use OCA\Keepiq\Db\EmergencyContactMapper;
use OCA\Keepiq\Db\EncryptionSuite;
use OCA\Keepiq\Db\SecretMapper;
use OCA\Keepiq\Db\ShareTargetMapper;
use OCA\Keepiq\Service\CompromiseBlastRadius;
use OCA\Keepiq\Service\CompromiseContainmentService;
use OCA\Keepiq\Service\MigrationService;
use OCA\Keepiq\Service\NotificationService;
use OCA\Keepiq\Tests\Support\ShareRowJudgeFixture;
use OCP\Authentication\Token\IProvider;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use RuntimeException;

/**
 * A failing lookup, notice or revoke is counted and logged; containment carries on.
 */
class CompromiseContainmentServiceFailuresTest extends TestCase {
	use ShareRowJudgeFixture;

	/** @var SecretMapper&MockObject */
	private SecretMapper $secretMapper;

	/** @var EmergencyContactMapper&MockObject */
	private EmergencyContactMapper $contactMapper;

	/** @var NotificationService&MockObject */
	private NotificationService $notifications;

	/** @var MigrationService&MockObject */
	private MigrationService $migration;

	/** @var LoggerInterface&MockObject */
	private LoggerInterface $logger;

	/**
	 * Fresh doubles.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		parent::setUp();
		$this->secretMapper = $this->createMock(SecretMapper::class);
		$this->contactMapper = $this->createMock(EmergencyContactMapper::class);
		$this->notifications = $this->createMock(NotificationService::class);
		$this->migration = $this->createMock(MigrationService::class);
		$this->logger = $this->createMock(LoggerInterface::class);
	}//end setUp()

	/**
	 * The service under test.
	 *
	 * @return CompromiseContainmentService
	 */
	private function service(): CompromiseContainmentService {
		return new CompromiseContainmentService(
			secretMapper: $this->secretMapper,
			rowJudge: $this->shareRowJudge(targets: $this->createMock(ShareTargetMapper::class), secrets: $this->secretMapper),
			contactMapper: $this->contactMapper,
			notificationService: $this->notifications,
			migrationService: $this->migration,
			tokenProvider: $this->createMock(IProvider::class),
			logger: $this->logger,
		);
	}//end service()

	/**
	 * A suite of the given owner type.
	 *
	 * @param string $ownerType user or group
	 *
	 * @return EncryptionSuite
	 */
	private function suite(string $ownerType = 'user'): EncryptionSuite {
		$suite = new EncryptionSuite();
		$suite->setId('suite-a');
		$suite->setOwnerType($ownerType);
		$suite->setOwnerId('alice');
		return $suite;
	}//end suite()

	/**
	 * Unlistable secrets and emergency grants each count as one failure, logged as errors.
	 *
	 * @return void
	 */
	public function testUnlistableSecretsAndGrantsAreCountedAsFailures(): void {
		$this->secretMapper->method('findByEncryptionSuiteId')->willThrowException(new RuntimeException('db down'));
		$this->contactMapper->method('findByGranteeSuite')->willThrowException(new RuntimeException('db down'));
		$messages = [];
		$this->logger->expects($this->exactly(2))->method('error')->willReturnCallback(
			function (string $message) use (&$messages): void {
				$messages[] = $message;
			}
		);

		$radius = $this->service()->collect(suiteIds: ['suite-a']);

		$this->assertSame(2, $radius->getFailures());
		$this->assertSame([], $radius->getSealed());
		$this->assertSame([], $radius->getGrantors());
		$this->assertSame(
			[
				'Keepiq: could not list the secrets sealed under suite suite-a: db down',
				'Keepiq: could not list the emergency grants of suite suite-a: db down',
			],
			$messages
		);
	}//end testUnlistableSecretsAndGrantsAreCountedAsFailures()

	/**
	 * A grantor listed twice is warned once; a notice that throws is a failure, the next grantor is still warned.
	 *
	 * @return void
	 */
	public function testAFailedGrantorNoticeIsCountedAndTheRestAreStillWarned(): void {
		$radius = new CompromiseBlastRadius();
		$radius->addGrantor(grantorId: 'carol', granteeId: 'alice');
		$radius->addGrantor(grantorId: 'carol', granteeId: 'alice');
		$radius->addGrantor(grantorId: 'dave', granteeId: 'alice');
		$warned = [];
		$this->notifications->method('notify')->willReturnCallback(
			function (string $subject, string $recipientId) use (&$warned): bool {
				$warned[] = [$subject, $recipientId];
				if ($recipientId === 'carol') {
					throw new RuntimeException('mail down');
				}

				return true;
			}
		);
		$this->logger->expects($this->once())->method('warning')->with('Keepiq: could not warn grantor carol: mail down');

		$tally = $this->service()->contain(radius: $radius, suite: $this->suite(ownerType: 'group'), revokedBy: 'admin');

		$this->assertSame(
			[['emergency_grantee_compromised', 'carol'], ['emergency_grantee_compromised', 'dave']],
			$warned
		);
		$this->assertSame(['stamped' => 0, 'notified' => 1, 'failed' => 1], $tally);
	}//end testAFailedGrantorNoticeIsCountedAndTheRestAreStillWarned()

	/**
	 * A user suite whose key material cannot be revoked leaves containment incomplete.
	 *
	 * @return void
	 */
	public function testAFailedAccountRevokeMakesContainmentIncomplete(): void {
		$this->migration->expects($this->once())
			->method('revokeKeyMaterialOfOwner')
			->with('alice')
			->willThrowException(new RuntimeException('token store down'));
		$errors = [];
		$this->logger->expects($this->exactly(2))->method('error')->willReturnCallback(
			function (string $message, array $context) use (&$errors): void {
				$errors[] = [$message, $context];
			}
		);

		$tally = $this->service()->contain(radius: new CompromiseBlastRadius(), suite: $this->suite(), revokedBy: 'admin');

		$this->assertSame(['stamped' => 0, 'notified' => 0, 'failed' => 1], $tally);
		$this->assertSame('Keepiq: could not revoke the link shares and passkeys of alice: token store down', $errors[0][0]);
		$this->assertSame('Keepiq: compromise containment for suite suite-a is incomplete', $errors[1][0]);
		$this->assertSame(['app' => 'keepiq', 'stamped' => 0, 'failed' => 1], $errors[1][1]);
	}//end testAFailedAccountRevokeMakesContainmentIncomplete()

	/**
	 * No notice for nothing cleared or for a non-user suite; a notice that throws reads as not sent.
	 *
	 * @return void
	 */
	public function testEmergencyAccessClearedNotice(): void {
		$this->notifications->expects($this->once())
			->method('notify')
			->with('emergency_access_cleared', 'alice', ['count' => 2], 'suite', 'suite-a')
			->willThrowException(new RuntimeException('mail down'));
		$this->logger->expects($this->once())
			->method('warning')
			->with('Keepiq: could not tell alice their emergency access was cleared: mail down', ['app' => 'keepiq']);

		$service = $this->service();
		$this->assertFalse($service->notifyEmergencyAccessCleared(suite: $this->suite(), count: 0));
		$this->assertFalse($service->notifyEmergencyAccessCleared(suite: $this->suite(ownerType: 'group'), count: 2));
		$this->assertFalse($service->notifyEmergencyAccessCleared(suite: $this->suite(), count: 2));
	}//end testEmergencyAccessClearedNotice()
}//end class
