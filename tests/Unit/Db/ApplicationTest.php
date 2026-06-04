<?php

/**
 * Unit tests for Application entity.
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
use OCA\Doriath\Db\Application;
use PHPUnit\Framework\TestCase;

/**
 * Tests for Application entity.
 */
class ApplicationTest extends TestCase
{
    /**
     * The constructor sets sensible defaults.
     *
     * @return void
     */
    public function testConstructorSetsDefaults(): void
    {
        $entity = new Application();

        $this->assertSame('', $entity->getName());
        $this->assertNull($entity->getDescription());
        $this->assertSame('external', $entity->getType());
        $this->assertSame('pending', $entity->getStatus());
        $this->assertNull($entity->getCsr());
        $this->assertNull($entity->getRegisteredBy());
        $this->assertNull($entity->getApprovedBy());
        $this->assertNull($entity->getCreatedAt());
        $this->assertNull($entity->getApprovedAt());
    }

    /**
     * Getters and setters round-trip every field.
     *
     * @return void
     */
    public function testGettersAndSetters(): void
    {
        $entity  = new Application();
        $created = new DateTime('2026-01-01T00:00:00+00:00');

        $entity->setId('app-1');
        $entity->setName('My App');
        $entity->setDescription('purpose');
        $entity->setType('internal');
        $entity->setStatus('active');
        $entity->setCsr('csr-blob');
        $entity->setRegisteredBy('alice');
        $entity->setApprovedBy('admin');
        $entity->setCreatedAt($created);
        $entity->setApprovedAt($created);

        $this->assertSame('app-1', $entity->getId());
        $this->assertSame('My App', $entity->getName());
        $this->assertSame('purpose', $entity->getDescription());
        $this->assertSame('internal', $entity->getType());
        $this->assertSame('active', $entity->getStatus());
        $this->assertSame('csr-blob', $entity->getCsr());
        $this->assertSame('alice', $entity->getRegisteredBy());
        $this->assertSame('admin', $entity->getApprovedBy());
        $this->assertSame($created, $entity->getCreatedAt());
        $this->assertSame($created, $entity->getApprovedAt());
    }

    /**
     * jsonSerialize never leaks the temporary CSR.
     *
     * @return void
     */
    public function testJsonSerializeOmitsCsr(): void
    {
        $entity = new Application();
        $entity->setId('app-1');
        $entity->setName('My App');
        $entity->setCsr('SECRET-CSR');

        $json = $entity->jsonSerialize();

        $this->assertArrayNotHasKey('csr', $json);
        $this->assertSame('app-1', $json['id']);
        $this->assertSame('My App', $json['name']);
    }
}
