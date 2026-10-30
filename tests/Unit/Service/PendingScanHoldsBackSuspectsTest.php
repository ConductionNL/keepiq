<?php

/**
 * While the share quarantine scan is pending, a planted row is still live
 * (GHSA-m5p9-6pw7-pf3g). Every path that deletes or changes a share row's copy
 * then judges the row on the spot: a plant is quarantined and audited there
 * and its copy is left alone, while a legitimate row goes through as usual.
 * Each plant scenario is run with the scan completed as well, to show the same
 * call reaches V without the guard.
 *
 * The services and the scanner run on the real mappers against the real
 * database (the scanner's candidate filter is SQL), inside a transaction that
 * is rolled back.
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

use DateTime;
use OCA\Keepiq\Db\AuditEntry;
use OCA\Keepiq\Db\AuditEntryMapper;
use OCA\Keepiq\Db\BulkGrantShareTargetMapper;
use OCA\Keepiq\Db\EncryptionSuite;
use OCA\Keepiq\Db\EncryptionSuiteMapper;
use OCA\Keepiq\Db\GroupShare;
use OCA\Keepiq\Db\GroupShareMapper;
use OCA\Keepiq\Db\QuarantinedShareTargetMapper;
use OCA\Keepiq\Db\Secret;
use OCA\Keepiq\Db\SecretDelegationMapper;
use OCA\Keepiq\Db\SecretMapper;
use OCA\Keepiq\Db\ShareSaveStageMapper;
use OCA\Keepiq\Db\ShareTarget;
use OCA\Keepiq\Db\ShareTargetMapper;
use OCA\Keepiq\Db\TeamFolder;
use OCA\Keepiq\Db\TeamFolderMember;
use OCA\Keepiq\Event\Audit\AuditEventTypes;
use OCA\Keepiq\Exception\ConflictException;
use OCA\Keepiq\Exception\ShareScanPendingException;
use OCA\Keepiq\Service\AccountShareCleanupService;
use OCA\Keepiq\Service\AuditService;
use OCA\Keepiq\Service\DeletionReport;
use OCA\Keepiq\Service\DirectShareRegistrar;
use OCA\Keepiq\Service\GroupShareService;
use OCA\Keepiq\Service\NotificationService;
use OCA\Keepiq\Service\RecipientSecretCopyFactory;
use OCA\Keepiq\Service\RecipientSecretCopyService;
use OCA\Keepiq\Service\SecretOwnerTransferService;
use OCA\Keepiq\Service\SecretTypeService;
use OCA\Keepiq\Service\SecretVersionService;
use OCA\Keepiq\Service\ShareAuthorizationService;
use OCA\Keepiq\Service\ShareQuarantineScanner;
use OCA\Keepiq\Service\ShareRestriction;
use OCA\Keepiq\Service\ShareRestrictionResolver;
use OCA\Keepiq\Service\ShareRevocationService;
use OCA\Keepiq\Service\ShareService;
use OCA\Keepiq\Service\ShareSync\ShareSyncGuards;
use OCA\Keepiq\Service\ShareSync\ShareWriteContext;
use OCA\Keepiq\Service\ShareSyncService;
use OCA\Keepiq\Service\SiemService;
use OCA\Keepiq\Service\TeamFolderQueryService;
use OCA\Keepiq\Service\TeamFolderShareService;
use OCA\Keepiq\Tests\Support\ShareTargetDatabase;
use OCP\AppFramework\Db\DoesNotExistException;
use OCP\IAppConfig;
use OCP\IDBConnection;
use OCP\IGroup;
use OCP\IGroupManager;
use OCP\Share\IManager as IShareManager;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use Ramsey\Uuid\Uuid;
use RuntimeException;

/**
 * Tests that a pending scan judges a row before its copy is touched.
 *
 * @spec openspec/specs/user-sharing/spec.md#requirement-planted-share-rows-are-quarantined
 *
 * @SuppressWarnings(PHPMD.CouplingBetweenObjects) Each path builds its own service on the real mappers.
 * @SuppressWarnings(PHPMD.ExcessiveClassComplexity) One scenario per copy-touching path.
 */
class PendingScanHoldsBackSuspectsTest extends TestCase {
	use ShareTargetDatabase;

	/**
	 * The attacker.
	 *
	 * @var string
	 */
	private string $attacker = '';

	/**
	 * The victim.
	 *
	 * @var string
	 */
	private string $victim = '';

	/**
	 * The attacker's source S_a.
	 *
	 * @var Secret
	 */
	private Secret $sourceA;

	/**
	 * The victim's own secret V.
	 *
	 * @var Secret
	 */
	private Secret $secretV;

	/**
	 * Open the database and seed both users' secrets.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		parent::setUp();
		$this->openDatabase();

		$this->attacker = $this->user('attacker');
		$this->victim   = $this->user('victim');
		$this->sourceA  = $this->insertSecret(ownerId: $this->attacker, createdAt: new DateTime('-2 hours'), name: 'S_a');
		$this->secretV  = $this->insertSecret(ownerId: $this->victim, createdAt: new DateTime('-1 hour'), name: 'V');
	}//end setUp()

	/**
	 * Roll back.
	 *
	 * @return void
	 */
	protected function tearDown(): void {
		$this->closeDatabase();
		parent::tearDown();
	}//end tearDown()

	/**
	 * The scan pending, or completed.
	 *
	 * @return array<string,array{0:bool}>
	 */
	public static function scanStates(): array {
		return [
			'pending' => [true],
			'completed (no guard)' => [false],
		];
	}//end scanStates()

	/**
	 * Plant {S_a, victim, V}, live, half an hour after V was created.
	 *
	 * @param array<string,string> $grants Optional groupShareId
	 *
	 * @return ShareTarget
	 */
	private function plant(array $grants = []): ShareTarget {
		return $this->insertShareTarget(
			sourceSecretId: $this->sourceA->getId(),
			targetUserId: $this->victim,
			secretId: $this->secretV->getId(),
			createdBy: $this->attacker,
			createdAt: new DateTime('-30 minutes'),
			grants: $grants,
		);
	}//end plant()

	/**
	 * A legitimate share of S_a whose copy was minted in the second before its
	 * row: a candidate of the scan that no signal catches.
	 *
	 * @param array<string,string> $grants Optional groupShareId
	 *
	 * @return ShareTarget
	 */
	private function rowAcrossASecond(array $grants = []): ShareTarget {
		$recipient = $this->user('recipient');
		$minted    = new DateTime('-10 minutes');

		return $this->insertShareTarget(
			sourceSecretId: $this->sourceA->getId(),
			targetUserId: $recipient,
			secretId: $this->insertSecret(ownerId: $recipient, createdAt: $minted)->getId(),
			createdBy: $this->attacker,
			createdAt: (clone $minted)->modify('+1 second'),
			grants: $grants,
		);
	}//end rowAcrossASecond()

