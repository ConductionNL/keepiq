<?php

/**
 * A quarantined planted row gives its creator nothing (GHSA-m5p9-6pw7-pf3g).
 *
 * The attacker owns S_a and planted {S_a, victim, V}, where V is the victim's
 * own secret. Once the row is quarantined, no lookup from V back to S_a, and
 * no exists-check on (S_a, victim), may treat it as a share: the attacker
 * cannot delegate S_a to the victim, the victim's writes of V are not routed
 * to S_a, a honey flag on S_a does not report the victim's reads of V, and the
 * victim may share V as the original it is. Nor does any path from S_a to its
 * copies reach V: a sync from S_a does not write it, and neither revoking the
 * row nor deleting S_a deletes it.
 *
 * The services run on the real mappers against the real database (the filter
 * is SQL), inside a transaction that is rolled back. Each scenario is run with
 * the row live as well, to show the same calls do reach the attacker without
 * the quarantine.
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
use InvalidArgumentException;
use OCA\Keepiq\Db\EncryptionSuite;
use OCA\Keepiq\Db\EncryptionSuiteMapper;
use OCA\Keepiq\Db\LinkShareMapper;
use OCA\Keepiq\Db\QuarantinedShareTargetMapper;
use OCA\Keepiq\Db\Secret;
use OCA\Keepiq\Db\SecretDelegation;
use OCA\Keepiq\Db\SecretDelegationMapper;
use OCA\Keepiq\Db\SecretMapper;
use OCA\Keepiq\Db\ShareTarget;
use OCA\Keepiq\Db\ShareTargetMapper;
use OCA\Keepiq\Event\Audit\AuditEvent;
use OCA\Keepiq\Exception\ConflictException;
use OCA\Keepiq\Event\Audit\AuditEventTypes;
use OCA\Keepiq\Listener\HoneyTripwireListener;
use OCA\Keepiq\Service\DelegationAuthorizer;
use OCA\Keepiq\Service\DelegationService;
use OCA\Keepiq\Service\DirectShareRegistrar;
use OCA\Keepiq\Service\HoneyTripwireService;
use OCA\Keepiq\Service\NotificationService;
use OCA\Keepiq\Service\RecipientSecretCopyFactory;
use OCA\Keepiq\Service\SecretVersionService;
use OCA\Keepiq\Service\ShareAuthorizationService;
use OCA\Keepiq\Service\ShareRevocationService;
use OCA\Keepiq\Service\ShareSync\ShareSyncGuards;
use OCA\Keepiq\Service\ShareSync\ShareWriteContext;
use OCA\Keepiq\Service\ShareSyncService;
use OCA\Keepiq\Tests\Support\ShareTargetDatabase;
use OCP\AppFramework\Db\DoesNotExistException;
use OCP\IRequest;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

/**
 * Tests that a quarantined row is inert.
 *
 * @spec openspec/specs/user-sharing/spec.md#requirement-planted-share-rows-are-quarantined
 */
class QuarantinedRowIsInertTest extends TestCase {
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
	 * The planted row, quarantined or live.
	 *
	 * @return array<string,array{0:bool}>
	 */
	public static function rowStates(): array {
		return [
			'quarantined' => [true],
			'live (the exploit)' => [false],
		];
	}//end rowStates()

	/**
	 * Plant {S_a, victim, V}.
	 *
	 * @param bool $quarantined Whether the row is quarantined
	 *
	 * @return ShareTarget
	 *
	 * @SuppressWarnings(PHPMD.BooleanArgumentFlag) The scenario switch.
	 */
	private function plant(bool $quarantined): ShareTarget {
		return $this->insertShareTarget(
			sourceSecretId: $this->sourceA->getId(),
			targetUserId: $this->victim,
			secretId: $this->secretV->getId(),
			createdBy: $this->attacker,
			createdAt: new DateTime('-30 minutes'),
			quarantined: $quarantined,
		);
	}//end plant()

	/**
	 * The real authorization service on the real mappers. Nobody holds a
	 * delegation unless a test grants one.
	 *
	 * @param EncryptionSuiteMapper|null $suites The suite mapper
	 *
	 * @return ShareAuthorizationService
	 */
	private function authorization(?EncryptionSuiteMapper $suites = null): ShareAuthorizationService {
		$delegations = $this->createMock(SecretDelegationMapper::class);
		$delegations->method('findActiveBySecretAndUser')->willThrowException(new DoesNotExistException('none'));

		return new ShareAuthorizationService(
			secretMapper: new SecretMapper(db: $this->db),
			delegationMapper: $delegations,
			shareTargetMapper: new ShareTargetMapper(db: $this->db),
		);
	}//end authorization()

