<?php

/**
 * The team-folder paths that follow a share row ask the pending quarantine
 * scan first. A confirmer whose own row the scan holds back has no copy to
 * hand on, so the pair is left out. A revoke keeps a row the scan
 * quarantines with its copy; one it cannot judge refuses a revoke that can be
 * refused, and is removed with its copy kept by one that cannot. A
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

use OCA\Keepiq\Db\BulkGrantShareTargetMapper;
use OCA\Keepiq\Db\EncryptionSuiteMapper;
use OCA\Keepiq\Db\Secret;
use OCA\Keepiq\Db\SecretDelegationMapper;
use OCA\Keepiq\Db\SecretMapper;
use OCA\Keepiq\Db\ShareTarget;
use OCA\Keepiq\Db\ShareTargetMapper;
use OCA\Keepiq\Db\TeamFolder;
use OCA\Keepiq\Db\TeamFolderMapper;
use OCA\Keepiq\Exception\ShareScanPendingException;
use OCA\Keepiq\Service\ConfirmerCopyResolver;
use OCA\Keepiq\Service\NotificationService;
use OCA\Keepiq\Service\RecipientSecretCopyService;
use OCA\Keepiq\Service\SecretOwnerTransferService;
use OCA\Keepiq\Service\SecretTypeService;
use OCA\Keepiq\Service\ShareQuarantineScanner;
use OCA\Keepiq\Service\ShareRowJudge;
use OCA\Keepiq\Service\TeamFolderMembershipResolver;
use OCA\Keepiq\Service\TeamFolderQueryService;
use OCA\Keepiq\Service\TeamFolderShareService;
use OCA\Keepiq\Service\TeamSecretTransferService;
use OCP\AppFramework\Db\DoesNotExistException;
use OCP\IDBConnection;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\MockObject\Rule\InvocationOrder;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

/**
 * Alice owns S_a in her team folder. A row `{S_a, bob, V}` names Bob's own
 * secret V as his copy of it.
 */
class TeamFolderFollowsOnlyJudgedRowsTest extends TestCase {

	/**
	 * The secrets by id.
	 *
	 * @var array<string,Secret>
	 */
	private array $secrets = [];

	/**
	 * The ids of the secrets deleted.
	 *
	 * @var list<string>
	 */
	private array $deletedSecrets = [];

	/**
	 * The ids of the share rows deleted.
	 *
	 * @var list<string>
	 */
	private array $deletedRows = [];

	/**
	 * The row `{S_a, bob, V}`.
	 *
	 * @var ShareTarget
	 */
	private ShareTarget $row;

	/**
	 * The team-folder share service's logger.
	 *
	 * @var LoggerInterface&MockObject
	 */
	private LoggerInterface&MockObject $tfLogger;

	/**
	 * Seed S_a, V and the row naming V.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		parent::setUp();
		$this->secrets        = [];
		$this->deletedSecrets = [];
		$this->deletedRows    = [];
		$this->tfLogger       = $this->createMock(LoggerInterface::class);
		$this->secret(id: 'S_a', ownerId: 'alice');
		$this->secret(id: 'V', ownerId: 'bob');

		$this->row = new ShareTarget();
		$this->row->setId('row-V');
		$this->row->setSourceSecretId('S_a');
		$this->row->setTargetUserId('bob');
		$this->row->setSecretId('V');
		$this->row->setTeamFolderId('tf-1');
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
	 * A confirmer with a write grade whose own row the pending scan holds
	 * back has no copy to hand on: the pair is left out.
	 *
	 * @param string $verdict The scan's verdict on the row
	 *
	 * @return void
	 */
	#[DataProvider('heldBackVerdicts')]
	public function testAConfirmersHeldBackRowHasNoCopyToHandOn(string $verdict): void {
		$this->assertNull($this->resolver(verdict: $verdict)->currentWriteCopy(sourceId: 'S_a', confirmerId: 'bob'));
	}//end testAConfirmersHeldBackRowHasNoCopyToHandOn()

