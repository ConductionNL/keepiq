<?php

/**
 * The authorization paths that read a share row ask the pending quarantine
 * scan first. A row the scan quarantines on the spot, or cannot judge, is no
 * share to delegate on, gives no grade, does not re-root a share onto its
 * source, and does not make its holder's own secret a copy; one it cannot
 * judge keeps every refusal of a copy in place until it has judged it. A
 * legitimate row behaves as before.
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

use InvalidArgumentException;
use OCA\Keepiq\Db\BulkGrantShareTargetMapper;
use OCA\Keepiq\Db\EncryptionSuiteMapper;
use OCA\Keepiq\Db\GroupShare;
use OCA\Keepiq\Db\GroupShareMapper;
use OCA\Keepiq\Db\Secret;
use OCA\Keepiq\Db\SecretDelegation;
use OCA\Keepiq\Db\SecretDelegationMapper;
use OCA\Keepiq\Db\SecretMapper;
use OCA\Keepiq\Db\ShareTarget;
use OCA\Keepiq\Db\ShareTargetMapper;
use OCA\Keepiq\Exception\ForbiddenException;
use OCA\Keepiq\Exception\ShareScanPendingException;
use OCA\Keepiq\Exception\ViewOnlyCopyException;
use OCA\Keepiq\Service\DelegationAuthorizer;
use OCA\Keepiq\Service\DirectShareRegistrar;
use OCA\Keepiq\Service\GroupShareService;
use OCA\Keepiq\Service\NotificationService;
use OCA\Keepiq\Service\RecipientSecretCopyFactory;
use OCA\Keepiq\Service\SecretVersionService;
use OCA\Keepiq\Service\ShareAuthorizationService;
use OCA\Keepiq\Service\ShareQuarantineScanner;
use OCA\Keepiq\Service\ShareRevocationService;
use OCA\Keepiq\Service\ShareRowJudge;
use OCA\Keepiq\Service\ShareSync\ShareSyncGuards;
use OCA\Keepiq\Service\ShareSync\ShareWriteContext;
use OCA\Keepiq\Service\ShareSyncService;
use OCA\Keepiq\Service\ViewOnlyCopyGuard;
use OCP\AppFramework\Db\DoesNotExistException;
use OCP\IDBConnection;
use OCP\IGroupManager;
use OCP\Share\IManager as IShareManager;
use PHPUnit\Framework\Attributes\DataProvider;
use OCA\Keepiq\Tests\Support\ShareRowJudgeFixture;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use ReflectionMethod;

/**
 * Alice owns S_a, fully shared with a group Bob is in, and Bob is a
 * delegate of S_a. A row `{S_a, bob, V}` names Bob's own secret V as his
 * copy of it.
 */
class AuthorizationFollowsOnlyJudgedRowsTest extends TestCase {
	use ShareRowJudgeFixture;


	/**
	 * The secrets by id.
	 *
	 * @var array<string,Secret>
	 */
	private array $secrets = [];

	/**
	 * The row `{S_a, bob, V}`.
	 *
	 * @var ShareTarget
	 */
	private ShareTarget $row;

	/**
	 * Seed S_a, V and the row naming V.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		parent::setUp();
		$this->secrets = [];
		foreach (['S_a' => 'alice', 'V' => 'bob'] as $id => $ownerId) {
			$secret = new Secret();
			$secret->setId($id);
			$secret->setOwnerType('user');
			$secret->setOwnerId($ownerId);
			$this->secrets[$id] = $secret;
		}

		$this->row = new ShareTarget();
		$this->row->setId('row-V');
		$this->row->setSourceSecretId('S_a');
		$this->row->setTargetUserId('bob');
		$this->row->setSecretId('V');
	}//end setUp()

	/**
	 * The pending scan's verdicts that hold a row back.
	 *
	 * @return array<string,array{0:string}>
	 */
	public static function heldBackVerdicts(): array {
		return [
			'quarantined on the spot' => [ShareQuarantineScanner::HELD_QUARANTINED],
			'cannot be judged' => [ShareQuarantineScanner::HELD_UNJUDGED],
		];
	}//end heldBackVerdicts()

