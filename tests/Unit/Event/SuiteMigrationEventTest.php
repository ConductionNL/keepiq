<?php

/**
 * Unit tests for the suite-migration lifecycle events.
 *
 * Pins what each event carries: the old suite, the new suite and the migration,
 * and for completion whether it ended with errors.
 *
 * @category Test
 * @package  OCA\Keepiq\Tests\Unit\Event
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

namespace OCA\Keepiq\Tests\Unit\Event;

use OCA\Keepiq\Event\SuiteMigrationAbortedEvent;
use OCA\Keepiq\Event\SuiteMigrationCompletedEvent;
use OCA\Keepiq\Event\SuiteMigrationStartedEvent;
use OCP\EventDispatcher\Event;
use PHPUnit\Framework\TestCase;

/**
 * Tests for the started, completed and aborted migration events.
 */
class SuiteMigrationEventTest extends TestCase {
	/**
	 * Each event hands back the suites and the migration it was built with.
	 *
	 * @return void
	 */
	public function testEveryEventCarriesTheSuitesAndTheMigration(): void {
		$events = [
			new SuiteMigrationStartedEvent(oldSuiteId: 'old-1', newSuiteId: 'new-1', migrationId: 'mig-1'),
			new SuiteMigrationCompletedEvent(oldSuiteId: 'old-1', newSuiteId: 'new-1', migrationId: 'mig-1'),
			new SuiteMigrationAbortedEvent(oldSuiteId: 'old-1', newSuiteId: 'new-1', migrationId: 'mig-1'),
		];

		foreach ($events as $event) {
			$this->assertInstanceOf(Event::class, $event);
			$this->assertSame('old-1', $event->getOldSuiteId());
			$this->assertSame('new-1', $event->getNewSuiteId());
			$this->assertSame('mig-1', $event->getMigrationId());
		}
	}//end testEveryEventCarriesTheSuitesAndTheMigration()

	/**
	 * The positional order is old suite, new suite, migration.
	 *
	 * @return void
	 */
	public function testPositionalArgumentsKeepTheirOrder(): void {
		$started = new SuiteMigrationStartedEvent('a', 'b', 'c');
		$aborted = new SuiteMigrationAbortedEvent('a', 'b', 'c');
		$completed = new SuiteMigrationCompletedEvent('a', 'b', 'c', true);

		foreach ([$started, $aborted, $completed] as $event) {
			$this->assertSame(['a', 'b', 'c'], [$event->getOldSuiteId(), $event->getNewSuiteId(), $event->getMigrationId()]);
		}

		$this->assertTrue($completed->hasErrors());
	}//end testPositionalArgumentsKeepTheirOrder()

	/**
	 * A completion without the flag has no errors.
	 *
	 * @return void
	 */
	public function testCompletionDefaultsToNoErrors(): void {
		$this->assertFalse((new SuiteMigrationCompletedEvent(oldSuiteId: 'o', newSuiteId: 'n', migrationId: 'm'))->hasErrors());
		$this->assertTrue((new SuiteMigrationCompletedEvent(oldSuiteId: 'o', newSuiteId: 'n', migrationId: 'm', hasErrors: true))->hasErrors());
	}//end testCompletionDefaultsToNoErrors()
}//end class