	/**
	 * A pending scanner whose judgements are scripted, one verdict per call,
	 * so judging can succeed and then fail within one request.
	 *
	 * @param array<int,string|null> $verdicts What heldBack() answers, in order
	 *
	 * @return ShareQuarantineScanner
	 */
	private function judgingScanner(array $verdicts): ShareQuarantineScanner {
		$scanner = $this->getMockBuilder(ShareQuarantineScanner::class)
			->setConstructorArgs(
				[
					$this->db,
					$this->createMock(IAppConfig::class),
					$this->createMock(LoggerInterface::class),
					new AuditService(mapper: new AuditEntryMapper(db: $this->db)),
					$this->createMock(SiemService::class),
				]
			)
			->onlyMethods(['heldBack'])
			->getMock();
		$scanner->method('heldBack')->willReturnOnConsecutiveCalls(...$verdicts);

		return $scanner;
	}//end judgingScanner()

	/**
	 * A copy as stored now, or null once it is gone.
	 *
	 * @param string $copyId The copy
	 *
	 * @return Secret|null
	 */
	private function storedCopy(string $copyId): ?Secret {
		try {
			return (new SecretMapper(db: $this->db))->findById($copyId);
		} catch (DoesNotExistException) {
			return null;
		}
	}//end storedCopy()

	/**
	 * A recipient copy factory whose recipients all have an active suite.
	 *
	 * @return array{0:EncryptionSuiteMapper,1:SecretTypeService}
	 */
	private function mintingCollaborators(): array {
		$suite = new EncryptionSuite();
		$suite->setId('suite-recipient');
		$suites = $this->createMock(EncryptionSuiteMapper::class);
		$suites->method('findActiveByOwner')->willReturn($suite);
		$types = $this->createMock(SecretTypeService::class);
		$types->method('resolveTypeForSecret')->willReturn('login');

		return [$suites, $types];
	}//end mintingCollaborators()

	/**
	 * The real scanner, pending or completed, auditing to the real audit log.
	 *
	 * @param bool               $pending Whether no pass has completed
	 * @param IDBConnection|null $db      The scanner's connection (the real one when null)
	 *
	 * @return ShareQuarantineScanner
	 *
	 * @SuppressWarnings(PHPMD.BooleanArgumentFlag) The scenario switch.
	 */
	private function scanner(bool $pending, ?IDBConnection $db = null): ShareQuarantineScanner {
		return new ShareQuarantineScanner(
			db: ($db ?? $this->db),
			appConfig: $this->appConfig(pending: $pending),
			logger: $this->createMock(LoggerInterface::class),
			auditService: new AuditService(mapper: new AuditEntryMapper(db: $this->db)),
			siem: $this->createMock(SiemService::class),
		);
	}//end scanner()

	/**
	 * App config with the scan pending or completed.
	 *
	 * @param bool $pending Whether no pass has completed
	 *
	 * @return IAppConfig
	 *
	 * @SuppressWarnings(PHPMD.BooleanArgumentFlag) The scenario switch.
	 */
	private function appConfig(bool $pending): IAppConfig {
		$appConfig = $this->createMock(IAppConfig::class);
		$appConfig->method('getValueString')->willReturnCallback(
			static fn (string $app, string $key, string $default = ''): string => ($key === ShareQuarantineScanner::COMPLETED_KEY && $pending === false
				? ShareQuarantineScanner::INITIAL_GENERATION
				: $default)
		);

		return $appConfig;
	}//end appConfig()

	/**
	 * A pending scanner that judges every row for real, except that judging
	 * one row fails from its second time on: the request judged it up front
	 * and then cannot judge it again.
	 *
	 * @param ShareTarget $failing The row whose second judging fails
	 *
	 * @return ShareQuarantineScanner
	 */
	private function scannerFailingLater(ShareTarget $failing): ShareQuarantineScanner {
		return new class(
			$this->db,
			$this->appConfig(pending: true),
			$this->createMock(LoggerInterface::class),
			new AuditService(mapper: new AuditEntryMapper(db: $this->db)),
			$this->createMock(SiemService::class),
			(string)$failing->getId(),
		) extends ShareQuarantineScanner {
			/**
			 * How often the failing row was judged.
			 *
			 * @var int
			 */
			private int $judged = 0;

			/**
			 * Constructor.
			 *
			 * @param IDBConnection   $db           The connection
			 * @param IAppConfig      $appConfig    The app config
			 * @param LoggerInterface $logger       The logger
			 * @param AuditService    $auditService The audit log
			 * @param SiemService     $siem         The SIEM forwarder
			 * @param string          $failingId    The row whose second judging fails
			 *
			 * @return void
			 */
			public function __construct(
				IDBConnection $db,
				IAppConfig $appConfig,
				LoggerInterface $logger,
				AuditService $auditService,
				SiemService $siem,
				private string $failingId,
			) {
				parent::__construct(db: $db, appConfig: $appConfig, logger: $logger, auditService: $auditService, siem: $siem);
			}//end __construct()

			/**
			 * The real judgement, or unjudged for the failing row's second one.
			 *
			 * @param ShareTarget $row The share row
			 *
			 * @return string|null
			 */
			public function heldBack(ShareTarget $row): ?string {
				if ($row->getId() === $this->failingId && ++$this->judged > 1) {
					return self::HELD_UNJUDGED;
				}

				return parent::heldBack(row: $row);
			}//end heldBack()
		};
	}//end scannerFailingLater()

	/**
	 * A pending scanner that cannot judge any row: its database fails.
	 *
	 * @return ShareQuarantineScanner
	 */
	private function brokenScanner(): ShareQuarantineScanner {
		$broken = $this->createMock(IDBConnection::class);
		$broken->method('getQueryBuilder')->willThrowException(new RuntimeException('database went away'));

		return $this->scanner(pending: true, db: $broken);
	}//end brokenScanner()

	/**
	 * A full group share of S_a, made by the attacker.
	 *
	 * @return GroupShare
	 */
	private function groupShareOfSourceA(): GroupShare {
		$groupShare = new GroupShare();
		$groupShare->setId(Uuid::uuid4()->toString());
		$groupShare->setSecretId($this->sourceA->getId());
		$groupShare->setGroupId('qt-group-'.substr(bin2hex(random_bytes(4)), 0, 8));
		$groupShare->setCreatedBy($this->attacker);
		$groupShare->setCreatedAt(new DateTime('-2 hours'));
		$groupShare->setUseOnly(false);

		return (new GroupShareMapper(db: $this->db))->insert($groupShare);
	}//end groupShareOfSourceA()

