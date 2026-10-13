<?php

/**
 * Database tests for the share-save version lock, partner-share writes and
 * stage table.
 *
 * @category Test
 * @package  OCA\Keepiq\Tests\Unit\Db
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

namespace OCA\Keepiq\Tests\Unit\Db;

use DateTime;
use OCA\Keepiq\Db\FederatedShare;
use OCA\Keepiq\Db\FederatedShareMapper;
use OCA\Keepiq\Db\Secret;
use OCA\Keepiq\Db\SecretMapper;
use OCA\Keepiq\Db\ShareSaveStage;
use OCA\Keepiq\Db\ShareSaveStageMapper;
use OCA\Keepiq\Service\FederatedNotificationDelivery;
use OCA\Keepiq\Service\FederatedShareAuditTrail;
use OCA\Keepiq\Service\FederatedShareMessenger;
use OCA\Keepiq\Service\FederatedShareService;
use OCA\Keepiq\Service\FederationPartnerService;
use OCA\Keepiq\Service\FederationRootService;
use OCP\Federation\ICloudIdManager;
use OCP\IDBConnection;
use OCP\Security\ISecureRandom;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Ramsey\Uuid\Uuid;

/**
 * Runs against the instance's real database, inside a transaction that is
 * rolled back after each test.
 */
class ShareSaveVersionLockTest extends TestCase {
	private const BASE = '2026-10-01T10:00:00+00:00';

	private ?IDBConnection $db = null;

	protected function setUp(): void {
		parent::setUp();

		if (class_exists(\OC::class) === false || \OC::$server === null) {
			$this->markTestSkipped(message: 'needs a bootstrapped Nextcloud with a database');
		}

		$this->db = \OC::$server->get(IDBConnection::class);
		if ($this->db->tableExists('keepiq_share_save_stages') === false) {
			$this->markTestSkipped(message: 'keepiq migrations have not run on this instance');
		}

		$this->db->beginTransaction();
	}

	protected function tearDown(): void {
		if ($this->db !== null && $this->db->inTransaction() === true) {
			$this->db->rollBack();
		}

		parent::tearDown();
	}

	private function insertSecret(): Secret {
		$secret = new Secret();
		$secret->setId(Uuid::uuid4()->toString());
		$secret->setName('Router');
		$secret->setTypeId('login');
		$secret->setKey('CIPHERTEXT');
		$secret->setEncryptionSuiteId('suite-1');
		$secret->setOwnerType('user');
		$secret->setOwnerId('share-save-test');
		$secret->setCreatedAt(new DateTime(self::BASE));
		$secret->setUpdatedAt(new DateTime(self::BASE));

		return (new SecretMapper(db: $this->db))->insert($secret);
	}

	public function testTheVersionMovesOnlyFromTheBaseAndOnlyOnce(): void {
		$mapper = new SecretMapper(db: $this->db);
		$id = $this->insertSecret()->getId();
		$savedAt = new DateTime('2026-10-01T10:00:01+00:00');

		$this->assertSame(0, $mapper->advanceVersion(id: $id, base: new DateTime('2026-10-01T09:59:59+00:00'), savedAt: $savedAt));
		// The same moment in another zone is the same base.
		$this->assertSame(1, $mapper->advanceVersion(id: $id, base: new DateTime('2026-10-01T12:00:00+02:00'), savedAt: $savedAt));
		$this->assertSame(0, $mapper->advanceVersion(id: $id, base: new DateTime(self::BASE), savedAt: new DateTime('+1 day')));
		$this->assertSame($savedAt->getTimestamp(), $mapper->findById($id)->getUpdatedAt()?->getTimestamp());
		$this->assertSame(0, $mapper->advanceVersion(id: 'no-such-row', base: $savedAt, savedAt: new DateTime('+1 day')));
	}

