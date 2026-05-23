<?php

/**
 * Unit tests for CACertificate entity.
 *
 * @category Test
 * @package  OCA\Doriath\Tests\Unit\Db
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

namespace OCA\Doriath\Tests\Unit\Db;

use DateTime;
use OCA\Doriath\Db\CACertificate;
use PHPUnit\Framework\TestCase;

/**
 * Tests for CACertificate entity.
 */
class CACertificateTest extends TestCase
{
    /**
     * Test that the constructor sets type mappings.
     *
     * @return void
     */
    public function testConstructorSetsTypeMappings(): void
    {
        $entity = new CACertificate();

        // Verify default values.
        $this->assertSame('', $entity->getType());
        $this->assertSame('', $entity->getCertificate());
        $this->assertNull($entity->getPrivateKey());
        $this->assertNull($entity->getCreatedAt());
        $this->assertNull($entity->getExpiresAt());
        $this->assertFalse($entity->getIsActive());
        $this->assertNull($entity->getRevokedAt());
        $this->assertNull($entity->getSuccessorId());
    }

    /**
     * Test getters and setters for all fields.
     *
     * @return void
     */
    public function testGettersAndSetters(): void
    {
        $entity = new CACertificate();

        $entity->setId('test-uuid-123');
        $this->assertSame('test-uuid-123', $entity->getId());

        $entity->setType('root');
        $this->assertSame('root', $entity->getType());

        $entity->setCertificate('-----BEGIN CERTIFICATE-----');
        $this->assertSame('-----BEGIN CERTIFICATE-----', $entity->getCertificate());

        $entity->setPrivateKey('encrypted-key-data');
        $this->assertSame('encrypted-key-data', $entity->getPrivateKey());

        $createdAt = new DateTime('2025-01-01');
        $entity->setCreatedAt($createdAt);
        $this->assertSame($createdAt, $entity->getCreatedAt());

        $expiresAt = new DateTime('2045-01-01');
        $entity->setExpiresAt($expiresAt);
        $this->assertSame($expiresAt, $entity->getExpiresAt());

        $entity->setIsActive(true);
        $this->assertTrue($entity->getIsActive());

        $revokedAt = new DateTime('2025-06-01');
        $entity->setRevokedAt($revokedAt);
        $this->assertSame($revokedAt, $entity->getRevokedAt());

        $entity->setSuccessorId('successor-uuid-456');
        $this->assertSame('successor-uuid-456', $entity->getSuccessorId());
    }

    /**
     * Test jsonSerialize returns all fields correctly.
     *
     * @return void
     */
    public function testJsonSerializeReturnsAllFields(): void
    {
        $entity = new CACertificate();
        $entity->setId('cert-id-001');
        $entity->setType('intermediate');
        $entity->setCertificate('-----BEGIN CERTIFICATE-----\nABC\n-----END CERTIFICATE-----');
        $entity->setPrivateKey('encrypted-private-key');
        $entity->setCreatedAt(new DateTime('2025-03-15T10:00:00+00:00'));
        $entity->setExpiresAt(new DateTime('2028-03-15T10:00:00+00:00'));
        $entity->setIsActive(true);
        $entity->setRevokedAt(null);
        $entity->setSuccessorId('next-cert-id');

        $serialized = $entity->jsonSerialize();

        $this->assertSame('cert-id-001', $serialized['id']);
        $this->assertSame('intermediate', $serialized['type']);
        $this->assertStringContainsString('BEGIN CERTIFICATE', $serialized['certificate']);
        $this->assertTrue($serialized['isActive']);
        $this->assertNull($serialized['revokedAt']);
        $this->assertSame('next-cert-id', $serialized['successorId']);
        // Note: privateKey is intentionally excluded from jsonSerialize.
        $this->assertArrayNotHasKey('privateKey', $serialized);
    }

    /**
     * Test DateTime fields format correctly in jsonSerialize.
     *
     * @return void
     */
    public function testJsonSerializeDateTimeFormat(): void
    {
        $entity = new CACertificate();
        $entity->setId('cert-id-002');

        $created = new DateTime('2025-06-15T14:30:00+02:00');
        $entity->setCreatedAt($created);

        $expires = new DateTime('2045-06-15T14:30:00+02:00');
        $entity->setExpiresAt($expires);

        $revoked = new DateTime('2026-01-01T00:00:00+00:00');
        $entity->setRevokedAt($revoked);

        $serialized = $entity->jsonSerialize();

        // format('c') produces ISO 8601 date.
        $this->assertSame($created->format('c'), $serialized['createdAt']);
        $this->assertSame($expires->format('c'), $serialized['expiresAt']);
        $this->assertSame($revoked->format('c'), $serialized['revokedAt']);
    }

    /**
     * Test jsonSerialize with null DateTime fields.
     *
     * @return void
     */
    public function testJsonSerializeWithNullDateTimes(): void
    {
        $entity = new CACertificate();
        $entity->setId('cert-id-003');

        $serialized = $entity->jsonSerialize();

        $this->assertNull($serialized['createdAt']);
        $this->assertNull($serialized['expiresAt']);
        $this->assertNull($serialized['revokedAt']);
    }
}
