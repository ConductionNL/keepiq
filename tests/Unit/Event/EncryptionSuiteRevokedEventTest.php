<?php

/**
 * Unit tests for EncryptionSuiteRevokedEvent: the payload the revocation listeners read.
 *
 * @category Test
 * @package  OCA\Keepiq\Tests\Unit\Event
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

namespace OCA\Keepiq\Tests\Unit\Event;

use OCA\Keepiq\Event\EncryptionSuiteRevokedEvent;
use OCP\EventDispatcher\Event;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

/**
 * Tests for the suite-revoked event.
 */
#[CoversClass(EncryptionSuiteRevokedEvent::class)]
class EncryptionSuiteRevokedEventTest extends TestCase {
	/**
	 * Each accessor returns the constructor argument in its own position.
	 *
	 * @return void
	 */
	public function testAccessorsReturnTheConstructorArguments(): void {
		$event = new EncryptionSuiteRevokedEvent('suite-1', 'application', 'app-7', 'admin', true);

		$this->assertInstanceOf(Event::class, $event);
		$this->assertSame('suite-1', $event->getSuiteId());
		$this->assertSame('application', $event->getOwnerType());
		$this->assertSame('app-7', $event->getOwnerId());
		$this->assertSame('admin', $event->getRevokedBy());
		$this->assertTrue($event->getCompromised());
	}//end testAccessorsReturnTheConstructorArguments()

	/**
	 * A revocation without the flag is not treated as a compromise.
	 *
	 * @return void
	 */
	public function testRevocationDefaultsToNotCompromised(): void {
		$event = new EncryptionSuiteRevokedEvent(suiteId: 's', ownerType: 'user', ownerId: 'bob', revokedBy: 'bob');

		$this->assertFalse($event->getCompromised());
		$this->assertSame('user', $event->getOwnerType());
		$this->assertSame('bob', $event->getOwnerId());
		$this->assertSame('bob', $event->getRevokedBy());
	}//end testRevocationDefaultsToNotCompromised()

	/**
	 * Owner and revoker stay distinct when an administrator revokes a user's suite.
	 *
	 * @return void
	 */
	public function testOwnerAndRevokerAreNotConflated(): void {
		$event = new EncryptionSuiteRevokedEvent(suiteId: 's-2', ownerType: 'user', ownerId: 'alice', revokedBy: 'admin', compromised: false);

		$this->assertSame(['s-2', 'alice', 'admin'], [$event->getSuiteId(), $event->getOwnerId(), $event->getRevokedBy()]);
		$this->assertFalse($event->getCompromised());
	}//end testOwnerAndRevokerAreNotConflated()
}//end class