	private function insertPartnerShare(string $status, ?string $pending, int $attempts = 0, string $sourceSecretId = 'src'): FederatedShare {
		$share = new FederatedShare();
		$share->setId(Uuid::uuid4()->toString());
		$share->setSourceSecretId($sourceSecretId);
		$share->setOwnerId('share-save-test');
		$share->setRecipientCloudId('bob@partner.example');
		$share->setPartnerId('p-1');
		$share->setRecipientCertFingerprint(str_repeat('a', 64));
		$share->setSharedSecretHash(str_repeat('b', 64));
		$share->setStatus($status);
		$share->setPendingNotification($pending);
		$share->setNotifyAttempts($attempts);
		$share->setCreatedAt(new DateTime(self::BASE));
		$share->setUpdatedAt(new DateTime(self::BASE));

		return (new FederatedShareMapper(db: $this->db))->insert($share);
	}

	/**
	 * The partner share as a share-save's transaction leaves it, with the
	 * entity that save hands to the delivery after the commit.
	 */
	private function committedShareSave(FederatedShareMapper $mapper, int $attempts = 0): FederatedShare {
		$row = $mapper->findById($this->insertPartnerShare(status: FederatedShare::STATUS_ACTIVE, pending: null)->getId());
		$savedAt = new DateTime('2026-10-01T10:00:05.123456+00:00');
		$row->setKey('NEW');
		$row->setUpdatedAt($savedAt);
		$row->setPendingNotification(FederatedNotificationDelivery::SHARE_UPDATED);
		$row->setNotifyAttempts($attempts);
		$row->setNextNotifyAt((clone $savedAt)->modify('+1 minute'));
		$this->assertSame(1, $mapper->updateIfActive(share: $row));

		return $row;
	}

	/**
	 * Each case's outcome write differs from what the revoke stored, so it
	 * fails without the guard: a cleared notification, a third attempt, or
	 * a failed status.
	 */
	public static function slowPartnerAnswers(): array {
		return [
			'the share-save notify arrives late' => [0, false, true],
			'a retried update times out' => [2, true, false],
			'the retry job gives the update up' => [FederatedNotificationDelivery::MAX_ATTEMPTS - 1, true, false],
		];
	}

	/**
	 * Alice's share-save notifies a slow partner; meanwhile she revokes in
	 * another tab and that revoke's own delivery fails. The retry job must
	 * still find the unshare.
	 */
	#[DataProvider('slowPartnerAnswers')]
	public function testASlowUpdateNeverReplacesARevokesPendingUnshare(int $attempts, bool $byRetryJob, bool $updateArrives): void {
		$mapper = new FederatedShareMapper(db: $this->db);
		$row = $this->committedShareSave(mapper: $mapper, attempts: $attempts);
		$messenger = $this->createMock(FederatedShareMessenger::class);
		$delivery = new FederatedNotificationDelivery($mapper, $messenger, $this->createMock(FederatedShareAuditTrail::class));
		$sent = [];
		$messenger->method('notify')->willReturnCallback(
			function (FederatedShare $notified, string $type) use (&$sent, $row, $mapper, $delivery, $updateArrives): bool {
				// The retry job also sees this instance's other rows.
				if ($notified->getId() !== $row->getId()) {
					return false;
				}

				$sent[] = $type;
				if ($type === FederatedNotificationDelivery::SHARE_UNSHARED) {
					return false;
				}

				$other = $mapper->findById($notified->getId());
				$other->setStatus(FederatedShare::STATUS_REVOKED);
				$other->setUpdatedAt(new DateTime('2026-10-01T10:00:09+00:00'));
				$mapper->update($other);
				$delivery->deliver(row: $other, type: FederatedNotificationDelivery::SHARE_UNSHARED);

				return $updateArrives;
			}
		);

		if ($byRetryJob === true) {
			$delivery->retryDue(now: clone $row->getNextNotifyAt(), limit: 1000);
		} else {
			$this->assertSame($updateArrives, $delivery->deliver(row: $row, type: FederatedNotificationDelivery::SHARE_UPDATED));
		}

		$stored = $mapper->findById($row->getId());
		$this->assertSame(['SHARE_UPDATED', 'SHARE_UNSHARED'], $sent);
		$this->assertSame(FederatedShare::STATUS_REVOKED, $stored->getStatus());
		$this->assertSame(FederatedNotificationDelivery::SHARE_UNSHARED, $stored->getPendingNotification());
		$this->assertSame(1, $stored->getNotifyAttempts());
		$this->assertNotNull($stored->getNextNotifyAt());
	}