	/**
	 * A held-back row is no share to delegate on, gives no grade on S_a,
	 * and does not re-root Bob's shares of V onto S_a.
	 *
	 * @param string $verdict The scan's verdict on the row
	 *
	 * @return void
	 */
	#[DataProvider('heldBackVerdicts')]
	public function testAHeldBackRowIsNoShareToDelegateGradeOrReRootOn(string $verdict): void {
		$auth = $this->authorization(verdict: $verdict);

		$this->assertNull($auth->copyGrade(source: $this->secrets['S_a'], userId: 'bob'));
		$this->assertSame('V', $auth->resolveShareSource(secret: $this->secrets['V'], userId: 'bob')->getId());

		// A quarantined row is no share; one the scan cannot judge waits for it.
		$this->expectException(
			$verdict === ShareQuarantineScanner::HELD_UNJUDGED ? ShareScanPendingException::class : InvalidArgumentException::class
		);
		$this->delegations(verdict: $verdict)->requirePreExistingShare(secretId: 'S_a', userId: 'bob');
	}//end testAHeldBackRowIsNoShareToDelegateGradeOrReRootOn()

	/**
	 * A legitimate row is a share to delegate on, gives its grade, and
	 * re-roots a delegate's share onto its source, as before.
	 *
	 * @return void
	 */
	public function testALegitimateRowIsAShareToDelegateGradeAndReRootOn(): void {
		$auth = $this->authorization(verdict: null);

		$this->assertSame('write', $auth->copyGrade(source: $this->secrets['S_a'], userId: 'bob'));
		$this->assertSame('S_a', $auth->resolveShareSource(secret: $this->secrets['V'], userId: 'bob')->getId());

		$this->delegations(verdict: null)->requirePreExistingShare(secretId: 'S_a', userId: 'bob');
		$this->addToAssertionCount(1);
	}//end testALegitimateRowIsAShareToDelegateGradeAndReRootOn()

	/**
	 * Sharing S_a onward as its delegate needs Bob's own copy through a
	 * trusted row: through a row the scan quarantines Bob holds no copy, and
	 * through one it cannot judge the share waits for the scan.
	 *
	 * @return void
	 */
	public function testOnwardSharingNeedsATrustedOwnRow(): void {
		try {
			$this->authorization(verdict: ShareQuarantineScanner::HELD_QUARANTINED)->assertMayShareOnward(source: $this->secrets['S_a'], userId: 'bob');
			$this->fail('A quarantined row is no copy to share from');
		} catch (InvalidArgumentException $exception) {
			$this->assertSame('You hold no single copy of this secret to share it from', $exception->getMessage());
		}

		$this->expectException(ShareScanPendingException::class);
		$this->authorization(verdict: ShareQuarantineScanner::HELD_UNJUDGED)->assertMayShareOnward(source: $this->secrets['S_a'], userId: 'bob');
	}//end testOnwardSharingNeedsATrustedOwnRow()

	/**
	 * Bob's legitimate copy lets him share S_a onward as its delegate, as before.
	 *
	 * @return void
	 */
	public function testOnwardSharingThroughALegitimateRowIsAllowed(): void {
		$this->authorization(verdict: null)->assertMayShareOnward(source: $this->secrets['S_a'], userId: 'bob');
		$this->addToAssertionCount(1);
	}//end testOnwardSharingThroughALegitimateRowIsAllowed()

	/**
	 * A row the scan quarantines on the spot does not make V a copy: Bob may
	 * share it, sync it, link-share it and change its value.
	 *
	 * @return void
	 */
	public function testARowQuarantinedOnTheSpotDoesNotMakeVACopy(): void {
		$verdict = ShareQuarantineScanner::HELD_QUARANTINED;

		$this->assertNotContains($this->registrarRefusal(verdict: $verdict), ['not_shareable', 'share_scan_pending']);
		$this->assertFalse($this->isReceivedCopy(service: $this->groupShares(verdict: $verdict)));
		$this->syncStartsFrom(verdict: $verdict, userId: 'bob');
		$this->authorization(verdict: $verdict)->assertMayReshare(secretId: 'V', userId: 'bob');
		$this->viewOnlyGuard(verdict: $verdict, standing: false)->assertMayChangeValue(row: $this->secrets['V'], fields: ['key'], userId: 'bob');
		$this->addToAssertionCount(3);
	}//end testARowQuarantinedOnTheSpotDoesNotMakeVACopy()

