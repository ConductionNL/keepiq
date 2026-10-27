<?php

/**
 * Database-backed tests for the share quarantine scan and its background job
 * (GHSA-m5p9-6pw7-pf3g).
 *
 * The scan's candidate filter and both signals are SQL, so these run it against
 * the real database inside a transaction that is rolled back. The scan reads
 * the whole table, so each test asserts only on the rows it seeded.
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
use OCA\Keepiq\BackgroundJob\ShareQuarantineScanJob;
use OCA\Keepiq\Db\AuditEntry;
use OCA\Keepiq\Db\AuditEntryMapper;
use OCA\Keepiq\Db\QuarantinedShareTargetMapper;
use OCA\Keepiq\Db\GroupShareMapper;
use OCA\Keepiq\Db\Secret;
use OCA\Keepiq\Db\SecretMapper;
use OCA\Keepiq\Db\ShareTarget;
use OCA\Keepiq\Db\ShareTargetMapper;
use OCA\Keepiq\Db\SiemQueueItem;
use OCA\Keepiq\Db\SiemQueueItemMapper;
use OCA\Keepiq\Db\SiemSink;
use OCA\Keepiq\Db\SiemSinkMapper;
use OCA\Keepiq\Event\Audit\AuditEvent;
use OCA\Keepiq\Event\Audit\AuditEventTypes;
use OCA\Keepiq\Service\AuditService;
use OCA\Keepiq\Service\ShareQuarantineScanner;
use OCA\Keepiq\Service\ShareQuarantineService;
use OCA\Keepiq\Service\ShareRestrictionResolver;
use OCA\Keepiq\Service\SiemService;
use OCA\Keepiq\Service\SiemSinkService;
use OCA\Keepiq\Service\SiemTransport;
use OCA\Keepiq\Service\TeamFolderQueryService;
use OCA\Keepiq\Tests\Support\ShareTargetDatabase;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\DB\QueryBuilder\IQueryBuilder;
use OCP\EventDispatcher\IEventDispatcher;
use OCP\IAppConfig;
use OCP\IDBConnection;
use OCP\IGroupManager;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use Ramsey\Uuid\Uuid;
use ReflectionMethod;
use RuntimeException;

/**
 * Tests for ShareQuarantineScanner and ShareQuarantineScanJob.
 *
 * @spec openspec/specs/user-sharing/spec.md#requirement-planted-share-rows-are-quarantined
 */
class ShareQuarantineScannerTest extends TestCase {
	use ShareTargetDatabase;

	/**
	 * The in-memory app config values.
	 *
	 * @var array<string,string>
	 */
	private array $config = [];

	/**
	 * Warning messages the scanner logged.
	 *
	 * @var list<string>
	 */
	private array $warnings = [];

	/**
	 * Error messages the scanner logged.
	 *
	 * @var list<string>
	 */
	private array $errors = [];

	/**
	 * Whether recording the completed pass fails.
	 *
	 * @var bool
	 */
	private bool $failRecording = false;

	/**
	 * A moment well in the past, so seeded rows sort among real ones harmlessly.
	 *
	 * @var DateTime
	 */
	private DateTime $base;

	/**
	 * Open the database.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		parent::setUp();
		$this->openDatabase();
		$this->base = new DateTime('2026-09-01T10:00:00+00:00');
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
	 * The base moment moved by some seconds.
	 *
	 * @param int $seconds Seconds after the base (negative = before)
	 *
	 * @return DateTime
	 */
	private function at(int $seconds): DateTime {
		return (clone $this->base)->modify(($seconds >= 0 ? '+' : '').$seconds.' seconds');
	}//end at()

	/**
	 * A scanner on the given connection, an in-memory app config and a
	 * recording logger.
	 *
	 * @param IDBConnection|null $db           The connection (the real one when null)
	 * @param int                $pageSize     Candidate rows per query
	 * @param SiemService|null   $siem         The SIEM forwarder (one with no sinks when null)
	 * @param AuditService|null  $auditService The audit log (the real one when null)
	 *
	 * @return ShareQuarantineScanner
	 */
	private function scanner(
		?IDBConnection $db = null,
		int $pageSize = ShareQuarantineScanner::PAGE_SIZE,
		?SiemService $siem = null,
		?AuditService $auditService = null,
	): ShareQuarantineScanner {
		$appConfig = $this->createMock(IAppConfig::class);
		$appConfig->method('getValueString')->willReturnCallback(
			fn (string $app, string $key, string $default = ''): string => ($this->config[$key] ?? $default)
		);
		$appConfig->method('setValueString')->willReturnCallback(
			function (string $app, string $key, string $value): bool {
				if ($this->failRecording === true) {
					throw new RuntimeException('app config is read-only');
				}

				$this->config[$key] = $value;
				return true;
			}
		);
		$appConfig->method('deleteKey')->willReturnCallback(
			function (string $app, string $key): void {
				unset($this->config[$key]);
			}
		);

		$logger = $this->createMock(LoggerInterface::class);
		$logger->method('warning')->willReturnCallback(function (string|\Stringable $message): void {
			$this->warnings[] = (string)$message;
		});
		$logger->method('error')->willReturnCallback(function (string|\Stringable $message): void {
			$this->errors[] = (string)$message;
		});

		return new ShareQuarantineScanner(
			db: ($db ?? $this->db),
			appConfig: $appConfig,
			logger: $logger,
			auditService: ($auditService ?? new AuditService(mapper: new AuditEntryMapper(db: $this->db))),
			siem: ($siem ?? $this->createMock(SiemService::class)),
			pageSize: $pageSize,
		);
	}//end scanner()

	/**
	 * Write an audit entry.
	 *
	 * @param string   $eventType  The event type
	 * @param string   $objectType The object type
	 * @param string   $objectId   The object id
	 * @param DateTime $at         When it occurred
	 *
	 * @return void
	 */
	private function auditEntry(string $eventType, string $objectType, string $objectId, DateTime $at): void {
		$entry = new AuditEntry();
		$entry->setOccurredAt($at);
		$entry->setActorType('user');
		$entry->setActorId($this->user('actor'));
		$entry->setEventType($eventType);
		$entry->setObjectType($objectType);
		$entry->setObjectId($objectId);
		$entry->setObjectName('entry');
		(new AuditEntryMapper(db: $this->db))->insert($entry);
	}//end auditEntry()

	/**
	 * A share row giving someone a fresh copy of a secret: the way the secret's
	 * id reaches them.
	 *
	 * @param Secret   $secret    The shared secret
	 * @param string   $recipient Who it was shared with
	 * @param DateTime $at        When
	 *
	 * @return ShareTarget
	 */
	private function shareOf(Secret $secret, string $recipient, DateTime $at): ShareTarget {
		return $this->insertShareTarget(
			sourceSecretId: $secret->getId(),
			targetUserId: $recipient,
			secretId: $this->insertSecret(ownerId: $recipient, createdAt: $at)->getId(),
			createdBy: $secret->getOwnerId(),
			createdAt: $at,
		);
	}//end shareOf()