	private function shareService(FederatedShareMapper $mapper, FederatedNotificationDelivery $delivery): FederatedShareService {
		return new FederatedShareService(
			shareMapper: $mapper,
			secretMapper: new SecretMapper(db: $this->db),
			partners: $this->createMock(FederationPartnerService::class),
			root: $this->createMock(FederationRootService::class),
			cloudIdManager: $this->createMock(ICloudIdManager::class),
			messenger: $this->createMock(FederatedShareMessenger::class),
			random: $this->createMock(ISecureRandom::class),
			delivery: $delivery,
			audit: $this->createMock(FederatedShareAuditTrail::class),
		);
	}

	/**
	 * Alice revokes in another tab after her share-save committed but before
	 * its notify runs, and the revoke's delivery fails. The share-save's
	 * notify must not queue over the pending unshare.
	 */
	public function testANotifyAfterARevokeLeavesItsUnshareQueued(): void {
		$mapper = new FederatedShareMapper(db: $this->db);
		$row = $this->committedShareSave(mapper: $mapper);
		$messenger = $this->createMock(FederatedShareMessenger::class);
		$sent = [];
		$messenger->method('notify')->willReturnCallback(
			function (FederatedShare $notified, string $type) use (&$sent): bool {
				$sent[] = $type;
				return false;
			}
		);
		$delivery = new FederatedNotificationDelivery($mapper, $messenger, $this->createMock(FederatedShareAuditTrail::class));

		$this->shareService(mapper: $mapper, delivery: $delivery)->revoke(shareId: $row->getId(), userId: 'share-save-test');
		$this->assertFalse($delivery->deliver(row: $row, type: FederatedNotificationDelivery::SHARE_UPDATED));

		$stored = $mapper->findById($row->getId());
		$this->assertSame(['SHARE_UNSHARED'], $sent);
		$this->assertSame(FederatedShare::STATUS_REVOKED, $stored->getStatus());
		$this->assertSame(FederatedNotificationDelivery::SHARE_UNSHARED, $stored->getPendingNotification());
		$this->assertSame(1, $stored->getNotifyAttempts());
	}

	/**
	 * The retry job leaves a notification alone while its first send may
	 * still be waiting on the partner, both after deliver() and after a
	 * share-save's write.
	 */
	public function testTheRetryJobSkipsANotificationWhoseFirstSendIsRunning(): void {
		$mapper = new FederatedShareMapper(db: $this->db);
		$row = $mapper->findById($this->insertPartnerShare(status: FederatedShare::STATUS_ACTIVE, pending: null)->getId());
		$messenger = $this->createMock(FederatedShareMessenger::class);
		$delivery = new FederatedNotificationDelivery($mapper, $messenger, $this->createMock(FederatedShareAuditTrail::class));
		$sent = 0;
		$messenger->method('notify')->willReturnCallback(
			function (FederatedShare $notified) use (&$sent, $row, $delivery): bool {
				if ($notified->getId() !== $row->getId()) {
					return false;
				}

				$sent++;
				if ($sent === 1) {
					$delivery->retryDue(now: new DateTime(), limit: 1000);
				}

				return false;
			}
		);

		$delivery->deliver(row: $row, type: FederatedNotificationDelivery::SHARE_UPDATED);
		$this->assertSame(1, $sent);

		$saved = $mapper->findById($this->insertPartnerShare(status: FederatedShare::STATUS_ACTIVE, pending: null)->getId());
		$savedAt = new DateTime('@'.time());
		$this->shareService(mapper: $mapper, delivery: $delivery)->writeCiphertext(
			row: $saved,
			certFingerprint: str_repeat('c', 64),
			ciphertext: ['key' => 'NEW', 'login' => null, 'additionalFields' => null],
			savedAt: $savedAt
		);
		$due = fn (DateTime $now): bool => in_array(
			$saved->getId(),
			array_map(static fn (FederatedShare $due): string => $due->getId(), $mapper->findDueNotifications(now: $now, limit: 10000)),
			true
		);
		$this->assertFalse($due($savedAt));
		$this->assertTrue($due($delivery->leaseFrom(queuedAt: $savedAt)));
	}