	/**
	 * The group-share service with the given scanner. Group sharing is on, and
	 * every group exists and has no members.
	 *
	 * @param ShareQuarantineScanner      $scanner    The scanner
	 * @param ShareRevocationService|null $revocation The per-row revoke (a mock when null)
	 *
	 * @return GroupShareService
	 */
	private function groupShares(ShareQuarantineScanner $scanner, ?ShareRevocationService $revocation = null): GroupShareService {
		$group = $this->createMock(IGroup::class);
		$group->method('getUsers')->willReturn([]);
		$groupManager = $this->createMock(IGroupManager::class);
		$groupManager->method('get')->willReturn($group);
		$shareManager = $this->createMock(IShareManager::class);
		$shareManager->method('allowGroupSharing')->willReturn(true);

		return new GroupShareService(
			mapper: new GroupShareMapper(db: $this->db),
			shareTargetMapper: new ShareTargetMapper(db: $this->db),
			bulkGrantMapper: new BulkGrantShareTargetMapper(db: $this->db),
			secretMapper: new SecretMapper(db: $this->db),
			suiteMapper: $this->createMock(EncryptionSuiteMapper::class),
			delegationMapper: $this->createMock(SecretDelegationMapper::class),
			groupManager: $groupManager,
			notificationService: $this->createMock(NotificationService::class),
			logger: $this->createMock(LoggerInterface::class),
			shareManager: $shareManager,
			revocationService: ($revocation ?? $this->createMock(ShareRevocationService::class)),
			restrictions: $this->restrictions(scanner: $scanner),
			quarantineScan: $scanner,
			db: $this->db,
		);
	}//end groupShares()

	/**
	 * The share service with the given scanner, for a restriction change.
	 *
	 * @param ShareQuarantineScanner $scanner The scanner
	 *
	 * @return ShareService
	 */
	private function shares(ShareQuarantineScanner $scanner): ShareService {
		return new ShareService(
			mapper: new ShareTargetMapper(db: $this->db),
			auth: $this->authorization(),
			directRegistrar: $this->createMock(DirectShareRegistrar::class),
			syncService: $this->createMock(ShareSyncService::class),
			revocationService: $this->createMock(ShareRevocationService::class),
			restrictions: $this->restrictions(scanner: $scanner),
			quarantineScan: $scanner,
			db: $this->db,
		);
	}//end shares()

	/**
	 * Whether a row is gone from the table, live or quarantined.
	 *
	 * @param ShareTarget $row The row
	 *
	 * @return bool
	 */
	private function isGone(ShareTarget $row): bool {
		$qb = $this->db->getQueryBuilder();
		$qb->select('id')
			->from('keepiq_share_targets')
			->where($qb->expr()->eq('id', $qb->createNamedParameter($row->getId())));
		$result = $qb->executeQuery();
		$found  = $result->fetch();
		$result->closeCursor();

		return $found === false;
	}//end isGone()

	/**
	 * The real authorization service on the real mappers.
	 *
	 * @return ShareAuthorizationService
	 */
	private function authorization(): ShareAuthorizationService {
		$delegations = $this->createMock(SecretDelegationMapper::class);
		$delegations->method('findActiveBySecretAndUser')->willThrowException(new DoesNotExistException('none'));

		return new ShareAuthorizationService(
			secretMapper: new SecretMapper(db: $this->db),
			delegationMapper: $delegations,
			shareTargetMapper: new ShareTargetMapper(db: $this->db),
		);
	}//end authorization()

	/**
	 * The revocation service with the given scanner.
	 *
	 * @param ShareQuarantineScanner $scanner The scanner
	 *
	 * @return ShareRevocationService
	 */
	private function revocation(ShareQuarantineScanner $scanner): ShareRevocationService {
		return new ShareRevocationService(
			mapper: new ShareTargetMapper(db: $this->db),
			secretMapper: new SecretMapper(db: $this->db),
			db: $this->db,
			logger: $this->createMock(LoggerInterface::class),
			auth: $this->authorization(),
			quarantined: new QuarantinedShareTargetMapper(db: $this->db),
			quarantineScan: $scanner,
		);
	}//end revocation()

	/**
	 * The restriction resolver with the given scanner; nobody is in a group
	 * and no team folder covers anyone.
	 *
	 * @param ShareQuarantineScanner $scanner The scanner
	 *
	 * @return ShareRestrictionResolver
	 */
	private function restrictions(ShareQuarantineScanner $scanner): ShareRestrictionResolver {
		$teamFolders = $this->createMock(TeamFolderQueryService::class);
		$teamFolders->method('coveringMemberships')->willReturn([]);

		return new ShareRestrictionResolver(
			secretMapper: new SecretMapper(db: $this->db),
			shareTargetMapper: new ShareTargetMapper(db: $this->db),
			groupShareMapper: new GroupShareMapper(db: $this->db),
			teamFolders: $teamFolders,
			groupManager: $this->createMock(IGroupManager::class),
			quarantineScan: $scanner,
		);
	}//end restrictions()

	/**
	 * V as stored now, or null once it is gone.
	 *
	 * @return Secret|null
	 */
	private function storedV(): ?Secret {
		try {
			return (new SecretMapper(db: $this->db))->findById($this->secretV->getId());
		} catch (DoesNotExistException) {
			return null;
		}
	}//end storedV()

	/**
	 * Whether a row is stored quarantined.
	 *
	 * @param ShareTarget $row The row
	 *
	 * @return bool
	 */
	private function isQuarantined(ShareTarget $row): bool {
		try {
			(new QuarantinedShareTargetMapper(db: $this->db))->findQuarantinedById($row->getId());
			return true;
		} catch (DoesNotExistException) {
			return false;
		}
	}//end isQuarantined()

	/**
	 * The `share.quarantined` audit entries of a row.
	 *
	 * @param ShareTarget $row The row
	 *
	 * @return int
	 */
	private function quarantineAudits(ShareTarget $row): int {
		return count(array_filter(
			(new AuditEntryMapper(db: $this->db))->findByObject(objectType: 'share', objectId: $row->getId()),
			static fn (AuditEntry $entry): bool => $entry->getEventType() === AuditEventTypes::SHARE_QUARANTINED
		));
	}//end quarantineAudits()

	/**
	 * The attacker's revoke of the planted row while the scan is pending
	 * quarantines and audits it on the spot and answers as for any
	 * quarantined row; V and the row stay.
	 *
	 * @param bool $pending Whether the scan is pending
	 *
	 * @return void
	 */
	#[DataProvider('scanStates')]
	public function testRevokingAPlantedRowQuarantinesItWhileTheScanIsPending(bool $pending): void {
		$row     = $this->plant();
		$service = $this->revocation(scanner: $this->scanner(pending: $pending));

		if ($pending === false) {
			$service->revokeShare(shareId: $row->getId(), userId: $this->attacker);
			$this->assertNull($this->storedV(), 'without the guard the revoke deletes V');
			return;
		}

		try {
			$service->revokeShare(shareId: $row->getId(), userId: $this->attacker);
			$this->fail('the revoke of a planted row went through');
		} catch (ConflictException $exception) {
			$this->assertNotInstanceOf(ShareScanPendingException::class, $exception);
			$this->assertStringContainsString('quarantined', $exception->getMessage());
		}

		$this->assertNotNull($this->storedV());
		$this->assertTrue($this->isQuarantined(row: $row));
		$this->assertSame(1, $this->quarantineAudits(row: $row));
	}//end testRevokingAPlantedRowQuarantinesItWhileTheScanIsPending()