	/**
	 * A row planted on the victim's own secret.
	 *
	 * @param Secret   $copy     The victim's own secret
	 * @param string   $attacker Who planted it
	 * @param DateTime $at       When
	 *
	 * @return ShareTarget
	 */
	private function plant(Secret $copy, string $attacker, DateTime $at): ShareTarget {
		return $this->insertShareTarget(
			sourceSecretId: $this->insertSecret(ownerId: $attacker, createdAt: $this->at(-3600))->getId(),
			targetUserId: $copy->getOwnerId(),
			secretId: $copy->getId(),
			createdBy: $attacker,
			createdAt: $at,
		);
	}//end plant()

	/**
	 * The scan-time audit entries for a row.
	 *
	 * @param ShareTarget $row The row
	 *
	 * @return list<AuditEntry>
	 */
	private function quarantineEntries(ShareTarget $row): array {
		return array_values(array_filter(
			(new AuditEntryMapper(db: $this->db))->findByObject(objectType: 'share', objectId: $row->getId()),
			static fn (AuditEntry $entry): bool => $entry->getEventType() === AuditEventTypes::SHARE_QUARANTINED
		));
	}//end quarantineEntries()

	/**
	 * A row as stored now.
	 *
	 * @param ShareTarget $row The seeded row
	 *
	 * @return array{quarantined:bool,reason:string|null}
	 */
	private function stored(ShareTarget $row): array {
		$qb = $this->db->getQueryBuilder();
		$qb->select('quarantined_at', 'quarantine_reason')
			->from('keepiq_share_targets')
			->where($qb->expr()->eq('id', $qb->createNamedParameter($row->getId())));
		$result = $qb->executeQuery();
		$found  = $result->fetch();
		$result->closeCursor();
		$this->assertNotFalse($found, 'the row is never deleted');

		return [
			'quarantined' => ($found['quarantined_at'] !== null),
			'reason' => $found['quarantine_reason'],
		];
	}//end stored()

	/**
	 * The advisory's timeline at its fastest: the victim creates V and shares
	 * it with the attacker, and the attacker plants {S_a, victim, V} inside
	 * the mint window. The row is quarantined because V was shared with the
	 * row's creator first.
	 *
	 * @return void
	 */
	public function testQuarantinesARowPlantedSoonAfterItsCopyWasSharedWithTheAttacker(): void {
		$victim   = $this->user('victim');
		$attacker = $this->user('attacker');
		$secretV  = $this->insertSecret(ownerId: $victim, createdAt: $this->at(0));
		$sourceA  = $this->insertSecret(ownerId: $attacker, createdAt: $this->at(-3600));
		// The victim's share of V to the attacker, which tells him V's id.
		$this->insertShareTarget(
			sourceSecretId: $secretV->getId(),
			targetUserId: $attacker,
			secretId: $this->insertSecret(ownerId: $attacker, createdAt: $this->at(1))->getId(),
			createdBy: $victim,
			createdAt: $this->at(1),
		);
		$planted = $this->insertShareTarget(
			sourceSecretId: $sourceA->getId(),
			targetUserId: $victim,
			secretId: $secretV->getId(),
			createdBy: $attacker,
			createdAt: $this->at(ShareQuarantineScanner::MINT_WINDOW_SECONDS),
		);

		$this->assertTrue($this->scanner()->run());

		$this->assertSame(
			['quarantined' => true, 'reason' => ShareQuarantineScanner::REASON_COPY_SHARED_BEFORE_ROW],
			$this->stored(row: $planted)
		);
		$this->assertSame($victim, (new \OCA\Keepiq\Db\SecretMapper(db: $this->db))->findById($secretV->getId())->getOwnerId());
	}//end testQuarantinesARowPlantedSoonAfterItsCopyWasSharedWithTheAttacker()

	/**
	 * The same planted row is caught when the victim's share of V to the
	 * attacker was revoked since: the register-batch audit entry still names V
	 * and the attacker.
	 *
	 * @return void
	 */
	public function testFindsTheShareInTheAuditLogAfterItWasRevoked(): void {
		$victim   = $this->user('victim');
		$attacker = $this->user('attacker');
		$secretV  = $this->insertSecret(ownerId: $victim, createdAt: $this->at(0));
		$entry    = new AuditEntry();
		$entry->setOccurredAt($this->at(1));
		$entry->setActorType('user');
		$entry->setActorId($victim);
		$entry->setEventType(AuditEventTypes::SHARE_GRANTED);
		$entry->setObjectType('secret');
		$entry->setObjectId($secretV->getId());
		$entry->setObjectName('V');
		$entry->setMetadata((string)json_encode(['recipientType' => 'user', 'recipientId' => $attacker]));
		(new AuditEntryMapper(db: $this->db))->insert($entry);
		$planted = $this->insertShareTarget(
			sourceSecretId: $this->insertSecret(ownerId: $attacker, createdAt: $this->at(-3600))->getId(),
			targetUserId: $victim,
			secretId: $secretV->getId(),
			createdBy: $attacker,
			createdAt: $this->at(3),
		);

		$this->scanner()->run();

		$this->assertSame(ShareQuarantineScanner::REASON_COPY_SHARED_BEFORE_ROW, $this->stored(row: $planted)['reason']);
	}//end testFindsTheShareInTheAuditLogAfterItWasRevoked()

	/**
	 * V shared with an accomplice tells the attacker its id just as well: a row
	 * planted by someone V was never shared with is quarantined too, whether
	 * the share is still a row or only an audit entry naming someone else.
	 *
	 * @return void
	 */
	public function testQuarantinesARowWhoseCopyWasSharedWithSomeoneElse(): void {
		$victim     = $this->user('victim');
		$accomplice = $this->user('accomplice');
		$secretV    = $this->insertSecret(ownerId: $victim, createdAt: $this->at(0));
		$this->shareOf(secret: $secretV, recipient: $accomplice, at: $this->at(1));
		$planted = $this->plant(copy: $secretV, attacker: $this->user('attacker'), at: $this->at(3));

		$secretW = $this->insertSecret(ownerId: $victim, createdAt: $this->at(0));
		$entry   = new AuditEntry();
		$entry->setOccurredAt($this->at(1));
		$entry->setActorType('user');
		$entry->setActorId($victim);
		$entry->setEventType(AuditEventTypes::SHARE_GRANTED);
		$entry->setObjectType('secret');
		$entry->setObjectId($secretW->getId());
		$entry->setObjectName('W');
		$entry->setMetadata((string)json_encode(['recipientType' => 'user', 'recipientId' => $accomplice]));
		(new AuditEntryMapper(db: $this->db))->insert($entry);
		$plantedAfterRevoke = $this->plant(copy: $secretW, attacker: $this->user('attacker'), at: $this->at(3));

		$this->scanner()->run();

		$this->assertSame(
			['quarantined' => true, 'reason' => ShareQuarantineScanner::REASON_COPY_SHARED_BEFORE_ROW],
			$this->stored(row: $planted)
		);
		$this->assertSame(
			['quarantined' => true, 'reason' => ShareQuarantineScanner::REASON_COPY_SHARED_BEFORE_ROW],
			$this->stored(row: $plantedAfterRevoke)
		);
	}//end testQuarantinesARowWhoseCopyWasSharedWithSomeoneElse()

