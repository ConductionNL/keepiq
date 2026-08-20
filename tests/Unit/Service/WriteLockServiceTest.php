<?php

/**
 * Unit tests for WriteLockService.
 *
 * This is now the single implementation of "is this owner mid-rotation?", and
 * four services plus MigrationService delegate to it, so its behaviour is worth
 * pinning directly rather than only through its callers.
 *
 * @category Test
 * @package  OCA\Doriath\Tests\Unit\Service
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

namespace OCA\Doriath\Tests\Unit\Service;

use OCA\Doriath\Db\EncryptionSuite;
use OCA\Doriath\Db\EncryptionSuiteMapper;
use OCA\Doriath\Db\SuiteMigrationMapper;
use OCA\Doriath\Exception\WriteLockedException;
use OCA\Doriath\Service\WriteLockService;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

/**
 * Tests for WriteLockService.
 */
class WriteLockServiceTest extends TestCase {

	private WriteLockService $service;

	private SuiteMigrationMapper&MockObject $migrationMapper;

	private EncryptionSuiteMapper&MockObject $suiteMapper;

	/**
	 * Set up test fixtures.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		parent::setUp();

		$this->migrationMapper = $this->createMock(SuiteMigrationMapper::class);
		$this->suiteMapper = $this->createMock(EncryptionSuiteMapper::class);

		$this->service = new WriteLockService(
			migrationMapper: $this->migrationMapper,
			suiteMapper: $this->suiteMapper
		);
	}//end setUp()

	/**
	 * Build a suite with the given id.
	 *
	 * @param string $id The suite id
	 *
	 * @return EncryptionSuite
	 */
	private function makeSuite(string $id): EncryptionSuite {
		$suite = new EncryptionSuite();
		$suite->setId($id);

		return $suite;
	}//end makeSuite()

	/**
	 * Test an owner with no suites at all is not locked.
	 *
	 * @return void
	 */
	public function testNotLockedWithoutSuites(): void {
		$this->suiteMapper->method('findByOwner')->willReturn([]);

		$this->assertFalse($this->service->isWriteLocked('user', 'alice'));
	}//end testNotLockedWithoutSuites()

	/**
	 * Test an owner whose suites have no in-progress migration is not locked.
	 *
	 * @return void
	 */
	public function testNotLockedWhenNoMigrationInProgress(): void {
		$this->suiteMapper->method('findByOwner')->willReturn([$this->makeSuite('suite-1')]);
		$this->migrationMapper->method('hasInProgress')->willReturn(false);

		$this->assertFalse($this->service->isWriteLocked('user', 'alice'));
	}//end testNotLockedWhenNoMigrationInProgress()

	/**
	 * Test the lock is found on ANY of the owner's suites, not just the first.
	 *
	 * Mid-rotation an owner has two suites, and the migration is keyed on the
	 * OLD one. Checking only the first suite the mapper returned would miss the
	 * lock depending on ordering.
	 *
	 * @return void
	 */
	public function testLockedWhenAnySuiteHasAMigration(): void {
		$this->suiteMapper->method('findByOwner')->willReturn(
			[$this->makeSuite('new-suite'), $this->makeSuite('old-suite')]
		);
		$this->migrationMapper->method('hasInProgress')->willReturnCallback(
			static fn (string $suiteId): bool => $suiteId === 'old-suite'
		);

		$this->assertTrue($this->service->isWriteLocked('user', 'alice'));
	}//end testLockedWhenAnySuiteHasAMigration()

	/**
	 * Test assertNotWriteLocked passes silently when unlocked.
	 *
	 * @return void
	 */
	public function testAssertPassesWhenUnlocked(): void {
		$this->suiteMapper->method('findByOwner')->willReturn([]);

		$this->service->assertNotWriteLocked(ownerId: 'alice');

		// Reaching here without an exception IS the assertion.
		$this->assertFalse($this->service->isWriteLocked('user', 'alice'));
	}//end testAssertPassesWhenUnlocked()

	/**
	 * Test assertNotWriteLocked refuses with an actionable message.
	 *
	 * @return void
	 */
	public function testAssertThrowsWhenLocked(): void {
		$this->suiteMapper->method('findByOwner')->willReturn([$this->makeSuite('suite-1')]);
		$this->migrationMapper->method('hasInProgress')->willReturn(true);

		try {
			$this->service->assertNotWriteLocked(ownerId: 'alice');
			$this->fail('Expected WriteLockedException');
		} catch (WriteLockedException $e) {
			// The user needs to know what to do about it, not just that they
			// cannot write.
			$this->assertStringContainsString('migration is in progress', $e->getMessage());
			$this->assertStringContainsString('resume', $e->getMessage());
		}
	}//end testAssertThrowsWhenLocked()

	/**
	 * Test application owners are scoped separately from users.
	 *
	 * @return void
	 */
	public function testOwnerTypeIsHonoured(): void {
		$this->suiteMapper->expects($this->once())
			->method('findByOwner')
			->with('application', 'app-1')
			->willReturn([]);

		$this->assertFalse($this->service->isWriteLocked('application', 'app-1'));
	}//end testOwnerTypeIsHonoured()
}//end class
