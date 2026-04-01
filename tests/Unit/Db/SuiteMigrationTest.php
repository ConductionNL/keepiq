<?php

/**
 * Unit tests for SuiteMigration entity.
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
use OCA\Doriath\Db\SuiteMigration;
use PHPUnit\Framework\TestCase;

/**
 * Tests for SuiteMigration entity.
 */
class SuiteMigrationTest extends TestCase
{
    /**
     * Test that the constructor sets type mappings and defaults.
     *
     * @return void
     */
    public function testConstructorSetsDefaults(): void
    {
        $entity = new SuiteMigration();

        $this->assertSame('', $entity->getOldSuiteId());
        $this->assertSame('', $entity->getNewSuiteId());
        $this->assertSame('in_progress', $entity->getStatus());
        $this->assertNull($entity->getStartedAt());
        $this->assertNull($entity->getCompletedAt());
    }

    /**
     * Test getters and setters for all fields.
     *
     * @return void
     */
    public function testGettersAndSetters(): void
    {
        $entity = new SuiteMigration();

        $entity->setId('migr-uuid-1');
        $this->assertSame('migr-uuid-1', $entity->getId());

        $entity->setOldSuiteId('old-suite-id');
        $this->assertSame('old-suite-id', $entity->getOldSuiteId());

        $entity->setNewSuiteId('new-suite-id');
        $this->assertSame('new-suite-id', $entity->getNewSuiteId());

        $entity->setStatus('completed');
        $this->assertSame('completed', $entity->getStatus());

        $startedAt = new DateTime('2025-05-01T10:00:00+00:00');
        $entity->setStartedAt($startedAt);
        $this->assertSame($startedAt, $entity->getStartedAt());

        $completedAt = new DateTime('2025-05-01T12:00:00+00:00');
        $entity->setCompletedAt($completedAt);
        $this->assertSame($completedAt, $entity->getCompletedAt());
    }

    /**
     * Test jsonSerialize returns all fields correctly.
     *
     * @return void
     */
    public function testJsonSerializeReturnsAllFields(): void
    {
        $entity = new SuiteMigration();
        $entity->setId('migr-001');
        $entity->setOldSuiteId('old-suite');
        $entity->setNewSuiteId('new-suite');
        $entity->setStatus('in_progress');
        $entity->setStartedAt(new DateTime('2025-03-01T12:00:00+00:00'));

        $serialized = $entity->jsonSerialize();

        $this->assertSame('migr-001', $serialized['id']);
        $this->assertSame('old-suite', $serialized['oldSuiteId']);
        $this->assertSame('new-suite', $serialized['newSuiteId']);
        $this->assertSame('in_progress', $serialized['status']);
        $this->assertNotNull($serialized['startedAt']);
        $this->assertNull($serialized['completedAt']);
    }

    /**
     * Test jsonSerialize with completed migration.
     *
     * @return void
     */
    public function testJsonSerializeCompletedMigration(): void
    {
        $entity = new SuiteMigration();
        $entity->setId('migr-002');
        $entity->setOldSuiteId('old');
        $entity->setNewSuiteId('new');
        $entity->setStatus('completed');

        $startedAt = new DateTime('2025-04-01T09:00:00+00:00');
        $completedAt = new DateTime('2025-04-01T11:00:00+00:00');
        $entity->setStartedAt($startedAt);
        $entity->setCompletedAt($completedAt);

        $serialized = $entity->jsonSerialize();

        $this->assertSame($startedAt->format('c'), $serialized['startedAt']);
        $this->assertSame($completedAt->format('c'), $serialized['completedAt']);
        $this->assertSame('completed', $serialized['status']);
    }

    /**
     * Test jsonSerialize with null DateTime fields.
     *
     * @return void
     */
    public function testJsonSerializeWithNullDateTimes(): void
    {
        $entity = new SuiteMigration();
        $entity->setId('migr-003');

        $serialized = $entity->jsonSerialize();

        $this->assertNull($serialized['startedAt']);
        $this->assertNull($serialized['completedAt']);
    }
}