	/**
	 * Each quarantine is written to the audit log with the system as actor and
	 * the row's source, recipient, copy and reason, so the plant stays on
	 * record after the row is dropped. A row already quarantined is not
	 * audited again.
	 *
	 * @return void
	 */
	public function testAuditsEachQuarantine(): void {
		$victim  = $this->user('victim');
		$secretV = $this->insertSecret(ownerId: $victim, createdAt: $this->at(0));
		$this->shareOf(secret: $secretV, recipient: $this->user('accomplice'), at: $this->at(10));
		$planted = $this->plant(copy: $secretV, attacker: $this->user('attacker'), at: $this->at(40));

		$this->scanner()->run();
		$this->scanner()->run();

		$entries = $this->quarantineEntries(row: $planted);
		$this->assertCount(1, $entries);
		$this->assertSame(AuditEvent::ACTOR_SYSTEM, $entries[0]->getActorType());
		$this->assertSame(
			[
				'sourceSecretId' => $planted->getSourceSecretId(),
				'recipientId' => $victim,
				'copyId' => $secretV->getId(),
				'reason' => ShareQuarantineScanner::REASON_COPY_SHARED_BEFORE_ROW,
				'copyUseOnly' => false,
				'copyAccessEndsAt' => null,
			],
			$entries[0]->getMetadataArray()
		);
	}//end testAuditsEachQuarantine()

	/**
	 * A planted row's restriction may have been written onto the victim's own
	 * secret: the scan's log and audit entry say when the copy is use-only or
	 * has an access end date.
	 *
	 * @return void
	 */
	public function testReportsTheRestrictionsTheCopyCarries(): void {
		$victim  = $this->user('victim');
		$secretV = $this->insertSecret(ownerId: $victim, createdAt: $this->at(0));
		$secretV->setUseOnly(true);
		$secretV->setAccessExpiresAt(new DateTime('2026-12-01T09:00:00+00:00'));
		(new SecretMapper(db: $this->db))->update($secretV);
		$planted = $this->plant(copy: $secretV, attacker: $this->user('attacker'), at: $this->at(3600));

		$this->scanner()->run();

		$metadata = $this->quarantineEntries(row: $planted)[0]->getMetadataArray();
		$this->assertTrue($metadata['copyUseOnly']);
		$this->assertSame('2026-12-01T09:00:00+00:00', $metadata['copyAccessEndsAt']);
		$logged = array_values(array_filter($this->warnings, static fn (string $m): bool => str_contains($m, $planted->getId())));
		$this->assertCount(1, $logged);
		$this->assertStringContainsString('the copy is use-only and has its access end at 2026-12-01T09:00:00+00:00', $logged[0]);
	}//end testReportsTheRestrictionsTheCopyCarries()

	/**
	 * Each quarantine is forwarded to the SIEM sinks too, as plain queue rows:
	 * no event listener is involved.
	 *
	 * @return void
	 */
	public function testForwardsEachQuarantineToTheSiemSinks(): void {
		$sink = new SiemSink();
		$sink->setId(Uuid::uuid4()->toString());
		$sink->setName('test sink');
		$sink->setType('webhook');
		$sink->setEndpoint('https://siem.example.invalid/');
		$sink->setCreatedBy('admin');
		$sink->setCreatedAt(new DateTime());
		$sink->setUpdatedAt(new DateTime());
		(new SiemSinkMapper(db: $this->db))->insert($sink);
		$queue = new SiemQueueItemMapper(db: $this->db);
		$siem  = new SiemService(
			sinkMapper: new SiemSinkMapper(db: $this->db),
			queueMapper: $queue,
			transport: $this->createMock(SiemTransport::class),
			sinkService: $this->createMock(SiemSinkService::class),
			groupManager: $this->createMock(IGroupManager::class),
			notificationService: null,
			logger: $this->createMock(LoggerInterface::class),
		);
		$planted = $this->plant(
			copy: $this->insertSecret(ownerId: $this->user('victim'), createdAt: $this->at(0)),
			attacker: $this->user('attacker'),
			at: $this->at(3600)
		);

		$this->scanner(siem: $siem)->run();

		$forwarded = array_values(array_filter(
			array_map(
				static fn (SiemQueueItem $item): array => json_decode($item->getPayload(), true),
				$queue->findDue($sink->getId(), new DateTime('+1 hour'), 1000)
			),
			static fn (array $payload): bool => $payload['objectId'] === $planted->getId()
		));
		$this->assertCount(1, $forwarded);
		$this->assertSame(AuditEventTypes::SHARE_QUARANTINED, $forwarded[0]['eventType']);
		$this->assertSame(AuditEvent::ACTOR_SYSTEM, $forwarded[0]['actorType']);
		$this->assertSame(ShareQuarantineScanner::REASON_COPY_PREDATES_ROW, $forwarded[0]['metadata']['reason']);
	}//end testForwardsEachQuarantineToTheSiemSinks()

	/**
	 * A member contributes T to a team folder: T is owned by the folder owner,
	 * and its id is returned to the member, who links it into a row aimed at
	 * the owner seconds later, or in the same second. T's `secret.created`
	 * entry catches both, as no server-minted copy has one.
	 *
	 * @return void
	 */
	public function testQuarantinesARowWhoseCopyWasCreatedAsASecret(): void {
		$folderOwner = $this->user('folder-owner');
		$member      = $this->user('member');
		$secretT     = $this->insertSecret(ownerId: $folderOwner, createdAt: $this->at(0));
		$this->auditEntry(eventType: AuditEventTypes::SECRET_CREATED, objectType: 'secret', objectId: $secretT->getId(), at: $this->at(0));
		$soonAfter = $this->plant(copy: $secretT, attacker: $member, at: $this->at(5));

		$secretU = $this->insertSecret(ownerId: $folderOwner, createdAt: $this->at(0));
		$this->auditEntry(eventType: AuditEventTypes::SECRET_CREATED, objectType: 'secret', objectId: $secretU->getId(), at: $this->at(0));
		$sameSecond = $this->plant(copy: $secretU, attacker: $member, at: $this->at(0));

		$this->scanner()->run();

		$this->assertSame(
			['quarantined' => true, 'reason' => ShareQuarantineScanner::REASON_COPY_CREATED_AS_SECRET],
			$this->stored(row: $soonAfter)
		);
		$this->assertSame(
			['quarantined' => true, 'reason' => ShareQuarantineScanner::REASON_COPY_CREATED_AS_SECRET],
			$this->stored(row: $sameSecond)
		);
	}//end testQuarantinesARowWhoseCopyWasCreatedAsASecret()

