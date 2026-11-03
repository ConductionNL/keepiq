<?php

/**
 * A copy holder's save goes to the copy's source only through a share row its
 * target holds and the pending quarantine scan does not hold back. Otherwise
 * the write context names no source and no owner certificate, and share-save
 * saves the caller's own row, or waits for the scan when it cannot judge the
 * row. A legitimate copy still saves for everyone as before.
 *
 * @category Test
 * @package  OCA\Keepiq\Tests\Unit\Service\ShareSave
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

namespace OCA\Keepiq\Tests\Unit\Service\ShareSave;

use InvalidArgumentException;
use OCA\Keepiq\Db\EncryptionSuite;
use OCA\Keepiq\Db\EncryptionSuiteMapper;
use OCA\Keepiq\Db\FederatedShareMapper;
use OCA\Keepiq\Db\GroupShare;
use OCA\Keepiq\Db\GroupShareMapper;
use OCA\Keepiq\Db\Secret;
use OCA\Keepiq\Db\SecretDelegationMapper;
use OCA\Keepiq\Db\SecretMapper;
use OCA\Keepiq\Db\ShareTarget;
use OCA\Keepiq\Db\ShareTargetMapper;
use OCA\Keepiq\Exception\ShareScanPendingException;
use OCA\Keepiq\Service\SecretVersionService;
use OCA\Keepiq\Service\ShareAuthorizationService;
use OCA\Keepiq\Service\ShareQuarantineScanner;
use OCA\Keepiq\Service\ShareRowJudge;
use OCA\Keepiq\Service\ShareSave\ShareSaveRefusal;
use OCA\Keepiq\Service\ShareSave\ShareSaveScope;
use OCA\Keepiq\Service\ShareSync\ShareSyncGuards;
use OCA\Keepiq\Service\ShareSync\ShareWriteContext;
use OCA\Keepiq\Service\ShareSyncService;
use OCP\AppFramework\Db\DoesNotExistException;
use OCP\AppFramework\Http;
use OCP\IDBConnection;
use OCP\IGroupManager;
use PHPUnit\Framework\Attributes\DataProvider;
use OCA\Keepiq\Tests\Support\ShareRowJudgeFixture;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

/**
 * Alice owns S_a and shares it with a group Bob is in. A row
 * `{S_a, bob, V}` names Bob's own secret V as his copy.
 */
class CopySaveFollowsOnlyAJudgedRowTest extends TestCase {
	use ShareRowJudgeFixture;

	/**
	 * The secrets by id.
	 *
	 * @var array<string,Secret>
	 */
	private array $secrets = [];

	/**
	 * The share rows.
	 *
	 * @var list<ShareTarget>
	 */
	private array $rows = [];

	/**
	 * Seed S_a, owned by Alice, and V, owned by Bob.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		parent::setUp();
		$this->secrets = [];
		$this->rows    = [];
		$this->secret(id: 'S_a', ownerId: 'alice');
		$this->secret(id: 'V', ownerId: 'bob');
	}//end setUp()

	/**
	 * Add a user-owned secret.
	 *
	 * @param string $id      The id
	 * @param string $ownerId The owner
	 *
	 * @return Secret
	 */
	private function secret(string $id, string $ownerId): Secret {
		$secret = new Secret();
		$secret->setId($id);
		$secret->setOwnerType('user');
		$secret->setOwnerId($ownerId);
		$secret->resetUpdatedFields();
		$this->secrets[$id] = $secret;

		return $secret;
	}//end secret()

	/**
	 * Add a live share row, of S_a unless another source is named.
	 *
	 * @param string $target The row's target
	 * @param string $copyId The copy it names
	 * @param string $source The row's source
	 *
	 * @return ShareTarget
	 */
	private function row(string $target, string $copyId, string $source = 'S_a'): ShareTarget {
		$row = new ShareTarget();
		$row->setId('row-'.$copyId);
		$row->setSourceSecretId($source);
		$row->setTargetUserId($target);
		$row->setSecretId($copyId);
		$this->rows[] = $row;

		return $row;
	}//end row()

