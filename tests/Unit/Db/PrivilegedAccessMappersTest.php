<?php

/**
 * Unit tests for the privileged login and access request mappers.
 *
 * @category Test
 * @package  OCA\Keepiq\Tests\Unit\Db
 *
 * @author    Conduction Development Team <dev@conductio.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git-id>
 *
 * @link https://conduction.nl
 */

declare(strict_types=1);

namespace OCA\Keepiq\Tests\Unit\Db;

use OCA\Keepiq\Db\AccessRequest;
use OCA\Keepiq\Db\AccessRequestMapper;
use OCA\Keepiq\Db\PrivilegedLoginMapper;
use OCA\Keepiq\Tests\Support\RecordingDatabase;
use OCP\DB\QueryBuilder\IQueryBuilder;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\UsesClass;
use PHPUnit\Framework\TestCase;

/**
 * The privileged access mappers against a recording connection.
 */
#[CoversClass(PrivilegedLoginMapper::class)]
#[CoversClass(AccessRequestMapper::class)]
#[UsesClass(AccessRequest::class)]
class PrivilegedAccessMappersTest extends TestCase {
	use RecordingDatabase;

	/**
	 * Only the marked sources among those asked come back, in one IN query.
	 *
	 * @return void
	 */
	public function testPrivilegedAmongReturnsTheMarkedSources(): void {
		$this->queueRows([['secret_id' => 's2']]);

		$found = (new PrivilegedLoginMapper($this->recordingConnection()))->privilegedAmong(secretIds: ['s1', 's2', 's1']);

		$this->assertSame(['s2'], $found);
		$this->assertSame(['select(secret_id)', 'from(keepiq_privileged_logins)', 'where(in(secret_id, :p0))'], $this->opsOf(0));
		$this->assertSame(['s1', 's2'], $this->paramsOf(0)[':p0']);
		$this->assertSame(IQueryBuilder::PARAM_STR_ARRAY, $this->queries[0]['types'][':p0']);
	}//end testPrivilegedAmongReturnsTheMarkedSources()

	/**
	 * No sources asked: no query at all.
	 *
	 * @return void
	 */
	public function testPrivilegedAmongOfNothingRunsNoQuery(): void {
		$this->assertSame([], (new PrivilegedLoginMapper($this->recordingConnection()))->privilegedAmong(secretIds: []));
		$this->assertSame([], $this->queries);
	}//end testPrivilegedAmongOfNothingRunsNoQuery()

	/**
	 * Unmarking and account deletion are scoped to the secret and the owner.
	 *
	 * @return void
	 */
	public function testFlagDeletesAreScoped(): void {
		$mapper = new PrivilegedLoginMapper($this->recordingConnection());
		$mapper->deleteBySecret(secretId: 's1');
		$mapper->deleteByUser(userId: 'alice');

		$this->assertSame(['delete(keepiq_privileged_logins)', 'where(eq(secret_id, :p0))'], $this->opsOf(0));
		$this->assertSame([':p0' => 's1'], $this->paramsOf(0));
		$this->assertSame(['delete(keepiq_privileged_logins)', 'where(eq(owner_id, :p1))'], $this->opsOf(1));
		$this->assertSame([':p1' => 'alice'], $this->paramsOf(1));
	}//end testFlagDeletesAreScoped()

	/**
	 * The approved requests of one requester on one secret only.
	 *
	 * @return void
	 */
	public function testFindApprovedFiltersOnSecretRequesterAndStatus(): void {
		$this->queueRows([['id' => 'r1', 'secret_id' => 's1', 'requester_id' => 'bob', 'status' => 'approved']]);

		$found = (new AccessRequestMapper($this->recordingConnection()))->findApproved(secretId: 's1', requesterId: 'bob');

		$this->assertCount(1, $found);
		$this->assertSame('r1', $found[0]->getId());
		$this->assertSame(
			['select(*)', 'from(keepiq_access_requests)', 'where(eq(secret_id, :p0))', 'andWhere(eq(requester_id, :p1))', 'andWhere(eq(status, :p2))'],
			$this->opsOf(0)
		);
		$this->assertSame([':p0' => 's1', ':p1' => 'bob', ':p2' => 'approved'], $this->paramsOf(0));
	}//end testFindApprovedFiltersOnSecretRequesterAndStatus()

	/**
	 * A secret's requests, narrowed to one requester when asked, newest first.
	 *
	 * @return void
	 */
	public function testFindBySecretNarrowsToTheRequester(): void {
		$mapper = new AccessRequestMapper($this->recordingConnection());
		$mapper->findBySecret(secretId: 's1');
		$mapper->findBySecret(secretId: 's1', requesterId: 'bob');

		$this->assertSame(['select(*)', 'from(keepiq_access_requests)', 'where(eq(secret_id, :p0))', 'orderBy(created_at, DESC)'], $this->opsOf(0));
		$this->assertSame(
			['select(*)', 'from(keepiq_access_requests)', 'where(eq(secret_id, :p1))', 'andWhere(eq(requester_id, :p2))', 'orderBy(created_at, DESC)'],
			$this->opsOf(1)
		);
	}//end testFindBySecretNarrowsToTheRequester()

	/**
	 * The owner's pending requests, and a lookup by id.
	 *
	 * @return void
	 */
	public function testPendingForOwnerAndFindById(): void {
		$this->queueRows([]);
		$this->queueRows([['id' => 'r9', 'status' => 'pending']]);
		$mapper = new AccessRequestMapper($this->recordingConnection());

		$this->assertSame([], $mapper->findPendingForOwner(ownerId: 'alice'));
		$this->assertSame('r9', $mapper->findById(id: 'r9')->getId());

		$this->assertSame(
			['select(*)', 'from(keepiq_access_requests)', 'where(eq(owner_id, :p0))', 'andWhere(eq(status, :p1))', 'orderBy(created_at, DESC)'],
			$this->opsOf(0)
		);
		$this->assertSame([':p0' => 'alice', ':p1' => 'pending'], $this->paramsOf(0));
		$this->assertSame(['select(*)', 'from(keepiq_access_requests)', 'where(eq(id, :p2))'], $this->opsOf(1));
	}//end testPendingForOwnerAndFindById()

	/**
	 * A deleted secret drops its requests; a departing user drops the ones
	 * they filed and the ones they were asked to answer.
	 *
	 * @return void
	 */
	public function testRequestDeletesAreScoped(): void {
		$mapper = new AccessRequestMapper($this->recordingConnection());
		$mapper->deleteBySecret(secretId: 's1');
		$mapper->deleteByUser(userId: 'bob');

		$this->assertSame(['delete(keepiq_access_requests)', 'where(eq(secret_id, :p0))'], $this->opsOf(0));
		$this->assertSame(['delete(keepiq_access_requests)', 'where(orX(eq(owner_id, :p1), eq(requester_id, :p2)))'], $this->opsOf(1));
		$this->assertSame([':p1' => 'bob', ':p2' => 'bob'], $this->paramsOf(1));
	}//end testRequestDeletesAreScoped()
}//end class
