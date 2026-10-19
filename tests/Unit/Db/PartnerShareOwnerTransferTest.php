<?php

/**
 * Database tests for partner shares following their secret to a new owner,
 * and for the repair step that hands left-behind shares over.
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
use OCA\Keepiq\Db\LinkShareMapper;
use OCA\Keepiq\Db\Secret;
use OCA\Keepiq\Db\SecretMapper;
use OCA\Keepiq\Exception\NotFoundException;
use OCA\Keepiq\Repair\ReownTransferredPartnerShares;
use OCA\Keepiq\Service\FederatedNotificationDelivery;
use OCA\Keepiq\Service\FederatedShareAuditTrail;
use OCA\Keepiq\Service\FederatedShareMessenger;
use OCA\Keepiq\Service\FederatedShareService;
use OCA\Keepiq\Service\FederationPartnerService;
use OCA\Keepiq\Service\FederationRootService;
use OCA\Keepiq\Service\SecretOwnerTransferService;
use OCP\Federation\ICloudIdManager;
use OCP\IDBConnection;
use OCP\Migration\IOutput;
use OCP\Security\ISecureRandom;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use Ramsey\Uuid\Uuid;

/**
 * Runs against the instance's real database, inside a transaction that is
 * rolled back after each test.
 */
class PartnerShareOwnerTransferTest extends TestCase {
	private const BASE = '2026-10-01T10:00:00+00:00';

	private ?IDBConnection $db = null;

