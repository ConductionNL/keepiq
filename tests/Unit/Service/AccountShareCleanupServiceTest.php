<?php

/**
 * Unit tests for AccountShareCleanupService::detachGrantedShares — the
 * defence-in-depth guard that keeps the GDPR erasure cascade from tombstoning a
 * secret the share's target does not genuinely hold (GHSA-m5p9-6pw7-pf3g).
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

use OCA\Keepiq\Db\GroupShareMapper;
use OCA\Keepiq\Db\Secret;
use OCA\Keepiq\Db\SecretDelegationMapper;
use OCA\Keepiq\Db\SecretMapper;
use OCA\Keepiq\Db\ShareSaveStageMapper;
use OCA\Keepiq\Db\ShareTarget;
use OCA\Keepiq\Db\ShareTargetMapper;
use OCA\Keepiq\Service\AccountShareCleanupService;
use OCA\Keepiq\Service\DeletionReport;
use OCA\Keepiq\Service\SecretOwnerTransferService;
use PHPUnit\Framework\TestCase;

/**
 * Tests for AccountShareCleanupService.
 */
class AccountShareCleanupServiceTest extends TestCase {

	/**
	 * @var SecretMapper&\PHPUnit\Framework\MockObject\MockObject
	 */
	private SecretMapper $secretMapper;

	/**
	 * @var ShareTargetMapper&\PHPUnit\Framework\MockObject\MockObject
	 */
	private ShareTargetMapper $shareMapper;

	/**
	 * @var GroupShareMapper&\PHPUnit\Framework\MockObject\MockObject
	 */
	private GroupShareMapper $groupShareMapper;

	/**
	 * The service under test.
	 *
	 * @var AccountShareCleanupService
	 */
	private AccountShareCleanupService $service;

	/**
	 * Set up fixtures.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		$this->secretMapper = $this->createMock(SecretMapper::class);
		$this->shareMapper = $this->createMock(ShareTargetMapper::class);
		$this->groupShareMapper = $this->createMock(GroupShareMapper::class);
		$this->groupShareMapper->method('findBySecret')->willReturn([]);

		$this->service = new AccountShareCleanupService(
			secretMapper: $this->secretMapper,
			shareMapper: $this->shareMapper,
			groupShareMapper: $this->groupShareMapper,
			delegationMapper: $this->createMock(SecretDelegationMapper::class),
			stageMapper: $this->createMock(ShareSaveStageMapper::class),
			ownerTransfer: $this->createMock(SecretOwnerTransferService::class),
		);
	}//end setUp()

	/**
	 * Build a secret with an owner.
	 *
	 * @param string $id        The secret id
	 * @param string $ownerType The owner type
	 * @param string $ownerId   The owner id
	 *
	 * @return Secret
	 */
	private function secret(string $id, string $ownerType, string $ownerId): Secret {
		$secret = new Secret();
		$secret->setId($id);
		$secret->setOwnerType($ownerType);
		$secret->setOwnerId($ownerId);
		return $secret;
	}//end secret()

	/**
	 * Build a share-target row for a source secret.
	 *
	 * @param string $secretId     The linked copy id
	 * @param string $targetUserId The recipient
	 *
	 * @return ShareTarget
	 */
	private function share(string $secretId, string $targetUserId): ShareTarget {
		$share = new ShareTarget();
		$share->setId('st-1');
		$share->setSourceSecretId('src-1');
		$share->setSecretId($secretId);
		$share->setTargetUserId($targetUserId);
		return $share;
	}//end share()

	/**
	 * The recipient's copy of a shared secret is tombstoned when the target
	 * genuinely holds it.
	 *
	 * @return void
	 */
	public function testTombstonesACopyHeldByItsTarget(): void {
		$this->shareMapper->method('findBySourceSecret')->willReturn([$this->share('copy-1', 'bob')]);
		$this->secretMapper->method('findById')->willReturn($this->secret('copy-1', 'user', 'bob'));
		$this->secretMapper->expects($this->once())->method('tombstone')
			->with('copy-1', AccountShareCleanupService::TOMBSTONE_REASON);

		$report = new DeletionReport();
		$this->service->detachGrantedShares([$this->secret('src-1', 'user', 'alice')], $report);
		$this->assertSame(1, $report->sharesDetached);
	}//end testTombstonesACopyHeldByItsTarget()

	/**
	 * A copy NOT held by its target (a poisoned or drifted secret_id owned by
	 * someone else) is never tombstoned: erasing one owner must not touch another
	 * user's secret. Removing the guard line tombstones it and turns this red.
	 *
	 * @return void
	 */
	public function testNeverTombstonesACopyNotHeldByItsTarget(): void {
		// The link's target is bob, but the secret it names is owned by victim.
		$this->shareMapper->method('findBySourceSecret')->willReturn([$this->share('copy-1', 'bob')]);
		$this->secretMapper->method('findById')->willReturn($this->secret('copy-1', 'user', 'victim'));
		$this->secretMapper->expects($this->never())->method('tombstone');

		$report = new DeletionReport();
		$this->service->detachGrantedShares([$this->secret('src-1', 'user', 'alice')], $report);
		$this->assertSame(0, $report->sharesDetached);
	}//end testNeverTombstonesACopyNotHeldByItsTarget()

	/**
	 * The received shares removed are the rows actually deleted, quarantined
	 * ones included, not the live rows a finder sees.
	 *
	 * @return void
	 */
	public function testReportsEveryReceivedShareRowDeleted(): void {
		$this->shareMapper->method('findByTargetUser')->willReturn([$this->share('copy-1', 'bob')]);
		$this->shareMapper->expects($this->once())->method('deleteByTargetUser')->with('bob')->willReturn(2);

		$report = new DeletionReport();
		$this->service->removeReceivedShares('bob', $report);
		$this->assertSame(2, $report->sharesRemoved);
	}//end testReportsEveryReceivedShareRowDeleted()
}//end class
