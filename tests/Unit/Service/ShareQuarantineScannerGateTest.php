<?php

/**
 * The share quarantine scan's gate, without a database: whether the scan of
 * the current generation finished, reopening it, holding a copy back while
 * it is pending (failing closed when a row cannot be judged), and the
 * held-back resolve marker.
 *
 * @category Test
 * @package  OCA\Keepiq\Tests\Unit\Service
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

namespace OCA\Keepiq\Tests\Unit\Service;

use OCA\Keepiq\Db\Secret;
use OCA\Keepiq\Db\ShareTarget;
use OCA\Keepiq\Exception\ConflictException;
use OCA\Keepiq\Exception\ShareScanPendingException;
use OCA\Keepiq\Service\AuditService;
use OCA\Keepiq\Service\ShareQuarantineScanner;
use OCA\Keepiq\Service\SiemService;
use OCP\IAppConfig;
use OCP\IDBConnection;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\UsesClass;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use RuntimeException;

/**
 * The scan gate as callers meet it before the scan has finished.
 */
#[CoversClass(ShareQuarantineScanner::class)]
#[UsesClass(ShareTarget::class)]
#[UsesClass(Secret::class)]
#[UsesClass(ConflictException::class)]
#[UsesClass(ShareScanPendingException::class)]
final class ShareQuarantineScannerGateTest extends TestCase {

	/**
	 * @var array<string,string>
	 */
	private array $config = [];

	/**
	 * @var list<string>
	 */
	private array $calls = [];

	/**
	 * @var IDBConnection&MockObject
	 */
	private $db;

	/**
	 * @var LoggerInterface&MockObject
	 */
	private $logger;

	/**
	 * @var ShareQuarantineScanner
	 */
	private ShareQuarantineScanner $scanner;

	/**
	 * Set up an app config held in memory and a database that cannot be read.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		parent::setUp();

		$appConfig = $this->createMock(IAppConfig::class);
		$appConfig->method('getValueString')->willReturnCallback(
			function (string $app, string $key, string $default = ''): string {
				$this->assertSame('keepiq', $app);
				return ($this->config[$key] ?? $default);
			}
		);
		$appConfig->method('setValueString')->willReturnCallback(
			function (string $app, string $key, string $value): bool {
				$this->calls[] = 'set '.$key;
				$this->config[$key] = $value;
				return true;
			}
		);
		$appConfig->method('deleteKey')->willReturnCallback(
			function (string $app, string $key): void {
				$this->calls[] = 'delete '.$key;
				unset($this->config[$key]);
			}
		);
		$appConfig->method('clearCache')->willReturnCallback(
			function (): void {
				$this->calls[] = 'clearCache';
			}
		);

		$this->db = $this->createMock(IDBConnection::class);
		$this->logger = $this->createMock(LoggerInterface::class);
		$this->scanner = new ShareQuarantineScanner(
			$this->db,
			$appConfig,
			$this->logger,
			$this->createMock(AuditService::class),
			$this->createMock(SiemService::class)
		);

	}//end setUp()

	/**
	 * A share row of a copy held by its recipient.
	 *
	 * @return ShareTarget
	 */
	private function row(): ShareTarget {
		$row = new ShareTarget();
		$row->setId(7);
		$row->setSecretId('copy-1');
		$row->setTargetUserId('bob');

		return $row;

	}//end row()

	/**
	 * A copy owned by the given user.
	 *
	 * @param string $owner The owner.
	 *
	 * @return Secret
	 */
	private function copy(string $owner): Secret {
		$copy = new Secret();
		$copy->setId('copy-1');
		$copy->setOwnerType('user');
		$copy->setOwnerId($owner);

		return $copy;

	}//end copy()

	/**
	 * Make the row judgement fail, as an unreadable database does.
	 *
	 * @return void
	 */
	private function unreadableDatabase(): void {
		$this->db->method('getQueryBuilder')->willThrowException(new RuntimeException('database gone'));
	}//end unreadableDatabase()

	/**
	 * The scan counts as complete only for the current generation.
	 *
	 * @return void
	 */
	public function testCompleteOnlyForTheCurrentGeneration(): void {
		$this->assertFalse($this->scanner->isComplete());

		$this->config[ShareQuarantineScanner::COMPLETED_KEY] = ShareQuarantineScanner::INITIAL_GENERATION;
		$this->assertTrue($this->scanner->isComplete());

		$this->config[ShareQuarantineScanner::GENERATION_KEY] = 'gen-2';
		$this->assertFalse($this->scanner->isComplete());
	}//end testCompleteOnlyForTheCurrentGeneration()

