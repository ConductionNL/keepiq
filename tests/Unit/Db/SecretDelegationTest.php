<?php

/**
 * Unit tests for SecretDelegation entity.
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
use OCA\Doriath\Db\SecretDelegation;
use PHPUnit\Framework\TestCase;

/**
 * Tests for SecretDelegation entity.
 */
class SecretDelegationTest extends TestCase
{
    /**
     * Default field values after construction.
     *
     * @return void
     */
    public function testConstructorSetsDefaults(): void
    {
        $entity = new SecretDelegation();

        $this->assertSame('', $entity->getId());
        $this->assertSame('', $entity->getSecretId());
        $this->assertSame('', $entity->getOriginalOwnerId());
        $this->assertSame('', $entity->getDelegatedTo());
        $this->assertNull($entity->getDelegatedAt());
        $this->assertSame('', $entity->getInitiatedBy());
        $this->assertFalse($entity->getIsPermanent());
        $this->assertNull($entity->getMadePermanentAt());
    }

    /**
     * Getters and setters round-trip.
     *
     * @return void
     */
    public function testGettersAndSetters(): void
    {
        $entity = new SecretDelegation();
        $now    = new DateTime('2026-06-12T10:00:00+00:00');

        $entity->setId('sd-1');
        $entity->setSecretId('s-1');
        $entity->setOriginalOwnerId('alice');
        $entity->setDelegatedTo('bob');
        $entity->setDelegatedAt($now);
        $entity->setInitiatedBy('alice');
        $entity->setIsPermanent(true);
        $entity->setMadePermanentAt($now);

        $this->assertSame('sd-1', $entity->getId());
        $this->assertSame('s-1', $entity->getSecretId());
        $this->assertSame('alice', $entity->getOriginalOwnerId());
        $this->assertSame('bob', $entity->getDelegatedTo());
        $this->assertSame($now, $entity->getDelegatedAt());
        $this->assertSame('alice', $entity->getInitiatedBy());
        $this->assertTrue($entity->getIsPermanent());
        $this->assertSame($now, $entity->getMadePermanentAt());
    }

    /**
     * jsonSerialize emits the expected shape.
     *
     * @return void
     */
    public function testJsonSerializeShape(): void
    {
        $entity = new SecretDelegation();
        $entity->setId('sd-1');
        $entity->setSecretId('s-1');
        $entity->setOriginalOwnerId('alice');
        $entity->setDelegatedTo('bob');
        $entity->setDelegatedAt(new DateTime('2026-06-12T10:00:00+00:00'));
        $entity->setInitiatedBy('alice');
        $entity->setIsPermanent(false);

        $payload = $entity->jsonSerialize();

        $this->assertSame('sd-1', $payload['id']);
        $this->assertSame('s-1', $payload['secretId']);
        $this->assertSame('alice', $payload['originalOwnerId']);
        $this->assertSame('bob', $payload['delegatedTo']);
        $this->assertSame('alice', $payload['initiatedBy']);
        $this->assertFalse($payload['isPermanent']);
        $this->assertNull($payload['madePermanentAt']);
        $this->assertStringContainsString('2026-06-12', (string) $payload['delegatedAt']);
    }
}
