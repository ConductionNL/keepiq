<?php

/**
 * Unit tests for ShareSaveRequiredGuard and SecretService::updateAlone().
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

use OCA\Keepiq\Db\EncryptionSuiteMapper;
use OCA\Keepiq\Db\FederatedShare;
use OCA\Keepiq\Db\FederatedShareMapper;
use OCA\Keepiq\Db\Secret;
use OCA\Keepiq\Db\SecretMapper;
use OCA\Keepiq\Db\ShareTarget;
use OCA\Keepiq\Exception\ShareSaveRequiredException;
use OCA\Keepiq\Exception\ShareScanPendingException;
use OCA\Keepiq\Service\LinkShareService;
use OCA\Keepiq\Service\MigrationService;
use OCA\Keepiq\Service\SecretService;
use OCA\Keepiq\Service\SecretTypeService;
use OCA\Keepiq\Service\ShareRowJudge;
use OCA\Keepiq\Service\ShareRowVerdict;
use OCA\Keepiq\Service\ShareSaveRequiredGuard;
use OCA\Keepiq\Service\ShareSync\ShareSyncGuards;
use OCA\Keepiq\Service\ViewOnlyCopyGuard;
use OCP\IDBConnection;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

/**
 * A plain update changes a value only where the share-save would have no
 * copy and no partner share to write beside it (keepiq#1300).
 *
 * @spec openspec/specs/user-sharing/spec.md#requirement-a-value-change-of-a-shared-secret-goes-through-the-share-save
 */
class ShareSaveRequiredGuardTest extends TestCase {
	private ShareRowJudge&MockObject $rowJudge;

	private ShareSyncGuards&MockObject $guards;

	private FederatedShareMapper&MockObject $federatedShares;

	/** @var array<string,array{secretId:string,holder:string,targetUserId:?string}> */
	private array $copies = [];

	/** @var list<string> */
	private array $unjudged = [];

	/** @var list<FederatedShare> */
	private array $partnerShares = [];

	private ?ShareTarget $copyRow = null;

	/**
	 * Alice's `src`, by default shared with nobody.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		parent::setUp();
		$this->rowJudge = $this->createMock(ShareRowJudge::class);
		$this->rowJudge->method('forCopy')->willReturnCallback(
			fn (): ShareRowVerdict => ($this->copyRow === null ? new ShareRowVerdict(state: ShareRowVerdict::NONE) : new ShareRowVerdict(state: ShareRowVerdict::TRUSTED, row: $this->copyRow))
		);
		$this->rowJudge->method('scanPending')->willReturnCallback(static fn (string $refusal): ShareScanPendingException => new ShareScanPendingException($refusal));
		$this->guards = $this->createMock(ShareSyncGuards::class);
		$this->guards->method('liveCopies')->willReturnCallback(
			fn (): array => ['copies' => $this->copies, 'ignored' => [], 'unjudged' => $this->unjudged]
		);
		$this->federatedShares = $this->createMock(FederatedShareMapper::class);
		$this->federatedShares->method('findBySourceSecret')->willReturnCallback(fn (): array => $this->partnerShares);
	}//end setUp()

	/**
	 * The guard over this test's judge, live copies and partner shares.
	 *
	 * @return ShareSaveRequiredGuard
	 */
	private function guard(): ShareSaveRequiredGuard {
		return new ShareSaveRequiredGuard(rowJudge: $this->rowJudge, guards: $this->guards, federatedShares: $this->federatedShares);
	}//end guard()

	/**
	 * Alice's row.
	 *
	 * @return Secret
	 */
	private function row(): Secret {
		$row = new Secret();
		$row->setId('src');
		$row->setOwnerType('user');
		$row->setOwnerId('alice');
		$row->setName('Router');
		$row->setKey('OLD');
		return $row;
	}//end row()

	/**
	 * A partner share in the given state.
	 *
	 * @param string $status The share's status
	 *
	 * @return FederatedShare
	 */
	private function partnerShare(string $status): FederatedShare {
		$share = new FederatedShare();
		$share->setStatus($status);
		return $share;
	}//end partnerShare()

	/**
	 * Each value field is refused on a source with a copy.
	 *
	 * @return array<string,array{0:string}>
	 */
	public static function valueFields(): array {
		return array_combine(Secret::VALUE_FIELDS, array_map(static fn (string $field): array => [$field], Secret::VALUE_FIELDS));
	}//end valueFields()