	/**
	 * A row the scan cannot judge keeps every refusal of a copy in place
	 * until it has judged it, and never leads to S_a; Bob, who holds V, is
	 * told on every onward path that V waits for the scan.
	 *
	 * @return void
	 */
	public function testARowTheScanCannotJudgeKeepsTheRefusalsOfACopy(): void {
		$verdict = ShareQuarantineScanner::HELD_UNJUDGED;

		$this->assertSame('share_scan_pending', $this->registrarRefusal(verdict: $verdict));
		foreach ([
			'group share' => fn () => $this->isReceivedCopy(service: $this->groupShares(verdict: $verdict)),
			'link share' => fn () => $this->authorization(verdict: $verdict)->assertMayReshare(secretId: 'V', userId: 'bob'),
			'value change' => fn () => $this->viewOnlyGuard(verdict: $verdict, standing: true)->assertMayChangeValue(
				row: $this->secrets['V'],
				fields: ['key'],
				userId: 'bob'
			),
		] as $path => $onward) {
			try {
				$onward();
				$this->fail('The '.$path.' of a secret whose row the scan cannot judge must wait for the scan');
			} catch (ShareScanPendingException) {
				$this->addToAssertionCount(1);
			}
		}
	}//end testARowTheScanCannotJudgeKeepsTheRefusalsOfACopy()

	/**
	 * Someone who does not hold V still gets the unknown-id answer, not the
	 * wait for the scan.
	 *
	 * @return void
	 */
	public function testAStrangerIsNotToldTheSecretWaitsForTheScan(): void {
		$this->expectException(DoesNotExistException::class);
		$this->authorization(verdict: ShareQuarantineScanner::HELD_UNJUDGED)->assertMayReshare(secretId: 'V', userId: 'mallory');
	}//end testAStrangerIsNotToldTheSecretWaitsForTheScan()

	/**
	 * A legitimate copy is still a copy everywhere, as before.
	 *
	 * @return void
	 */
	public function testALegitimateCopyIsStillACopy(): void {
		$this->assertSame('not_shareable', $this->registrarRefusal(verdict: null));
		$this->assertTrue($this->isReceivedCopy(service: $this->groupShares(verdict: null)));

		$this->expectException(ViewOnlyCopyException::class);
		$this->viewOnlyGuard(verdict: null, standing: false)->assertMayChangeValue(row: $this->secrets['V'], fields: ['key'], userId: 'bob');
	}//end testALegitimateCopyIsStillACopy()

	/**
	 * A sync from V is refused as a sync from a copy unless the scan
	 * quarantines V's row. While the scan cannot judge the row, V's holder is
	 * told the sync waits for it; anyone else keeps the copy refusal.
	 *
	 * @return void
	 */
	public function testASyncFromVIsRefusedOnlyWhileItsRowIsACopy(): void {
		$this->syncStartsFrom(verdict: ShareQuarantineScanner::HELD_QUARANTINED, userId: 'bob');

		try {
			$this->syncStartsFrom(verdict: ShareQuarantineScanner::HELD_UNJUDGED, userId: 'bob');
			$this->fail("The holder's sync must wait for the scan");
		} catch (ShareScanPendingException) {
			$this->addToAssertionCount(1);
		}

		foreach ([[ShareQuarantineScanner::HELD_UNJUDGED, 'mallory'], [null, 'bob']] as [$verdict, $userId]) {
			try {
				$this->syncStartsFrom(verdict: $verdict, userId: $userId);
				$this->fail('A sync from a copy must be refused');
			} catch (ForbiddenException) {
				$this->addToAssertionCount(1);
			}
		}
	}//end testASyncFromVIsRefusedOnlyWhileItsRowIsACopy()

	/**
	 * A row naming Bob's V as Mallory's copy is foreign. While the scan cannot
	 * judge it, every check that refuses copies tells Bob, who holds V, that
	 * it waits for the scan, as for an unjudged row he holds; Mallory keeps the
	 * refusal of a copy, and a value change still saves Bob's own V.
	 *
	 * @return void
	 */
	public function testAForeignRowTheScanCannotJudgeTellsTheHolderItWaits(): void {
		$verdict = ShareQuarantineScanner::HELD_UNJUDGED;
		$this->row->setTargetUserId('mallory');

		$auth = $this->createMock(ShareAuthorizationService::class);
		$auth->method('loadSecret')->willReturn($this->secrets['V']);
		$sync = new ShareSyncService(
			secretMapper: $this->secretMapper(),
			db: $this->createMock(IDBConnection::class),
			auth: $auth,
			versionService: $this->createMock(SecretVersionService::class),
			guards: $this->createMock(ShareSyncGuards::class),
			contexts: $this->createMock(ShareWriteContext::class),
			rowJudge: $this->judge(verdict: $verdict),
		);

		$this->assertSame('share_scan_pending', $this->registrarRefusal(verdict: $verdict));
		foreach ([
			'sync' => fn () => $sync->syncUpdate(secretId: 'V', updates: [], expectedUpdatedAt: '', userId: 'bob'),
			'group share' => fn () => $this->isReceivedCopy(service: $this->groupShares(verdict: $verdict)),
			'link share' => fn () => $this->authorization(verdict: $verdict)->assertMayReshare(secretId: 'V', userId: 'bob'),
		] as $path => $onward) {
			try {
				$onward();
				$this->fail('The '.$path.' of a secret a foreign row the scan cannot judge names must wait for the scan');
			} catch (ShareScanPendingException) {
				$this->addToAssertionCount(1);
			}
		}

		$this->viewOnlyGuard(verdict: $verdict, standing: false)->assertMayChangeValue(row: $this->secrets['V'], fields: ['key'], userId: 'bob');

		$this->expectException(ForbiddenException::class);
		$this->syncStartsFrom(verdict: $verdict, userId: 'mallory');
	}//end testAForeignRowTheScanCannotJudgeTellsTheHolderItWaits()

