<?php

/**
 * A database double for SecretVersionLock: answers its lock and read queries from a table of versions.
 *
 * @category Test
 * @package  OCA\Keepiq\Tests\Support
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

namespace OCA\Keepiq\Tests\Support;

use OCP\DB\IResult;
use OCP\DB\QueryBuilder\IExpressionBuilder;
use OCP\DB\QueryBuilder\IQueryBuilder;
use OCP\DB\QueryBuilder\IQueryFunction;
use OCP\IDBConnection;
use PHPUnit\Framework\MockObject\MockObject;

/**
 * Lets a test hand a mocked connection the versions SecretVersionLock reads, and see which rows it locked.
 */
trait SecretVersionLockDouble {
	/**
	 * The rows locked, in order.
	 *
	 * @var list<string>
	 */
	private array $lockedIds = [];

	/**
	 * Answer the version lock's queries on this connection from `$versions` (id => 'Y-m-d H:i:s' in UTC);
	 * an id not listed is gone.
	 *
	 * @param IDBConnection&MockObject $connection The mocked connection
	 * @param array<string,string> $versions The stored versions by id
	 *
	 * @return void
	 */
	private function answerVersionLocks(IDBConnection&MockObject $connection, array $versions): void {
		$connection->method('getQueryBuilder')->willReturnCallback(
			fn (): IQueryBuilder => $this->versionQuery(versions: $versions)
		);
	}//end answerVersionLocks()

	/**
	 * One query builder of the lock: the UPDATE records the row it locks, the SELECT answers its version.
	 *
	 * @param array<string,string> $versions The stored versions by id
	 *
	 * @return IQueryBuilder
	 */
	private function versionQuery(array $versions): IQueryBuilder {
		$id = null;
		$qb = $this->createMock(originalClassName: IQueryBuilder::class);
		foreach (['update', 'set', 'where', 'select', 'from'] as $chained) {
			$qb->method($chained)->willReturnSelf();
		}

		$expr = $this->createMock(originalClassName: IExpressionBuilder::class);
		$expr->method('eq')->willReturn('eq');
		$qb->method('expr')->willReturn($expr);
		$qb->method('createFunction')->willReturn($this->createMock(originalClassName: IQueryFunction::class));
		$qb->method('createNamedParameter')->willReturnCallback(
			static function (mixed $value) use (&$id): string {
				$id = (string)$value;
				return ':id';
			}
		);
		$qb->method('executeStatement')->willReturnCallback(
			function () use (&$id): int {
				$this->lockedIds[] = (string)$id;
				return 0;
			}
		);
		$qb->method('executeQuery')->willReturnCallback(
			function () use (&$id, $versions): IResult {
				$result = $this->createMock(originalClassName: IResult::class);
				$result->method('fetchOne')->willReturn(($versions[(string)$id] ?? false));
				return $result;
			}
		);

		return $qb;
	}//end versionQuery()
}//end trait