	/**
	 * Reopening starts a new generation and forgets the completion.
	 *
	 * @return void
	 */
	public function testReopenStartsANewGeneration(): void {
		$this->config[ShareQuarantineScanner::COMPLETED_KEY] = ShareQuarantineScanner::INITIAL_GENERATION;

		$this->scanner->reopen();

		$this->assertMatchesRegularExpression('/^[0-9a-f-]{36}$/', $this->config[ShareQuarantineScanner::GENERATION_KEY]);
		$this->assertArrayNotHasKey(ShareQuarantineScanner::COMPLETED_KEY, $this->config);
		$this->assertFalse($this->scanner->isComplete());
	}//end testReopenStartsANewGeneration()

	/**
	 * Once the scan is complete nothing is held back and the database is not asked.
	 *
	 * @return void
	 */
	public function testNothingIsHeldBackOnceComplete(): void {
		$this->config[ShareQuarantineScanner::COMPLETED_KEY] = ShareQuarantineScanner::INITIAL_GENERATION;
		$this->db->expects($this->never())->method('beginTransaction');

		$this->assertNull($this->scanner->heldBack($this->row()));
		$this->assertTrue($this->scanner->mayTouchCopy($this->row(), $this->copy('bob')));
		$this->assertFalse($this->scanner->mayTouchCopy($this->row(), $this->copy('mallory')));
	}//end testNothingIsHeldBackOnceComplete()

	/**
	 * A row that cannot be judged while the scan is pending is held back, the transaction rolled back.
	 *
	 * @return void
	 */
	public function testAnUnjudgeableRowIsHeldBack(): void {
		$this->unreadableDatabase();
		$this->db->expects($this->once())->method('beginTransaction');
		$this->db->expects($this->once())->method('rollBack');
		$this->db->expects($this->never())->method('commit');
		$this->logger->expects($this->once())->method('error')
			->with($this->stringContains('row 7 could not be judged while the share quarantine scan is pending'));

		$this->assertSame(ShareQuarantineScanner::HELD_UNJUDGED, $this->scanner->heldBack($this->row()));
	}//end testAnUnjudgeableRowIsHeldBack()

	/**
	 * An unjudged row may not have its copy touched, even by its own recipient.
	 *
	 * @return void
	 */
	public function testAnUnjudgedCopyMayNotBeTouched(): void {
		$this->unreadableDatabase();

		$this->assertFalse($this->scanner->mayTouchCopy($this->row(), $this->copy('bob')));
	}//end testAnUnjudgedCopyMayNotBeTouched()

	/**
	 * Refusing an unjudged row says why and points at the background jobs.
	 *
	 * @return void
	 */
	public function testAnUnjudgedRowIsRefusedAsPending(): void {
		$this->unreadableDatabase();

		$this->expectException(ShareScanPendingException::class);
		$this->expectExceptionMessage('This share waits for the share quarantine scan and cannot be released yet; ask an administrator to check that background jobs run');

		$this->scanner->assertNotHeldBack($this->row(), 'released');
	}//end testAnUnjudgedRowIsRefusedAsPending()

	/**
	 * A complete scan lets the row through.
	 *
	 * @return void
	 */
	public function testACompleteScanLetsTheRowThrough(): void {
		$this->config[ShareQuarantineScanner::COMPLETED_KEY] = ShareQuarantineScanner::INITIAL_GENERATION;

		$this->assertFalse($this->scanner->refuseUnjudged($this->row(), 'refused'));
		$this->scanner->assertNotHeldBack($this->row(), 'released');
		$this->addToAssertionCount(1);
	}//end testACompleteScanLetsTheRowThrough()

	/**
	 * The held-back resolve is recorded after a cache clear, read back, and cleared only when unchanged.
	 *
	 * @return void
	 */
	public function testTheHeldBackResolveMarker(): void {
		$this->assertNull($this->scanner->heldBackResolve());

		$this->scanner->holdBackResolve();
		$record = $this->scanner->heldBackResolve();
		$this->assertNotNull($record);
		$this->assertSame(['clearCache', 'set '.ShareQuarantineScanner::RESOLVE_PENDING_KEY], $this->calls);

		$this->scanner->clearHeldBackResolve('another-record');
		$this->assertSame($record, $this->scanner->heldBackResolve());

		$this->scanner->clearHeldBackResolve($record);
		$this->assertNull($this->scanner->heldBackResolve());
		$this->assertSame('delete '.ShareQuarantineScanner::RESOLVE_PENDING_KEY, end($this->calls));
	}//end testTheHeldBackResolveMarker()
}//end class