	/**
	 * The secret mapper over the secrets.
	 *
	 * @return SecretMapper
	 */
	private function secretMapper(): SecretMapper {
		$mapper = $this->createMock(SecretMapper::class);
		$mapper->method('findById')->willReturnCallback(
			fn (string $id): Secret => ($this->secrets[$id] ?? throw new DoesNotExistException('none'))
		);

		return $mapper;
	}//end secretMapper()

	/**
	 * The share-target mapper over the one row.
	 *
	 * @return ShareTargetMapper
	 */
	private function targets(): ShareTargetMapper {
		$targets = $this->createMock(ShareTargetMapper::class);
		$targets->method('findByRecipientSecret')->willReturnCallback(
			fn (string $id): ShareTarget => ($id === 'V' ? $this->row : throw new DoesNotExistException('none'))
		);
		$targets->method('findBySourceSecretAndTargetUser')->willReturnCallback(
			fn (string $sourceId, string $targetUserId): ShareTarget => (
				$sourceId === 'S_a' && $targetUserId === 'bob' ? $this->row : throw new DoesNotExistException('none')
			)
		);
		$targets->method('findBySourceSecret')->willReturn([]);

		return $targets;
	}//end targets()

	/**
	 * The judge with a scanner answering the verdict for every row.
	 *
	 * @param string|null $verdict The verdict
	 *
	 * @return ShareRowJudge
	 */
	private function judge(?string $verdict): ShareRowJudge {
		$scanner = $this->createMock(ShareQuarantineScanner::class);
		$scanner->method('heldBack')->willReturn($verdict);

		return new ShareRowJudge(targets: $this->targets(), secrets: $this->secretMapper(), scanner: $scanner);
	}//end judge()

	/**
	 * The authorization service: S_a is fully shared with a group Bob is
	 * in, and Bob is a delegate of S_a.
	 *
	 * @param string|null $verdict The scan's verdict on the row
	 *
	 * @return ShareAuthorizationService
	 */
	private function authorization(?string $verdict): ShareAuthorizationService {
		$delegations = $this->createMock(SecretDelegationMapper::class);
		$delegations->method('findActiveBySecretAndUser')->willReturnCallback(
			static fn (string $secretId, string $userId): SecretDelegation => (
				$secretId === 'S_a' && $userId === 'bob' ? new SecretDelegation() : throw new DoesNotExistException('none')
			)
		);
		$groupShare = new GroupShare();
		$groupShare->setSecretId('S_a');
		$groupShare->setGroupId('team');
		$groupShare->setUseOnly(false);
		$groupShares = $this->createMock(GroupShareMapper::class);
		$groupShares->method('findBySecret')->willReturnCallback(
			static fn (string $secretId): array => ($secretId === 'S_a' ? [$groupShare] : [])
		);
		$groupManager = $this->createMock(IGroupManager::class);
		$groupManager->method('isInGroup')->willReturn(true);

		return new ShareAuthorizationService(
			secretMapper: $this->secretMapper(),
			delegationMapper: $delegations,
			groupShareMapper: $groupShares,
			groupManager: $groupManager,
			rowJudge: $this->judge(verdict: $verdict),
		);
	}//end authorization()

