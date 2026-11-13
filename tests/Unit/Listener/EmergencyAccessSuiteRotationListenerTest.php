<?php

/**
 * Unit tests for EmergencyAccessSuiteRotationListener: sweeps contacts left on the rotated-away suite.
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
use OCA\Keepiq\Event\SuiteMigrationAbortedEvent;
use OCA\Keepiq\Event\SuiteMigrationCompletedEvent;
use OCA\Keepiq\Event\SuiteMigrationEvent;
use OCA\Keepiq\Listener\EmergencyAccessSuiteRotationListener;
use OCA\Keepiq\Service\EmergencyAccessAuditTrail;
use OCA\Keepiq\Service\EmergencyEnvelopeInvalidationService;
use OCP\EventDispatcher\Event;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\UsesClass;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use RuntimeException;

/**
 * Tests for EmergencyAccessSuiteRotationListener.
 */
#[CoversClass(EmergencyAccessSuiteRotationListener::class)]
#[UsesClass(EmergencyEnvelopeInvalidationService::class)]
#[UsesClass(EmergencyContact::class)]
#[UsesClass(SuiteMigrationEvent::class)]
#[UsesClass(SuiteMigrationCompletedEvent::class)]
#[UsesClass(SuiteMigrationAbortedEvent::class)]
class EmergencyAccessSuiteRotationListenerTest extends TestCase {
	/**
	 * Build the listener over the real service with mocked storage.
	 *
	 * @param EmergencyContactMapper $mapper The contact mapper
	 * @param LoggerInterface $logger The listener logger
	 *
	 * @return EmergencyAccessSuiteRotationListener
	 */
	private function listener(EmergencyContactMapper $mapper, LoggerInterface $logger): EmergencyAccessSuiteRotationListener {
		$service = new EmergencyEnvelopeInvalidationService(
			$mapper,
			$this->createMock(EncryptionSuiteMapper::class),
			$this->createMock(EmergencyAccessAuditTrail::class)
		);

		return new EmergencyAccessSuiteRotationListener($service, $logger);
	}//end listener()

	/**
	 * The sweep runs against the OLD suite, the one the envelopes were sealed to;
	 * an already-invalidated contact is left alone.
	 *
	 * @return void
	 */
	public function testSweepsContactsOfTheOldSuite(): void {
		$done = new EmergencyContact();
		$done->setState(EmergencyContact::STATE_INVALIDATED);

		$mapper = $this->createMock(EmergencyContactMapper::class);
		$mapper->expects($this->once())->method('findByGrantorSuite')->with('old-suite')->willReturn([$done]);
		$mapper->expects($this->never())->method('update');

		$logger = $this->createMock(LoggerInterface::class);
		$logger->expects($this->never())->method('error');

		$this->listener($mapper, $logger)->handle(new SuiteMigrationCompletedEvent('old-suite', 'new-suite', 'mig-1'));
	}//end testSweepsContactsOfTheOldSuite()

	/**
	 * A failing sweep is logged, never thrown into the completed migration.
	 *
	 * @return void
	 */
	public function testSweepFailureIsLoggedNotThrown(): void {
		$mapper = $this->createMock(EmergencyContactMapper::class);
		$mapper->method('findByGrantorSuite')->willThrowException(new RuntimeException('db down'));

		$logger = $this->createMock(LoggerInterface::class);
		$logger->expects($this->once())->method('error')->with('Keepiq: emergency-access rotation invalidation failed: db down');

		$this->listener($mapper, $logger)->handle(new SuiteMigrationCompletedEvent('old-suite', 'new-suite', 'mig-1'));
	}//end testSweepFailureIsLoggedNotThrown()

	/**
	 * An aborted migration keeps the old suite, so nothing is swept.
	 *
	 * @return void
	 */
	public function testIgnoresOtherEvents(): void {
		$mapper = $this->createMock(EmergencyContactMapper::class);
		$mapper->expects($this->never())->method('findByGrantorSuite');
		$logger = $this->createMock(LoggerInterface::class);
		$logger->expects($this->never())->method('error');

		$listener = $this->listener($mapper, $logger);
		$listener->handle(new SuiteMigrationAbortedEvent('old', 'new', 'mig-1'));
		$listener->handle(new Event());
	}//end testIgnoresOtherEvents()
}//end class
