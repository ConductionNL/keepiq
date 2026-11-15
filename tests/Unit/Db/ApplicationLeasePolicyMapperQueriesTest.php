<?php

/**
 * ApplicationLeasePolicyMapper against a recording connection.
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

use OCA\Keepiq\Db\ApplicationLeasePolicy;
use OCA\Keepiq\Db\ApplicationLeasePolicyMapper;
use OCP\AppFramework\Db\DoesNotExistException;
use OCA\Keepiq\Tests\Support\RecordingDatabase;
use PHPUnit\Framework\TestCase;

/**
 * The lookup by application and the delete-then-insert upsert, including the nullable renewable flag.
 *
 * @covers \OCA\Keepiq\Db\ApplicationLeasePolicyMapper
 * @uses   \OCA\Keepiq\Db\ApplicationLeasePolicy
 * @uses   \OCA\Keepiq\Db\StringPrimaryKey
 */
class ApplicationLeasePolicyMapperQueriesTest extends TestCase {
	use RecordingDatabase;

	/**
	 * The mapper over a recording connection.
	 *
	 * @return ApplicationLeasePolicyMapper
	 */
	private function mapper(): ApplicationLeasePolicyMapper {
		return new ApplicationLeasePolicyMapper($this->recordingConnection());
	}//end mapper()

	/**
	 * The policy is read by application id.
	 *
	 * @return void
	 */
	public function testFindByApplicationHydratesThePolicy(): void {
		$this->queueRows([['id' => 'p1', 'application_id' => 'app-1', 'default_ttl_seconds' => '300']]);

		$policy = $this->mapper()->findByApplication(applicationId: 'app-1');

		$this->assertInstanceOf(ApplicationLeasePolicy::class, $policy);
		$this->assertSame('app-1', $policy->getApplicationId());
		$this->assertSame(['select(*)', 'from(keepiq_app_lease_policies)', 'where(eq(application_id, :p0))'], $this->opsOf(0));
		$this->assertSame([':p0' => 'app-1'], $this->paramsOf(0));
	}//end testFindByApplicationHydratesThePolicy()

	/**
	 * An application without a policy throws.
	 *
	 * @return void
	 */
	public function testFindByApplicationThrowsWhenMissing(): void {
		$this->expectException(DoesNotExistException::class);

		$this->mapper()->findByApplication(applicationId: 'none');
	}//end testFindByApplicationThrowsWhenMissing()

	/**
	 * Upsert deletes the old row, then inserts the new values with renewable as 1/0/null.
	 *
	 * @return void
	 */
	public function testUpsertReplacesTheRowAndEncodesRenewable(): void {
		$mapper = $this->mapper();

		$mapper->upsert(applicationId: 'app-1', defaultTtlSeconds: 300, maxTtlSeconds: 3600, renewable: true);
		$mapper->upsert(applicationId: 'app-2', defaultTtlSeconds: null, maxTtlSeconds: null, renewable: false);
		$mapper->upsert(applicationId: 'app-3', defaultTtlSeconds: 60, maxTtlSeconds: 120, renewable: null);

		$this->assertSame(['delete(keepiq_app_lease_policies)', 'where(eq(application_id, :p0))'], $this->opsOf(0));
		$this->assertSame('statement', $this->ranOf(0));
		$this->assertSame('insert(keepiq_app_lease_policies)', $this->opsOf(1)[0]);
		$this->assertSame('statement', $this->ranOf(1));
		$this->assertSame(['app-1', 300, 3600, 1], array_values($this->paramsOf(1)));
		$this->assertSame(['app-2', null, null, 0], array_values($this->paramsOf(3)));
		$this->assertSame(['app-3', 60, 120, null], array_values($this->paramsOf(5)));
	}//end testUpsertReplacesTheRowAndEncodesRenewable()

	/**
	 * Delete removes only the application's row.
	 *
	 * @return void
	 */
	public function testDeleteByApplication(): void {
		$this->mapper()->deleteByApplication(applicationId: 'app-9');

		$this->assertSame(['delete(keepiq_app_lease_policies)', 'where(eq(application_id, :p0))'], $this->opsOf(0));
		$this->assertSame([':p0' => 'app-9'], $this->paramsOf(0));
		$this->assertSame('statement', $this->ranOf(0));
	}//end testDeleteByApplication()
}//end class