	/**
	 * A server-minted row is kept: its source has a `secret.created` entry,
	 * its copy has none. Neither does an entry of another type on the copy, or
	 * a `secret.created` entry for another kind of object with the copy's id,
	 * make it suspect.
	 *
	 * @return void
	 */
	public function testKeepsAServerMintedRowWhoseCopyHasNoCreatedEntry(): void {
		$owner  = $this->user('owner');
		$source = $this->insertSecret(ownerId: $owner, createdAt: $this->at(-3600));
		$this->auditEntry(eventType: AuditEventTypes::SECRET_CREATED, objectType: 'secret', objectId: $source->getId(), at: $this->at(-3600));
		$minted = $this->shareOf(secret: $source, recipient: $this->user('recipient'), at: $this->at(0));

		$updated = $this->shareOf(secret: $source, recipient: $this->user('recipient'), at: $this->at(0));
		$this->auditEntry(eventType: AuditEventTypes::SECRET_UPDATED, objectType: 'secret', objectId: $updated->getSecretId(), at: $this->at(0));

		$otherObject = $this->shareOf(secret: $source, recipient: $this->user('recipient'), at: $this->at(0));
		$this->auditEntry(eventType: AuditEventTypes::SECRET_CREATED, objectType: 'share', objectId: $otherObject->getSecretId(), at: $this->at(0));

		$this->scanner()->run();

		$this->assertSame(['quarantined' => false, 'reason' => null], $this->stored(row: $minted));
		$this->assertSame(['quarantined' => false, 'reason' => null], $this->stored(row: $updated));
		$this->assertSame(['quarantined' => false, 'reason' => null], $this->stored(row: $otherObject));
	}//end testKeepsAServerMintedRowWhoseCopyHasNoCreatedEntry()

	/**
	 * A row planted in the same second its copy was shared counts as planted
	 * after that share, whether the share is a row or an audit entry.
	 *
	 * @return void
	 */
	public function testQuarantinesARowPlantedInTheSecondItsCopyWasShared(): void {
		$victim  = $this->user('victim');
		$secretV = $this->insertSecret(ownerId: $victim, createdAt: $this->at(0));
		$this->shareOf(secret: $secretV, recipient: $this->user('accomplice'), at: $this->at(3));
		$plantedAfterRow = $this->plant(copy: $secretV, attacker: $this->user('attacker'), at: $this->at(3));

		$secretW = $this->insertSecret(ownerId: $victim, createdAt: $this->at(0));
		$this->auditEntry(eventType: AuditEventTypes::SHARE_GRANTED, objectType: 'secret', objectId: $secretW->getId(), at: $this->at(3));
		$plantedAfterEntry = $this->plant(copy: $secretW, attacker: $this->user('attacker'), at: $this->at(3));

		$this->scanner()->run();

		$this->assertSame(ShareQuarantineScanner::REASON_COPY_SHARED_BEFORE_ROW, $this->stored(row: $plantedAfterRow)['reason']);
		$this->assertSame(ShareQuarantineScanner::REASON_COPY_SHARED_BEFORE_ROW, $this->stored(row: $plantedAfterEntry)['reason']);
	}//end testQuarantinesARowPlantedInTheSecondItsCopyWasShared()

	/**
	 * Only a `share.granted` entry naming the copy as a secret counts as a
	 * share of it: another event on the copy, or a grant whose object is
	 * another kind with the same id, leaves a row inside the window alone.
	 *
	 * @return void
	 */
	public function testOnlyAGrantOfTheCopyCountsAsAShare(): void {
		$victim  = $this->user('victim');
		$secretV = $this->insertSecret(ownerId: $victim, createdAt: $this->at(0));
		$this->auditEntry(eventType: AuditEventTypes::SHARE_GRANTED, objectType: 'share', objectId: $secretV->getId(), at: $this->at(1));
		$grantOfAnotherKind = $this->plant(copy: $secretV, attacker: $this->user('attacker'), at: $this->at(3));

		$secretW = $this->insertSecret(ownerId: $victim, createdAt: $this->at(0));
		$this->auditEntry(eventType: AuditEventTypes::SECRET_READ, objectType: 'secret', objectId: $secretW->getId(), at: $this->at(1));
		$anotherEvent = $this->plant(copy: $secretW, attacker: $this->user('attacker'), at: $this->at(3));

		$this->scanner()->run();

		$this->assertSame(['quarantined' => false, 'reason' => null], $this->stored(row: $grantOfAnotherKind));
		$this->assertSame(['quarantined' => false, 'reason' => null], $this->stored(row: $anotherEvent));
	}//end testOnlyAGrantOfTheCopyCountsAsAShare()

	/**
	 * A copy exactly MINT_WINDOW_SECONDS older than its row is still inside the
	 * window.
	 *
	 * @return void
	 */
	public function testKeepsARowExactlyAtTheEdgeOfTheMintWindow(): void {
		$recipient = $this->user('recipient');
		$row       = $this->insertShareTarget(
			sourceSecretId: $this->insertSecret(ownerId: $this->user('owner'), createdAt: $this->at(-3600))->getId(),
			targetUserId: $recipient,
			secretId: $this->insertSecret(ownerId: $recipient, createdAt: $this->at(0))->getId(),
			createdBy: $this->user('owner'),
			createdAt: $this->at(ShareQuarantineScanner::MINT_WINDOW_SECONDS),
		);

		$this->scanner()->run();

		$this->assertSame(['quarantined' => false, 'reason' => null], $this->stored(row: $row));
	}//end testKeepsARowExactlyAtTheEdgeOfTheMintWindow()

	/**
	 * A row whose copy predates it by more than the mint window is quarantined
	 * on that signal alone, even when its copy is no longer any share's source.
	 *
	 * @return void
	 */
	public function testQuarantinesARowWhoseCopyPredatesItBeyondTheWindow(): void {
		$victim  = $this->user('victim');
		$planted = $this->insertShareTarget(
			sourceSecretId: $this->insertSecret(ownerId: $this->user('attacker'), createdAt: $this->at(-3600))->getId(),
			targetUserId: $victim,
			secretId: $this->insertSecret(ownerId: $victim, createdAt: $this->at(0))->getId(),
			createdBy: $this->user('attacker'),
			createdAt: $this->at(ShareQuarantineScanner::MINT_WINDOW_SECONDS + 1),
		);

		$this->scanner()->run();

		$this->assertSame(
			['quarantined' => true, 'reason' => ShareQuarantineScanner::REASON_COPY_PREDATES_ROW],
			$this->stored(row: $planted)
		);
	}//end testQuarantinesARowWhoseCopyPredatesItBeyondTheWindow()

