<?php

/**
 * Unit tests for the SeedDevelopmentApplications repair step.
 *
 * Covers:
 *  - debug=false → no-op (no Application insert);
 *  - debug=true + empty mapper → 3 Application inserts with the spec'd
 *    type / status / approval shape;
 *  - debug=true + existing rows → idempotency no-op (no insert);
 *  - active apps carry approvedBy / approvedAt; pending app does not.
 *
 * @category Test
 * @package  OCA\Doriath\Tests\Unit\Repair
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

namespace OCA\Doriath\Tests\Unit\Repair;

use OCA\Doriath\Db\Application;
use OCA\Doriath\Db\ApplicationMapper;
use OCA\Doriath\Repair\SeedDevelopmentApplications;
use OCP\AppFramework\Db\DoesNotExistException;
use OCP\IConfig;
use OCP\Migration\IOutput;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

/**
 * Tests for SeedDevelopmentApplications.
 */
class SeedDevelopmentApplicationsTest extends TestCase
{
    /**
     * debug=false → no insert.
     *
     * @return void
     */
    public function testNoOpWhenDebugDisabled(): void
    {
        $config = $this->createMock(IConfig::class);
        $config->method('getSystemValueBool')->with('debug', false)->willReturn(false);

        $mapper = $this->createMock(ApplicationMapper::class);
        $mapper->expects($this->never())->method('insert');
        $mapper->expects($this->never())->method('findById');

        $step = new SeedDevelopmentApplications(
            appMapper: $mapper,
            config: $config,
            logger: $this->createMock(LoggerInterface::class),
        );

        $step->run($this->createMock(IOutput::class));
    }//end testNoOpWhenDebugDisabled()

    /**
     * Happy path: 3 applications inserted on a fresh dev vault, with the
     * documented status + approval shape (active rows carry approvedBy,
     * the pending row does not).
     *
     * @return void
     */
    public function testSeedsThreeApplicationsOnFreshRun(): void
    {
        $config = $this->createMock(IConfig::class);
        $config->method('getSystemValueBool')->willReturn(true);

        $mapper = $this->createMock(ApplicationMapper::class);
        $mapper->method('findById')->willThrowException(new DoesNotExistException('none'));

        /** @var list<Application> $inserted */
        $inserted = [];
        $mapper->expects($this->exactly(3))
            ->method('insert')
            ->willReturnCallback(function (Application $application) use (&$inserted): Application {
                $inserted[] = $application;
                return $application;
            });

        $step = new SeedDevelopmentApplications(
            appMapper: $mapper,
            config: $config,
            logger: $this->createMock(LoggerInterface::class),
        );

        $step->run($this->createMock(IOutput::class));

        $this->assertCount(3, $inserted);

        $byName = [];
        foreach ($inserted as $row) {
            $byName[$row->getName()] = $row;
        }

        $this->assertArrayHasKey('OpenConnector Dev', $byName);
        $this->assertSame(Application::TYPE_INTERNAL, $byName['OpenConnector Dev']->getType());
        $this->assertSame(Application::STATUS_ACTIVE, $byName['OpenConnector Dev']->getStatus());
        $this->assertSame('admin', $byName['OpenConnector Dev']->getApprovedBy());
        $this->assertNotNull($byName['OpenConnector Dev']->getApprovedAt());

        $this->assertArrayHasKey('CI Pipeline Bot', $byName);
        $this->assertSame(Application::TYPE_EXTERNAL, $byName['CI Pipeline Bot']->getType());
        $this->assertSame(Application::STATUS_PENDING, $byName['CI Pipeline Bot']->getStatus());
        $this->assertSame('', (string) $byName['CI Pipeline Bot']->getApprovedBy());
        $this->assertNull($byName['CI Pipeline Bot']->getApprovedAt());

        $this->assertArrayHasKey('Monitoring Agent', $byName);
        $this->assertSame(Application::TYPE_EXTERNAL, $byName['Monitoring Agent']->getType());
        $this->assertSame(Application::STATUS_ACTIVE, $byName['Monitoring Agent']->getStatus());

        // Every row records the dev admin as registrant.
        foreach ($inserted as $row) {
            $this->assertSame('admin', $row->getRegisteredBy());
            $this->assertNotNull($row->getCreatedAt());
            $this->assertNotSame('', $row->getId());
        }

        // IDs are deterministic UUIDv5 values keyed off the name.
        $ids = array_map(fn (Application $a): string => $a->getId(), $inserted);
        $this->assertCount(3, array_unique($ids));
        foreach ($ids as $id) {
            $this->assertMatchesRegularExpression(
                '/^[0-9a-f]{8}-[0-9a-f]{4}-5[0-9a-f]{3}-[0-9a-f]{4}-[0-9a-f]{12}$/',
                $id,
            );
        }
    }//end testSeedsThreeApplicationsOnFreshRun()

    /**
     * Idempotent on re-run: every application already exists → no inserts.
     *
     * @return void
     */
    public function testIdempotentOnReRun(): void
    {
        $config = $this->createMock(IConfig::class);
        $config->method('getSystemValueBool')->willReturn(true);

        $existing = new Application();
        $existing->setId('some-id');

        $mapper = $this->createMock(ApplicationMapper::class);
        $mapper->method('findById')->willReturn($existing);
        $mapper->expects($this->never())->method('insert');

        $step = new SeedDevelopmentApplications(
            appMapper: $mapper,
            config: $config,
            logger: $this->createMock(LoggerInterface::class),
        );

        $step->run($this->createMock(IOutput::class));
    }//end testIdempotentOnReRun()

    /**
     * Mixed state: one application already exists, the other two are new.
     * Only the two new rows are inserted.
     *
     * @return void
     */
    public function testInsertsOnlyMissingRows(): void
    {
        $config = $this->createMock(IConfig::class);
        $config->method('getSystemValueBool')->willReturn(true);

        $mapper = $this->createMock(ApplicationMapper::class);

        // Mark the first row (OpenConnector Dev) as already present and the
        // other two as missing. The repair iterates in declared order so the
        // first findById() call corresponds to OpenConnector Dev.
        $existing = new Application();
        $existing->setId('preexisting');
        $mapper->method('findById')->willReturnOnConsecutiveCalls(
            $existing,
            $this->throwException(new DoesNotExistException('none')),
            $this->throwException(new DoesNotExistException('none')),
        );

        $mapper->expects($this->exactly(2))->method('insert');

        $step = new SeedDevelopmentApplications(
            appMapper: $mapper,
            config: $config,
            logger: $this->createMock(LoggerInterface::class),
        );

        $step->run($this->createMock(IOutput::class));
    }//end testInsertsOnlyMissingRows()
}//end class