	/**
	 * A confirmer's legitimate copy, cleared by the scan, is handed on as before.
	 *
	 * @return void
	 */
	public function testAConfirmersLegitimateCopyIsHandedOn(): void {
		$this->assertSame('V', $this->resolver(verdict: null)->currentWriteCopy(sourceId: 'S_a', confirmerId: 'bob'));
	}//end testAConfirmersLegitimateCopyIsHandedOn()

	/**
	 * Every team-folder revoke keeps a row the pending scan quarantines on
	 * the spot, with its copy, for an administrator to judge.
	 *
	 * @return void
	 */
	public function testEveryRevokeKeepsAQuarantinedRowAndItsCopy(): void {
		$shares = $this->shares(verdict: ShareQuarantineScanner::HELD_QUARANTINED);

		$shares->assertRevocable(teamFolderId: 'tf-1', targetUserId: 'bob');
		$this->assertSame(0, $shares->revokeForMember(teamFolderId: 'tf-1', targetUserId: 'bob'));
		$this->assertSame(0, $shares->revokeForTeamFolder(teamFolderId: 'tf-1'));
		$this->assertSame(0, $shares->releaseForMember(teamFolderId: 'tf-1', targetUserId: 'bob'));
		$this->assertSame(0, $shares->revokeTeamSharesForUser(targetUserId: 'bob'));

		$this->assertSame([], $this->deletedRows);
		$this->assertSame([], $this->deletedSecrets);
	}//end testEveryRevokeKeepsAQuarantinedRowAndItsCopy()

	/**
	 * An owner removing a member or unsharing the folder waits for a row the
	 * pending scan cannot judge, and changes nothing.
	 *
	 * @return void
	 */
	public function testARefusableRevokeWaitsForAnUnjudgedRow(): void {
		$shares = $this->shares(verdict: ShareQuarantineScanner::HELD_UNJUDGED);

		foreach ([
			fn () => $shares->assertRevocable(teamFolderId: 'tf-1', targetUserId: 'bob'),
			fn () => $shares->assertRevocable(teamFolderId: 'tf-1'),
			fn () => $shares->revokeForMember(teamFolderId: 'tf-1', targetUserId: 'bob'),
			fn () => $shares->revokeForTeamFolder(teamFolderId: 'tf-1'),
		] as $revoke) {
			try {
				$revoke();
				$this->fail('A revoke that reaches an unjudged row must wait for the scan');
			} catch (ShareScanPendingException) {
				$this->addToAssertionCount(1);
			}
		}

		$this->assertSame([], $this->deletedRows);
		$this->assertSame([], $this->deletedSecrets);
	}//end testARefusableRevokeWaitsForAnUnjudgedRow()

	/**
	 * A member leaving a group or the folder, a membership ending and
	 * offboarding cannot wait: an unjudged row is removed and its copy kept.
	 *
	 * @return void
	 */
	public function testARevokeThatCannotWaitDropsAnUnjudgedRowAndKeepsItsCopy(): void {
		$shares = $this->shares(verdict: ShareQuarantineScanner::HELD_UNJUDGED);
		$this->tfLogger->expects($this->exactly(2))->method('warning')->with(
			$this->anything(),
			$this->callback(
				static fn (array $context): bool => $context['shareId'] === 'row-V' && $context['sourceSecretId'] === 'S_a'
					&& $context['targetUserId'] === 'bob' && $context['secretId'] === 'V'
			)
		);

		$this->assertSame(1, $shares->releaseForMember(teamFolderId: 'tf-1', targetUserId: 'bob'));
		$this->assertSame(1, $shares->revokeTeamSharesForUser(targetUserId: 'bob'));

		$this->assertSame(['row-V', 'row-V'], $this->deletedRows);
		$this->assertSame([], $this->deletedSecrets);
	}//end testARevokeThatCannotWaitDropsAnUnjudgedRowAndKeepsItsCopy()