	/**
	 * Deleting S_a while the scan is pending quarantines and audits the plant
	 * on the spot and keeps V; the cascade then removes the row, as it does
	 * every quarantined row of the source.
	 *
	 * @param bool $pending Whether the scan is pending
	 *
	 * @return void
	 */
	#[DataProvider('scanStates')]
	public function testDeletingTheSourceAuditsThePlantAndKeepsVWhileTheScanIsPending(bool $pending): void {
		$row = $this->plant();

		$this->revocation(scanner: $this->scanner(pending: $pending))->deleteAllForSecret(sourceSecretId: $this->sourceA->getId());

		$this->assertSame($pending, $this->storedV() !== null);
		$this->assertSame(($pending === true ? 1 : 0), $this->quarantineAudits(row: $row));
	}//end testDeletingTheSourceAuditsThePlantAndKeepsVWhileTheScanIsPending()

	/**
	 * A sync from S_a does not write V while the scan is pending.
	 *
	 * @param bool $pending Whether the scan is pending
	 *
	 * @return void
	 */
	#[DataProvider('scanStates')]
	public function testASyncDoesNotWriteVWhileTheScanIsPending(bool $pending): void {
		$row  = $this->plant();
		$sync = new ShareSyncService(
			mapper: new ShareTargetMapper(db: $this->db),
			secretMapper: new SecretMapper(db: $this->db),
			db: $this->db,
			auth: $this->authorization(),
			versionService: $this->createMock(SecretVersionService::class),
			guards: $this->createMock(ShareSyncGuards::class),
			contexts: $this->createMock(ShareWriteContext::class),
			quarantineScan: $this->scanner(pending: $pending),
		);

		$sync->writeCopyBlobs(
			source: $this->sourceA,
			updates: [['secretId' => $this->secretV->getId(), 'key' => 'EVIL']],
			allowedIds: [$this->secretV->getId() => true],
			savedAt: new DateTime(),
			writerId: $this->attacker,
		);

		$this->assertSame(($pending === true ? 'CIPHERTEXT' : 'EVIL'), $this->storedV()?->getKey());
		$this->assertSame($pending, $this->isQuarantined(row: $row));
	}//end testASyncDoesNotWriteVWhileTheScanIsPending()

	/**
	 * The victim leaving a group the plant is linked to keeps V and the row
	 * while the scan is pending; the row is quarantined on the spot.
	 *
	 * @param bool $pending Whether the scan is pending
	 *
	 * @return void
	 */
	#[DataProvider('scanStates')]
	public function testAMemberLeavingTheGroupKeepsVAndTheRowWhileTheScanIsPending(bool $pending): void {
		$groupShare = $this->groupShareOfSourceA();
		$row        = $this->plant(grants: ['groupShareId' => $groupShare->getId()]);

		$revoked = $this->groupShares(scanner: $this->scanner(pending: $pending))->handleMemberLeave(
			userId: $this->victim,
			groupId: $groupShare->getGroupId()
		);

		$this->assertSame(($pending === true ? 0 : 1), $revoked);
		$this->assertSame($pending, $this->storedV() !== null);
		$this->assertSame($pending, $this->isQuarantined(row: $row), 'the row stays, quarantined');
	}//end testAMemberLeavingTheGroupKeepsVAndTheRowWhileTheScanIsPending()

	/**
	 * S_a's owner restricting the planted row (PATCH /shares/{id}) while the
	 * scan is pending quarantines it on the spot and is refused as for any
	 * quarantined row; nothing is written onto V.
	 *
	 * @param bool $pending Whether the scan is pending
	 *
	 * @return void
	 */
	#[DataProvider('scanStates')]
	public function testRestrictingThePlantedRowDoesNotRestrictVWhileTheScanIsPending(bool $pending): void {
		$row     = $this->plant();
		$service = $this->shares(scanner: $this->scanner(pending: $pending));

		try {
			$service->updateRestriction(
				shareId: $row->getId(),
				restriction: new ShareRestriction(useOnly: true, expiresAt: new DateTime('+1 minute')),
				userId: $this->attacker,
			);
			$this->assertFalse($pending, 'the restriction change of a planted row went through');
		} catch (ConflictException $exception) {
			$this->assertTrue($pending);
			$this->assertNotInstanceOf(ShareScanPendingException::class, $exception);
			$this->assertStringContainsString('quarantined', $exception->getMessage());
		}

		$stored = $this->storedV();
		$this->assertNotNull($stored);
		$this->assertSame(($pending === false), $stored->getUseOnly() === true);
		$this->assertSame(($pending === false), $stored->getAccessExpiresAt() !== null);
		$this->assertSame($pending, $this->isQuarantined(row: $row));
	}//end testRestrictingThePlantedRowDoesNotRestrictVWhileTheScanIsPending()

	/**
	 * The attacker deleting their account does not tombstone V while the scan
	 * is pending; the plant is audited before its row is severed.
	 *
	 * @param bool $pending Whether the scan is pending
	 *
	 * @return void
	 */
	#[DataProvider('scanStates')]
	public function testDeletingTheAttackersAccountDoesNotTombstoneVWhileTheScanIsPending(bool $pending): void {
		$row     = $this->plant();
		$service = new AccountShareCleanupService(
			secretMapper: new SecretMapper(db: $this->db),
			shareMapper: new ShareTargetMapper(db: $this->db),
			groupShareMapper: new GroupShareMapper(db: $this->db),
			delegationMapper: $this->createMock(SecretDelegationMapper::class),
			stageMapper: $this->createMock(ShareSaveStageMapper::class),
			ownerTransfer: $this->createMock(SecretOwnerTransferService::class),
			quarantineScan: $this->scanner(pending: $pending),
		);

		$service->detachGrantedShares(ownedSecrets: [$this->sourceA], report: new DeletionReport());

		$this->assertSame(($pending === false), $this->storedV()?->getTombstonedAt() !== null);
		$this->assertSame(($pending === true ? 1 : 0), $this->quarantineAudits(row: $row));
	}//end testDeletingTheAttackersAccountDoesNotTombstoneVWhileTheScanIsPending()

