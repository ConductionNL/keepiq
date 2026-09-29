<?php

/**
 * Unit tests for EmergencyContact entity.
 *
 * @category Test
 * @package  OCA\Keepiq\Tests\Unit\Db
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

namespace OCA\Keepiq\Tests\Unit\Db;

use OCA\Keepiq\Db\EmergencyContact;
use PHPUnit\Framework\TestCase;

/**
 * Tests for EmergencyContact entity.
 */
class EmergencyContactTest extends TestCase {
	/**
	 * An invalidated contact with a reason and an envelope.
	 *
	 * @return EmergencyContact
	 */
	private function contact(): EmergencyContact {
		$contact = new EmergencyContact();
		$contact->setId('rel-1');
		$contact->setGrantorUserId('alice');
		$contact->setGranteeUserId('bob');
		$contact->setState(EmergencyContact::STATE_INVALIDATED);
		$contact->setInvalidatedReason('grantor_rotation_not_carried');
		$contact->setRecoveryEnvelope('ENVELOPE');
		return $contact;
	}//end contact()

	/**
	 * The grantor's view carries why the contact was invalidated, so the
	 * Emergency Access view can offer Re-establish only where it is safe.
	 *
	 * @return void
	 */
	public function testGrantorSerializationCarriesTheInvalidationReason(): void {
		$data = $this->contact()->jsonSerializeForGrantor();

		$this->assertSame('grantor_rotation_not_carried', $data['invalidatedReason']);
		$this->assertArrayNotHasKey('recoveryEnvelope', $data);
	}//end testGrantorSerializationCarriesTheInvalidationReason()

	/**
	 * The shared serialization (used for the grantee's incoming list) does not:
	 * whether the grantor chose not to carry a contact is the grantor's to know.
	 *
	 * @return void
	 */
	public function testSharedSerializationOmitsTheInvalidationReason(): void {
		$data = $this->contact()->jsonSerialize();

		$this->assertArrayNotHasKey('invalidatedReason', $data);
		$this->assertArrayNotHasKey('recoveryEnvelope', $data);
	}//end testSharedSerializationOmitsTheInvalidationReason()
}//end class