	/**
	 * A legitimate row's copy goes with the row, as before, on every revoke.
	 *
	 * @return void
	 */
	public function testRevokingALegitimateRowDeletesItsCopy(): void {
		$shares = $this->shares(verdict: null);
		$shares->assertRevocable(teamFolderId: 'tf-1');
		$shares->revokeForMember(teamFolderId: 'tf-1', targetUserId: 'bob');
		$shares->releaseForMember(teamFolderId: 'tf-1', targetUserId: 'bob');

		$this->assertSame(['row-V', 'row-V'], $this->deletedRows);
		$this->assertSame(['V', 'V'], $this->deletedSecrets);
	}//end testRevokingALegitimateRowDeletesItsCopy()

	/**
	 * Offboarding Alice to Bob delegates S_a to Bob only through his own
	 * trusted copy: a row the scan holds back is no copy, so S_a is skipped.
	 *
	 * @param string $verdict The scan's verdict on the row
	 *
	 * @return void
	 */
	#[DataProvider('heldBackVerdicts')]
	public function testAHandOverSkipsASecretTheSuccessorHoldsOnlyThroughAHeldBackRow(string $verdict): void {
		$result = $this->transfers(verdict: $verdict, delegations: $this->never())->transfer(
			leavingUserId: 'alice',
			successorUserId: 'bob',
			adminId: 'admin'
		);

		$this->assertSame(0, $result['transferred']);
		$this->assertSame(['S_a'], $result['skipped']);
	}//end testAHandOverSkipsASecretTheSuccessorHoldsOnlyThroughAHeldBackRow()

	/**
	 * A successor's legitimate copy is promoted on hand-over, as before.
	 *
	 * @return void
	 */
	public function testAHandOverPromotesTheSuccessorsLegitimateCopy(): void {
		$result = $this->transfers(verdict: null, delegations: $this->once())->transfer(
			leavingUserId: 'alice',
			successorUserId: 'bob',
			adminId: 'admin'
		);

		$this->assertSame(1, $result['transferred']);
		$this->assertSame([], $result['skipped']);
	}//end testAHandOverPromotesTheSuccessorsLegitimateCopy()

	/**
	 * The team-secret transfer of Alice's folder holding S_a.
	 *
	 * @param string|null     $verdict     The scan's verdict on Bob's row
	 * @param InvocationOrder $delegations How often a delegation is inserted
	 *
	 * @return TeamSecretTransferService
	 */
	private function transfers(?string $verdict, InvocationOrder $delegations): TeamSecretTransferService {
		$folders = $this->createMock(TeamFolderMapper::class);
		$folders->method('findByOwner')->willReturn([new TeamFolder()]);
		$memberships = $this->createMock(TeamFolderMembershipResolver::class);
		$memberships->method('subtreeSecretRefs')->willReturn([['id' => 'S_a', 'name' => 'S_a']]);
		$delegationMapper = $this->createMock(SecretDelegationMapper::class);
		$delegationMapper->expects($delegations)->method('insert')->willReturnArgument(0);
		$secrets = $this->secretMapper();

		return new TeamSecretTransferService(
			mapper: $folders,
			memberships: $memberships,
			rowJudge: $this->judge(targets: $this->targets(), secrets: $secrets, verdict: $verdict),
			delegationMapper: $delegationMapper,
			ownerTransfer: $this->createMock(SecretOwnerTransferService::class),
		);
	}//end transfers()

	/**
	 * Add a user-owned secret.
	 *
	 * @param string $id      The id
	 * @param string $ownerId The owner
	 *
	 * @return void
	 */
	private function secret(string $id, string $ownerId): void {
		$secret = new Secret();
		$secret->setId($id);
		$secret->setOwnerType('user');
		$secret->setOwnerId($ownerId);
		$this->secrets[$id] = $secret;
	}//end secret()