	/**
	 * A legitimate row whose copy was minted the second before it is a
	 * candidate no signal catches: while the scan is pending, its revoke
	 * deletes the copy as usual and the row is not quarantined.
	 *
	 * @return void
	 */
	public function testALegitimateCandidateIsRevokedWhileTheScanIsPending(): void {
		$row = $this->rowAcrossASecond();

		$this->revocation(scanner: $this->scanner(pending: true))->revokeShare(shareId: $row->getId(), userId: $this->attacker);

		$this->assertSame(0, $this->quarantineAudits(row: $row));
		$this->expectException(DoesNotExistException::class);
		(new SecretMapper(db: $this->db))->findById($row->getSecretId());
	}//end testALegitimateCandidateIsRevokedWhileTheScanIsPending()

	/**
	 * Deleting the source while the scan is pending deletes the copy of a
	 * legitimate row whose copy was minted the second before it, so no
	 * untracked copy is left behind, and does not quarantine it.
	 *
	 * @return void
	 */
	public function testDeletingTheSourceDeletesALegitimateCandidatesCopyWhileTheScanIsPending(): void {
		$row = $this->rowAcrossASecond();

		$this->revocation(scanner: $this->scanner(pending: true))->deleteAllForSecret(sourceSecretId: $this->sourceA->getId());

		$this->assertSame(0, $this->quarantineAudits(row: $row));
		$this->expectException(DoesNotExistException::class);
		(new SecretMapper(db: $this->db))->findById($row->getSecretId());
	}//end testDeletingTheSourceDeletesALegitimateCandidatesCopyWhileTheScanIsPending()

	/**
	 * When the pending scan cannot judge a row, its revoke waits for the scan
	 * with an error of its own, and V and the row stay live and unjudged.
	 *
	 * @return void
	 */
	public function testARowThePendingScanCannotJudgeWaitsForIt(): void {
		$row    = $this->plant();
		$broken = $this->createMock(IDBConnection::class);
		$broken->method('getQueryBuilder')->willThrowException(new RuntimeException('database went away'));

		try {
			$this->revocation(scanner: $this->scanner(pending: true, db: $broken))->revokeShare(shareId: $row->getId(), userId: $this->attacker);
			$this->fail('the revoke of an unjudged row went through');
		} catch (ShareScanPendingException $exception) {
			$this->assertStringContainsString('waits for the share quarantine scan', $exception->getMessage());
		}

		$this->assertNotNull($this->storedV());
		$this->assertFalse($this->isQuarantined(row: $row));
		$this->assertSame($row->getId(), (new ShareTargetMapper(db: $this->db))->findById($row->getId())->getId());
	}//end testARowThePendingScanCannotJudgeWaitsForIt()

	/**
	 * A sync from S_a that names V twice while the scan is pending: the first
	 * entry quarantines the plant, and the second, judged on the row the sync
	 * loaded before that, finds it quarantined and leaves V alone too.
	 *
	 * @return void
	 */
	public function testASyncNamingVTwiceDoesNotWriteVWhileTheScanIsPending(): void {
		$row  = $this->plant();
		$sync = new ShareSyncService(
			mapper: new ShareTargetMapper(db: $this->db),
			secretMapper: new SecretMapper(db: $this->db),
			db: $this->db,
			auth: $this->authorization(),
			versionService: $this->createMock(SecretVersionService::class),
			guards: $this->createMock(ShareSyncGuards::class),
			contexts: $this->createMock(ShareWriteContext::class),
			quarantineScan: $this->scanner(pending: true),
		);

		$updated = $sync->writeCopyBlobs(
			source: $this->sourceA,
			updates: [['secretId' => $this->secretV->getId(), 'key' => 'x'], ['secretId' => $this->secretV->getId(), 'key' => 'EVIL']],
			allowedIds: [$this->secretV->getId() => true],
			savedAt: new DateTime(),
			writerId: $this->attacker,
		);

		$this->assertSame(0, $updated);
		$this->assertSame('CIPHERTEXT', $this->storedV()?->getKey());
		$this->assertTrue($this->isQuarantined(row: $row));
	}//end testASyncNamingVTwiceDoesNotWriteVWhileTheScanIsPending()

	/**
	 * A row a scan pass quarantines after a caller loaded it is held back:
	 * resolving the stale row's restriction leaves V alone.
	 *
	 * @return void
	 */
	public function testARowQuarantinedAfterItWasLoadedIsHeldBack(): void {
		$stale = $this->plant();
		$stale->setUseOnly(true);
		$scanner = $this->scanner(pending: true);
		$this->assertTrue($scanner->run());
		$this->assertTrue($this->isQuarantined(row: $stale));

		$this->assertSame(ShareQuarantineScanner::HELD_QUARANTINED, $scanner->heldBack(row: $stale));
		$this->assertFalse($this->restrictions(scanner: $scanner)->resolveTarget(target: $stale));
		$this->assertNotTrue($this->storedV()?->getUseOnly());
	}//end testARowQuarantinedAfterItWasLoadedIsHeldBack()

	/**
	 * A member leaving the group while the scan cannot judge their row: the
	 * row goes, so the departed member gets no later sync, and its copy is
	 * kept, as it may be their own secret.
	 *
	 * @return void
	 */
	public function testAMemberLeavingDropsAnUnjudgedRowAndKeepsItsCopy(): void {
		$groupShare = $this->groupShareOfSourceA();
		$row        = $this->plant(grants: ['groupShareId' => $groupShare->getId()]);

		$revoked = $this->groupShares(scanner: $this->brokenScanner())->handleMemberLeave(
			userId: $this->victim,
			groupId: $groupShare->getGroupId()
		);

		$this->assertSame(1, $revoked);
		$this->assertTrue($this->isGone(row: $row));
		$this->assertNotNull($this->storedV());
	}//end testAMemberLeavingDropsAnUnjudgedRowAndKeepsItsCopy()

	/**
	 * Restricting a row the scan cannot judge (PATCH /shares/{id}) waits for
	 * the scan: neither the row nor its copy is written.
	 *
	 * @return void
	 */
	public function testRestrictingAnUnjudgedRowWaitsForTheScan(): void {
		$row = $this->plant();

		try {
			$this->shares(scanner: $this->brokenScanner())->updateRestriction(
				shareId: $row->getId(),
				restriction: new ShareRestriction(useOnly: true),
				userId: $this->attacker,
			);
			$this->fail('the restriction change of an unjudged row went through');
		} catch (ShareScanPendingException $exception) {
			$this->assertStringContainsString('waits for the share quarantine scan', $exception->getMessage());
		}

		$this->assertNotTrue((new ShareTargetMapper(db: $this->db))->findById($row->getId())->getUseOnly());
		$this->assertNotTrue($this->storedV()?->getUseOnly());
	}//end testRestrictingAnUnjudgedRowWaitsForTheScan()