	/**
	 * The attacker cannot make the victim a delegate of S_a through the
	 * quarantined row, which would route the victim's edits of V to S_a.
	 *
	 * @param bool $quarantined Whether the planted row is quarantined
	 *
	 * @return void
	 */
	#[DataProvider('rowStates')]
	public function testTheAttackerCannotDelegateHisSourceToTheVictim(bool $quarantined): void {
		$this->plant(quarantined: $quarantined);
		$delegations = $this->createMock(SecretDelegationMapper::class);
		$delegations->method('insert')->willReturnArgument(0);
		$service = new DelegationService(
			mapper: $delegations,
			authorizer: new DelegationAuthorizer(
				secretMapper: new SecretMapper(db: $this->db),
				shareTargetMapper: new ShareTargetMapper(db: $this->db),
			),
		);

		if ($quarantined === true) {
			$this->expectException(InvalidArgumentException::class);
			$this->expectExceptionMessage('already hold a share');
		}

		$delegation = $service->createDelegation(
			secretId: $this->sourceA->getId(),
			delegatedTo: $this->victim,
			initiatedBy: $this->attacker,
		);
		$this->assertInstanceOf(SecretDelegation::class, $delegation);
	}//end testTheAttackerCannotDelegateHisSourceToTheVictim()

	/**
	 * The victim's write context of V names V itself and the victim's own
	 * certificate, never S_a and the attacker's certificate.
	 *
	 * @param bool $quarantined Whether the planted row is quarantined
	 *
	 * @return void
	 */
	#[DataProvider('rowStates')]
	public function testTheVictimsWriteContextOfVIsNotRoutedToTheAttacker(bool $quarantined): void {
		$this->plant(quarantined: $quarantined);
		$suites = $this->createMock(EncryptionSuiteMapper::class);
		$suites->method('findActiveByOwner')->willReturnCallback(
			static function (string $ownerType, string $ownerId): EncryptionSuite {
				$suite = new EncryptionSuite();
				$suite->setCertificate('CERT-'.$ownerId);
				return $suite;
			}
		);
		$context = (new ShareWriteContext(
			targets: new ShareTargetMapper(db: $this->db),
			suites: $suites,
			auth: $this->authorization(suites: $suites),
		))->writeContext(secretId: $this->secretV->getId(), userId: $this->victim);

		if ($quarantined === false) {
			// The live row pivots V to S_a; the victim reads as a copy holder.
			$this->assertSame($this->sourceA->getId(), $context['sourceSecretId']);
			return;
		}

		$this->assertSame($this->secretV->getId(), $context['sourceSecretId']);
		$this->assertSame('owner', $context['effectiveGrade']);
		$this->assertSame('CERT-'.$this->victim, $context['ownerCertificate']);
	}//end testTheVictimsWriteContextOfVIsNotRoutedToTheAttacker()

	/**
	 * A read of V trips no honey alert on S_a.
	 *
	 * @param bool $quarantined Whether the planted row is quarantined
	 *
	 * @return void
	 */
	#[DataProvider('rowStates')]
	public function testAReadOfVTripsNoAlertOnTheAttackersSource(bool $quarantined): void {
		$this->plant(quarantined: $quarantined);
		$alerted = [];
		$honey   = $this->createMock(HoneyTripwireService::class);
		$honey->method('raiseAlert')->willReturnCallback(
			function (string $secretId) use (&$alerted): bool {
				$alerted[] = $secretId;
				return $secretId === $this->sourceA->getId();
			}
		);
		$listener = new HoneyTripwireListener(
			honeyService: $honey,
			linkShareMapper: $this->createMock(LinkShareMapper::class),
			shareTargetMapper: new ShareTargetMapper(db: $this->db),
			request: $this->createMock(IRequest::class),
			logger: $this->createMock(LoggerInterface::class),
		);

		$listener->handle(
			AuditEvent::forUser($this->victim, AuditEventTypes::SECRET_READ, 'secret', $this->secretV->getId())
		);

		if ($quarantined === false) {
			$this->assertSame([$this->secretV->getId(), $this->sourceA->getId()], $alerted);
			return;
		}

		$this->assertSame([$this->secretV->getId()], $alerted, 'only V itself is checked, never S_a');
	}//end testAReadOfVTripsNoAlertOnTheAttackersSource()