	/**
	 * A row minted in the same request as its copy is kept: in the same second,
	 * and one second apart when a second boundary falls between the two inserts.
	 * The copy shared onward afterwards, back to the row's creator or to anyone
	 * else, does not make it suspect either, as those shares came after the row.
	 *
	 * @return void
	 */
	public function testKeepsRowsMintedInTheSameRequest(): void {
		$owner     = $this->user('owner');
		$recipient = $this->user('recipient');
		$source    = $this->insertSecret(ownerId: $owner, createdAt: $this->at(-3600));
		$sameSecond = $this->insertShareTarget(
			sourceSecretId: $source->getId(),
			targetUserId: $recipient,
			secretId: $this->insertSecret(ownerId: $recipient, createdAt: $this->at(0))->getId(),
			createdBy: $owner,
			createdAt: $this->at(0),
		);
		$copy = $this->insertSecret(ownerId: $recipient, createdAt: $this->at(9));
		$acrossASecond = $this->insertShareTarget(
			sourceSecretId: $this->insertSecret(ownerId: $owner, createdAt: $this->at(-3600))->getId(),
			targetUserId: $recipient,
			secretId: $copy->getId(),
			createdBy: $owner,
			createdAt: $this->at(10),
		);
		$this->insertShareTarget(
			sourceSecretId: $copy->getId(),
			targetUserId: $owner,
			secretId: $this->insertSecret(ownerId: $owner, createdAt: $this->at(500))->getId(),
			createdBy: $recipient,
			createdAt: $this->at(500),
		);
		$this->shareOf(secret: $copy, recipient: $this->user('colleague'), at: $this->at(11));

		$this->scanner()->run();

		$this->assertSame(['quarantined' => false, 'reason' => null], $this->stored(row: $sameSecond));
		$this->assertSame(['quarantined' => false, 'reason' => null], $this->stored(row: $acrossASecond));
	}//end testKeepsRowsMintedInTheSameRequest()

	/**
	 * A plant whose evidence is gone: V was created 30 seconds before the
	 * row, and no share row, `share.granted` entry or `secret.created` entry
	 * names V any more (the accomplice's account was deleted and the audit
	 * retention passed). The gap alone quarantines it.
	 *
	 * @return void
	 */
	public function testQuarantinesAPlantThirtySecondsAfterItsCopyWithNoOtherEvidence(): void {
		$victim  = $this->user('victim');
		$secretV = $this->insertSecret(ownerId: $victim, createdAt: $this->at(0));
		$planted = $this->plant(copy: $secretV, attacker: $this->user('attacker'), at: $this->at(30));

		$this->scanner()->run();

		$this->assertSame(
			['quarantined' => true, 'reason' => ShareQuarantineScanner::REASON_COPY_PREDATES_ROW],
			$this->stored(row: $planted)
		);
	}//end testQuarantinesAPlantThirtySecondsAfterItsCopyWithNoOtherEvidence()

	/**
	 * The scan pages through the candidates by row id and reaches every one,
	 * however many pages they take.
	 *
	 * @return void
	 */
	public function testPagesThroughEveryCandidate(): void {
		$rows = [];
		for ($i = 0; $i < 5; $i++) {
			$victim = $this->user('victim');
			$rows[] = $this->insertShareTarget(
				sourceSecretId: $this->insertSecret(ownerId: $this->user('attacker'), createdAt: $this->at(-3600))->getId(),
				targetUserId: $victim,
				secretId: $this->insertSecret(ownerId: $victim, createdAt: $this->at(0))->getId(),
				createdBy: $this->user('attacker'),
				createdAt: $this->at(3600),
			);
		}

		$this->assertTrue($this->scanner(pageSize: 2)->run());

		foreach ($rows as $row) {
			$this->assertTrue($this->stored(row: $row)['quarantined'], 'row '.$row->getId().' was reached');
		}
	}//end testPagesThroughEveryCandidate()

	/**
	 * A row an administrator released keeps its reason, and a later pass
	 * leaves it alone.
	 *
	 * @return void
	 */
	public function testAReleasedRowIsNotQuarantinedAgain(): void {
		$victim  = $this->user('victim');
		$planted = $this->insertShareTarget(
			sourceSecretId: $this->insertSecret(ownerId: $this->user('owner'), createdAt: $this->at(-3600))->getId(),
			targetUserId: $victim,
			secretId: $this->insertSecret(ownerId: $victim, createdAt: $this->at(0))->getId(),
			createdBy: $this->user('owner'),
			createdAt: $this->at(3600),
		);
		$this->scanner()->run();
		(new ShareQuarantineService(
			quarantined: new QuarantinedShareTargetMapper(db: $this->db),
			mapper: new ShareTargetMapper(db: $this->db),
			secretMapper: new SecretMapper(db: $this->db),
			groupShareMapper: new GroupShareMapper(db: $this->db),
			eventDispatcher: $this->createMock(IEventDispatcher::class),
		))->release(id: $planted->getId(), force: true);

		$this->scanner()->run();

		$this->assertFalse($this->stored(row: $planted)['quarantined']);
	}//end testAReleasedRowIsNotQuarantinedAgain()

	/**
	 * The scan fails closed and retries: a run whose query throws logs an
	 * error, quarantines nothing and leaves the completion flag unset; the
	 * background job's next run quarantines the planted row and sets the flag;
	 * a run after that does nothing.
	 *
	 * @return void
	 */
	public function testAFailedRunStaysPendingAndTheJobsNextRunCompletesIt(): void {
		$victim  = $this->user('victim');
		$planted = $this->insertShareTarget(
			sourceSecretId: $this->insertSecret(ownerId: $this->user('attacker'), createdAt: $this->at(-3600))->getId(),
			targetUserId: $victim,
			secretId: $this->insertSecret(ownerId: $victim, createdAt: $this->at(0))->getId(),
			createdBy: $this->user('attacker'),
			createdAt: $this->at(3600),
		);

		$failing = true;
		$real    = $this->db;
		$db      = $this->createMock(IDBConnection::class);
		$db->method('getQueryBuilder')->willReturnCallback(
			static function () use (&$failing, $real): IQueryBuilder {
				if ($failing === true) {
					throw new RuntimeException('database went away');
				}

				return $real->getQueryBuilder();
			}
		);
		$job = new ShareQuarantineScanJob(
			time: $this->createMock(ITimeFactory::class),
			scanner: $this->scanner(db: $db),
			restrictions: $this->createMock(ShareRestrictionResolver::class),
			logger: $this->createMock(LoggerInterface::class),
		);
		$run = new ReflectionMethod($job, 'run');

		$run->invoke($job, null);
		$this->assertArrayNotHasKey(ShareQuarantineScanner::COMPLETED_KEY, $this->config, 'a failed pass is not recorded as done');
		$this->assertFalse($this->stored(row: $planted)['quarantined']);
		$this->assertCount(1, $this->errors, 'the failure is logged at error level');

		$failing = false;
		$run->invoke($job, null);
		$this->assertTrue($this->stored(row: $planted)['quarantined'], 'the next run quarantines the planted row');
		$this->assertArrayHasKey(ShareQuarantineScanner::COMPLETED_KEY, $this->config, 'and records the pass as done');

		$failing = true;
		$run->invoke($job, null);
		$this->assertCount(1, $this->errors, 'a completed scan is not run again');
	}//end testAFailedRunStaysPendingAndTheJobsNextRunCompletesIt()