	/**
	 * Changing a group share's restriction by sharing again while the scan
	 * cannot judge one of its rows waits for the scan: the group share keeps
	 * its restriction.
	 *
	 * @return void
	 */
	public function testRestrictingAGroupShareWithAnUnjudgedRowWaitsForTheScan(): void {
		$groupShare = $this->groupShareOfSourceA();
		$this->plant(grants: ['groupShareId' => $groupShare->getId()]);

		try {
			$this->groupShares(scanner: $this->brokenScanner())->createGroupShare(
				secretId: $this->sourceA->getId(),
				groupId: $groupShare->getGroupId(),
				userId: $this->attacker,
				restriction: new ShareRestriction(useOnly: true),
			);
			$this->fail('the restriction change of a group share with an unjudged row went through');
		} catch (ShareScanPendingException $exception) {
			$this->assertStringContainsString('waits for the share quarantine scan', $exception->getMessage());
		}

		$this->assertNotTrue((new GroupShareMapper(db: $this->db))->findById($groupShare->getId())->getUseOnly());
		$this->assertNotTrue($this->storedV()?->getUseOnly());
	}//end testRestrictingAGroupShareWithAnUnjudgedRowWaitsForTheScan()

	/**
	 * Changing a group share's restriction while the scan is pending
	 * quarantines a planted row of it on the spot and goes on without it: the
	 * group share takes the restriction, V does not.
	 *
	 * @return void
	 */
	public function testRestrictingAGroupShareQuarantinesAPlantAndGoesOn(): void {
		$groupShare = $this->groupShareOfSourceA();
		$row        = $this->plant(grants: ['groupShareId' => $groupShare->getId()]);

		$this->groupShares(scanner: $this->scanner(pending: true))->createGroupShare(
			secretId: $this->sourceA->getId(),
			groupId: $groupShare->getGroupId(),
			userId: $this->attacker,
			restriction: new ShareRestriction(useOnly: true),
		);

		$this->assertTrue((new GroupShareMapper(db: $this->db))->findById($groupShare->getId())->getUseOnly());
		$this->assertTrue($this->isQuarantined(row: $row));
		$this->assertNotTrue($this->storedV()?->getUseOnly());
	}//end testRestrictingAGroupShareQuarantinesAPlantAndGoesOn()

	/**
	 * Revoking a group share while the scan cannot judge one of its rows waits
	 * for the scan: the group share, the row and its copy all stay, so no copy
	 * is left behind with no row.
	 *
	 * @return void
	 */
	public function testRevokingAGroupShareWithAnUnjudgedRowWaitsForTheScan(): void {
		$groupShare = $this->groupShareOfSourceA();
		$row        = $this->plant(grants: ['groupShareId' => $groupShare->getId()]);
		$scanner    = $this->brokenScanner();

		try {
			$this->groupShares(scanner: $scanner, revocation: $this->revocation(scanner: $scanner))->revokeGroupShare(
				groupShareId: $groupShare->getId(),
				userId: $this->attacker
			);
			$this->fail('the revoke of a group share with an unjudged row went through');
		} catch (ShareScanPendingException $exception) {
			$this->assertStringContainsString('waits for the share quarantine scan', $exception->getMessage());
		}

		$this->assertFalse($this->isGone(row: $row));
		$this->assertNotNull($this->storedV());
		$this->assertCount(1, (new GroupShareMapper(db: $this->db))->findBySecret($this->sourceA->getId()));
	}//end testRevokingAGroupShareWithAnUnjudgedRowWaitsForTheScan()

	/**
	 * A group revoke whose judging succeeds up front and then fails on a later
	 * row rolls back as a whole: the member already revoked gets their row and
	 * copy back, and the group share stays.
	 *
	 * @return void
	 */
	public function testAGroupRevokeWhoseSecondJudgingFailsRollsBackEveryRow(): void {
		$groupShare = $this->groupShareOfSourceA();
		$first      = $this->rowAcrossASecond(grants: ['groupShareId' => $groupShare->getId()]);
		$second     = $this->rowAcrossASecond(grants: ['groupShareId' => $groupShare->getId()]);
		// Two up-front judgements, two for the first row's revoke, then the
		// second row's revoke cannot be judged.
		$scanner = $this->judgingScanner(verdicts: [null, null, null, null, ShareQuarantineScanner::HELD_UNJUDGED]);

		try {
			$this->groupShares(scanner: $scanner, revocation: $this->revocation(scanner: $scanner))->revokeGroupShare(
				groupShareId: $groupShare->getId(),
				userId: $this->attacker
			);
			$this->fail('the group revoke went through');
		} catch (ShareScanPendingException) {
			// Refused.
		}

		foreach ([$first, $second] as $row) {
			$this->assertFalse($this->isGone(row: $row));
			$this->assertNotNull($this->storedCopy(copyId: $row->getSecretId()));
		}

		$this->assertCount(1, (new GroupShareMapper(db: $this->db))->findBySecret($this->sourceA->getId()));
	}//end testAGroupRevokeWhoseSecondJudgingFailsRollsBackEveryRow()

	/**
	 * A revoke whose judging succeeds up front and then fails inside its
	 * transaction is refused and rolled back: the row and its copy stay,
	 * rather than the row going and the copy staying behind.
	 *
	 * @return void
	 */
	public function testARevokeWhoseSecondJudgingFailsRollsBack(): void {
		$row     = $this->rowAcrossASecond();
		$scanner = $this->judgingScanner(verdicts: [null, ShareQuarantineScanner::HELD_UNJUDGED]);

		try {
			$this->revocation(scanner: $scanner)->revokeShare(shareId: $row->getId(), userId: $this->attacker);
			$this->fail('the revoke went through');
		} catch (ShareScanPendingException $exception) {
			$this->assertStringContainsString('waits for the share quarantine scan', $exception->getMessage());
		}

		$this->assertFalse($this->isGone(row: $row));
		$this->assertNotNull($this->storedCopy(copyId: $row->getSecretId()));
	}//end testARevokeWhoseSecondJudgingFailsRollsBack()

	/**
	 * Sharing again with a new restriction, when judging succeeds up front and
	 * fails while a member's copy is resolved, is refused and rolled back: the
	 * group share keeps its old restriction, as the member's copy does.
	 *
	 * @return void
	 */
	public function testAGroupRestrictionChangeWhoseSecondJudgingFailsRollsBack(): void {
		$groupShare = $this->groupShareOfSourceA();
		$member     = $this->rowAcrossASecond(grants: ['groupShareId' => $groupShare->getId()]);
		$scanner    = $this->judgingScanner(verdicts: [null, ShareQuarantineScanner::HELD_UNJUDGED]);

		try {
			$this->groupShares(scanner: $scanner)->createGroupShare(
				secretId: $this->sourceA->getId(),
				groupId: $groupShare->getGroupId(),
				userId: $this->attacker,
				restriction: new ShareRestriction(useOnly: true),
			);
			$this->fail('the restriction change went through');
		} catch (ShareScanPendingException) {
			// Refused.
		}

		$this->assertNotTrue((new GroupShareMapper(db: $this->db))->findById($groupShare->getId())->getUseOnly());
		$this->assertNotTrue($this->storedCopy(copyId: $member->getSecretId())?->getUseOnly());
	}//end testAGroupRestrictionChangeWhoseSecondJudgingFailsRollsBack()

