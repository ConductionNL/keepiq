<?php

/**
 * Unit tests for EncryptionSuite entity.
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

use DateTime;
use OCA\Keepiq\Db\EncryptionSuite;
use PHPUnit\Framework\TestCase;

/**
 * Tests for EncryptionSuite entity.
 */
class EncryptionSuiteTest extends TestCase {
	/**
	 * Test that the constructor sets type mappings and defaults.
	 *
	 * @return void
	 */
	public function testConstructorSetsDefaults(): void {
		$entity = new EncryptionSuite();

		$this->assertSame('', $entity->getOwnerType());
		$this->assertSame('', $entity->getOwnerId());
		$this->assertNull($entity->getCertificate());
		$this->assertNull($entity->getPrivateKey());
		$this->assertSame('active', $entity->getStatus());
		$this->assertNull($entity->getRevokedAt());
		$this->assertNull($entity->getRevokedReason());
		$this->assertNull($entity->getRevokedBy());
		$this->assertNull($entity->getReinstatedAt());
		$this->assertNull($entity->getReinstatedBy());
		$this->assertNull($entity->getCreatedAt());
	}//end testConstructorSetsDefaults()

	/**
	 * Test getters and setters for all fields.
	 *
	 * @return void
	 */
	public function testGettersAndSetters(): void {
		$entity = new EncryptionSuite();

		$entity->setId('suite-uuid-1');
		$this->assertSame('suite-uuid-1', $entity->getId());

		$entity->setOwnerType('user');
		$this->assertSame('user', $entity->getOwnerType());

		$entity->setOwnerId('alice');
		$this->assertSame('alice', $entity->getOwnerId());

		$entity->setCertificate('cert-pem-data');
		$this->assertSame('cert-pem-data', $entity->getCertificate());

		$entity->setPrivateKey('encrypted-pk');
		$this->assertSame('encrypted-pk', $entity->getPrivateKey());

		$entity->setStatus('revoked');
		$this->assertSame('revoked', $entity->getStatus());

		$revokedAt = new DateTime('2025-05-01');
		$entity->setRevokedAt($revokedAt);
		$this->assertSame($revokedAt, $entity->getRevokedAt());

		$entity->setRevokedReason('key leaked');
		$this->assertSame('key leaked', $entity->getRevokedReason());

		$entity->setRevokedBy('admin');
		$this->assertSame('admin', $entity->getRevokedBy());

		$reinstatedAt = new DateTime('2025-06-01');
		$entity->setReinstatedAt($reinstatedAt);
		$this->assertSame($reinstatedAt, $entity->getReinstatedAt());

		$entity->setReinstatedBy('superadmin');
		$this->assertSame('superadmin', $entity->getReinstatedBy());

		$createdAt = new DateTime('2025-01-01');
		$entity->setCreatedAt($createdAt);
		$this->assertSame($createdAt, $entity->getCreatedAt());
	}//end testGettersAndSetters()

	/**
	 * Test jsonSerialize returns all fields correctly.
	 *
	 * @return void
	 */
	public function testJsonSerializeReturnsAllFields(): void {
		$entity = new EncryptionSuite();
		$entity->setId('suite-001');
		$entity->setOwnerType('user');
		$entity->setOwnerId('bob');
		$entity->setCertificate('cert-data');
		$entity->setPrivateKey('pk-data');
		$entity->setStatus('active');
		$entity->setCreatedAt(new DateTime('2025-03-01T12:00:00+00:00'));

		$serialized = $entity->jsonSerialize();

		$this->assertSame('suite-001', $serialized['id']);
		$this->assertSame('user', $serialized['ownerType']);
		$this->assertSame('bob', $serialized['ownerId']);
		$this->assertSame('cert-data', $serialized['certificate']);
		$this->assertSame('pk-data', $serialized['privateKey']);
		$this->assertSame('active', $serialized['status']);
		$this->assertNull($serialized['revokedAt']);
		$this->assertNull($serialized['revokedReason']);
		$this->assertNull($serialized['revokedBy']);
		$this->assertNull($serialized['reinstatedAt']);
		$this->assertNull($serialized['reinstatedBy']);
		$this->assertNotNull($serialized['createdAt']);
	}//end testJsonSerializeReturnsAllFields()

	/**
	 * Test jsonSerialize with revocation and reinstatement fields populated.
	 *
	 * @return void
	 */
	public function testJsonSerializeWithRevocationAndReinstatement(): void {
		$entity = new EncryptionSuite();
		$entity->setId('suite-002');
		$entity->setStatus('active');
		$entity->setCreatedAt(new DateTime('2025-01-01T00:00:00+00:00'));

		$revokedAt = new DateTime('2025-04-01T09:00:00+00:00');
		$entity->setRevokedAt($revokedAt);
		$entity->setRevokedReason('compromised');
		$entity->setRevokedBy('admin');

		$reinstatedAt = new DateTime('2025-04-02T10:00:00+00:00');
		$entity->setReinstatedAt($reinstatedAt);
		$entity->setReinstatedBy('superadmin');

		$serialized = $entity->jsonSerialize();

		$this->assertSame($revokedAt->format('c'), $serialized['revokedAt']);
		$this->assertSame('compromised', $serialized['revokedReason']);
		$this->assertSame('admin', $serialized['revokedBy']);
		$this->assertSame($reinstatedAt->format('c'), $serialized['reinstatedAt']);
		$this->assertSame('superadmin', $serialized['reinstatedBy']);
	}//end testJsonSerializeWithRevocationAndReinstatement()

	/**
	 * Test DateTime fields format correctly in jsonSerialize.
	 *
	 * @return void
	 */
	public function testJsonSerializeDateTimeFormat(): void {
		$entity = new EncryptionSuite();
		$entity->setId('suite-003');

		$created = new DateTime('2025-06-15T14:30:00+02:00');
		$entity->setCreatedAt($created);

		$serialized = $entity->jsonSerialize();

		$this->assertSame($created->format('c'), $serialized['createdAt']);
	}//end testJsonSerializeDateTimeFormat()
}//end class