	/**
	 * A value change of a source with a live copy is refused.
	 *
	 * @param string $field The value field changed
	 *
	 * @return void
	 */
	#[DataProvider('valueFields')]
	public function testAValueChangeOfASourceWithACopyIsRefused(string $field): void {
		$this->copies = ['copy' => ['secretId' => 'copy', 'holder' => 'recipient', 'targetUserId' => 'bob']];
		$this->expectException(ShareSaveRequiredException::class);
		$this->guard()->assertSavedAlone(row: $this->row(), fields: [$field]);
	}//end testAValueChangeOfASourceWithACopyIsRefused()

	/**
	 * A value change of a source with an active partner share is refused;
	 * a suspended or revoked one is not written by the share-save either.
	 *
	 * @return void
	 */
	public function testOnlyAnActivePartnerShareRefusesTheValueChange(): void {
		$this->partnerShares = [$this->partnerShare(FederatedShare::STATUS_SUSPENDED), $this->partnerShare(FederatedShare::STATUS_REVOKED)];
		$this->guard()->assertSavedAlone(row: $this->row(), fields: ['key']);

		$this->partnerShares[] = $this->partnerShare(FederatedShare::STATUS_ACTIVE);
		$this->expectException(ShareSaveRequiredException::class);
		$this->guard()->assertSavedAlone(row: $this->row(), fields: ['key']);
	}//end testOnlyAnActivePartnerShareRefusesTheValueChange()

	/**
	 * A value change of a copy its holder writes for everyone is refused:
	 * the share-save writes the source and every other copy.
	 *
	 * @return void
	 */
	public function testAValueChangeOfAWritersCopyIsRefused(): void {
		$this->copyRow = new ShareTarget();
		$this->copyRow->setSourceSecretId('elsewhere');
		$this->expectException(ShareSaveRequiredException::class);
		$this->guard()->assertSavedAlone(row: $this->row(), fields: ['login']);
	}//end testAValueChangeOfAWritersCopyIsRefused()

	/**
	 * A copy whose share row the pending quarantine scan cannot judge waits for it.
	 *
	 * @return void
	 */
	public function testACopyTheScanCannotJudgeWaitsForTheScan(): void {
		$this->unjudged = ['copy'];
		$this->expectException(ShareScanPendingException::class);
		$this->guard()->assertSavedAlone(row: $this->row(), fields: ['key']);
	}//end testACopyTheScanCannotJudgeWaitsForTheScan()

	/**
	 * An unshared row's value, and a shared row's name, change through the plain update.
	 *
	 * @return void
	 */
	public function testAnUnsharedValueOrANameStillSaves(): void {
		$this->guard()->assertSavedAlone(row: $this->row(), fields: ['key', 'totp']);

		$this->copies = ['copy' => ['secretId' => 'copy', 'holder' => 'recipient', 'targetUserId' => 'bob']];
		$this->guard()->assertSavedAlone(row: $this->row(), fields: []);
		$this->addToAssertionCount(2);
	}//end testAnUnsharedValueOrANameStillSaves()

	/**
	 * Through SecretService::updateAlone(): a new value of a shared secret is
	 * refused before anything is written, the stored ciphertext sent again is
	 * no change, and update(), which the share-save uses, is not guarded.
	 *
	 * @return void
	 */
	public function testUpdateAloneGuardsOnlyAChangedValue(): void {
		$this->copies = ['copy' => ['secretId' => 'copy', 'holder' => 'recipient', 'targetUserId' => 'bob']];
		$secrets = $this->createMock(SecretMapper::class);
		$secrets->method('findById')->willReturnCallback(fn (): Secret => $this->row());
		$secrets->method('advanceVersion')->willReturn(1);
		$secrets->method('update')->willReturnArgument(0);
		$service = new SecretService(
			mapper: $secrets,
			typeService: $this->createMock(SecretTypeService::class),
			suiteMapper: $this->createMock(EncryptionSuiteMapper::class),
			migrationService: $this->createMock(MigrationService::class),
			linkShareService: $this->createMock(LinkShareService::class),
			logger: $this->createMock(LoggerInterface::class),
			db: $this->createMock(IDBConnection::class),
			viewOnlyCopies: $this->createStub(ViewOnlyCopyGuard::class),
			shareSaveRequired: $this->guard(),
		);

		try {
			$service->updateAlone(id: 'src', data: ['key' => 'NEW'], userId: 'alice');
			$this->fail('A new value of a shared secret must go through the share-save');
		} catch (ShareSaveRequiredException) {
			$this->addToAssertionCount(1);
		}

		$this->assertSame('Renamed', $service->updateAlone(id: 'src', data: ['name' => 'Renamed', 'key' => 'OLD'], userId: 'alice')->getName());
		$this->assertSame('NEW', $service->update(id: 'src', data: ['key' => 'NEW'], userId: 'alice')->getKey());
	}//end testUpdateAloneGuardsOnlyAChangedValue()
}//end class