	/**
	 * A rename lands in the same second as Alice's share-save, while the
	 * share-save's notify waits on the partner, and the rename's own notify
	 * fails. The share-save's late success must not clear the rename's
	 * notification: the partner pulled before the new name was there.
	 */
	public function testASameSecondRenameKeepsItsNotificationQueued(): void {
		$mapper = new FederatedShareMapper(db: $this->db);
		$sourceId = Uuid::uuid4()->toString();
		$share = $mapper->findById($this->insertPartnerShare(status: FederatedShare::STATUS_ACTIVE, pending: null, sourceSecretId: $sourceId)->getId());
		$messenger = $this->createMock(FederatedShareMessenger::class);
		$delivery = new FederatedNotificationDelivery($mapper, $messenger, $this->createMock(FederatedShareAuditTrail::class));
		$service = $this->shareService(mapper: $mapper, delivery: $delivery);
		$sent = [];
		$messenger->method('notify')->willReturnCallback(
			function (FederatedShare $notified, string $type) use (&$sent, $service, $sourceId): bool {
				$sent[] = $type;
				if (count($sent) === 1) {
					$service->detailsChanged(secretId: $sourceId, userId: 'share-save-test');
					return true;
				}

				return false;
			}
		);

		// Start on a fresh second, so both writes land inside it.
		time_sleep_until(floor(microtime(true)) + 1);
		$savedAt = new DateTime('@'.time());
		$service->writeCiphertext(
			row: $share,
			certFingerprint: str_repeat('c', 64),
			ciphertext: ['key' => 'NEW', 'login' => null, 'additionalFields' => null],
			savedAt: $savedAt
		);
		$this->assertTrue($delivery->deliver(row: $share, type: FederatedNotificationDelivery::SHARE_UPDATED));

		$stored = $mapper->findById($share->getId());
		$this->assertSame(['SHARE_UPDATED', 'SHARE_UPDATED'], $sent);
		$this->assertSame(FederatedNotificationDelivery::SHARE_UPDATED, $stored->getPendingNotification());
		$this->assertSame(1, $stored->getNotifyAttempts());
		$this->assertSame($savedAt->getTimestamp() + 1, $stored->getUpdatedAt()?->getTimestamp());
	}

	public function testADeliveryStoresItsOutcomeAndARetryOfTheStoredRowClearsIt(): void {
		$mapper = new FederatedShareMapper(db: $this->db);
		$row = $this->committedShareSave(mapper: $mapper);
		$messenger = $this->createMock(FederatedShareMessenger::class);
		$messenger->method('notify')->willReturnOnConsecutiveCalls(false, true);
		$delivery = new FederatedNotificationDelivery($mapper, $messenger, $this->createMock(FederatedShareAuditTrail::class));

		$this->assertFalse($delivery->deliver(row: $row, type: FederatedNotificationDelivery::SHARE_UPDATED));
		$stored = $mapper->findById($row->getId());
		$this->assertSame(1, $stored->getNotifyAttempts());
		$this->assertSame('NEW', $stored->getKey());

		// The retry job's row comes from the database, its version included.
		$this->assertTrue($delivery->deliver(row: $stored, type: FederatedNotificationDelivery::SHARE_UPDATED));
		$this->assertNull($mapper->findById($row->getId())->getPendingNotification());
	}