	/**
	 * The secret mapper over the secrets, recording deletes.
	 *
	 * @return SecretMapper
	 */
	private function secretMapper(): SecretMapper {
		$mapper = $this->createMock(SecretMapper::class);
		$mapper->method('findById')->willReturnCallback(
			function (string $id): Secret {
				if (isset($this->secrets[$id]) === false) {
					throw new DoesNotExistException('none');
				}

				return $this->secrets[$id];
			}
		);
		$mapper->method('delete')->willReturnCallback(
			function (Secret $secret): Secret {
				$this->deletedSecrets[] = $secret->getId();
				return $secret;
			}
		);

		return $mapper;
	}//end secretMapper()

	/**
	 * The share-target mapper over the one row, recording deletes.
	 *
	 * @return ShareTargetMapper
	 */
	private function targets(): ShareTargetMapper {
		$targets = $this->createMock(ShareTargetMapper::class);
		$targets->method('findBySourceSecretAndTargetUser')->willReturn($this->row);
		$targets->method('findByTargetUser')->willReturn([$this->row]);
		$targets->method('delete')->willReturnCallback(
			function (ShareTarget $row): ShareTarget {
				$this->deletedRows[] = (string)$row->getId();
				return $row;
			}
		);

		return $targets;
	}//end targets()

	/**
	 * The judge over the given collaborators and a scanner answering the verdict.
	 *
	 * @param ShareTargetMapper $targets The share targets
	 * @param SecretMapper      $secrets The secrets
	 * @param string|null       $verdict The scan's verdict on every row
	 *
	 * @return ShareRowJudge
	 */
	private function judge(ShareTargetMapper $targets, SecretMapper $secrets, ?string $verdict): ShareRowJudge {
		$scanner = $this->createMock(ShareQuarantineScanner::class);
		$scanner->method('heldBack')->willReturn($verdict);

		return new ShareRowJudge(targets: $targets, secrets: $secrets, scanner: $scanner);
	}//end judge()

	/**
	 * The confirmer-copy resolver; Bob has a write grade on S_a.
	 *
	 * @param string|null $verdict The scan's verdict on Bob's row
	 *
	 * @return ConfirmerCopyResolver
	 */
	private function resolver(?string $verdict): ConfirmerCopyResolver {
		$queries = $this->createMock(TeamFolderQueryService::class);
		$queries->method('resolveGrade')->willReturn('write');
		$secrets = $this->secretMapper();

		return new ConfirmerCopyResolver(
			queries: $queries,
			rowJudge: $this->judge(targets: $this->targets(), secrets: $secrets, verdict: $verdict),
			secretMapper: $secrets,
		);
	}//end resolver()

	/**
	 * The team-folder share service over the one row.
	 *
	 * @param string|null $verdict The scan's verdict on the row
	 *
	 * @return TeamFolderShareService
	 */
	private function shares(?string $verdict): TeamFolderShareService {
		$secrets = $this->secretMapper();
		$targets = $this->targets();
		$bulk    = $this->createMock(BulkGrantShareTargetMapper::class);
		$bulk->method('findByTeamFolderAndTargetUser')->willReturn([$this->row]);
		$bulk->method('findByTeamFolder')->willReturn([$this->row]);

		return new TeamFolderShareService(
			shareTargetMapper: $targets,
			bulkGrantMapper: $bulk,
			copies: new RecipientSecretCopyService(
				secretMapper: $secrets,
				suiteMapper: $this->createMock(EncryptionSuiteMapper::class),
				typeService: $this->createMock(SecretTypeService::class),
			),
			notificationService: $this->createMock(NotificationService::class),
			db: $this->createMock(IDBConnection::class),
			logger: $this->tfLogger,
			rowJudge: $this->judge(targets: $targets, secrets: $secrets, verdict: $verdict));
	}//end shares()
}//end class