	/**
	 * The share-target mapper over the rows.
	 *
	 * @return ShareTargetMapper
	 */
	private function targets(): ShareTargetMapper {
		$targets = $this->createMock(ShareTargetMapper::class);
		$targets->method('findByRecipientSecret')->willReturnCallback(
			function (string $recipientSecretId): ShareTarget {
				foreach ($this->rows as $row) {
					if ($row->getSecretId() === $recipientSecretId) {
						return $row;
					}
				}

				throw new DoesNotExistException('none');
			}
		);
		$targets->method('findBySourceSecretAndTargetUser')->willReturnCallback(
			function (string $sourceSecretId, string $targetUserId): ShareTarget {
				foreach ($this->rows as $row) {
					if ($row->getSourceSecretId() === $sourceSecretId && $row->getTargetUserId() === $targetUserId) {
						return $row;
					}
				}

				throw new DoesNotExistException('none');
			}
		);
		$targets->method('findBySourceSecret')->willReturnCallback(
			fn (string $sourceSecretId): array => array_values(
				array_filter($this->rows, static fn (ShareTarget $row): bool => $row->getSourceSecretId() === $sourceSecretId)
			)
		);

		return $targets;
	}//end targets()

	/**
	 * Whether the secret mapper may write; a test that writes sets it.
	 *
	 * @var bool
	 */
	private bool $mayWrite = false;

	/**
	 * The ids of the secrets written, when the mapper may write.
	 *
	 * @var list<string>
	 */
	private array $written = [];

	/**
	 * The database connection the sync's transaction runs on.
	 *
	 * @var IDBConnection&MockObject
	 */
	private IDBConnection&MockObject $db;

	/**
	 * The secret mapper over the secrets; it never writes unless the test allows it.
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
		$mapper->method('findByIds')->willReturnCallback(
			fn (array $ids): array => array_intersect_key($this->secrets, array_flip($ids))
		);
		if ($this->mayWrite === false) {
			$mapper->expects($this->never())->method('update');
		}

		$mapper->method('update')->willReturnCallback(
			function (Secret $secret): Secret {
				$this->written[] = $secret->getId();
				return $secret;
			}
		);

		return $mapper;
	}//end secretMapper()

	/**
	 * Every owner has an active suite whose certificate names them.
	 *
	 * @return EncryptionSuiteMapper
	 */
	private function suites(): EncryptionSuiteMapper {
		$suites = $this->createMock(EncryptionSuiteMapper::class);
		$suites->method('findActiveByOwner')->willReturnCallback(
			static function (string $ownerType, string $ownerId): EncryptionSuite {
				$suite = new EncryptionSuite();
				$suite->setCertificate('CERT-'.$ownerId);
				return $suite;
			}
		);
		$suites->method('countActiveByOwner')->willReturn(1);

		return $suites;
	}//end suites()