	/**
	 * The victim may share V through register-batch: it is their original, not
	 * a received copy.
	 *
	 * @param bool $quarantined Whether the planted row is quarantined
	 *
	 * @return void
	 */
	#[DataProvider('rowStates')]
	public function testTheVictimMayShareV(bool $quarantined): void {
		$this->plant(quarantined: $quarantined);
		$friend  = $this->user('friend');
		$factory = $this->createMock(RecipientSecretCopyFactory::class);
		$factory->method('create')->willReturnCallback(
			fn (): Secret => $this->insertSecret(ownerId: $friend, createdAt: new DateTime())
		);
		$registrar = new DirectShareRegistrar(
			mapper: new ShareTargetMapper(db: $this->db),
			secretMapper: new SecretMapper(db: $this->db),
			copyFactory: $factory,
			notificationService: $this->createMock(NotificationService::class),
			authorization: $this->authorization(),
		);

		$report = $registrar->registerDirectShares(
			userId: $this->victim,
			shares: [['sourceSecretId' => $this->secretV->getId(), 'targetUserId' => $friend, 'encryptedKey' => 'blob']]
		);

		$this->assertSame(($quarantined === true ? 'created' : 'not_shareable'), $report[0]['status']);
	}//end testTheVictimMayShareV()

	/**
	 * A sync from S_a never writes V, even when the attacker names it.
	 *
	 * @param bool $quarantined Whether the planted row is quarantined
	 *
	 * @return void
	 */
	#[DataProvider('rowStates')]
	public function testTheAttackersSyncDoesNotWriteV(bool $quarantined): void {
		$this->plant(quarantined: $quarantined);
		$secrets = new SecretMapper(db: $this->db);
		$sync    = new ShareSyncService(
			mapper: new ShareTargetMapper(db: $this->db),
			secretMapper: $secrets,
			db: $this->db,
			auth: $this->authorization(),
			versionService: $this->createMock(SecretVersionService::class),
			guards: $this->createMock(ShareSyncGuards::class),
			contexts: $this->createMock(ShareWriteContext::class),
		);

		$sync->writeCopyBlobs(
			source: $this->sourceA,
			updates: [['secretId' => $this->secretV->getId(), 'key' => 'EVIL']],
			allowedIds: [$this->secretV->getId() => true],
			savedAt: new DateTime(),
			writerId: $this->attacker,
		);

		$this->assertSame(($quarantined === true ? 'CIPHERTEXT' : 'EVIL'), $secrets->findById($this->secretV->getId())->getKey());
	}//end testTheAttackersSyncDoesNotWriteV()

	/**
	 * The attacker's revoke of the planted row does not delete V: it is
	 * refused with an explicit error, and V and the row stay.
	 *
	 * @param bool $quarantined Whether the planted row is quarantined
	 *
	 * @return void
	 */
	#[DataProvider('rowStates')]
	public function testRevokingThePlantedRowDoesNotDeleteV(bool $quarantined): void {
		$row     = $this->plant(quarantined: $quarantined);
		$secrets = new SecretMapper(db: $this->db);
		$service = new ShareRevocationService(
			mapper: new ShareTargetMapper(db: $this->db),
			secretMapper: $secrets,
			db: $this->db,
			logger: $this->createMock(LoggerInterface::class),
			auth: $this->authorization(),
			quarantined: new QuarantinedShareTargetMapper(db: $this->db),
		);

		if ($quarantined === false) {
			// The exploit: the revoke deletes the victim's own V.
			$service->revokeShare(shareId: $row->getId(), userId: $this->attacker);
			$this->expectException(DoesNotExistException::class);
			$secrets->findById($this->secretV->getId());
			return;
		}

		$this->expectException(ConflictException::class);
		try {
			$service->revokeShare(shareId: $row->getId(), userId: $this->attacker);
		} finally {
			$this->assertSame($this->victim, $secrets->findById($this->secretV->getId())->getOwnerId());
			$this->assertSame(
				$row->getId(),
				(new QuarantinedShareTargetMapper(db: $this->db))->findQuarantinedById($row->getId())->getId()
			);
		}
	}//end testRevokingThePlantedRowDoesNotDeleteV()

	/**
	 * Deleting S_a ends its shares without deleting V. The quarantined row
	 * goes with its source; V stays the victim's.
	 *
	 * @param bool $quarantined Whether the planted row is quarantined
	 *
	 * @return void
	 */
	#[DataProvider('rowStates')]
	public function testDeletingTheAttackersSourceLeavesV(bool $quarantined): void {
		$this->plant(quarantined: $quarantined);
		$secrets = new SecretMapper(db: $this->db);
		(new ShareRevocationService(
			mapper: new ShareTargetMapper(db: $this->db),
			secretMapper: $secrets,
			db: $this->db,
			logger: $this->createMock(LoggerInterface::class),
			auth: $this->authorization(),
		))->deleteAllForSecret(sourceSecretId: $this->sourceA->getId());

		if ($quarantined === false) {
			$this->expectException(DoesNotExistException::class);
		}

		$this->assertSame($this->victim, $secrets->findById($this->secretV->getId())->getOwnerId());
		$this->assertNotContains(
			$this->secretV->getId(),
			array_map(static fn ($row): string => $row->getSecretId(), (new QuarantinedShareTargetMapper(db: $this->db))->findQuarantined())
		);
	}//end testDeletingTheAttackersSourceLeavesV()
}//end class
