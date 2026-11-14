<?php

/**
 * Unit tests for EmergencyAccessSuiteRevocationListener: clears grantor and grantee envelopes of a revoked user suite.
 *
 * @category Test
 * @package  OCA\Keepiq\Tests\Unit\Listener
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

namespace OCA\Keepiq\Tests\Unit\Listener;

use OCA\Keepiq\Db\EmergencyContact;
use OCA\Keepiq\Db\EmergencyContactMapper;
use OCA\Keepiq\Db\EncryptionSuiteMapper;
use OCA\Keepiq\Event\EncryptionSuiteRevokedEvent;
use OCA\Keepiq\Event\SuiteMigrationCompletedEvent;
use OCA\Keepiq\Event\SuiteMigrationEvent;
use OCA\Keepiq\Listener\EmergencyAccessSuiteRevocationListener;
use OCA\Keepiq\Service\EmergencyAccessAuditTrail;
use OCA\Keepiq\Service\EmergencyEnvelopeInvalidationService;
use OCP\EventDispatcher\Event;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\UsesClass;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use RuntimeException;

/**
 * Tests for EmergencyAccessSuiteRevocationListener.
 */
#[CoversClass(EmergencyAccessSuiteRevocationListener::class)]
#[UsesClass(EmergencyEnvelopeInvalidationService::class)]
#[UsesClass(EmergencyContact::class)]
#[UsesClass(EncryptionSuiteRevokedEvent::class)]
#[UsesClass(SuiteMigrationEvent::class)]
#[UsesClass(SuiteMigrationCompletedEvent::class)]
class EmergencyAccessSuiteRevocationListenerTest extends TestCase {
	/**
	 * Build the listener over the real service with mocked storage.
	 *
	 * @param EmergencyContactMapper    $mapper The contact mapper
	 * @param EmergencyAccessAuditTrail $trail  The audit trail
	 * @param LoggerInterface           $logger The listener logger
	 *
	 * @return EmergencyAccessSuiteRevocationListener
	 */
	private function listener(EmergencyContactMapper $mapper, EmergencyAccessAuditTrail $trail, LoggerInterface $logger): EmergencyAccessSuiteRevocationListener {
		$service = new EmergencyEnvelopeInvalidationService($mapper, $this->createMock(EncryptionSuiteMapper::class), $trail);

		return new EmergencyAccessSuiteRevocationListener($service, $logger);
	}//end listener()

	/**
	 * A revoked user suite deletes the contacts it granted (audited) and then
	 * checks the contacts it was a grantee of.
	 *
	 * @return void
	 */
	public function testClearsGrantorContactsThenChecksGranteeContacts(): void {
		$granted = new EmergencyContact();
		$granted->setId(7);
		$granted->setGrantorUserId('alice');
		$granted->setGranteeUserId('bob');

		$mapper = $this->createMock(EmergencyContactMapper::class);
		$mapper->expects($this->once())->method('findByGrantorSuite')->with('suite-1')->willReturn([$granted]);
		$mapper->expects($this->once())->method('delete')->with($granted);
		$mapper->expects($this->once())->method('findByGranteeSuite')->with('suite-1')->willReturn([]);

		$trail = $this->createMock(EmergencyAccessAuditTrail::class);
		$trail->expects($this->once())->method('recordInvalidated')->with('alice', 'bob', 7, 'grantor_revocation');

		$logger = $this->createMock(LoggerInterface::class);
		$logger->expects($this->never())->method('error');

		$this->listener($mapper, $trail, $logger)->handle(new EncryptionSuiteRevokedEvent('suite-1', 'user', 'alice', 'admin'));
	}//end testClearsGrantorContactsThenChecksGranteeContacts()

	/**
	 * Application suites carry no emergency access, so nothing is touched.
	 *
	 * @return void
	 */
	public function testSkipsApplicationSuitesAndOtherEvents(): void {
		$mapper = $this->createMock(EmergencyContactMapper::class);
		$mapper->expects($this->never())->method('findByGrantorSuite');
		$mapper->expects($this->never())->method('findByGranteeSuite');

		$listener = $this->listener($mapper, $this->createMock(EmergencyAccessAuditTrail::class), $this->createMock(LoggerInterface::class));
		$listener->handle(new EncryptionSuiteRevokedEvent('suite-1', 'application', 'app-1', 'admin'));
		$listener->handle(new SuiteMigrationCompletedEvent('suite-1', 'suite-2', 'mig-1'));
		$listener->handle(new Event());
	}//end testSkipsApplicationSuitesAndOtherEvents()

	/**
	 * A failing grantor cleanup is logged, and the grantee pass is not reached.
	 *
	 * @return void
	 */
	public function testCleanupFailureIsLoggedNotThrown(): void {
		$mapper = $this->createMock(EmergencyContactMapper::class);
		$mapper->method('findByGrantorSuite')->willThrowException(new RuntimeException('db down'));
		$mapper->expects($this->never())->method('findByGranteeSuite');

		$logger = $this->createMock(LoggerInterface::class);
		$logger->expects($this->once())->method('error')->with('Keepiq: emergency-access revocation cleanup failed: db down');

		$this->listener($mapper, $this->createMock(EmergencyAccessAuditTrail::class), $logger)
			->handle(new EncryptionSuiteRevokedEvent('suite-1', 'user', 'alice', 'admin'));
	}//end testCleanupFailureIsLoggedNotThrown()
}//end class