	/**
	 * A restriction resolve that skips a row the pending scan cannot judge is
	 * recorded, and the job resolves the copy once the scan completes: a
	 * use-only row does not keep a full copy for good.
	 *
	 * @return void
	 */
	public function testAResolveTheScanCouldNotJudgeIsDoneOnceTheScanCompletes(): void {
		$owner     = $this->user('owner');
		$recipient = $this->user('recipient');
		$row       = $this->insertShareTarget(
			sourceSecretId: $this->insertSecret(ownerId: $owner, createdAt: $this->at(-3600))->getId(),
			targetUserId: $recipient,
			secretId: $this->insertSecret(ownerId: $recipient, createdAt: $this->at(0))->getId(),
			createdBy: $owner,
			createdAt: $this->at(0),
		);
		$row->setUseOnly(true);
		$row = (new ShareTargetMapper(db: $this->db))->update($row);

		$failing = true;
		$real    = $this->db;
		$db      = $this->createMock(IDBConnection::class);
		$db->method('getQueryBuilder')->willReturnCallback(
			static function () use (&$failing, $real): IQueryBuilder {
				if ($failing === true) {
					throw new RuntimeException('database went away');
				}

				return $real->getQueryBuilder();
			}
		);
		$scanner      = $this->scanner(db: $db);
		$restrictions = $this->restrictions(scanner: $scanner);

		$this->assertFalse($restrictions->resolveTarget(target: $row), 'the pending scan cannot judge the row');
		$this->assertNotTrue($this->copyOf(row: $row)->getUseOnly());
		$this->assertArrayHasKey(ShareQuarantineScanner::RESOLVE_PENDING_KEY, $this->config);

		$failing = false;
		$job     = new ShareQuarantineScanJob(
			time: $this->createMock(ITimeFactory::class),
			scanner: $scanner,
			restrictions: $restrictions,
			logger: $this->createMock(LoggerInterface::class),
		);
		(new ReflectionMethod($job, 'run'))->invoke($job, null);

		$this->assertTrue($scanner->isComplete());
		$this->assertTrue($this->copyOf(row: $row)->getUseOnly(), 'the copy takes the row\'s restriction');
		$this->assertArrayNotHasKey(ShareQuarantineScanner::RESOLVE_PENDING_KEY, $this->config);
	}//end testAResolveTheScanCouldNotJudgeIsDoneOnceTheScanCompletes()

	/**
	 * The job leaves a held-back resolve recorded while the scan is pending,
	 * and when resolving fails, so a later run does it.
	 *
	 * @return void
	 */
	public function testAHeldBackResolveStaysRecordedUntilItIsDone(): void {
		$this->config[ShareQuarantineScanner::RESOLVE_PENDING_KEY] = '1';
		$broken = $this->createMock(IDBConnection::class);
		$broken->method('getQueryBuilder')->willThrowException(new RuntimeException('database went away'));
		$restrictions = $this->createMock(ShareRestrictionResolver::class);
		$restrictions->expects($this->once())->method('resolveAll')->willThrowException(new RuntimeException('resolve failed'));
		$logger = $this->createMock(LoggerInterface::class);
		$logger->expects($this->atLeastOnce())->method('error');

		$pending = new ShareQuarantineScanJob(
			time: $this->createMock(ITimeFactory::class),
			scanner: $this->scanner(db: $broken),
			restrictions: $restrictions,
			logger: $logger,
		);
		(new ReflectionMethod($pending, 'run'))->invoke($pending, null);
		$this->assertArrayHasKey(ShareQuarantineScanner::RESOLVE_PENDING_KEY, $this->config, 'kept while the scan is pending');

		$this->config[ShareQuarantineScanner::COMPLETED_KEY] = ShareQuarantineScanner::INITIAL_GENERATION;
		(new ReflectionMethod($pending, 'run'))->invoke($pending, null);
		$this->assertArrayHasKey(ShareQuarantineScanner::RESOLVE_PENDING_KEY, $this->config, 'kept when resolving fails');
	}//end testAHeldBackResolveStaysRecordedUntilItIsDone()

	/**
	 * A resolve held back while the job resolves every copy stays recorded
	 * after the run, so the next run picks up the copy it skipped.
	 *
	 * @return void
	 */
	public function testAResolveHeldBackDuringTheRunStaysRecorded(): void {
		$this->config[ShareQuarantineScanner::COMPLETED_KEY] = ShareQuarantineScanner::INITIAL_GENERATION;
		$scanner = $this->scanner();
		$scanner->holdBackResolve();
		$restrictions = $this->createMock(ShareRestrictionResolver::class);
		$restrictions->expects($this->once())->method('resolveAll')->willReturnCallback(
			static function () use ($scanner): int {
				$scanner->holdBackResolve();
				return 0;
			}
		);

		$job = new ShareQuarantineScanJob(
			time: $this->createMock(ITimeFactory::class),
			scanner: $scanner,
			restrictions: $restrictions,
			logger: $this->createMock(LoggerInterface::class),
		);
		(new ReflectionMethod($job, 'run'))->invoke($job, null);

		$this->assertArrayHasKey(ShareQuarantineScanner::RESOLVE_PENDING_KEY, $this->config);
	}//end testAResolveHeldBackDuringTheRunStaysRecorded()

	/**
	 * The restriction resolver on the real mappers with the given scanner;
	 * nobody is in a group and no team folder covers anyone.
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
	 * A row's copy as stored now.
	 *
	 * @param ShareTarget $row The row
	 *
	 * @return Secret
	 */
	private function copyOf(ShareTarget $row): Secret {
		return (new SecretMapper(db: $this->db))->findById($row->getSecretId());
	}//end copyOf()

	/**
	 * reopen() sets a completed scan pending again, so the job runs a new pass.
	 *
	 * @return void
	 */
	public function testReopenSetsACompletedScanPendingAgain(): void {
		$scanner = $this->scanner();
		$this->assertTrue($scanner->run());
		$this->assertTrue($scanner->isComplete());

		$scanner->reopen();

		$this->assertFalse($scanner->isComplete());
	}//end testReopenSetsACompletedScanPendingAgain()

	/**
	 * A failure to record the completed pass is handled like a failed pass:
	 * logged at error level, the pass stays pending, and the caller sees no
	 * exception.
	 *
	 * @return void
	 */
	public function testAFailureToRecordThePassLeavesItPending(): void {
		$this->failRecording = true;

		$this->assertFalse($this->scanner()->run());

		$this->assertArrayNotHasKey(ShareQuarantineScanner::COMPLETED_KEY, $this->config);
		$this->assertCount(1, $this->errors);
	}//end testAFailureToRecordThePassLeavesItPending()

