<?php

/**
 * Database tests for a former owner's link shares following their secret to
 * a new owner, and for the repair step that hands left-behind links over.
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
use OCA\Keepiq\Db\FederatedShareMapper;
use OCA\Keepiq\Db\LinkShare;
use OCA\Keepiq\Db\LinkShareMapper;
use OCA\Keepiq\Db\Secret;
use OCA\Keepiq\Db\SecretDelegation;
use OCA\Keepiq\Db\SecretDelegationMapper;
use OCA\Keepiq\Db\SecretMapper;
use OCA\Keepiq\Repair\ReownTransferredLinkShares;
use OCA\Keepiq\Service\SecretOwnerTransferService;
use OCP\IDBConnection;
use OCP\Migration\IOutput;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use Ramsey\Uuid\Uuid;

/**
 * Runs against the instance's real database, inside a transaction that is
 * rolled back after each test.
 */
class LinkShareOwnerTransferTest extends TestCase {
	private const BASE = '2026-10-01T10:00:00+00:00';

	private ?IDBConnection $db = null;

	protected function setUp(): void {
		parent::setUp();

		if (class_exists(\OC::class) === false || \OC::$server === null) {
			$this->markTestSkipped(message: 'needs a bootstrapped Nextcloud with a database');
		}

		$this->db = \OC::$server->get(IDBConnection::class);
		if ($this->db->tableExists('keepiq_link_shares') === false) {
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

	private function insertLink(string $secretId, string $createdBy, string $createdAt = self::BASE): LinkShare {
		$link = new LinkShare();
		$link->setId(Uuid::uuid4()->toString());
		$link->setSecretId($secretId);
		$link->setToken(bin2hex(random_bytes(32)));
		$link->setEncryptedSecretSnapshot('SNAPSHOT');
		$link->setArgon2idSalt('SALT');
		$link->setEncryptionSuiteId('suite-1');
		$link->setUsageLimit(1);
		$link->setUsageCount(0);
		$link->setFailedAttempts(0);
		$link->setCreatedBy($createdBy);
		$link->setCreatedAt(new DateTime($createdAt));

		return (new LinkShareMapper(db: $this->db))->insert($link);
	}

	private function insertDelegation(string $secretId, string $originalOwnerId, string $delegatedTo, bool $permanent): void {
		$delegation = new SecretDelegation();
		$delegation->setId(Uuid::uuid4()->toString());
		$delegation->setSecretId($secretId);
		$delegation->setOriginalOwnerId($originalOwnerId);
		$delegation->setDelegatedTo($delegatedTo);
		$delegation->setDelegatedAt(new DateTime(self::BASE));
		$delegation->setInitiatedBy($originalOwnerId);
		$delegation->setIsPermanent($permanent);

		(new SecretDelegationMapper(db: $this->db))->insert($delegation);
	}

	private function creatorOf(LinkShare $link): string {
		return (new LinkShareMapper(db: $this->db))->findById($link->getId())->getCreatedBy();
	}

	/**
	 * Alice's secret passes to Dave: the links Alice created on it are
	 * Dave's. Carol's link, made as a delegate, stays hers, and so does
	 * Alice's link on another secret.
	 */
	public function testATransferHandsThePreviousOwnersLinksToTheNewOwner(): void {
		$secret = $this->insertSecret(ownerId: 'link-test-alice');
		$alices = $this->insertLink(secretId: $secret->getId(), createdBy: 'link-test-alice');
		$carols = $this->insertLink(secretId: $secret->getId(), createdBy: 'link-test-carol');
		$other = $this->insertLink(secretId: $this->insertSecret(ownerId: 'link-test-alice')->getId(), createdBy: 'link-test-alice');

		(new SecretOwnerTransferService(
			db: $this->db,
			secretMapper: new SecretMapper(db: $this->db),
			federatedShares: new FederatedShareMapper(db: $this->db),
			linkShares: new LinkShareMapper(db: $this->db)
		))->transfer(secretId: $secret->getId(), newOwnerId: 'link-test-dave');

		$this->assertSame('link-test-dave', (new SecretMapper(db: $this->db))->findById($secret->getId())->getOwnerId());
		$this->assertSame('link-test-dave', $this->creatorOf($alices));
		$this->assertSame('link-test-carol', $this->creatorOf($carols));
		$this->assertSame('link-test-alice', $this->creatorOf($other));
	}

	/**
	 * The repair hands a link a former owner left behind to the secret's
	 * current owner, also down a chain of transfers, and leaves alone a
	 * delegate's link, a link the former owner made after the transfer as a
	 * delegate again, a link on a secret whose delegation was made
	 * permanent without a change of owner, and a link on a secret that is
	 * not a user's. A second run finds nothing left.
	 */
	public function testTheRepairHandsLeftBehindLinksToTheSecretsOwner(): void {
		// Alice was offboarded to Dave; Carol is a delegate.
		$offboarded = $this->insertSecret(ownerId: 'link-test-dave');
		$this->insertDelegation(secretId: $offboarded->getId(), originalOwnerId: 'link-test-alice', delegatedTo: 'link-test-dave', permanent: true);
		$this->insertDelegation(secretId: $offboarded->getId(), originalOwnerId: 'link-test-dave', delegatedTo: 'link-test-carol', permanent: false);
		$leftBehind = $this->insertLink(secretId: $offboarded->getId(), createdBy: 'link-test-alice');
		$delegates = $this->insertLink(secretId: $offboarded->getId(), createdBy: 'link-test-carol');
		$owners = $this->insertLink(secretId: $offboarded->getId(), createdBy: 'link-test-dave');
		// Dave delegated the secret back to Alice, who made a link as his delegate.
		$this->insertDelegation(secretId: $offboarded->getId(), originalOwnerId: 'link-test-dave', delegatedTo: 'link-test-alice', permanent: false);
		$alicesAsDelegate = $this->insertLink(secretId: $offboarded->getId(), createdBy: 'link-test-alice', createdAt: '2026-10-02T10:00:00+00:00');

		// Xavier to Yves to Zoe.
		$chained = $this->insertSecret(ownerId: 'link-test-zoe');
		$this->insertDelegation(secretId: $chained->getId(), originalOwnerId: 'link-test-xavier', delegatedTo: 'link-test-yves', permanent: true);
		$this->insertDelegation(secretId: $chained->getId(), originalOwnerId: 'link-test-yves', delegatedTo: 'link-test-zoe', permanent: true);
		$xaviers = $this->insertLink(secretId: $chained->getId(), createdBy: 'link-test-xavier');
		$yves = $this->insertLink(secretId: $chained->getId(), createdBy: 'link-test-yves');

		// A suite revocation made Alice's delegation to Bob permanent; Alice still owns the secret.
		$revoked = $this->insertSecret(ownerId: 'link-test-alice');
		$this->insertDelegation(secretId: $revoked->getId(), originalOwnerId: 'link-test-alice', delegatedTo: 'link-test-bob', permanent: true);
		$stillAlices = $this->insertLink(secretId: $revoked->getId(), createdBy: 'link-test-alice');
		$bobs = $this->insertLink(secretId: $revoked->getId(), createdBy: 'link-test-bob');

		$application = $this->insertSecret(ownerId: 'app-1', ownerType: 'application');
		$this->insertDelegation(secretId: $application->getId(), originalOwnerId: 'link-test-alice', delegatedTo: 'app-1', permanent: true);
		$onApplication = $this->insertLink(secretId: $application->getId(), createdBy: 'link-test-alice');

		$step = new ReownTransferredLinkShares(db: $this->db, links: new LinkShareMapper(db: $this->db), logger: $this->createMock(LoggerInterface::class));
		$step->run($this->createMock(IOutput::class));

		$this->assertSame('link-test-dave', $this->creatorOf($leftBehind));
		$this->assertSame('link-test-carol', $this->creatorOf($delegates));
		$this->assertSame('link-test-dave', $this->creatorOf($owners));
		$this->assertSame('link-test-alice', $this->creatorOf($alicesAsDelegate));
		$this->assertSame('link-test-zoe', $this->creatorOf($xaviers));
		$this->assertSame('link-test-zoe', $this->creatorOf($yves));
		$this->assertSame('link-test-alice', $this->creatorOf($stillAlices));
		$this->assertSame('link-test-bob', $this->creatorOf($bobs));
		$this->assertSame('link-test-alice', $this->creatorOf($onApplication));

		$output = $this->createMock(IOutput::class);
		$output->expects($this->once())->method('info')->with($this->stringContains(': 0 link share(s)'));
		$step->run($output);
	}
}
