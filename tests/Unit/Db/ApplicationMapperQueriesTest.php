<?php

/**
 * ApplicationMapper against a recording connection.
 *
 * @category Test
 * @package  OCA\Keepiq\Tests\Unit\Db
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git-id>
 *
 * @link https://conduction.nl
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Keepiq\Tests\Unit\Db;

use OCA\Keepiq\Db\Application;
use OCA\Keepiq\Db\ApplicationMapper;
use OCA\Keepiq\Tests\Support\RecordingDatabase;
use OCP\AppFramework\Db\DoesNotExistException;
use PHPUnit\Framework\TestCase;

/**
 * The SQL each ApplicationMapper query builds: optional status filters on
 * the listing and the count, the pending shortcut, the registrant listing and
 * the active-by-name lookup.
 *
 * @covers \OCA\Keepiq\Db\ApplicationMapper
 * @covers \OCA\Keepiq\Db\FindsByStringId
 * @uses   \OCA\Keepiq\Db\Application
 * @uses   \OCA\Keepiq\Db\StringPrimaryKey
 */
class ApplicationMapperQueriesTest extends TestCase {
	use RecordingDatabase;

	/**
	 * The mapper over the recording connection.
	 *
	 * @return ApplicationMapper
	 */
	private function mapper(): ApplicationMapper {
		return new ApplicationMapper($this->recordingConnection());
	}//end mapper()

	/**
	 * findById selects by id and hydrates.
	 *
	 * @return void
	 */
	public function testFindByIdHydratesTheRow(): void {
		$this->queueRows([['id' => 'app1', 'name' => 'Backup', 'type' => 'internal', 'status' => 'active']]);

		$app = $this->mapper()->findById(id: 'app1');

		$this->assertInstanceOf(Application::class, $app);
		$this->assertSame('Backup', $app->getName());
		$this->assertSame(Application::TYPE_INTERNAL, $app->getType());
		$this->assertSame(Application::STATUS_ACTIVE, $app->getStatus());
		$this->assertSame(['select(*)', 'from(keepiq_applications)', 'where(eq(id, :p0))'], $this->opsOf(0));
		$this->assertSame([':p0' => 'app1'], $this->paramsOf(0));
	}//end testFindByIdHydratesTheRow()

	/**
	 * A missing id throws DoesNotExistException.
	 *
	 * @return void
	 */
	public function testFindByIdThrowsWhenMissing(): void {
		$this->expectException(DoesNotExistException::class);

		$this->mapper()->findById(id: 'missing');
	}//end testFindByIdThrowsWhenMissing()

	/**
	 * Without a status the listing is unfiltered, newest first.
	 *
	 * @return void
	 */
	public function testFindAllWithoutStatusIsUnfiltered(): void {
		$this->queueRows([['id' => 'b'], ['id' => 'a']]);

		$rows = $this->mapper()->findAll();

		$this->assertSame(['b', 'a'], array_map(static fn (Application $a) => $a->getId(), $rows));
		$this->assertSame(['select(*)', 'from(keepiq_applications)', 'orderBy(created_at, DESC)'], $this->opsOf(0));
		$this->assertSame([], $this->paramsOf(0));
	}//end testFindAllWithoutStatusIsUnfiltered()

	/**
	 * A status filters the listing; findPending filters on pending.
	 *
	 * @return void
	 */
	public function testFindAllWithStatusAndFindPending(): void {
		$mapper = $this->mapper();

		$mapper->findAll(status: 'active');
		$mapper->findPending();

		$expected = ['select(*)', 'from(keepiq_applications)', 'where(eq(status, :p0))', 'orderBy(created_at, DESC)'];
		$this->assertSame($expected, $this->opsOf(0));
		$this->assertSame([':p0' => 'active'], $this->paramsOf(0));
		$this->assertSame('where(eq(status, :p1))', $this->opsOf(1)[2]);
		$this->assertSame([':p1' => Application::STATUS_PENDING], $this->paramsOf(1));
	}//end testFindAllWithStatusAndFindPending()

	/**
	 * countPending counts rows with status pending.
	 *
	 * @return void
	 */
	public function testCountPending(): void {
		$this->queueRows([['cnt' => '4']]);

		$this->assertSame(4, $this->mapper()->countPending());
		$this->assertSame(['select(count(*, cnt))', 'from(keepiq_applications)', 'where(eq(status, :p0))'], $this->opsOf(0));
		$this->assertSame([':p0' => 'pending'], $this->paramsOf(0));
		$this->assertSame('query', $this->ranOf(0));
	}//end testCountPending()

	/**
	 * countPending with no row is zero.
	 *
	 * @return void
	 */
	public function testCountPendingWithoutRowIsZero(): void {
		$this->assertSame(0, $this->mapper()->countPending());
	}//end testCountPendingWithoutRowIsZero()

	/**
	 * The registrant listing filters on registered_by, newest first.
	 *
	 * @return void
	 */
	public function testFindByRegistrant(): void {
		$this->queueRows([['id' => 'app3', 'registered_by' => 'carol']]);

		$rows = $this->mapper()->findByRegistrant(userId: 'carol');

		$this->assertSame('carol', $rows[0]->getRegisteredBy());
		$this->assertSame(
			['select(*)', 'from(keepiq_applications)', 'where(eq(registered_by, :p0))', 'orderBy(created_at, DESC)'],
			$this->opsOf(0)
		);
		$this->assertSame([':p0' => 'carol'], $this->paramsOf(0));
	}//end testFindByRegistrant()

	/**
	 * The active-by-name lookup filters on name and status active.
	 *
	 * @return void
	 */
	public function testFindActiveByName(): void {
		$this->queueRows([['id' => 'app4', 'name' => 'Sync', 'status' => 'active']]);

		$app = $this->mapper()->findActiveByName(name: 'Sync');

		$this->assertSame('app4', $app->getId());
		$this->assertSame(
			['select(*)', 'from(keepiq_applications)', 'where(eq(name, :p0))', 'andWhere(eq(status, :p1))'],
			$this->opsOf(0)
		);
		$this->assertSame([':p0' => 'Sync', ':p1' => 'active'], $this->paramsOf(0));
	}//end testFindActiveByName()

	/**
	 * No active app with that name throws DoesNotExistException.
	 *
	 * @return void
	 */
	public function testFindActiveByNameThrowsWhenMissing(): void {
		$this->expectException(DoesNotExistException::class);

		$this->mapper()->findActiveByName(name: 'Nothing');
	}//end testFindActiveByNameThrowsWhenMissing()

	/**
	 * countAll is unfiltered without a status and filtered with one.
	 *
	 * @return void
	 */
	public function testCountAll(): void {
		$this->queueRows([['cnt' => '12']]);
		$this->queueRows([['cnt' => '5']]);
		$mapper = $this->mapper();

		$this->assertSame(12, $mapper->countAll());
		$this->assertSame(5, $mapper->countAll(status: 'active'));
		$this->assertSame(0, $mapper->countAll(status: 'pending'));

		$this->assertSame(['select(count(*, cnt))', 'from(keepiq_applications)'], $this->opsOf(0));
		$this->assertSame([], $this->paramsOf(0));
		$this->assertSame(['select(count(*, cnt))', 'from(keepiq_applications)', 'where(eq(status, :p0))'], $this->opsOf(1));
		$this->assertSame([':p0' => 'active'], $this->paramsOf(1));
	}//end testCountAll()
}//end class