	/**
	 * A direct share's restriction change whose judging succeeds up front and
	 * fails while its copy is resolved is refused and rolled back: the row
	 * keeps its old restriction, as its copy does.
	 *
	 * @return void
	 */
	public function testARestrictionChangeWhoseSecondJudgingFailsRollsBack(): void {
		$row     = $this->rowAcrossASecond();
		$scanner = $this->judgingScanner(verdicts: [null, ShareQuarantineScanner::HELD_UNJUDGED]);

		try {
			$this->shares(scanner: $scanner)->updateRestriction(
				shareId: $row->getId(),
				restriction: new ShareRestriction(useOnly: true, expiresAt: new DateTime('+1 day')),
				userId: $this->attacker,
			);
			$this->fail('the restriction change went through');
		} catch (ShareScanPendingException $exception) {
			$this->assertStringContainsString('waits for the share quarantine scan', $exception->getMessage());
		}

		$stored = (new ShareTargetMapper(db: $this->db))->findById($row->getId());
		$this->assertNotTrue($stored->getUseOnly());
		$this->assertNull($stored->getExpiresAt());
		$copy = $this->storedCopy(copyId: $row->getSecretId());
		$this->assertNotTrue($copy?->getUseOnly());
		$this->assertNull($copy?->getAccessExpiresAt());
	}//end testARestrictionChangeWhoseSecondJudgingFailsRollsBack()

	/**
	 * Trashing the source while the scan cannot judge one of its rows waits
	 * for the scan: every row and copy stays, a legitimate one too.
	 *
	 * @return void
	 */
	public function testTrashingTheSourceWithAnUnjudgedRowWaitsForTheScan(): void {
		$plant      = $this->plant();
		$legitimate = $this->rowAcrossASecond();

		try {
			$this->revocation(scanner: $this->brokenScanner())->revokeAllForSecret(sourceSecretId: $this->sourceA->getId());
			$this->fail('the source delete went through');
		} catch (ShareScanPendingException $exception) {
			$this->assertStringContainsString('waits for the share quarantine scan', $exception->getMessage());
		}

		$this->assertFalse($this->isGone(row: $plant));
		$this->assertFalse($this->isGone(row: $legitimate));
		$this->assertNotNull($this->storedV());
		$this->assertNotNull($this->storedCopy(copyId: $legitimate->getSecretId()));
	}//end testTrashingTheSourceWithAnUnjudgedRowWaitsForTheScan()

	/**
	 * Trashing the source when judging succeeds up front and then fails on a
	 * later row rolls back as a whole: the copy already deleted comes back.
	 *
	 * @return void
	 */
	public function testATrashWhoseSecondJudgingFailsRollsBackEveryRow(): void {
		$first   = $this->rowAcrossASecond();
		$second  = $this->rowAcrossASecond();
		$scanner = $this->judgingScanner(verdicts: [null, null, null, ShareQuarantineScanner::HELD_UNJUDGED]);

		try {
			$this->revocation(scanner: $scanner)->revokeAllForSecret(sourceSecretId: $this->sourceA->getId());
			$this->fail('the source delete went through');
		} catch (ShareScanPendingException) {
			// Refused.
		}

		foreach ([$first, $second] as $row) {
			$this->assertFalse($this->isGone(row: $row));
			$this->assertNotNull($this->storedCopy(copyId: $row->getSecretId()));
		}
	}//end testATrashWhoseSecondJudgingFailsRollsBackEveryRow()

	/**
	 * A group revoke refused because a row cannot be judged a second time
	 * keeps the quarantine its up-front judging made of a plant: the plant is
	 * judged before the revoke's transaction, so its rollback does not undo
	 * the quarantine.
	 *
	 * @return void
	 */
	public function testARefusedGroupRevokeKeepsTheQuarantineItMade(): void {
		$groupShare = $this->groupShareOfSourceA();
		$plant      = $this->plant(grants: ['groupShareId' => $groupShare->getId()]);
		$legitimate = $this->rowAcrossASecond(grants: ['groupShareId' => $groupShare->getId()]);
		$scanner    = $this->scannerFailingLater(failing: $legitimate);

		try {
			$this->groupShares(scanner: $scanner, revocation: $this->revocation(scanner: $scanner))->revokeGroupShare(
				groupShareId: $groupShare->getId(),
				userId: $this->attacker
			);
			$this->fail('the group revoke went through');
		} catch (ShareScanPendingException) {
			// Refused.
		}

		$this->assertTrue($this->isQuarantined(row: $plant));
		$this->assertSame(1, $this->quarantineAudits(row: $plant));
		$this->assertNotNull($this->storedV());
		$this->assertNotNull($this->storedCopy(copyId: $legitimate->getSecretId()));
	}//end testARefusedGroupRevokeKeepsTheQuarantineItMade()

	/**
	 * A source delete refused because a row cannot be judged a second time
	 * keeps the quarantine its up-front judging made of a plant.
	 *
	 * @return void
	 */
	public function testARefusedTrashKeepsTheQuarantineItMade(): void {
		$plant      = $this->plant();
		$legitimate = $this->rowAcrossASecond();

		try {
			$this->revocation(scanner: $this->scannerFailingLater(failing: $legitimate))->revokeAllForSecret(
				sourceSecretId: $this->sourceA->getId()
			);
			$this->fail('the source delete went through');
		} catch (ShareScanPendingException) {
			// Refused.
		}

		$this->assertTrue($this->isQuarantined(row: $plant));
		$this->assertNotNull($this->storedV());
		$this->assertNotNull($this->storedCopy(copyId: $legitimate->getSecretId()));
	}//end testARefusedTrashKeepsTheQuarantineItMade()

	/**
	 * A group restriction change refused because a row cannot be judged a
	 * second time keeps the quarantine its up-front judging made of a plant.
	 *
	 * @return void
	 */
	public function testARefusedGroupRestrictionChangeKeepsTheQuarantineItMade(): void {
		$groupShare = $this->groupShareOfSourceA();
		$plant      = $this->plant(grants: ['groupShareId' => $groupShare->getId()]);
		$legitimate = $this->rowAcrossASecond(grants: ['groupShareId' => $groupShare->getId()]);

		try {
			$this->groupShares(scanner: $this->scannerFailingLater(failing: $legitimate))->createGroupShare(
				secretId: $this->sourceA->getId(),
				groupId: $groupShare->getGroupId(),
				userId: $this->attacker,
				restriction: new ShareRestriction(useOnly: true),
			);
			$this->fail('the restriction change went through');
		} catch (ShareScanPendingException) {
			// Refused.
		}

		$this->assertTrue($this->isQuarantined(row: $plant));
		$this->assertNotTrue((new GroupShareMapper(db: $this->db))->findById($groupShare->getId())->getUseOnly());
		$this->assertNotTrue($this->storedV()?->getUseOnly());
	}//end testARefusedGroupRestrictionChangeKeepsTheQuarantineItMade()

