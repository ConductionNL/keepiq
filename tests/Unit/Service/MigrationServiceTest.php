<?php

declare(strict_types=1);

namespace OCA\Doriath\Tests\Unit\Service;

use OCA\Doriath\Db\EncryptionSuite;
use OCA\Doriath\Db\EncryptionSuiteMapper;
use OCA\Doriath\Db\SuiteMigration;
use OCA\Doriath\Db\SuiteMigrationMapper;
use OCA\Doriath\Service\EncryptionSuiteService;
use OCA\Doriath\Service\LinkShareService;
use OCA\Doriath\Service\MigrationService;
use OCP\AppFramework\Db\DoesNotExistException;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

class MigrationServiceTest extends TestCase
{

    private MigrationService $service;

    private SuiteMigrationMapper $migrationMapper;

    private EncryptionSuiteMapper $suiteMapper;

    protected function setUp(): void
    {
        $this->migrationMapper = $this->createMock(SuiteMigrationMapper::class);
        $this->suiteMapper     = $this->createMock(EncryptionSuiteMapper::class);
        $logger = $this->createMock(LoggerInterface::class);

        // Named arguments: the constructor gained collaborators for the terminal
        // work moved out of EncryptionSuiteController, and positional wiring
        // would silently slot the logger into the suite-service parameter.
        $this->service = new MigrationService(
            mapper: $this->migrationMapper,
            suiteMapper: $this->suiteMapper,
            suiteService: $this->createMock(EncryptionSuiteService::class),
            linkShareService: $this->createMock(LinkShareService::class),
            logger: $logger,
        );
    }//end setUp()

    public function testInitiateCompromiseRecovery(): void
    {
        $this->migrationMapper->expects($this->once())->method('insert');

        $migration = $this->service->initiateCompromiseRecovery('old-suite', 'new-suite');

        $this->assertEquals('in_progress', $migration->getStatus());
        $this->assertEquals('old-suite', $migration->getOldSuiteId());
        $this->assertEquals('new-suite', $migration->getNewSuiteId());
    }//end testInitiateCompromiseRecovery()

    public function testCompleteMigrationSetsMigrationCompleted(): void
    {
        $migration = new SuiteMigration();
        $migration->setId('migration-1');
        $migration->setOldSuiteId('old-suite');
        $migration->setNewSuiteId('new-suite');
        $migration->setStatus('in_progress');

        $this->migrationMapper->method('findById')->willReturn($migration);
        $this->migrationMapper->expects($this->once())->method('update');

        // Old suite is already marked compromised at initiation time,
        // so completeMigration should NOT touch the suite mapper.
        $this->suiteMapper->expects($this->never())->method('update');

        $result = $this->service->completeMigration('migration-1');

        $this->assertEquals('completed', $result->getStatus());
        $this->assertNotNull($result->getCompletedAt());
    }//end testCompleteMigrationSetsMigrationCompleted()

    public function testCompleteMigrationWithErrors(): void
    {
        $migration = new SuiteMigration();
        $migration->setId('migration-1');
        $migration->setOldSuiteId('old-suite');

        $this->migrationMapper->method('findById')->willReturn($migration);

        $result = $this->service->completeMigration('migration-1', true);

        $this->assertEquals('completed_with_errors', $result->getStatus());
    }//end testCompleteMigrationWithErrors()

    public function testIsWriteLockedWhenMigrationInProgress(): void
    {
        $suite = new EncryptionSuite();
        $suite->setId('suite-1');

        $this->suiteMapper->method('findByOwner')->willReturn([$suite]);
        $this->migrationMapper->method('hasInProgress')->willReturn(true);

        $this->assertTrue($this->service->isWriteLocked('user', 'testuser'));
    }//end testIsWriteLockedWhenMigrationInProgress()

    public function testIsNotWriteLockedWhenNoMigration(): void
    {
        $this->suiteMapper->method('findByOwner')->willReturn([]);

        $this->assertFalse($this->service->isWriteLocked('user', 'testuser'));
    }//end testIsNotWriteLockedWhenNoMigration()
}//end class