	public function testADeliveredUnshareDeletesOnlyARowStillWaitingForIt(): void {
		$mapper = new FederatedShareMapper(db: $this->db);
		$share = $this->insertPartnerShare(status: FederatedShare::STATUS_REVOKED, pending: null);

		$this->assertSame(0, $mapper->deleteIfPending(share: $share, pending: FederatedNotificationDelivery::SHARE_UNSHARED));
		$share->setPendingNotification(FederatedNotificationDelivery::SHARE_UNSHARED);
		$mapper->update($share);
		$this->assertSame(1, $mapper->deleteIfPending(share: $share, pending: FederatedNotificationDelivery::SHARE_UNSHARED));
	}

	public function testAPartnerShareIsWrittenOnlyWhileActive(): void {
		$mapper = new FederatedShareMapper(db: $this->db);
		$share = $this->insertPartnerShare(status: FederatedShare::STATUS_REVOKED, pending: 'SHARE_UNSHARED', attempts: 2);

		$revoked = $mapper->findById($share->getId());
		$revoked->setKey('NEW');
		$revoked->setPendingNotification('SHARE_UPDATED');
		$this->assertSame(0, $mapper->updateIfActive(share: $revoked));
		$this->assertSame('SHARE_UNSHARED', $mapper->findById($share->getId())->getPendingNotification());

		$active = $mapper->findById($share->getId());
		$active->setStatus(FederatedShare::STATUS_ACTIVE);
		$mapper->update($active);
		$active = $mapper->findById($share->getId());
		$active->setKey('NEW');
		$this->assertSame(1, $mapper->updateIfActive(share: $active));
		$this->assertSame('NEW', $mapper->findById($share->getId())->getKey());
	}

	public function testFindByIdsLeavesUnknownIdsOut(): void {
		$id = $this->insertSecret()->getId();

		$this->assertSame([$id], array_keys((new SecretMapper(db: $this->db))->findByIds(ids: [$id, 'no-such-row', $id])));
	}

	public function testStageChunksExpireAndGoWithTheirUserOrRow(): void {
		$mapper = new ShareSaveStageMapper(db: $this->db);
		$now = new DateTime();
		$mapper->insert($this->chunk(stageId: 'stage-live', userId: 'u1', secretId: 's1', expiresAt: new DateTime('+30 minutes')));
		$mapper->insert($this->chunk(stageId: 'stage-live', userId: 'u1', secretId: 's1', expiresAt: new DateTime('+30 minutes')));
		$mapper->insert($this->chunk(stageId: 'stage-old', userId: 'u1', secretId: 's2', expiresAt: new DateTime('-1 minute')));
		$mapper->insert($this->chunk(stageId: 'stage-u2', userId: 'u2', secretId: 's1', expiresAt: new DateTime('+30 minutes')));

		$this->assertCount(2, $mapper->findLiveByStage(stageId: 'stage-live', now: $now));
		$this->assertSame([], $mapper->findLiveByStage(stageId: 'stage-old', now: $now));
		$this->assertSame('{"copies":[]}', $mapper->findLiveByStage(stageId: 'stage-live', now: $now)[0]->getEntries());

		$this->assertSame(1, $mapper->deleteExpired(now: $now));
		$this->assertSame(2, $mapper->deleteByUserAndSecret(userId: 'u1', secretId: 's1'));
		$this->assertSame(1, $mapper->deleteByUser(userId: 'u2'));
	}

	private function chunk(string $stageId, string $userId, string $secretId, DateTime $expiresAt): ShareSaveStage {
		$chunk = new ShareSaveStage();
		$chunk->setId(Uuid::uuid4()->toString());
		$chunk->setStageId($stageId);
		$chunk->setUserId($userId);
		$chunk->setSecretId($secretId);
		$chunk->setBaseUpdatedAt(new DateTime(self::BASE));
		$chunk->setEntries('{"copies":[]}');
		$chunk->setCopyCount(0);
		$chunk->setFederatedCount(0);
		$chunk->setExpiresAt($expiresAt);
		$chunk->setCreatedAt(new DateTime());

		return $chunk;
	}
}