	/**
	 * A pass that was already running when a restore reopened the scan walks
	 * the data from before the restore, so it does not record completion; the
	 * next pass, in the new generation, does.
	 *
	 * @return void
	 */
	public function testAPassReopenedWhileRunningDoesNotRecordCompletion(): void {
		$scanner  = null;
		$reopened = false;
		$real     = $this->db;
		$db       = $this->createMock(IDBConnection::class);
		$db->method('getQueryBuilder')->willReturnCallback(
			static function () use (&$scanner, &$reopened, $real): IQueryBuilder {
				if ($reopened === false) {
					$reopened = true;
					$scanner->reopen();
				}

				return $real->getQueryBuilder();
			}
		);
		$scanner = $this->scanner(db: $db);

		$this->assertFalse($scanner->run());
		$this->assertFalse($scanner->isComplete());
		$this->assertArrayNotHasKey(ShareQuarantineScanner::COMPLETED_KEY, $this->config);

		$this->assertTrue($scanner->run());
		$this->assertTrue($scanner->isComplete());
	}//end testAPassReopenedWhileRunningDoesNotRecordCompletion()

	/**
	 * A completion recorded for an earlier generation, as a pass that started
	 * before a reopen() and ended after it would write, does not count.
	 *
	 * @return void
	 */
	public function testACompletionOfAnEarlierGenerationDoesNotCount(): void {
		$scanner = $this->scanner();
		$this->assertTrue($scanner->run());

		$scanner->reopen();
		$this->config[ShareQuarantineScanner::COMPLETED_KEY] = ShareQuarantineScanner::INITIAL_GENERATION;

		$this->assertFalse($scanner->isComplete());
	}//end testACompletionOfAnEarlierGenerationDoesNotCount()

	/**
	 * While the scan is pending, heldBack() judges a candidate on the spot: a
	 * row a signal catches is quarantined, audited once and held back. A
	 * candidate no signal catches (copy and row a second apart), a row minted
	 * with its copy and a row an administrator released go through. Once a
	 * pass completed, nothing is judged or held back.
	 *
	 * @return void
	 */
	public function testHeldBackJudgesACandidateOnTheSpotUntilAPassCompleted(): void {
		$victim  = $this->user('victim');
		$planted = $this->plant(copy: $this->insertSecret(ownerId: $victim, createdAt: $this->at(0)), attacker: $this->user('attacker'), at: $this->at(3600));

		$secretT = $this->insertSecret(ownerId: $victim, createdAt: $this->at(0));
		$this->auditEntry(eventType: AuditEventTypes::SECRET_CREATED, objectType: 'secret', objectId: $secretT->getId(), at: $this->at(0));
		$createdAsSecret = $this->plant(copy: $secretT, attacker: $this->user('attacker'), at: $this->at(0));

		$owner     = $this->user('owner');
		$recipient = $this->user('recipient');
		$minted    = $this->shareOf(secret: $this->insertSecret(ownerId: $owner, createdAt: $this->at(-3600)), recipient: $recipient, at: $this->at(0));
		$acrossASecond = $this->insertShareTarget(
			sourceSecretId: $this->insertSecret(ownerId: $owner, createdAt: $this->at(-3600))->getId(),
			targetUserId: $recipient,
			secretId: $this->insertSecret(ownerId: $recipient, createdAt: $this->at(0))->getId(),
			createdBy: $owner,
			createdAt: $this->at(1),
		);
		$released = $this->plant(copy: $this->insertSecret(ownerId: $victim, createdAt: $this->at(0)), attacker: $this->user('attacker'), at: $this->at(3600));
		$qb = $this->db->getQueryBuilder();
		$qb->update('keepiq_share_targets')
			->set('quarantine_reason', $qb->createNamedParameter(ShareQuarantineScanner::REASON_COPY_PREDATES_ROW))
			->where($qb->expr()->eq('id', $qb->createNamedParameter($released->getId())))
			->executeStatement();

		$scanner = $this->scanner();

		$this->assertSame(ShareQuarantineScanner::HELD_QUARANTINED, $scanner->heldBack(row: $planted));
		$this->assertSame(ShareQuarantineScanner::REASON_COPY_PREDATES_ROW, $this->stored(row: $planted)['reason']);
		$this->assertSame(ShareQuarantineScanner::HELD_QUARANTINED, $scanner->heldBack(row: $createdAsSecret));
		$this->assertSame(ShareQuarantineScanner::REASON_COPY_CREATED_AS_SECRET, $this->stored(row: $createdAsSecret)['reason']);
		$this->assertCount(1, $this->quarantineEntries(row: $planted));
		$this->assertCount(1, $this->quarantineEntries(row: $createdAsSecret));

		foreach ([$minted, $acrossASecond, $released] as $row) {
			$this->assertNull($scanner->heldBack(row: $row));
			$this->assertFalse($this->stored(row: $row)['quarantined']);
		}

		$this->config[ShareQuarantineScanner::COMPLETED_KEY] = ShareQuarantineScanner::INITIAL_GENERATION;
		$later = $this->plant(copy: $this->insertSecret(ownerId: $victim, createdAt: $this->at(0)), attacker: $this->user('attacker'), at: $this->at(3600));
		$this->assertNull($scanner->heldBack(row: $later));
		$this->assertFalse($this->stored(row: $later)['quarantined'], 'a completed scan judges nothing on the spot');
	}//end testHeldBackJudgesACandidateOnTheSpotUntilAPassCompleted()

	/**
	 * When judging fails, the scan itself is broken: a candidate is held back
	 * unjudged, not quarantined, and the failure is logged at error level.
	 * That holds whether the candidate query or a signal's query fails.
	 *
	 * @return void
	 */
	public function testHeldBackHoldsARowBackUnjudgedWhenJudgingFails(): void {
		foreach ([1, 2] as $failFrom) {
			$planted = $this->plant(copy: $this->insertSecret(ownerId: $this->user('victim'), createdAt: $this->at(0)), attacker: $this->user('attacker'), at: $this->at(3600));
			$calls   = 0;
			$real    = $this->db;
			$db      = $this->createMock(IDBConnection::class);
			$db->method('getQueryBuilder')->willReturnCallback(
				static function () use (&$calls, $failFrom, $real): IQueryBuilder {
					if (++$calls >= $failFrom) {
						throw new RuntimeException('database went away');
					}

					return $real->getQueryBuilder();
				}
			);
			$this->errors = [];

			$this->assertSame(ShareQuarantineScanner::HELD_UNJUDGED, $this->scanner(db: $db)->heldBack(row: $planted), 'failing from query '.$failFrom);
			$this->assertFalse($this->stored(row: $planted)['quarantined']);
			$this->assertCount(1, $this->errors);
			$this->assertStringContainsString('could not be judged while the share quarantine scan is pending', $this->errors[0]);
		}
	}//end testHeldBackHoldsARowBackUnjudgedWhenJudgingFails()

