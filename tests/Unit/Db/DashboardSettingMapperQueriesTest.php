<?php

/**
 * DashboardSettingMapper against a recording connection.
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

use OCA\Keepiq\Db\DashboardSetting;
use OCA\Keepiq\Db\DashboardSettingMapper;
use OCA\Keepiq\Tests\Support\RecordingDatabase;
use OCP\AppFramework\Db\DoesNotExistException;
use PHPUnit\Framework\TestCase;

/**
 * The SQL each DashboardSettingMapper query builds: lookup by id, the one setting of a user
 * by key, all of a user's settings, and the per-user cascade.
 *
 * @covers \OCA\Keepiq\Db\DashboardSettingMapper
 * @covers \OCA\Keepiq\Db\FindsByStringId
 * @uses   \OCA\Keepiq\Db\DashboardSetting
 * @uses   \OCA\Keepiq\Db\StringPrimaryKey
 */
class DashboardSettingMapperQueriesTest extends TestCase {
	use RecordingDatabase;

	/**
	 * The mapper over the recording connection.
	 *
	 * @return DashboardSettingMapper
	 */
	private function mapper(): DashboardSettingMapper {
		return new DashboardSettingMapper(db: $this->recordingConnection());
	}//end mapper()

	/**
	 * Lookup by id hydrates the row; no row throws.
	 *
	 * @return void
	 */
	public function testFindById(): void {
		$this->queueRows(rows: [['id' => 'r1']]);
		$mapper = $this->mapper();

		$this->assertSame(expected: 'r1', actual: $mapper->findById(id: 'r1')->getId());
		$this->assertSame(expected: ['select(*)', 'from(keepiq_dashboard_settings)', 'where(eq(id, :p0))'], actual: $this->opsOf(index: 0));
		$this->assertSame(expected: [':p0' => 'r1'], actual: $this->paramsOf(index: 0));

		$this->expectException(exception: DoesNotExistException::class);
		$mapper->findById(id: 'gone');
	}//end testFindById()

	/**
	 * One user's setting is matched on both user and key; no row throws.
	 *
	 * @return void
	 */
	public function testFindByUserAndKey(): void {
		$this->queueRows(rows: [['id' => 'r1']]);
		$mapper = $this->mapper();

		$this->assertSame(expected: 'r1', actual: $mapper->findByUserAndKey(userId: 'alice', settingKey: 'layout')->getId());
		$this->assertSame(
			expected: [
				'select(*)', 'from(keepiq_dashboard_settings)', 'where(eq(user_id, :p0))',
				'andWhere(eq(setting_key, :p1))'
			],
			actual: $this->opsOf(index: 0)
		);
		$this->assertSame(expected: [':p0' => 'alice', ':p1' => 'layout'], actual: $this->paramsOf(index: 0));

		$this->expectException(exception: DoesNotExistException::class);
		$mapper->findByUserAndKey(userId: 'alice', settingKey: 'missing');
	}//end testFindByUserAndKey()

	/**
	 * Every setting of one user.
	 *
	 * @return void
	 */
	public function testFindByUser(): void {
		$this->queueRows(rows: [['id' => 'r1'], ['id' => 'r2']]);

		$rows = $this->mapper()->findByUser(userId: 'alice');

		$this->assertSame(expected: ['r1', 'r2'], actual: array_map(static fn (DashboardSetting $e): string => $e->getId(), $rows));
		$this->assertSame(
			expected: [
				'select(*)', 'from(keepiq_dashboard_settings)', 'where(eq(user_id, :p0))'
			],
			actual: $this->opsOf(index: 0)
		);
		$this->assertSame(expected: [':p0' => 'alice'], actual: $this->paramsOf(index: 0));
		$this->assertSame(expected: 'query', actual: $this->ranOf(index: 0));
	}//end testFindByUser()

	/**
	 * The cascade deletes every setting of the user.
	 *
	 * @return void
	 */
	public function testDeleteByUser(): void {
		$this->mapper()->deleteByUser(userId: 'alice');

		$this->assertSame(
			expected: [
				'delete(keepiq_dashboard_settings)', 'where(eq(user_id, :p0))'
			],
			actual: $this->opsOf(index: 0)
		);
		$this->assertSame(expected: [':p0' => 'alice'], actual: $this->paramsOf(index: 0));
		$this->assertSame(expected: 'statement', actual: $this->ranOf(index: 0));
	}//end testDeleteByUser()
}//end class
