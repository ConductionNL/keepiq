<?php

/**
 * Unit tests for SecretUrlMapper's queries and the address replace transaction.
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

use OCA\Keepiq\Db\SecretUrlMapper;
use OCA\Keepiq\Tests\Support\RecordingDatabase;
use OCP\DB\QueryBuilder\IQueryBuilder;
use PHPUnit\Framework\TestCase;
use RuntimeException;

/**
 * SecretUrlMapper against a recording connection.
 *
 * @covers \OCA\Keepiq\Db\SecretUrlMapper
 * @uses   \OCA\Keepiq\Db\SecretUrl
 */
class SecretUrlMapperTest extends TestCase {
	use RecordingDatabase;

	/**
	 * Addresses are grouped per secret in list order.
	 *
	 * @return void
	 */
	public function testFindUrlsBySecretIdsGroupsPerSecretInListOrder(): void {
		$this->queueRows([['secret_id' => 's1', 'url' => 'https://login.example.net'], ['secret_id' => 's1', 'url' => 'https://example.org'], ['secret_id' => 's2', 'url' => 'https://b.example']]);

		$urls = (new SecretUrlMapper($this->recordingConnection()))->findUrlsBySecretIds(secretIds: ['s1', 's2', 's1']);

		$this->assertSame(['s1' => ['https://login.example.net', 'https://example.org'], 's2' => ['https://b.example']], $urls);
		$this->assertSame(
			['select(secret_id, url)', 'from(keepiq_secret_urls)', 'where(in(secret_id, :p0))', 'orderBy(secret_id, ASC)', 'addOrderBy(position, ASC)'],
			$this->opsOf(0)
		);
		$this->assertSame(['s1', 's2'], $this->paramsOf(0)[':p0']);
		$this->assertSame(IQueryBuilder::PARAM_STR_ARRAY, $this->queries[0]['types'][':p0']);
	}//end testFindUrlsBySecretIdsGroupsPerSecretInListOrder()

	/**
	 * Replacing deletes the row's addresses, inserts each with its position, and commits.
	 *
	 * @return void
	 */
	public function testReplaceForSecretDeletesInsertsInOrderAndCommits(): void {
		(new SecretUrlMapper($this->recordingConnection()))->replaceForSecret(secretId: 's1', ownerId: 'alice', urls: ['https://a.example', 'https://b.example']);

		$this->assertSame(['beginTransaction', 'commit'], $this->connectionCalls);
		$this->assertSame(['delete(keepiq_secret_urls)', 'where(eq(secret_id, :p0))'], $this->opsOf(0));
		$this->assertCount(3, $this->queries);
		// Position 0 equals the column default, so the entity leaves it out of the insert.
		$this->assertSame(['s1', 'alice', 'https://a.example'], array_values($this->paramsOf(1)));
		$this->assertSame(['s1', 'alice', 'https://b.example', 1], array_values($this->paramsOf(2)));
	}//end testReplaceForSecretDeletesInsertsInOrderAndCommits()

	/**
	 * A failing write rolls back and rethrows.
	 *
	 * @return void
	 */
	public function testReplaceForSecretRollsBackOnFailure(): void {
		$db = $this->recordingConnection();
		$db->method('getQueryBuilder')->willThrowException(new RuntimeException('db down'));
		try {
			(new SecretUrlMapper($db))->replaceForSecret(secretId: 's1', ownerId: 'alice', urls: ['https://a.example']);
			$this->fail('no exception');
		} catch (RuntimeException $e) {
			$this->assertSame('db down', $e->getMessage());
		}

		$this->assertSame(['beginTransaction', 'rollBack'], $this->connectionCalls);
	}//end testReplaceForSecretRollsBackOnFailure()

	/**
	 * Account deletion removes every address of the holder's rows.
	 *
	 * @return void
	 */
	public function testDeleteByOwnerIsScopedToTheHolder(): void {
		(new SecretUrlMapper($this->recordingConnection()))->deleteByOwner(ownerId: 'alice');

		$this->assertSame(['delete(keepiq_secret_urls)', 'where(eq(owner_id, :p0))'], $this->opsOf(0));
		$this->assertSame([':p0' => 'alice'], $this->paramsOf(0));
	}//end testDeleteByOwnerIsScopedToTheHolder()
}//end class