	/**
	 * The real authorization service: S_a is fully shared with a group
	 * everyone is in, and nobody holds a delegation.
	 *
	 * @param ShareTargetMapper $targets The share targets
	 * @param SecretMapper      $secrets The secrets
	 *
	 * @return ShareAuthorizationService
	 */
	private function authorization(ShareTargetMapper $targets, SecretMapper $secrets): ShareAuthorizationService {
		$delegations = $this->createMock(SecretDelegationMapper::class);
		$delegations->method('findActiveBySecretAndUser')->willThrowException(new DoesNotExistException('none'));

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
			secretMapper: $secrets,
			delegationMapper: $delegations,
			rowJudge: $this->shareRowJudge(targets: $targets, secrets: $secrets),
			groupShareMapper: $groupShares,
			groupManager: $groupManager,
		);
	}//end authorization()

	/**
	 * A scanner whose heldBack() answers the given verdict, or none at all.
	 *
	 * @param string|null|false $verdict The verdict, or false for no scanner
	 *
	 * @return ShareQuarantineScanner|null
	 */
	private function scanner(string|null|false $verdict): ?ShareQuarantineScanner {
		if ($verdict === false) {
			return null;
		}

		$scanner = $this->createMock(ShareQuarantineScanner::class);
		$scanner->method('heldBack')->willReturn($verdict);

		return $scanner;
	}//end scanner()

	/**
	 * A scanner whose heldBack() answers per row id, and null for any other row.
	 *
	 * @param array<string,string> $verdicts The verdicts by row id
	 *
	 * @return ShareQuarantineScanner
	 */
	private function scannerFor(array $verdicts): ShareQuarantineScanner {
		$scanner = $this->createMock(ShareQuarantineScanner::class);
		$scanner->method('heldBack')->willReturnCallback(
			static fn (ShareTarget $row): ?string => ($verdicts[(string)$row->getId()] ?? null)
		);

		return $scanner;
	}//end scannerFor()

	/**
	 * The write context, the share-save scope, the guards and the sync on the
	 * same collaborators.
	 *
	 * @param ShareQuarantineScanner|null $scanner The scanner
	 *
	 * @return array{0:ShareWriteContext,1:ShareSaveScope,2:ShareSyncGuards,3:ShareSyncService}
	 */
	private function services(?ShareQuarantineScanner $scanner): array {
		$this->db = $this->createMock(IDBConnection::class);
		$targets  = $this->targets();
		$secrets  = $this->secretMapper();
		$suites   = $this->suites();
		$auth     = $this->authorization(targets: $targets, secrets: $secrets);
		$judge    = new ShareRowJudge(targets: $targets, secrets: $secrets, scanner: ($scanner ?? $this->createMock(ShareQuarantineScanner::class)));
		$contexts = new ShareWriteContext(suites: $suites, auth: $auth, rowJudge: $judge);
		$guards   = new ShareSyncGuards(secrets: $secrets, suites: $suites, auth: $auth, rowJudge: $judge);
		$sync     = new ShareSyncService(
			secretMapper: $secrets,
			db: $this->db,
			auth: $auth,
			versionService: $this->createMock(SecretVersionService::class),
			guards: $guards,
			contexts: $contexts,
			rowJudge: $judge,
		);
		$federated = $this->createMock(FederatedShareMapper::class);
		$federated->method('findBySourceSecret')->willReturn([]);

		return [
			$contexts,
			new ShareSaveScope(
				secrets: $secrets,
				federatedShares: $federated,
				sync: $sync,
				guards: $guards,
				rowJudge: $judge,
			),
			$guards,
			$sync,
		];
	}//end services()

	/**
	 * The scanner's verdicts on a legitimate copy that let it through: no
	 * scanner wired, the scan completed, or a pending scan that clears it.
	 *
	 * @return array<string,array{0:string|null|false}>
	 */
	public static function clearingScans(): array {
		return [
			'no scanner' => [false],
			'cleared' => [null],
		];
	}//end clearingScans()

	/**
	 * While the pending scan cannot judge the row naming V, Bob's write
	 * context of V names neither S_a nor Alice's certificate, and a
	 * share-save of V waits for the scan, so neither V nor S_a is written.
	 *
	 * @return void
	 */
	public function testAnUnjudgedRowLeadsTheHoldersSaveNowhere(): void {
		$this->row(target: 'bob', copyId: 'V');
		[$contexts, $scope] = $this->services(scanner: $this->scanner(verdict: ShareQuarantineScanner::HELD_UNJUDGED));

		$context = $contexts->writeContext(secretId: 'V', userId: 'bob');
		$this->assertNull($context['sourceSecretId']);
		$this->assertNull($context['ownerCertificate']);
		$this->assertSame('none', $context['effectiveGrade']);

		try {
			$scope->resolve(row: $this->secrets['V'], userId: 'bob');
			$this->fail('A share-save through an unjudged row must wait for the scan');
		} catch (ShareSaveRefusal $refusal) {
			$this->assertSame('share_scan_pending', $refusal->getBody()['error']);
			$this->assertSame(Http::STATUS_CONFLICT, $refusal->getStatus());
		}

		$this->assertSame([], $this->secrets['S_a']->getUpdatedFields());
		$this->assertSame([], $this->secrets['V']->getUpdatedFields());
	}//end testAnUnjudgedRowLeadsTheHoldersSaveNowhere()

	/**
	 * A row the pending scan quarantines on the spot is no copy: V is its own
	 * source, as with any quarantined row.
	 *
	 * @return void
	 */
	public function testARowQuarantinedOnTheSpotIsNoCopy(): void {
		$this->row(target: 'bob', copyId: 'V');
		[$contexts, $scope] = $this->services(scanner: $this->scanner(verdict: ShareQuarantineScanner::HELD_QUARANTINED));

		$context = $contexts->writeContext(secretId: 'V', userId: 'bob');
		$this->assertSame('V', $context['sourceSecretId']);
		$this->assertSame('owner', $context['effectiveGrade']);
		$this->assertSame('CERT-bob', $context['ownerCertificate']);

		$path = $scope->resolve(row: $this->secrets['V'], userId: 'bob');
		$this->assertFalse($path->isCopyEditor());
		$this->assertSame('V', $path->source->getId());
	}//end testARowQuarantinedOnTheSpotIsNoCopy()

	/**
	 * A row whose target does not hold V never leads Bob's save to S_a,
	 * whatever the scan says, even when Bob writes S_a for everyone
	 * through a copy of their own.
	 *
	 * @param string|null|false $verdict The scanner's verdict
	 *
	 * @return void
	 */
	#[DataProvider('clearingScans')]
	public function testARowWhoseTargetDoesNotHoldTheCopyNeverPivots(string|null|false $verdict): void {
		$this->secret(id: 'C_v', ownerId: 'bob');
		$this->row(target: 'bob', copyId: 'C_v');
		$this->row(target: 'someone-else', copyId: 'V');
		[$contexts, $scope] = $this->services(scanner: $this->scanner(verdict: $verdict));

		$context = $contexts->writeContext(secretId: 'V', userId: 'bob');
		$this->assertNull($context['sourceSecretId']);
		$this->assertNull($context['ownerCertificate']);

		$path = $scope->resolve(row: $this->secrets['V'], userId: 'bob');
		$this->assertFalse($path->isCopyEditor());
		$this->assertSame('V', $path->source->getId());
	}//end testARowWhoseTargetDoesNotHoldTheCopyNeverPivots()

	/**
	 * A legitimate copy, held by its target and cleared by the scan (or with
	 * no scanner wired), still saves for everyone through its source.
	 *
	 * @param string|null|false $verdict The scanner's verdict
	 *
	 * @return void
	 */
	#[DataProvider('clearingScans')]
	public function testALegitimateCopyStillSavesForEveryone(string|null|false $verdict): void {
		$this->secret(id: 'C_r', ownerId: 'recipient');
		$this->row(target: 'recipient', copyId: 'C_r');
		[$contexts, $scope] = $this->services(scanner: $this->scanner(verdict: $verdict));

		$context = $contexts->writeContext(secretId: 'C_r', userId: 'recipient');
		$this->assertSame('S_a', $context['sourceSecretId']);
		$this->assertSame('write', $context['effectiveGrade']);
		$this->assertSame('CERT-alice', $context['ownerCertificate']);

		$path = $scope->resolve(row: $this->secrets['C_r'], userId: 'recipient');
		$this->assertTrue($path->isCopyEditor());
		$this->assertSame('S_a', $path->source->getId());
		$this->assertSame(ShareSyncService::GROUP_WRITE, $path->writesOn);
	}//end testALegitimateCopyStillSavesForEveryone()

	/**
	 * While the pending scan cannot judge the row naming V, and V is itself
	 * shared, Bob's write context of V tells him it waits for the scan, as
	 * share-save does, rather than answering as an unknown id.
	 *
	 * @return void
	 */
	public function testAnUnjudgedRowOnASharedSecretAnswersScanPending(): void {
		$this->secret(id: 'C_c', ownerId: 'carol');
		$this->row(target: 'bob', copyId: 'V');
		$this->row(target: 'carol', copyId: 'C_c', source: 'V');
		[$contexts] = $this->services(scanner: $this->scannerFor(verdicts: ['row-V' => ShareQuarantineScanner::HELD_UNJUDGED]));

		$this->expectException(ShareScanPendingException::class);
		$contexts->writeContext(secretId: 'V', userId: 'bob');
	}//end testAnUnjudgedRowOnASharedSecretAnswersScanPending()

	/**
	 * A shared V whose row the pending scan quarantines on the spot is Bob's
	 * own source, also when the row's target does not hold V; a row its
	 * target does not hold that the scan clears still answers as an unknown
	 * id, as before.
	 *
	 * @return void
	 */
	public function testASharedSecretWithAQuarantinedOrForeignRowAnswersAsBefore(): void {
		$this->secret(id: 'C_c', ownerId: 'carol');
		$this->row(target: 'bob', copyId: 'V');
		$this->row(target: 'carol', copyId: 'C_c', source: 'V');
		[$contexts] = $this->services(scanner: $this->scannerFor(verdicts: ['row-V' => ShareQuarantineScanner::HELD_QUARANTINED]));

		$context = $contexts->writeContext(secretId: 'V', userId: 'bob');
		$this->assertSame('V', $context['sourceSecretId']);
		$this->assertSame('owner', $context['effectiveGrade']);

		$this->rows[0]->setTargetUserId('someone-else');
		$this->assertSame('V', $contexts->writeContext(secretId: 'V', userId: 'bob')['sourceSecretId']);

		[$clearing] = $this->services(scanner: $this->scannerFor(verdicts: []));
		$this->expectException(InvalidArgumentException::class);
		$clearing->writeContext(secretId: 'V', userId: 'bob');
	}//end testASharedSecretWithAQuarantinedOrForeignRowAnswersAsBefore()

	/**
	 * A group member's /sync of S_a that reaches a copy whose row the pending
	 * scan cannot judge waits for the scan, as share-save does, so that copy
	 * is not left on the old value.
	 *
	 * @return void
	 */
	public function testAGroupSyncWaitsForAnUnjudgedCopy(): void {
		$this->secret(id: 'C_r', ownerId: 'recipient');
		$this->secret(id: 'C_x', ownerId: 'xavier');
		$this->row(target: 'recipient', copyId: 'C_r');
		$this->row(target: 'xavier', copyId: 'C_x');
		[, , $guards, $sync] = $this->services(scanner: $this->scannerFor(verdicts: ['row-C_x' => ShareQuarantineScanner::HELD_UNJUDGED]));

		$this->expectException(ShareScanPendingException::class);
		$guards->assertGroupWrite(sync: $sync, source: $this->secrets['S_a'], updates: $this->groupUpdates(), userId: 'recipient');
	}//end testAGroupSyncWaitsForAnUnjudgedCopy()

	/**
	 * A group member's /sync of S_a whose copies the scan clears writes the
	 * source and every copy, as before.
	 *
	 * @return void
	 */
	public function testAGroupSyncOfClearedCopiesWritesEveryCopy(): void {
		$this->secret(id: 'C_r', ownerId: 'recipient');
		$this->secret(id: 'C_x', ownerId: 'xavier');
		$this->row(target: 'recipient', copyId: 'C_r');
		$this->row(target: 'xavier', copyId: 'C_x');
		[, , $guards, $sync] = $this->services(scanner: $this->scannerFor(verdicts: []));

		[$writesOn, $ids] = $guards->assertGroupWrite(sync: $sync, source: $this->secrets['S_a'], updates: $this->groupUpdates(), userId: 'recipient');

		$this->assertSame(ShareSyncService::GROUP_WRITE, $writesOn);
		$this->assertSame(['S_a' => true, 'C_r' => true, 'C_x' => true], $ids);
	}//end testAGroupSyncOfClearedCopiesWritesEveryCopy()

	/**
	 * The owner's /sync of S_a that names a copy whose row the pending scan
	 * cannot judge waits for the scan and writes nothing.
	 *
	 * @return void
	 */
	public function testAnOwnerSyncNamingAnUnjudgedCopyWaitsForTheScan(): void {
		$this->secret(id: 'C_x', ownerId: 'xavier');
		$this->row(target: 'xavier', copyId: 'C_x');
		[, , , $sync] = $this->services(scanner: $this->scannerFor(verdicts: ['row-C_x' => ShareQuarantineScanner::HELD_UNJUDGED]));

		$this->expectException(ShareScanPendingException::class);
		$sync->syncUpdate(secretId: 'S_a', updates: [['secretId' => 'C_x', 'key' => 'k-x']], expectedUpdatedAt: '', userId: 'alice');
	}//end testAnOwnerSyncNamingAnUnjudgedCopyWaitsForTheScan()

	/**
	 * Copies the scan clears, or that a sync does not name, do not hold the
	 * owner's sync back.
	 *
	 * @return void
	 */
	public function testAnOwnerSyncOfClearedCopiesIsNotHeldBack(): void {
		$this->secret(id: 'C_r', ownerId: 'recipient');
		$this->secret(id: 'C_x', ownerId: 'xavier');
		$this->row(target: 'recipient', copyId: 'C_r');
		$this->row(target: 'xavier', copyId: 'C_x');
		[, , $guards] = $this->services(scanner: $this->scannerFor(verdicts: ['row-C_x' => ShareQuarantineScanner::HELD_UNJUDGED]));

		$guards->assertCopiesJudged(
			source: $this->secrets['S_a'],
			updates: [['secretId' => 'C_r', 'key' => 'k-r']],
			writesOn: null,
			judged: $guards->judgedRowsOf(source: $this->secrets['S_a'])
		);
		$this->addToAssertionCount(1);
	}//end testAnOwnerSyncOfClearedCopiesIsNotHeldBack()

	/**
	 * A sync judges each row once: a row the scan clears when the sync is
	 * checked is written, even when asking the scan again would find it
	 * unjudged, so no copy is silently left on its old value.
	 *
	 * @return void
	 */
	public function testASyncWritesWhatItsCheckJudged(): void {
		$this->mayWrite = true;
		$this->secret(id: 'C_x', ownerId: 'xavier');
		$this->row(target: 'xavier', copyId: 'C_x');
		$scanner = $this->createMock(ShareQuarantineScanner::class);
		$scanner->expects($this->once())->method('heldBack')
			->willReturnOnConsecutiveCalls(null, ShareQuarantineScanner::HELD_UNJUDGED);
		[, , , $sync] = $this->services(scanner: $scanner);

		$this->assertSame(1, $sync->syncUpdate(secretId: 'S_a', updates: [['secretId' => 'C_x', 'key' => 'k-x']], expectedUpdatedAt: '', userId: 'alice'));
		$this->assertSame(['C_x'], $this->written);
	}//end testASyncWritesWhatItsCheckJudged()

	/**
	 * A sync refused for a row the scan cannot judge is refused before its
	 * transaction opens, so a quarantine the same judging made on another row
	 * is not rolled back.
	 *
	 * @return void
	 */
	public function testARefusedSyncKeepsTheQuarantinesItsCheckMade(): void {
		$this->secret(id: 'C_q', ownerId: 'quinn');
		$this->secret(id: 'C_x', ownerId: 'xavier');
		$this->row(target: 'quinn', copyId: 'C_q');
		$this->row(target: 'xavier', copyId: 'C_x');
		[, , , $sync] = $this->services(scanner: $this->scannerFor(verdicts: [
			'row-C_q' => ShareQuarantineScanner::HELD_QUARANTINED,
			'row-C_x' => ShareQuarantineScanner::HELD_UNJUDGED,
		]));
		$this->db->expects($this->never())->method('beginTransaction');

		$this->expectException(ShareScanPendingException::class);
		$sync->syncUpdate(
			secretId: 'S_a',
			updates: [['secretId' => 'C_q', 'key' => 'k-q'], ['secretId' => 'C_x', 'key' => 'k-x']],
			expectedUpdatedAt: '',
			userId: 'alice'
		);
	}//end testARefusedSyncKeepsTheQuarantinesItsCheckMade()

	/**
	 * A sync reads the source's rows again under its lock: a share registered
	 * after the check before the transaction is still written, not silently
	 * left on its old value.
	 *
	 * @return void
	 */
	public function testASyncWritesACopyRegisteredBeforeItsLock(): void {
		$this->mayWrite = true;
		$this->secret(id: 'C_x', ownerId: 'xavier');
		$this->secret(id: 'C_y', ownerId: 'yara');
		$this->row(target: 'xavier', copyId: 'C_x');
		[, , , $sync] = $this->services(scanner: $this->scannerFor(verdicts: []));
		$this->db->method('beginTransaction')->willReturnCallback(function (): void {
			$this->row(target: 'yara', copyId: 'C_y');
		});

		$updated = $sync->syncUpdate(
			secretId: 'S_a',
			updates: [['secretId' => 'C_x', 'key' => 'k-x'], ['secretId' => 'C_y', 'key' => 'k-y']],
			expectedUpdatedAt: '',
			userId: 'alice'
		);

		$this->assertSame(2, $updated);
		$this->assertSame(['C_x', 'C_y'], $this->written);
	}//end testASyncWritesACopyRegisteredBeforeItsLock()

	/**
	 * A share registered before the lock whose row the scan cannot judge makes
	 * the sync wait, inside its transaction, and nothing is written.
	 *
	 * @return void
	 */
	public function testASyncWaitsForAnUnjudgedRowRegisteredBeforeItsLock(): void {
		$this->mayWrite = true;
		$this->secret(id: 'C_x', ownerId: 'xavier');
		$this->secret(id: 'C_y', ownerId: 'yara');
		$this->row(target: 'xavier', copyId: 'C_x');
		[, , , $sync] = $this->services(scanner: $this->scannerFor(verdicts: ['row-C_y' => ShareQuarantineScanner::HELD_UNJUDGED]));
		$this->db->method('beginTransaction')->willReturnCallback(function (): void {
			$this->row(target: 'yara', copyId: 'C_y');
		});
		$this->db->expects($this->never())->method('commit');

		try {
			$sync->syncUpdate(
				secretId: 'S_a',
				updates: [['secretId' => 'C_x', 'key' => 'k-x'], ['secretId' => 'C_y', 'key' => 'k-y']],
				expectedUpdatedAt: '',
				userId: 'alice'
			);
			$this->fail('The sync did not wait for the unjudged row');
		} catch (ShareScanPendingException) {
			$this->assertSame([], $this->written);
		}
	}//end testASyncWaitsForAnUnjudgedRowRegisteredBeforeItsLock()

	/**
	 * A group-write body naming S_a and both copies.
	 *
	 * @return list<array<string,string>>
	 */
	private function groupUpdates(): array {
		return [
			['secretId' => 'S_a', 'key' => 'k-a'],
			['secretId' => 'C_r', 'key' => 'k-r'],
			['secretId' => 'C_x', 'key' => 'k-x'],
		];
	}//end groupUpdates()
}//end class
