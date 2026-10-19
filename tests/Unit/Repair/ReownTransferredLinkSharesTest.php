<?php

/**
 * Unit tests for the repair step that hands a former owner's left-behind
 * link shares to their secret's owner.
 *
 * The step runs on every upgrade, so it must never stop one. The database
 * behaviour is covered by LinkShareOwnerTransferTest.
 *
 * @category Test
 * @package  OCA\Keepiq\Tests\Unit\Repair
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

namespace OCA\Keepiq\Tests\Unit\Repair;

use OCA\Keepiq\Db\LinkShareMapper;
use OCA\Keepiq\Repair\ReownTransferredLinkShares;
use OCP\DB\IResult;
use OCP\DB\QueryBuilder\ICompositeExpression;
use OCP\DB\QueryBuilder\IExpressionBuilder;
use OCP\DB\QueryBuilder\IQueryBuilder;
use OCP\IDBConnection;
use OCP\Migration\IOutput;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use RuntimeException;

/**
 * Tests for ReownTransferredLinkShares.
 *
 * @covers \OCA\Keepiq\Repair\ReownTransferredLinkShares
 */
final class ReownTransferredLinkSharesTest extends TestCase {
	/**
	 * A database error is logged as a warning and does not stop the upgrade.
	 *
	 * @return void
	 */
	public function testAFailureIsLoggedAndNeverThrown(): void {
		$db = $this->createMock(IDBConnection::class);
		$db->method('getQueryBuilder')->willThrowException(new RuntimeException('database gone'));

		$links = $this->createMock(LinkShareMapper::class);
		$links->expects($this->never())->method('reassignCreatorById');

		$logger = $this->createMock(LoggerInterface::class);
		$logger->expects($this->once())->method('warning')->with($this->stringContains('database gone'));

		$output = $this->createMock(IOutput::class);
		$output->expects($this->once())->method('warning');

		(new ReownTransferredLinkShares(db: $db, links: $links, logger: $logger))->run($output);
	}//end testAFailureIsLoggedAndNeverThrown()

	/**
	 * Each matched link is handed to its secret's current owner, with a
	 * numeric link id passed as a string.
	 *
	 * @return void
	 */
	public function testEachLeftBehindLinkIsHandedOver(): void {
		$expr = $this->createMock(IExpressionBuilder::class);
		$expr->method('eq')->willReturn('eq');
		$expr->method('neq')->willReturn('neq');
		$expr->method('lte')->willReturn('lte');
		$expr->method('andX')->willReturn($this->createMock(ICompositeExpression::class));

		$result = $this->createMock(IResult::class);
		$result->method('fetch')->willReturnOnConsecutiveCalls(
			['id' => '42', 'owner_id' => 'dave'],
			['id' => 'link-2', 'owner_id' => 'zoe'],
			false
		);

		$qb = $this->createMock(IQueryBuilder::class);
		$qb->method('selectDistinct')->willReturnSelf();
		$qb->method('from')->willReturnSelf();
		$qb->method('innerJoin')->willReturnSelf();
		$qb->method('where')->willReturnSelf();
		$qb->method('andWhere')->willReturnSelf();
		$qb->method('expr')->willReturn($expr);
		$qb->method('createNamedParameter')->willReturn(':param');
		$qb->method('executeQuery')->willReturn($result);

		$db = $this->createMock(IDBConnection::class);
		$db->method('getQueryBuilder')->willReturn($qb);

		$handedOver = [];
		$links = $this->createMock(LinkShareMapper::class);
		$links->method('reassignCreatorById')->willReturnCallback(
			static function (string $id, string $toUserId) use (&$handedOver): int {
				$handedOver[] = [$id, $toUserId];
				return 1;
			}
		);

		$logger = $this->createMock(LoggerInterface::class);
		$logger->expects($this->never())->method('warning');

		$output = $this->createMock(IOutput::class);
		$output->expects($this->once())->method('info')->with($this->stringContains(': 2 link share(s)'));

		(new ReownTransferredLinkShares(db: $db, links: $links, logger: $logger))->run($output);

		$this->assertSame([['42', 'dave'], ['link-2', 'zoe']], $handedOver);
	}//end testEachLeftBehindLinkIsHandedOver()
}//end class