	/**
	 * mayTouchCopy() needs both: the row's target holds the copy, and the
	 * pending scan does not hold the row back.
	 *
	 * @return void
	 */
	public function testMayTouchCopyNeedsAHeldCopyAndARowTheScanLetsThrough(): void {
		$victim    = $this->user('victim');
		$secretV   = $this->insertSecret(ownerId: $victim, createdAt: $this->at(0));
		$planted   = $this->plant(copy: $secretV, attacker: $this->user('attacker'), at: $this->at(3600));
		$recipient = $this->user('recipient');
		$minted    = $this->shareOf(secret: $this->insertSecret(ownerId: $this->user('owner'), createdAt: $this->at(-3600)), recipient: $recipient, at: $this->at(0));
		$secrets   = new SecretMapper(db: $this->db);
		$scanner   = $this->scanner();

		$this->assertTrue($scanner->mayTouchCopy(row: $minted, copy: $secrets->findById($minted->getSecretId())));
		$this->assertFalse($scanner->mayTouchCopy(row: $minted, copy: $secretV), 'a copy the target does not hold');
		$this->assertFalse($scanner->mayTouchCopy(row: $planted, copy: $secretV), 'a row the scan quarantines on the spot');
	}//end testMayTouchCopyNeedsAHeldCopyAndARowTheScanLetsThrough()

	/**
	 * An audit entry that cannot be written leaves the row quarantined, logs
	 * the failure, and does not fail the pass.
	 *
	 * @return void
	 */
	public function testAQuarantineWhoseAuditFailsStaysQuarantined(): void {
		$planted = $this->plant(
			copy: $this->insertSecret(ownerId: $this->user('victim'), createdAt: $this->at(0)),
			attacker: $this->user('attacker'),
			at: $this->at(3600)
		);
		$auditService = $this->createMock(AuditService::class);
		$auditService->method('record')->willThrowException(new RuntimeException('audit log is full'));

		$this->assertTrue($this->scanner(auditService: $auditService)->run());

		$this->assertTrue($this->stored(row: $planted)['quarantined']);
		$logged = array_values(array_filter($this->errors, static fn (string $m): bool => str_contains($m, $planted->getId())));
		$this->assertCount(1, $logged);
		$this->assertStringContainsString('could not be recorded or forwarded: audit log is full', $logged[0]);
	}//end testAQuarantineWhoseAuditFailsStaysQuarantined()

	/**
	 * A candidate whose dates cannot be read is kept: an unreadable value
	 * never triggers a quarantine.
	 *
	 * @return void
	 */
	public function testACandidateWithAnUnreadableDateIsKept(): void {
		$planted = $this->plant(
			copy: $this->insertSecret(ownerId: $this->user('victim'), createdAt: $this->at(0)),
			attacker: $this->user('attacker'),
			at: $this->at(3600)
		);
		$scanner = $this->scanner();
		$judge   = new ReflectionMethod($scanner, 'judge');

		$this->assertFalse($judge->invoke($scanner, $this->candidate(row: $planted, rowCreatedAt: 'not a date'), null));
		$this->assertSame(['quarantined' => false, 'reason' => null], $this->stored(row: $planted));
	}//end testACandidateWithAnUnreadableDateIsKept()

	/**
	 * A candidate that another pass quarantined after this one read it is not
	 * quarantined or audited again.
	 *
	 * @return void
	 */
	public function testACandidateQuarantinedMeanwhileIsNotAuditedAgain(): void {
		$victim  = $this->user('victim');
		$planted = $this->insertShareTarget(
			sourceSecretId: $this->insertSecret(ownerId: $this->user('attacker'), createdAt: $this->at(-3600))->getId(),
			targetUserId: $victim,
			secretId: $this->insertSecret(ownerId: $victim, createdAt: $this->at(0))->getId(),
			createdBy: $this->user('attacker'),
			createdAt: $this->at(3600),
			quarantined: true,
		);
		$scanner = $this->scanner();
		$judge   = new ReflectionMethod($scanner, 'judge');

		$this->assertFalse($judge->invoke($scanner, $this->candidate(row: $planted, rowCreatedAt: $this->at(3600)->format('Y-m-d H:i:s')), null));
		$this->assertSame('test', $this->stored(row: $planted)['reason']);
		$this->assertSame([], $this->quarantineEntries(row: $planted));
	}//end testACandidateQuarantinedMeanwhileIsNotAuditedAgain()

	/**
	 * A pass that fails after quarantining some rows leaves the scan pending;
	 * the next pass quarantines the rest, and every row is audited once.
	 *
	 * @return void
	 */
	public function testAPassThatFailsPartWayResumesWhereItStopped(): void {
		$rows = [];
		for ($i = 0; $i < 5; $i++) {
			$rows[] = $this->plant(
				copy: $this->insertSecret(ownerId: $this->user('victim'), createdAt: $this->at(0)),
				attacker: $this->user('attacker'),
				at: $this->at(3600)
			);
		}

		$ids     = array_map(static fn (ShareTarget $row): string => $row->getId(), $rows);
		$failing = true;
		$real    = $this->db;
		$db      = $this->createMock(IDBConnection::class);
		$db->method('getQueryBuilder')->willReturnCallback(
			static function () use (&$failing, $real, $ids): IQueryBuilder {
				$qb = $real->getQueryBuilder();
				$qb->select('id')
					->from('keepiq_share_targets')
					->where($qb->expr()->in('id', $qb->createNamedParameter($ids, IQueryBuilder::PARAM_STR_ARRAY)))
					->andWhere($qb->expr()->isNotNull('quarantined_at'));
				$result = $qb->executeQuery();
				$done   = count($result->fetchAll());
				$result->closeCursor();
				if ($failing === true && $done >= 2) {
					throw new RuntimeException('database went away');
				}

				return $real->getQueryBuilder();
			}
		);

		$this->assertFalse($this->scanner(db: $db, pageSize: 2)->run());
		$quarantined = array_filter($rows, fn (ShareTarget $row): bool => $this->stored(row: $row)['quarantined']);
		$this->assertCount(2, $quarantined, 'the pass stopped part-way');
		$this->assertArrayNotHasKey(ShareQuarantineScanner::COMPLETED_KEY, $this->config);

		$failing = false;
		$this->assertTrue($this->scanner(db: $db, pageSize: 2)->run());
		foreach ($rows as $row) {
			$this->assertTrue($this->stored(row: $row)['quarantined']);
			$this->assertCount(1, $this->quarantineEntries(row: $row), 'row '.$row->getId().' is audited once');
		}
	}//end testAPassThatFailsPartWayResumesWhereItStopped()

	/**
	 * A seeded row as candidatePage() reads it, with the given row date.
	 *
	 * @param ShareTarget $row          The seeded row
	 * @param string      $rowCreatedAt The row's stored creation date
	 *
	 * @return array<string,string>
	 */
	private function candidate(ShareTarget $row, string $rowCreatedAt): array {
		return [
			'id' => $row->getId(),
			'sourceSecretId' => $row->getSourceSecretId(),
			'targetUserId' => $row->getTargetUserId(),
			'secretId' => $row->getSecretId(),
			'createdBy' => $row->getCreatedBy(),
			'rowCreatedAt' => $rowCreatedAt,
			'copyCreatedAt' => $this->at(0)->format('Y-m-d H:i:s'),
			'createdAsSecret' => '0',
			'copyUseOnly' => '0',
			'copyAccessEndsAt' => '',
		];
	}//end candidate()
}//end class
