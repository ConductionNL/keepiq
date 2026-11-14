<?php

/**
 * TerminationNotices: what a completed, aborted or already-ended suite
 * migration logs, answers and tells its listeners.
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

use OCA\Keepiq\Db\SuiteMigration;
use OCA\Keepiq\Event\SuiteMigrationAbortedEvent;
use OCA\Keepiq\Event\SuiteMigrationCompletedEvent;
use OCA\Keepiq\Service\TerminationNotices;
use OCA\Keepiq\Service\TerminationRun;
use OCP\EventDispatcher\IEventDispatcher;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

/**
 * The termination notices.
 */
class TerminationNoticesTest extends TestCase {
	/**
	 * A migration from suite old-1 to new-2.
	 *
	 * @param string $status The migration's status.
	 *
	 * @return SuiteMigration
	 */
	private function migration(string $status='in_progress'): SuiteMigration {
		$migration = new SuiteMigration();
		$migration->setId('migr-1');
		$migration->setOldSuiteId('old-1');
		$migration->setNewSuiteId('new-2');
		$migration->setStatus($status);
		return $migration;
	}//end migration()

	/**
	 * A completed migration announces the two suites and whether it had errors.
	 *
	 * @return void
	 */
	public function testACompletedMigrationIsAnnounced(): void {
		$dispatched = [];
		$dispatcher = $this->createMock(originalClassName: IEventDispatcher::class);
		$dispatcher->method('dispatchTyped')->willReturnCallback(
			static function (object $event) use (&$dispatched): void {
				$dispatched[] = $event;
			}
		);
		$logger = $this->createMock(originalClassName: LoggerInterface::class);
		$logger->expects($this->once())->method('info')->with('Keepiq: Compromise recovery completed for migration migr-1', ['hasErrors' => true]);
		$notices = new TerminationNotices(logger: $logger, eventDispatcher: $dispatcher);
		$notices->completed(run: new TerminationRun(migration: $this->migration(), migrationId: 'migr-1', ownerId: 'anne'), hasErrors: true);
		$this->assertCount(expectedCount: 1, haystack: $dispatched);
		$this->assertInstanceOf(expected: SuiteMigrationCompletedEvent::class, actual: $dispatched[0]);
		$event = $dispatched[0];
		$this->assertSame(
			expected: ['old-1', 'new-2', 'migr-1', true],
			actual: [$event->getOldSuiteId(), $event->getNewSuiteId(), $event->getMigrationId(), $event->hasErrors()]
		);
	}//end testACompletedMigrationIsAnnounced()

	/**
	 * An aborted migration announces its suites and logs the suite the vault returned to.
	 *
	 * @return void
	 */
	public function testAnAbortedMigrationIsAnnounced(): void {
		$dispatched = [];
		$dispatcher = $this->createMock(originalClassName: IEventDispatcher::class);
		$dispatcher->method('dispatchTyped')->willReturnCallback(
			static function (object $event) use (&$dispatched): void {
				$dispatched[] = $event;
			}
		);
		$logger = $this->createMock(originalClassName: LoggerInterface::class);
		$logger->expects($this->once())->method('info')->with(
			'Keepiq: Compromise recovery aborted for migration migr-1; vault returned to the old suite',
			['oldSuiteId' => 'old-1']
		);
		$notices = new TerminationNotices(logger: $logger, eventDispatcher: $dispatcher);
		$notices->aborted(run: new TerminationRun(migration: $this->migration(), migrationId: 'migr-1', ownerId: null));
		$this->assertCount(expectedCount: 1, haystack: $dispatched);
		$this->assertInstanceOf(expected: SuiteMigrationAbortedEvent::class, actual: $dispatched[0]);
		$this->assertSame(expected: 'old-1', actual: $dispatched[0]->getOldSuiteId());
	}//end testAnAbortedMigrationIsAnnounced()

	/**
	 * Without an event dispatcher the outcome is still logged.
	 *
	 * @return void
	 */
	public function testWithoutADispatcherOnlyTheLogIsWritten(): void {
		$logger = $this->createMock(originalClassName: LoggerInterface::class);
		$logger->expects($this->exactly(count: 2))->method('info');
		$notices = new TerminationNotices(logger: $logger, eventDispatcher: null);
		$run     = new TerminationRun(migration: $this->migration(), migrationId: 'migr-1', ownerId: 'anne');
		$notices->completed(run: $run, hasErrors: false);
		$notices->aborted(run: $run);
	}//end testWithoutADispatcherOnlyTheLogIsWritten()

	/**
	 * Completing an ended migration is ignored, logged and answered with its status.
	 *
	 * @return void
	 */
	public function testCompletingAnEndedMigrationIsIgnored(): void {
		$logger = $this->createMock(originalClassName: LoggerInterface::class);
		$logger->expects($this->once())->method('info')->with(
			'Keepiq: completeMigration called on an already-terminated migration; ignoring',
			['migrationId' => 'migr-1', 'status' => 'completed']
		);
		$dispatcher = $this->createMock(originalClassName: IEventDispatcher::class);
		$dispatcher->expects($this->never())->method('dispatchTyped');
		$notices = new TerminationNotices(logger: $logger, eventDispatcher: $dispatcher);
		$answer  = $notices->ignoredCompletion(migration: $this->migration(status: 'completed'), migrationId: 'migr-1');
		$this->assertSame(
			expected: ['status' => 'completed', 'droppedVersions' => 0, 'unrecoverable' => [], 'alreadyCompleted' => true],
			actual: $answer
		);
	}//end testCompletingAnEndedMigrationIsIgnored()

	/**
	 * Aborting an ended migration is ignored, logged and answered as not aborted.
	 *
	 * @return void
	 */
	public function testAbortingAnEndedMigrationIsIgnored(): void {
		$logger = $this->createMock(originalClassName: LoggerInterface::class);
		$logger->expects($this->once())->method('info')->with(
			'Keepiq: abortMigration called on an already-terminated migration; ignoring',
			['migrationId' => 'migr-1', 'status' => 'aborted']
		);
		$notices = new TerminationNotices(logger: $logger, eventDispatcher: null);
		$answer  = $notices->ignoredAbort(migration: $this->migration(status: 'aborted'), migrationId: 'migr-1');
		$this->assertSame(expected: ['status' => 'aborted', 'aborted' => false, 'alreadyTerminated' => true], actual: $answer);
	}//end testAbortingAnEndedMigrationIsIgnored()
}//end class
