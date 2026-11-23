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
use OCA\Keepiq\Exception\NotFoundException;
use OCA\Keepiq\Exception\ShareSaveRequiredException;
use OCA\Keepiq\Exception\ShareScanPendingException;
use OCA\Keepiq\Exception\ViewOnlyCopyException;
use OCA\Keepiq\Service\LinkShareService;
use OCA\Keepiq\Service\MigrationService;
use OCA\Keepiq\Service\SecretService;
use OCA\Keepiq\Service\SecretTypeService;
use OCA\Keepiq\Service\ShareRowJudge;
use OCA\Keepiq\Service\ShareRowVerdict;
use OCA\Keepiq\Service\ShareSaveRequiredGuard;
use OCA\Keepiq\Service\ShareSync\ShareSyncGuards;
use OCA\Keepiq\Service\ShareSync\ShareWriteContext;
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

	/** The verdict on the row's own share row when it is not one with a row (AMBIGUOUS, UNJUDGED) */
	private ?string $verdictState = null;

	/**
	 * Alice's `src`, by default shared with nobody.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		parent::setUp();
		$this->rowJudge = $this->createMock(ShareRowJudge::class);
		$this->rowJudge->method('forCopy')->willReturnCallback(
			fn (): ShareRowVerdict => match (true) {
				$this->verdictState !== null => new ShareRowVerdict(state: $this->verdictState),
				$this->copyRow !== null => new ShareRowVerdict(state: ShareRowVerdict::TRUSTED, row: $this->copyRow),
				default => new ShareRowVerdict(state: ShareRowVerdict::NONE),
			}
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
	 * A row whose own share row the pending scan cannot judge waits for it,
	 * whatever guard ran before.
	 *
	 * @return void
	 */
	public function testARowWhoseShareRowTheScanCannotJudgeWaitsForTheScan(): void {
		$this->verdictState = ShareRowVerdict::UNJUDGED;
		$this->expectException(ShareScanPendingException::class);
		$this->guard()->assertSavedAlone(row: $this->row(), fields: ['key']);
	}//end testARowWhoseShareRowTheScanCannotJudgeWaitsForTheScan()

	/**
	 * A row named by more than one share row is not found, as in the share-save.
	 *
	 * @return void
	 */
	public function testARowWithAnAmbiguousShareRowIsNotFound(): void {
		$this->verdictState = ShareRowVerdict::AMBIGUOUS;
		$this->expectException(NotFoundException::class);
		$this->guard()->assertSavedAlone(row: $this->row(), fields: ['key']);
	}//end testARowWithAnAmbiguousShareRowIsNotFound()

	/**
	 * A SecretService over the given mapper, view-only guard and this test's share-save guard.
	 *
	 * @param SecretMapper $secrets The rows
	 * @param ViewOnlyCopyGuard $viewOnlyCopies The view-only guard
	 *
	 * @return SecretService
	 */
	private function service(SecretMapper $secrets, ViewOnlyCopyGuard $viewOnlyCopies): SecretService {
		return new SecretService(
			mapper: $secrets,
			typeService: $this->createMock(SecretTypeService::class),
			suiteMapper: $this->createMock(EncryptionSuiteMapper::class),
			migrationService: $this->createMock(MigrationService::class),
			linkShareService: $this->createMock(LinkShareService::class),
			logger: $this->createMock(LoggerInterface::class),
			db: $this->createMock(IDBConnection::class),
			viewOnlyCopies: $viewOnlyCopies,
			shareSaveRequired: $this->guard(),
		);
	}//end service()

	/**
	 * Through SecretService::updateAlone(), a new value of a shared secret
	 * is refused before anything is written.
	 *
	 * @return void
	 */
	public function testUpdateAloneRefusesASharedValueBeforeAnyWrite(): void {
		$this->copies = ['copy' => ['secretId' => 'copy', 'holder' => 'recipient', 'targetUserId' => 'bob']];
		$secrets = $this->createMock(SecretMapper::class);
		$secrets->method('findById')->willReturnCallback(fn (): Secret => $this->row());
		$secrets->expects($this->never())->method('advanceVersion');
		$secrets->expects($this->never())->method('update');
		$secrets->expects($this->never())->method('insert');

		$this->expectException(ShareSaveRequiredException::class);
		$this->service(secrets: $secrets, viewOnlyCopies: $this->createStub(ViewOnlyCopyGuard::class))
			->updateAlone(id: 'src', data: ['key' => 'NEW'], userId: 'alice');
	}//end testUpdateAloneRefusesASharedValueBeforeAnyWrite()

	/**
	 * Through updateAlone(), a copy its holder may only view keeps the
	 * view_only refusal, even when it has live copies of its own.
	 *
	 * @return void
	 */
	public function testUpdateAloneRefusesAViewOnlyCopyAsViewOnly(): void {
		$this->copyRow = new ShareTarget();
		$this->copyRow->setSourceSecretId('elsewhere');
		$this->copies = ['copy' => ['secretId' => 'copy', 'holder' => 'recipient', 'targetUserId' => 'bob']];
		$source = new Secret();
		$source->setId('elsewhere');
		$secrets = $this->createMock(SecretMapper::class);
		$secrets->method('findById')->willReturnCallback(fn (string $id): Secret => ($id === 'elsewhere') ? $source : $this->row());
		$secrets->expects($this->never())->method('update');
		$contexts = $this->createStub(ShareWriteContext::class);
		$contexts->method('standingOn')->willReturn([]);
		$contexts->method('writesForEveryone')->willReturn(false);
		$viewOnly = new ViewOnlyCopyGuard(rowJudge: $this->rowJudge, secrets: $secrets, contexts: $contexts);

		$this->expectException(ViewOnlyCopyException::class);
		$this->service(secrets: $secrets, viewOnlyCopies: $viewOnly)->updateAlone(id: 'src', data: ['key' => 'NEW'], userId: 'alice');
	}//end testUpdateAloneRefusesAViewOnlyCopyAsViewOnly()

	/**
	 * Through updateAlone(), the stored ciphertext sent again is no change,
	 * and update(), which the share-save uses, is not guarded.
	 *
	 * @return void
	 */
	public function testUpdateAloneGuardsOnlyAChangedValue(): void {
		$this->copies = ['copy' => ['secretId' => 'copy', 'holder' => 'recipient', 'targetUserId' => 'bob']];
		$secrets = $this->createMock(SecretMapper::class);
		$secrets->method('findById')->willReturnCallback(fn (): Secret => $this->row());
		$secrets->method('advanceVersion')->willReturn(1);
		$secrets->method('update')->willReturnArgument(0);
		$service = $this->service(secrets: $secrets, viewOnlyCopies: $this->createStub(ViewOnlyCopyGuard::class));

		$this->assertSame('Renamed', $service->updateAlone(id: 'src', data: ['name' => 'Renamed', 'key' => 'OLD'], userId: 'alice')->getName());
		$this->assertSame('NEW', $service->update(id: 'src', data: ['key' => 'NEW'], userId: 'alice')->getKey());
	}//end testUpdateAloneGuardsOnlyAChangedValue()
}//end class
