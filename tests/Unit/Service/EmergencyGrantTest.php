<?php

/**
 * EmergencyGrant and SecretDeletion: the emergency contact row a grant
 * writes, and the audit event a secret deletion records.
 *
 * @category Test
 * @package  OCA\Keepiq\Tests\Unit\Service
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @link https://conduction.nl
 */

declare(strict_types=1);

namespace OCA\Keepiq\Tests\Unit\Service;

use DateTime;
use OCA\Keepiq\Db\EmergencyContact;
use OCA\Keepiq\Db\EmergencyContactMapper;
use OCA\Keepiq\Service\EmergencyDesignation;
use OCA\Keepiq\Service\EmergencyGrant;
use OCA\Keepiq\Service\SecretDeletion;
use OCP\AppFramework\Db\DoesNotExistException;
use PHPUnit\Framework\TestCase;

/**
 * The emergency grant and the secret deletion.
 */
class EmergencyGrantTest extends TestCase {
	/**
	 * A grant from anne to bob, made at a fixed moment.
	 *
	 * @return EmergencyGrant
	 */
	private function grant(): EmergencyGrant {
		return new EmergencyGrant(
			designation: new EmergencyDesignation(
				grantorUserId: 'anne',
				granteeUserId: 'bob',
				waitPeriodDays: 7,
				accessLevel: 'takeover',
				recoveryEnvelope: 'envelope-b64'
			),
			granteeSuiteId: 'suite-bob',
			grantorSuiteId: 'suite-anne',
			now: new DateTime('2026-11-14T12:00:00+00:00')
		);
	}//end grant()

	/**
	 * Assert a contact carries the grant: view access, granted, the envelope and both suites.
	 *
	 * @param EmergencyContact $contact The contact.
	 *
	 * @return void
	 */
	private function assertGranted(EmergencyContact $contact): void {
		$this->assertSame(expected: EmergencyContact::ACCESS_VIEW, actual: $contact->getAccessLevel());
		$this->assertSame(expected: 7, actual: $contact->getWaitPeriodDays());
		$this->assertSame(expected: EmergencyContact::STATE_GRANTED, actual: $contact->getState());
		$this->assertNull(actual: $contact->getRequestedAt());
		$this->assertSame(expected: 'envelope-b64', actual: $contact->getRecoveryEnvelope());
		$this->assertSame(expected: 'suite-anne', actual: $contact->getGrantorSuiteId());
		$this->assertSame(expected: 'suite-bob', actual: $contact->getGranteeSuiteId());
		$this->assertNull(actual: $contact->getInvalidatedReason());
		$this->assertSame(expected: '2026-11-14T12:00:00+00:00', actual: $contact->getUpdatedAt()->format('c'));
	}//end assertGranted()

	/**
	 * A first grant inserts a new contact between the two users.
	 *
	 * @return void
	 */
	public function testAFirstGrantInsertsAContact(): void {
		$mapper = $this->createMock(originalClassName: EmergencyContactMapper::class);
		$mapper->method('findByGrantorAndGrantee')->with('anne', 'bob')->willThrowException(new DoesNotExistException('none'));
		$mapper->expects($this->never())->method('update');
		$mapper->expects($this->once())->method('insert')->willReturnArgument(0);
		$contact = $this->grant()->save(mapper: $mapper);
		$this->assertMatchesRegularExpression(pattern: '/^[0-9a-f-]{36}$/', string: (string)$contact->getId());
		$this->assertSame(expected: ['anne', 'bob'], actual: [$contact->getGrantorUserId(), $contact->getGranteeUserId()]);
		$this->assertSame(expected: '2026-11-14T12:00:00+00:00', actual: $contact->getCreatedAt()->format('c'));
		$this->assertGranted(contact: $contact);
	}//end testAFirstGrantInsertsAContact()

	/**
	 * A grant over an earlier contact resets it: a pending request and an invalidation are cleared.
	 *
	 * @return void
	 */
	public function testAGrantResetsAnEarlierContact(): void {
		$earlier = new EmergencyContact();
		$earlier->setId('c1');
		$earlier->setState('requested');
		$earlier->setRequestedAt(new DateTime('2026-11-01'));
		$earlier->setInvalidatedReason('suite_rotated');
		$earlier->setAccessLevel('takeover');
		$mapper = $this->createMock(originalClassName: EmergencyContactMapper::class);
		$mapper->method('findByGrantorAndGrantee')->willReturn($earlier);
		$mapper->expects($this->never())->method('insert');
		$mapper->expects($this->once())->method('update')->with($earlier)->willReturnArgument(0);
		$contact = $this->grant()->save(mapper: $mapper);
		$this->assertSame(expected: 'c1', actual: $contact->getId());
		$this->assertGranted(contact: $contact);
	}//end testAGrantResetsAnEarlierContact()

	/**
	 * A plain deletion and a purge record different audit events.
	 *
	 * @return void
	 */
	public function testADeletionAndAPurgeAreAuditedApart(): void {
		$deleted = new SecretDeletion(userId: 'anne', purgeReason: null);
		$this->assertSame(expected: 'secret.deleted', actual: $deleted->eventType());
		$this->assertSame(expected: [], actual: $deleted->metadata());
		$this->assertFalse(condition: $deleted->byRetention());

		$purged = new SecretDeletion(userId: 'anne', purgeReason: 'trash_emptied');
		$this->assertSame(expected: 'secret.purged', actual: $purged->eventType());
		$this->assertSame(expected: ['reason' => 'trash_emptied'], actual: $purged->metadata());
		$this->assertFalse(condition: $purged->byRetention());

		$this->assertTrue(condition: (new SecretDeletion(userId: 'anne', purgeReason: 'retention'))->byRetention());
	}//end testADeletionAndAPurgeAreAuditedApart()
}//end class
