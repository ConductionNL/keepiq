<?php

/**
 * Unit tests for the repair step that hands left-behind partner shares to
 * their secret's owner.
 *
 * The step runs on every upgrade, so it must never stop one. The database
 * behaviour is covered by PartnerShareOwnerTransferTest.
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

use OCA\Keepiq\Db\FederatedShareMapper;
use OCA\Keepiq\Repair\ReownTransferredPartnerShares;
use OCP\DB\IResult;
use OCP\DB\QueryBuilder\IExpressionBuilder;
use OCP\DB\QueryBuilder\IQueryBuilder;
use OCP\IDBConnection;
use OCP\Migration\IOutput;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use RuntimeException;

/**
 * Tests for ReownTransferredPartnerShares.
 *
 * @covers \OCA\Keepiq\Repair\ReownTransferredPartnerShares
 */
final class ReownTransferredPartnerSharesTest extends TestCase {
	/**
	 * A database error is logged as a warning and does not stop the upgrade.
	 *
	 * @return void
	 */
	public function testAFailureIsLoggedAndNeverThrown(): void {
		$db = $this->createMock(IDBConnection::class);
		$db->method('getQueryBuilder')->willThrowException(new RuntimeException('database gone'));

		$shares = $this->createMock(FederatedShareMapper::class);
		$shares->expects($this->never())->method('reassignOwner');

		$logger = $this->createMock(LoggerInterface::class);
		$logger->expects($this->once())->method('warning')->with($this->stringContains('database gone'));

		$output = $this->createMock(IOutput::class);
		$output->expects($this->once())->method('warning');

		(new ReownTransferredPartnerShares(db: $db, shares: $shares, logger: $logger))->run($output);
	}//end testAFailureIsLoggedAndNeverThrown()

	/**
	 * A numeric secret id, which PHP turns into an int array key, is still
	 * handed over as a string.
	 *
	 * @return void
	 */
	public function testANumericSecretIdIsHandedOver(): void {
		$expr = $this->createMock(IExpressionBuilder::class);
		$expr->method('eq')->willReturn('eq');
		$expr->method('neq')->willReturn('neq');

		$result = $this->createMock(IResult::class);
		$result->method('fetch')->willReturnOnConsecutiveCalls(['source_secret_id' => '42', 'owner_id' => 'dave'], false);

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

		$shares = $this->createMock(FederatedShareMapper::class);
		$shares->expects($this->once())->method('reassignOwner')->with('42', 'dave')->willReturn(1);

		$logger = $this->createMock(LoggerInterface::class);
		$logger->expects($this->never())->method('warning');

		(new ReownTransferredPartnerShares(db: $db, shares: $shares, logger: $logger))->run($this->createMock(IOutput::class));
	}//end testANumericSecretIdIsHandedOver()
}//end class