	/**
	 * A refusable resolve of a row a scan pass quarantined after the caller
	 * loaded it leaves V alone and goes on.
	 *
	 * @return void
	 */
	public function testARefusableResolveLeavesARowQuarantinedSinceItWasLoadedAlone(): void {
		$stale = $this->plant();
		$stale->setUseOnly(true);
		$scanner = $this->scanner(pending: true);
		$this->assertTrue($scanner->run());

		$this->assertSame(0, $this->restrictions(scanner: $scanner)->resolveTargetsOrRefuse(targets: [$stale], refusal: 'refused'));
		$this->assertNotTrue($this->storedV()?->getUseOnly());
	}//end testARefusableResolveLeavesARowQuarantinedSinceItWasLoadedAlone()

	/**
	 * A use-only share registered while the scan is pending and cannot judge
	 * any row still gets a use-only copy: the row was minted in this request,
	 * so it is not judged.
	 *
	 * @return void
	 */
	public function testAUseOnlyShareMintedWhileJudgingFailsGetsAUseOnlyCopy(): void {
		$recipient        = $this->user('recipient');
		[$suites, $types] = $this->mintingCollaborators();
		$registrar        = new DirectShareRegistrar(
			mapper: new ShareTargetMapper(db: $this->db),
			secretMapper: new SecretMapper(db: $this->db),
			copyFactory: new RecipientSecretCopyFactory(
				secretMapper: new SecretMapper(db: $this->db),
				suiteMapper: $suites,
				typeService: $types
			),
			notificationService: $this->createMock(NotificationService::class),
			restrictions: $this->restrictions(scanner: $this->brokenScanner()),
			connection: $this->db,
		);

		$report = $registrar->registerDirectShares(
			userId: $this->attacker,
			shares: [['sourceSecretId' => $this->sourceA->getId(), 'targetUserId' => $recipient, 'encryptedKey' => 'K', 'useOnly' => true]]
		);

		$this->assertSame('created', $report[0]['status']);
		$this->assertTrue($this->storedCopy(copyId: (string)$report[0]['recipientSecretId'])?->getUseOnly());
	}//end testAUseOnlyShareMintedWhileJudgingFailsGetsAUseOnlyCopy()

	/**
	 * A team-folder fan-out while the scan is pending and cannot judge any
	 * row still gives a copy covered by a use-only membership a use-only copy.
	 *
	 * @return void
	 */
	public function testATeamFolderCopyMintedWhileJudgingFailsTakesItsMembershipsRestriction(): void {
		$recipient  = $this->user('recipient');
		$membership = new TeamFolderMember();
		$membership->setGrade('read');
		$membership->setUseOnly(true);
		$teamFolders = $this->createMock(TeamFolderQueryService::class);
		$teamFolders->method('coveringMemberships')->willReturn([$membership]);
		[$suites, $types] = $this->mintingCollaborators();

		$folder = new TeamFolder();
		$folder->setId(Uuid::uuid4()->toString());
		$folder->setOwnerId($this->attacker);
		$service = new TeamFolderShareService(
			shareTargetMapper: new ShareTargetMapper(db: $this->db),
			bulkGrantMapper: new BulkGrantShareTargetMapper(db: $this->db),
			copies: new RecipientSecretCopyService(
				secretMapper: new SecretMapper(db: $this->db),
				suiteMapper: $suites,
				typeService: $types
			),
			notificationService: $this->createMock(NotificationService::class),
			db: $this->db,
			restrictions: new ShareRestrictionResolver(
				secretMapper: new SecretMapper(db: $this->db),
				shareTargetMapper: new ShareTargetMapper(db: $this->db),
				groupShareMapper: new GroupShareMapper(db: $this->db),
				teamFolders: $teamFolders,
				groupManager: $this->createMock(IGroupManager::class),
				quarantineScan: $this->brokenScanner(),
			),
		);

		$result = $service->registerFanOutShares(
			teamFolder: $folder,
			shares: [['sourceSecretId' => $this->sourceA->getId(), 'targetUserId' => $recipient, 'encryptedKey' => 'K']],
			subtreeSecretIds: [$this->sourceA->getId() => true],
			userId: $this->attacker,
		);

		$this->assertSame(1, $result['created']);
		$this->assertTrue($this->storedCopy(copyId: $result['rows'][0]['recipientSecretId'])?->getUseOnly());
	}//end testATeamFolderCopyMintedWhileJudgingFailsTakesItsMembershipsRestriction()

	/**
	 * Deleting the source where nobody can be refused (a background delete)
	 * while the scan cannot judge its rows: the rows go and every copy is
	 * kept, a legitimate one too.
	 *
	 * @return void
	 */
	public function testDeletingTheSourceDropsUnjudgedRowsAndKeepsTheirCopies(): void {
		$plant      = $this->plant();
		$legitimate = $this->rowAcrossASecond();

		$this->revocation(scanner: $this->brokenScanner())->deleteAllForSecret(sourceSecretId: $this->sourceA->getId());

		$this->assertTrue($this->isGone(row: $plant));
		$this->assertTrue($this->isGone(row: $legitimate));
		$this->assertNotNull($this->storedV());
		$this->assertSame($legitimate->getSecretId(), (new SecretMapper(db: $this->db))->findById($legitimate->getSecretId())->getId());
	}//end testDeletingTheSourceDropsUnjudgedRowsAndKeepsTheirCopies()

	/**
	 * A judging query that fails in the database, inside the cascade's
	 * transaction, is rolled back to its own savepoint: on Postgres the
	 * cascade's transaction stays usable and the cascade completes, keeping
	 * the copy it could not judge.
	 *
	 * @return void
	 */
	public function testAJudgingQueryThatFailsDoesNotAbortTheCallersTransaction(): void {
		$row = $this->plant();
		$db  = $this->createMock(IDBConnection::class);
		$db->method('beginTransaction')->willReturnCallback(fn () => $this->db->beginTransaction());
		$db->method('commit')->willReturnCallback(fn () => $this->db->commit());
		$db->method('rollBack')->willReturnCallback(fn () => $this->db->rollBack());
		$db->method('getQueryBuilder')->willReturnCallback(
			fn () => $this->db->executeQuery('SELECT id FROM *PREFIX*keepiq_no_such_table')
		);

		$this->revocation(scanner: $this->scanner(pending: true, db: $db))->deleteAllForSecret(sourceSecretId: $this->sourceA->getId());

		$this->assertTrue($this->isGone(row: $row));
		$this->assertNotNull($this->storedV());
	}//end testAJudgingQueryThatFailsDoesNotAbortTheCallersTransaction()
}//end class