	protected function setUp(): void {
		parent::setUp();

		if (class_exists(\OC::class) === false || \OC::$server === null) {
			$this->markTestSkipped(message: 'needs a bootstrapped Nextcloud with a database');
		}

		$this->db = \OC::$server->get(IDBConnection::class);
		if ($this->db->tableExists('keepiq_federated_shares') === false) {
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

	private function insertSecret(string $ownerId, string $ownerType = 'user'): Secret {
		$secret = new Secret();
		$secret->setId(Uuid::uuid4()->toString());
		$secret->setName('Router');
		$secret->setTypeId('login');
		$secret->setKey('CIPHERTEXT');
		$secret->setEncryptionSuiteId('suite-1');
		$secret->setOwnerType($ownerType);
		$secret->setOwnerId($ownerId);
		$secret->setCreatedAt(new DateTime(self::BASE));
		$secret->setUpdatedAt(new DateTime(self::BASE));

		return (new SecretMapper(db: $this->db))->insert($secret);
	}

	private function insertPartnerShare(string $sourceSecretId, string $ownerId, string $status = FederatedShare::STATUS_ACTIVE, ?string $pending = null): FederatedShare {
		$share = new FederatedShare();
		$share->setId(Uuid::uuid4()->toString());
		$share->setSourceSecretId($sourceSecretId);
		$share->setOwnerId($ownerId);
		$share->setRecipientCloudId('bob@partner.example');
		$share->setPartnerId('p-1');
		$share->setRecipientCertFingerprint(str_repeat('a', 64));
		$share->setSharedSecretHash(str_repeat('b', 64));
		$share->setStatus($status);
		$share->setPendingNotification($pending);
		$share->setNotifyAttempts(0);
		$share->setCreatedAt(new DateTime(self::BASE));
		$share->setUpdatedAt(new DateTime(self::BASE));

		return (new FederatedShareMapper(db: $this->db))->insert($share);
	}

	private function shareService(FederatedShareMapper $mapper): FederatedShareService {
		return new FederatedShareService(
			shareMapper: $mapper,
			secretMapper: new SecretMapper(db: $this->db),
			partners: $this->createMock(FederationPartnerService::class),
			root: $this->createMock(FederationRootService::class),
			cloudIdManager: $this->createMock(ICloudIdManager::class),
			messenger: $this->createMock(FederatedShareMessenger::class),
			random: $this->createMock(ISecureRandom::class),
			delivery: $this->createMock(FederatedNotificationDelivery::class),
			audit: $this->createMock(FederatedShareAuditTrail::class),
		);
	}

	/**
	 * Alice's secret passes to Dave: every partner share of it is Dave's,
	 * whatever its status, with its version unchanged. Dave can suspend the
	 * live one; Alice no longer can.
	 */
	public function testATransferHandsThePartnerSharesToTheNewOwner(): void {
		$mapper = new FederatedShareMapper(db: $this->db);
		$secret = $this->insertSecret(ownerId: 'transfer-test-alice');
		$live = $this->insertPartnerShare(sourceSecretId: $secret->getId(), ownerId: 'transfer-test-alice');
		$revoking = $this->insertPartnerShare(
			sourceSecretId: $secret->getId(),
			ownerId: 'transfer-test-alice',
			status: FederatedShare::STATUS_REVOKED,
			pending: FederatedNotificationDelivery::SHARE_UNSHARED
		);
		$other = $this->insertPartnerShare(sourceSecretId: $this->insertSecret(ownerId: 'transfer-test-alice')->getId(), ownerId: 'transfer-test-alice');
		$version = $mapper->findById($live->getId())->getUpdatedAt()?->getTimestamp();

		(new SecretOwnerTransferService(db: $this->db, secretMapper: new SecretMapper(db: $this->db), federatedShares: $mapper, linkShares: new LinkShareMapper(db: $this->db)))
			->transfer(secretId: $secret->getId(), newOwnerId: 'transfer-test-dave');

		$this->assertSame('transfer-test-dave', (new SecretMapper(db: $this->db))->findById($secret->getId())->getOwnerId());
		foreach ([$live, $revoking] as $share) {
			$stored = $mapper->findById($share->getId());
			$this->assertSame('transfer-test-dave', $stored->getOwnerId());
			$this->assertSame($version, $stored->getUpdatedAt()?->getTimestamp());
		}

		$this->assertSame(FederatedNotificationDelivery::SHARE_UNSHARED, $mapper->findById($revoking->getId())->getPendingNotification());
		$this->assertSame('transfer-test-alice', $mapper->findById($other->getId())->getOwnerId());

		$service = $this->shareService(mapper: $mapper);
		try {
			$service->suspend(shareId: $live->getId(), userId: 'transfer-test-alice', reason: 'untrusted_root');
			$this->fail('the previous owner suspended the share');
		} catch (NotFoundException) {
			// Not Alice's any more.
		}

		$this->assertSame(FederatedShare::STATUS_SUSPENDED, $service->suspend(shareId: $live->getId(), userId: 'transfer-test-dave', reason: 'untrusted_root')->getStatus());
	}

	/**
	 * The repair hands a share left with a previous owner to its secret's
	 * owner, and leaves shares of a secret that is not a user's, or that is
	 * gone, alone.
	 */
	public function testTheRepairHandsLeftBehindSharesToTheSecretsOwner(): void {
		$mapper = new FederatedShareMapper(db: $this->db);
		$secret = $this->insertSecret(ownerId: 'transfer-test-dave');
		$leftBehind = $this->insertPartnerShare(sourceSecretId: $secret->getId(), ownerId: 'transfer-test-alice');
		$current = $this->insertPartnerShare(sourceSecretId: $secret->getId(), ownerId: 'transfer-test-dave');
		$application = $this->insertPartnerShare(sourceSecretId: $this->insertSecret(ownerId: 'app-1', ownerType: 'application')->getId(), ownerId: 'transfer-test-alice');
		$gone = $this->insertPartnerShare(sourceSecretId: Uuid::uuid4()->toString(), ownerId: 'transfer-test-alice');

		$step = new ReownTransferredPartnerShares(db: $this->db, shares: $mapper, logger: $this->createMock(LoggerInterface::class));
		$step->run($this->createMock(IOutput::class));

		$this->assertSame('transfer-test-dave', $mapper->findById($leftBehind->getId())->getOwnerId());
		$this->assertSame('transfer-test-dave', $mapper->findById($current->getId())->getOwnerId());
		$this->assertSame('transfer-test-alice', $mapper->findById($application->getId())->getOwnerId());
		$this->assertSame('transfer-test-alice', $mapper->findById($gone->getId())->getOwnerId());

		// A second run finds nothing left.
		$output = $this->createMock(IOutput::class);
		$output->expects($this->once())->method('info')->with($this->stringContains(': 0 partner share(s)'));
		$step->run($output);
	}
}
