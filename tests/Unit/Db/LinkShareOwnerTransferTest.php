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
use OCP\AppFramework\Db\DoesNotExistException;
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

	private function makerOf(LinkShare $link): ?string {
		return (new LinkShareMapper(db: $this->db))->findById($link->getId())->getOriginalCreatedBy();
	}

	private function exists(LinkShare $link): bool {
		try {
			(new LinkShareMapper(db: $this->db))->findById($link->getId());
			return true;
		} catch (DoesNotExistException) {
			return false;
		}
	}

	private function transfer(string $secretId, string $fromOwnerId, string $newOwnerId): int {
		return (new SecretOwnerTransferService(
			db: $this->db,
			secretMapper: new SecretMapper(db: $this->db),
			federatedShares: new FederatedShareMapper(db: $this->db),
			linkShares: new LinkShareMapper(db: $this->db)
		))->transfer(secretId: $secretId, fromOwnerId: $fromOwnerId, newOwnerId: $newOwnerId);
	}

	/**
	 * Alice's secret passes to Dave: the links Alice created on it are
	 * Dave's, with Alice kept as their maker. Carol's link, made as a
	 * delegate, stays hers, and so does Alice's link on another secret.
	 */
	public function testATransferHandsThePreviousOwnersLinksToTheNewOwner(): void {
		$secret = $this->insertSecret(ownerId: 'link-test-alice');
		$alices = $this->insertLink(secretId: $secret->getId(), createdBy: 'link-test-alice');
		$carols = $this->insertLink(secretId: $secret->getId(), createdBy: 'link-test-carol');
		$other = $this->insertLink(secretId: $this->insertSecret(ownerId: 'link-test-alice')->getId(), createdBy: 'link-test-alice');

		$moved = $this->transfer(secretId: $secret->getId(), fromOwnerId: 'link-test-alice', newOwnerId: 'link-test-dave');

		$this->assertSame(1, $moved);
		$this->assertSame('link-test-dave', (new SecretMapper(db: $this->db))->findById($secret->getId())->getOwnerId());
		$this->assertSame('link-test-dave', $this->creatorOf($alices));
		$this->assertSame('link-test-alice', $this->makerOf($alices));
		$this->assertSame('link-test-carol', $this->creatorOf($carols));
		$this->assertNull($this->makerOf($carols));
		$this->assertSame('link-test-alice', $this->creatorOf($other));
		$this->assertNull($this->makerOf($other));
	}

	/**
	 * After Alice's secret passed to Dave, a compromise revoke of Alice
	 * still cuts the link she made, and leaves Carol's and Dave's own links.
	 */
	public function testTheLeaversCompromiseRevokeStillCutsTheLinksTheyMade(): void {
		$secret = $this->insertSecret(ownerId: 'link-test-alice');
		$alices = $this->insertLink(secretId: $secret->getId(), createdBy: 'link-test-alice');
		$carols = $this->insertLink(secretId: $secret->getId(), createdBy: 'link-test-carol');
		$this->transfer(secretId: $secret->getId(), fromOwnerId: 'link-test-alice', newOwnerId: 'link-test-dave');
		$daves = $this->insertLink(secretId: $secret->getId(), createdBy: 'link-test-dave');

		(new LinkShareMapper(db: $this->db))->deleteMadeBy(userId: 'link-test-alice');

		$this->assertFalse($this->exists($alices));
		$this->assertTrue($this->exists($carols));
		$this->assertTrue($this->exists($daves));
	}

	/**
	 * A compromise revoke of Dave cuts the link he made, but not the one
	 * Alice made and the transfer handed to him: his key pair never touched
	 * its snapshot.
	 */
	public function testTheSuccessorsCompromiseRevokeLeavesTheLinksHandedToThem(): void {
		$secret = $this->insertSecret(ownerId: 'link-test-alice');
		$alices = $this->insertLink(secretId: $secret->getId(), createdBy: 'link-test-alice');
		$this->transfer(secretId: $secret->getId(), fromOwnerId: 'link-test-alice', newOwnerId: 'link-test-dave');
		$daves = $this->insertLink(secretId: $secret->getId(), createdBy: 'link-test-dave');

		(new LinkShareMapper(db: $this->db))->deleteMadeBy(userId: 'link-test-dave');

		$this->assertTrue($this->exists($alices));
		$this->assertFalse($this->exists($daves));
	}

	/**
	 * Down a chain of transfers, Alice to Dave to Erin, the link keeps
	 * Alice as its maker: Alice's compromise revoke cuts it, Dave's does not.
	 */
	public function testAChainOfTransfersKeepsTheFirstMaker(): void {
		$secret = $this->insertSecret(ownerId: 'link-test-alice');
		$alices = $this->insertLink(secretId: $secret->getId(), createdBy: 'link-test-alice');
		$this->transfer(secretId: $secret->getId(), fromOwnerId: 'link-test-alice', newOwnerId: 'link-test-dave');
		$this->transfer(secretId: $secret->getId(), fromOwnerId: 'link-test-dave', newOwnerId: 'link-test-erin');

		$this->assertSame('link-test-erin', $this->creatorOf($alices));
		$this->assertSame('link-test-alice', $this->makerOf($alices));

		$mapper = new LinkShareMapper(db: $this->db);
		$mapper->deleteMadeBy(userId: 'link-test-dave');
		$this->assertTrue($this->exists($alices));
		$mapper->deleteMadeBy(userId: 'link-test-alice');
		$this->assertFalse($this->exists($alices));
	}

	/**
	 * Deleting Alice's account replaces her by the deleted-account marker as
	 * the maker of the links handed to Dave, and leaves the maker of anyone
	 * else's handed-over link. A second run changes nothing.
	 */
	public function testForgettingAMakerClearsOnlyTheirLinks(): void {
		$alicesSecret = $this->insertSecret(ownerId: 'link-test-alice');
		$alices = $this->insertLink(secretId: $alicesSecret->getId(), createdBy: 'link-test-alice');
		$this->transfer(secretId: $alicesSecret->getId(), fromOwnerId: 'link-test-alice', newOwnerId: 'link-test-dave');
		$bobsSecret = $this->insertSecret(ownerId: 'link-test-bob');
		$bobs = $this->insertLink(secretId: $bobsSecret->getId(), createdBy: 'link-test-bob');
		$this->transfer(secretId: $bobsSecret->getId(), fromOwnerId: 'link-test-bob', newOwnerId: 'link-test-dave');

		$mapper = new LinkShareMapper(db: $this->db);
		$forgotten = $mapper->forgetOriginalCreator(userId: 'link-test-alice');

		$this->assertSame(1, $forgotten);
		$this->assertSame(LinkShareMapper::DELETED_ACCOUNT_MARKER, $this->makerOf($alices));
		$this->assertSame('link-test-dave', $this->creatorOf($alices));
		$this->assertSame('link-test-bob', $this->makerOf($bobs));
		$this->assertSame(0, $mapper->forgetOriginalCreator(userId: 'link-test-alice'));
	}

	/**
	 * Once Alice's account is deleted, the link she made and the transfer
	 * handed to Dave survives Dave's compromise revoke: he did not make it.
	 * A later transfer keeps the marker as its maker.
	 */
	public function testALinkWhoseMakerIsDeletedSurvivesTheHoldersCompromiseRevoke(): void {
		$secret = $this->insertSecret(ownerId: 'link-test-alice');
		$alices = $this->insertLink(secretId: $secret->getId(), createdBy: 'link-test-alice');
		$this->transfer(secretId: $secret->getId(), fromOwnerId: 'link-test-alice', newOwnerId: 'link-test-dave');

		$mapper = new LinkShareMapper(db: $this->db);
		$mapper->forgetOriginalCreator(userId: 'link-test-alice');
		$mapper->deleteMadeBy(userId: 'link-test-dave');
		$mapper->deleteMadeBy(userId: 'link-test-alice');

		$this->assertTrue($this->exists($alices));
		$this->assertSame(LinkShareMapper::DELETED_ACCOUNT_MARKER, $this->makerOf($alices));

		$this->transfer(secretId: $secret->getId(), fromOwnerId: 'link-test-dave', newOwnerId: 'link-test-erin');
		$this->assertSame('link-test-erin', $this->creatorOf($alices));
		$this->assertSame(LinkShareMapper::DELETED_ACCOUNT_MARKER, $this->makerOf($alices));
	}

	/**
	 * The GDPR export finder returns, for Alice, the link she holds and the
	 * one she made that the transfer handed to Dave; for Dave, the handed-over
	 * link and his own. Carol finds only the link she made as a delegate.
	 */
	public function testFindingHeldOrMadeLinksIncludesTheOnesHandedOver(): void {
		$secret = $this->insertSecret(ownerId: 'link-test-alice');
		$handedOver = $this->insertLink(secretId: $secret->getId(), createdBy: 'link-test-alice');
		$carols = $this->insertLink(secretId: $secret->getId(), createdBy: 'link-test-carol');
		$this->transfer(secretId: $secret->getId(), fromOwnerId: 'link-test-alice', newOwnerId: 'link-test-dave');
		$daves = $this->insertLink(secretId: $secret->getId(), createdBy: 'link-test-dave');
		$alicesOwn = $this->insertLink(secretId: $this->insertSecret(ownerId: 'link-test-alice')->getId(), createdBy: 'link-test-alice');

		$mapper = new LinkShareMapper(db: $this->db);
		$ids = static function (array $links): array {
			$ids = array_map(static fn (LinkShare $link): string => $link->getId(), $links);
			sort($ids);
			return $ids;
		};

		$this->assertSame($ids([$handedOver, $alicesOwn]), $ids($mapper->findHeldOrMadeBy(userId: 'link-test-alice')));
		$this->assertSame($ids([$handedOver, $daves]), $ids($mapper->findHeldOrMadeBy(userId: 'link-test-dave')));
		$this->assertSame([$carols->getId()], $ids($mapper->findHeldOrMadeBy(userId: 'link-test-carol')));
	}

	/**
	 * A user whose uid equals the deleted-account marker finds only the link
	 * they hold, not the one a deleted account made, and their compromise
	 * revoke cuts only their own.
	 */
	public function testAUidEqualToTheMarkerDoesNotReachTheMarkersLinks(): void {
		$secret = $this->insertSecret(ownerId: 'link-test-alice');
		$forgotten = $this->insertLink(secretId: $secret->getId(), createdBy: 'link-test-alice');
		$this->transfer(secretId: $secret->getId(), fromOwnerId: 'link-test-alice', newOwnerId: 'link-test-dave');
		$mapper = new LinkShareMapper(db: $this->db);
		$mapper->forgetOriginalCreator(userId: 'link-test-alice');
		$markers = $this->insertLink(secretId: $this->insertSecret(ownerId: LinkShareMapper::DELETED_ACCOUNT_MARKER)->getId(), createdBy: LinkShareMapper::DELETED_ACCOUNT_MARKER);

		$found = $mapper->findHeldOrMadeBy(userId: LinkShareMapper::DELETED_ACCOUNT_MARKER);
		$this->assertSame([$markers->getId()], array_map(static fn (LinkShare $link): string => $link->getId(), $found));

		$mapper->deleteMadeBy(userId: LinkShareMapper::DELETED_ACCOUNT_MARKER);
		$this->assertTrue($this->exists($forgotten));
		$this->assertFalse($this->exists($markers));
	}

	/**
	 * The repair hands a link a former owner left behind to the secret's
	 * current owner, also down a chain of transfers, and leaves alone a
	 * delegate's link, a link the former owner made after the transfer as a
	 * delegate again, a link on a secret whose delegation was made
	 * permanent without a change of owner, a link matched only through a
	 * temporary delegation, a link shaped like the dev seeds, and a link on
	 * a secret that is not a user's. A handed-over link keeps the former
	 * owner as its maker. A second run finds nothing left.
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

		// Zoe owns the secret; the only delegation naming Alice is a temporary one to Bob.
		$temporary = $this->insertSecret(ownerId: 'link-test-zoe');
		$this->insertDelegation(secretId: $temporary->getId(), originalOwnerId: 'link-test-alice', delegatedTo: 'link-test-bob', permanent: false);
		$alicesThroughTemporary = $this->insertLink(secretId: $temporary->getId(), createdBy: 'link-test-alice');

		// The dev seeds' shape: the owner delegated temporarily and made a link.
		$seeded = $this->insertSecret(ownerId: 'link-test-admin');
		$this->insertDelegation(secretId: $seeded->getId(), originalOwnerId: 'link-test-admin', delegatedTo: 'link-test-dev-user-2', permanent: false);
		$seedLink = $this->insertLink(secretId: $seeded->getId(), createdBy: 'link-test-admin');

		$application = $this->insertSecret(ownerId: 'app-1', ownerType: 'application');
		$this->insertDelegation(secretId: $application->getId(), originalOwnerId: 'link-test-alice', delegatedTo: 'app-1', permanent: true);
		$onApplication = $this->insertLink(secretId: $application->getId(), createdBy: 'link-test-alice');

		$step = new ReownTransferredLinkShares(db: $this->db, links: new LinkShareMapper(db: $this->db), logger: $this->createMock(LoggerInterface::class));
		$step->run($this->createMock(IOutput::class));

		$this->assertSame('link-test-dave', $this->creatorOf($leftBehind));
		$this->assertSame('link-test-alice', $this->makerOf($leftBehind));
		$this->assertSame('link-test-carol', $this->creatorOf($delegates));
		$this->assertSame('link-test-dave', $this->creatorOf($owners));
		$this->assertSame('link-test-alice', $this->creatorOf($alicesAsDelegate));
		$this->assertSame('link-test-zoe', $this->creatorOf($xaviers));
		$this->assertSame('link-test-xavier', $this->makerOf($xaviers));
		$this->assertSame('link-test-zoe', $this->creatorOf($yves));
		$this->assertSame('link-test-yves', $this->makerOf($yves));
		$this->assertSame('link-test-alice', $this->creatorOf($stillAlices));
		$this->assertSame('link-test-bob', $this->creatorOf($bobs));
		$this->assertSame('link-test-alice', $this->creatorOf($alicesThroughTemporary));
		$this->assertSame('link-test-admin', $this->creatorOf($seedLink));
		$this->assertNull($this->makerOf($seedLink));
		$this->assertSame('link-test-alice', $this->creatorOf($onApplication));

		$output = $this->createMock(IOutput::class);
		$output->expects($this->once())->method('info')->with($this->stringContains(': 0 link share(s)'));
		$step->run($output);
	}
}