	/**
	 * The delegation authorizer.
	 *
	 * @param string|null $verdict The scan's verdict on the row
	 *
	 * @return DelegationAuthorizer
	 */
	private function delegations(?string $verdict): DelegationAuthorizer {
		return new DelegationAuthorizer(
			secretMapper: $this->secretMapper(),
			rowJudge: $this->judge(verdict: $verdict),
		);
	}//end delegations()

	/**
	 * The direct-share registrar.
	 *
	 * @param string|null $verdict The scan's verdict on the row
	 *
	 * @return DirectShareRegistrar
	 */
	private function registrar(?string $verdict): DirectShareRegistrar {
		return new DirectShareRegistrar(
			mapper: $this->targets(),
			secretMapper: $this->secretMapper(),
			copyFactory: $this->createMock(RecipientSecretCopyFactory::class),
			notificationService: $this->createMock(NotificationService::class),
			rowJudge: $this->judge(verdict: $verdict),
		);
	}//end registrar()

	/**
	 * The group-share service.
	 *
	 * @param string|null $verdict The scan's verdict on the row
	 *
	 * @return GroupShareService
	 */
	private function groupShares(?string $verdict): GroupShareService {
		return new GroupShareService(
			mapper: $this->createMock(GroupShareMapper::class),
			shareTargetMapper: $this->targets(),
			bulkGrantMapper: $this->createMock(BulkGrantShareTargetMapper::class),
			secretMapper: $this->secretMapper(),
			suiteMapper: $this->createMock(EncryptionSuiteMapper::class),
			delegationMapper: $this->createMock(SecretDelegationMapper::class),
			groupManager: $this->createMock(IGroupManager::class),
			notificationService: $this->createMock(NotificationService::class),
			logger: $this->createMock(LoggerInterface::class),
			shareManager: $this->createMock(IShareManager::class),
			revocationService: $this->createMock(ShareRevocationService::class),
			rowJudge: $this->judge(verdict: $verdict),
			quarantineScan: $this->clearedQuarantineScan(),
		);
	}//end groupShares()

	/**
	 * The view-only guard; Bob writes S_a for everyone when standing is true.
	 *
	 * @param string|null $verdict  The scan's verdict on the row
	 * @param bool        $standing Whether Bob writes S_a for everyone
	 *
	 * @return ViewOnlyCopyGuard
	 */
	private function viewOnlyGuard(?string $verdict, bool $standing): ViewOnlyCopyGuard {
		$contexts = $this->createMock(ShareWriteContext::class);
		$contexts->method('standingOn')->willReturn(['grade' => null, 'writesOn' => null]);
		$contexts->method('writesForEveryone')->willReturn($standing);

		return new ViewOnlyCopyGuard(
			secrets: $this->secretMapper(),
			contexts: $contexts,
			rowJudge: $this->judge(verdict: $verdict),
		);
	}//end viewOnlyGuard()

	/**
	 * Whether the group-share service's private check calls V a received copy.
	 *
	 * @param GroupShareService $service The service
	 *
	 * @return bool
	 */
	private function isReceivedCopy(GroupShareService $service): bool {
		return (bool)(new ReflectionMethod($service, 'isReceivedCopy'))->invoke($service, $this->secrets['V']);
	}//end isReceivedCopy()

	/**
	 * The status register-batch reports for a share of V by Bob, its holder.
	 *
	 * @param string|null $verdict The scan's verdict on the row
	 *
	 * @return string|null
	 */
	private function registrarRefusal(?string $verdict): ?string {
		$registrar = $this->registrar(verdict: $verdict);

		return (new ReflectionMethod($registrar, 'refusalFor'))->invoke($registrar, $this->secrets['V'], null, 'bob', []);
	}//end registrarRefusal()

	/**
	 * Run the sync's check that it starts from a source, not a copy, on V.
	 *
	 * @param string|null $verdict The scan's verdict on the row
	 * @param string      $userId  The user syncing
	 *
	 * @return void
	 */
	private function syncStartsFrom(?string $verdict, string $userId): void {
		$sync = new ShareSyncService(
			secretMapper: $this->secretMapper(),
			db: $this->createMock(IDBConnection::class),
			auth: $this->createMock(ShareAuthorizationService::class),
			versionService: $this->createMock(SecretVersionService::class),
			guards: $this->createMock(ShareSyncGuards::class),
			contexts: $this->createMock(ShareWriteContext::class),
			rowJudge: $this->judge(verdict: $verdict),
		);
		(new ReflectionMethod($sync, 'assertNotACopy'))->invoke($sync, $this->secrets['V'], $userId);
	}//end syncStartsFrom()
}//end class
