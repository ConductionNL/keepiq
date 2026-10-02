<?php

/**
 * Unit tests for the trash and the archive of a vault (vault-trash-and-archive).
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
use OCA\Keepiq\Db\GroupShareMapper;
use OCA\Keepiq\Db\Secret;
use OCA\Keepiq\Db\SecretDelegationMapper;
use OCA\Keepiq\Db\SecretMapper;
use OCA\Keepiq\Event\Audit\AuditEvent;
use OCA\Keepiq\Event\Audit\AuditEventTypes;
use OCA\Keepiq\Exception\ForbiddenException;
use OCA\Keepiq\Service\AuditService;
use OCA\Keepiq\Service\LinkShareService;
use OCA\Keepiq\Service\SecretRequestService;
use OCA\Keepiq\Service\SecretService;
use OCA\Keepiq\Service\SecretSharingRevoker;
use OCA\Keepiq\Service\SecretTrashService;
use OCA\Keepiq\Service\ShareService;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

/**
 * Trash, restore, purge, archive and unarchive against the real service and
 * the real sharing revoker.
 */
class SecretTrashServiceTest extends TestCase {
	/** @var SecretMapper&MockObject */
	private SecretMapper $mapper;

	/** @var SecretService&MockObject */
	private SecretService $secretService;

	/** @var LinkShareService&MockObject */
	private LinkShareService $linkShares;

	/** @var SecretRequestService&MockObject */
	private SecretRequestService $requests;

	/** @var ShareService&MockObject */
	private ShareService $shares;

	/** @var GroupShareMapper&MockObject */
	private GroupShareMapper $groupShares;

	/** @var SecretDelegationMapper&MockObject */
	private SecretDelegationMapper $delegations;

	/** @var list<AuditEvent> */
	private array $recorded = [];

	private SecretTrashService $service;

	/**
	 * Wire the real service and revoker over mocked storage.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		parent::setUp();
		$this->mapper = $this->createMock(SecretMapper::class);
		$this->secretService = $this->createMock(SecretService::class);
		$this->linkShares = $this->createMock(LinkShareService::class);
		$this->requests = $this->createMock(SecretRequestService::class);
		$this->shares = $this->createMock(ShareService::class);
		$this->groupShares = $this->createMock(GroupShareMapper::class);
		$this->delegations = $this->createMock(SecretDelegationMapper::class);

		$audit = $this->createMock(AuditService::class);
		$audit->method('record')->willReturnCallback(
			function (AuditEvent $event) {
				$this->recorded[] = $event;
				return $this->createMock(\OCA\Keepiq\Db\AuditEntry::class);
			}
		);

		$this->service = new SecretTrashService(
			mapper: $this->mapper,
			secretService: $this->secretService,
			sharingRevoker: new SecretSharingRevoker(
				linkShareService: $this->linkShares,
				secretRequestService: $this->requests,
				shareService: $this->shares,
				groupShareMapper: $this->groupShares,
				delegationMapper: $this->delegations,
			),
			auditService: $audit,
			logger: $this->createMock(LoggerInterface::class),
		);
	}//end setUp()

	/**
	 * A secret owned by alice.
	 *
	 * @param DateTime|null $trashedAt  When it was trashed
	 * @param DateTime|null $archivedAt When it was archived
	 *
	 * @return Secret
	 */
	private function secret(?DateTime $trashedAt = null, ?DateTime $archivedAt = null): Secret {
		$secret = new Secret();
		$secret->setId('s-1');
		$secret->setName('Router');
		$secret->setOwnerType('user');
		$secret->setOwnerId('alice');
		$secret->setTrashedAt($trashedAt);
		$secret->setArchivedAt($archivedAt);
		$this->secretService->method('findOwned')->with('s-1', 'alice')->willReturn($secret);
		return $secret;
	}//end secret()

	/**
	 * Trashing ends every share, request and delegation now, and keeps the
	 * secret's own data: nothing is purged.
	 *
	 * @return void
	 */
	public function testTrashRevokesSharingAndKeepsTheSecret(): void {
		$secret = $this->secret(archivedAt: new DateTime());
		$this->linkShares->expects($this->once())->method('deleteBySecretId')->with('s-1');
		$this->requests->expects($this->once())->method('deleteAllForSecret')->with('s-1');
		$this->shares->expects($this->once())->method('deleteAllForSecret')->with('s-1');
		$this->groupShares->expects($this->once())->method('deleteBySecret')->with('s-1');
		$this->delegations->expects($this->once())->method('deleteBySecret')->with('s-1');
		$this->secretService->expects($this->never())->method('delete');
		$this->mapper->expects($this->once())->method('update')->with($secret);

		$this->service->trash('s-1', 'alice');

		$this->assertNotNull($secret->getTrashedAt());
		$this->assertNull($secret->getArchivedAt(), 'a trashed item is never also archived');
		$this->assertSame(AuditEventTypes::SECRET_TRASHED, $this->recorded[0]->getEventType());
	}//end testTrashRevokesSharingAndKeepsTheSecret()

	/**
	 * Another user's secret is refused before anything is revoked.
	 *
	 * @return void
	 */
	public function testTrashRefusesAnotherUser(): void {
		$this->secretService->method('findOwned')->willThrowException(new ForbiddenException(message: 'no'));
		$this->linkShares->expects($this->never())->method('deleteBySecretId');

		$this->expectException(ForbiddenException::class);
		$this->service->trash('s-1', 'mallory');
	}//end testTrashRefusesAnotherUser()

	/**
	 * Restore takes the secret out of the trash.
	 *
	 * @return void
	 */
	public function testRestoreClearsTheTrashMark(): void {
		$secret = $this->secret(trashedAt: new DateTime('-2 days'));
		$this->mapper->expects($this->once())->method('update')->with($secret);

		$this->service->restore('s-1', 'alice');

		$this->assertNull($secret->getTrashedAt());
		$this->assertSame(AuditEventTypes::SECRET_RESTORED, $this->recorded[0]->getEventType());
	}//end testRestoreClearsTheTrashMark()

	/**
	 * Restore and purge only act on a trashed secret.
	 *
	 * @return void
	 */
	public function testPurgeRefusesALiveSecret(): void {
		$this->secret();
		$this->secretService->expects($this->never())->method('delete');

		$this->expectException(InvalidArgumentException::class);
		$this->service->purge('s-1', 'alice');
	}//end testPurgeRefusesALiveSecret()

	/**
	 * Purge runs the full delete cascade with the owner as the reason.
	 *
	 * @return void
	 */
	public function testPurgeRunsTheFullDelete(): void {
		$this->secret(trashedAt: new DateTime('-1 day'));
		$this->secretService->expects($this->once())->method('delete')->with('s-1', 'alice', 'owner');

		$this->service->purge('s-1', 'alice');
	}//end testPurgeRunsTheFullDelete()

	/**
	 * Archive keeps shares and marks the secret; unarchive clears it.
	 *
	 * @return void
	 */
	public function testArchiveAndUnarchiveKeepShares(): void {
		$secret = $this->secret();
		$this->linkShares->expects($this->never())->method('deleteBySecretId');
		$this->shares->expects($this->never())->method('deleteAllForSecret');

		$this->service->archive('s-1', 'alice');
		$this->assertNotNull($secret->getArchivedAt());

		$this->service->unarchive('s-1', 'alice');
		$this->assertNull($secret->getArchivedAt());
		$this->assertSame(
			[AuditEventTypes::SECRET_ARCHIVED, AuditEventTypes::SECRET_UNARCHIVED],
			array_map(static fn (AuditEvent $e) => $e->getEventType(), $this->recorded)
		);
	}//end testArchiveAndUnarchiveKeepShares()

	/**
	 * A trashed secret cannot be archived.
	 *
	 * @return void
	 */
	public function testArchiveRefusesATrashedSecret(): void {
		$this->secret(trashedAt: new DateTime());

		$this->expectException(InvalidArgumentException::class);
		$this->service->archive('s-1', 'alice');
	}//end testArchiveRefusesATrashedSecret()

	/**
	 * Every trash and archive event type is known to the audit whitelist and
	 * records the item name only.
	 *
	 * @return void
	 */
	public function testEventTypesAreWhitelisted(): void {
		foreach (['secret.trashed', 'secret.restored', 'secret.purged', 'secret.archived', 'secret.unarchived'] as $type) {
			$this->assertTrue(AuditEventTypes::isKnown($type), $type);
		}

		$this->assertSame(['reason'], AuditEventTypes::WHITELIST[AuditEventTypes::SECRET_PURGED]);
	}//end testEventTypesAreWhitelisted()

	/**
	 * A trash service over the REAL SecretService, so the offline delete
	 * precondition runs through findOwned as in production.
	 *
	 * @param Secret $stored The secret the store holds
	 *
	 * @return SecretTrashService
	 */
	private function serviceOverRealSecretService(Secret $stored): SecretTrashService {
		$store = $this->createMock(SecretMapper::class);
		$store->method('findById')->willReturn($stored);
		$secretService = new SecretService(
			mapper: $store,
			typeService: $this->createMock(\OCA\Keepiq\Service\SecretTypeService::class),
			suiteMapper: $this->createMock(\OCA\Keepiq\Db\EncryptionSuiteMapper::class),
			migrationService: $this->createMock(\OCA\Keepiq\Service\MigrationService::class),
			linkShareService: $this->linkShares,
			logger: $this->createMock(LoggerInterface::class),
		);

		return new SecretTrashService(
			mapper: $this->mapper,
			secretService: $secretService,
			sharingRevoker: new SecretSharingRevoker(
				linkShareService: $this->linkShares,
				secretRequestService: $this->requests,
				shareService: $this->shares,
				groupShareMapper: $this->groupShares,
				delegationMapper: $this->delegations,
			),
			auditService: $this->createMock(AuditService::class),
			logger: $this->createMock(LoggerInterface::class),
		);
	}//end serviceOverRealSecretService()

	/**
	 * A stored secret owned by alice, last changed at 10:00.
	 *
	 * @return Secret
	 */
	private function storedSecret(): Secret {
		$secret = new Secret();
		$secret->setId('s-1');
		$secret->setName('Router');
		$secret->setOwnerType('user');
		$secret->setOwnerId('alice');
		$secret->setUpdatedAt(new DateTime('2026-10-02T10:00:00+00:00'));
		return $secret;
	}//end storedSecret()

	/**
	 * An offline delete based on an older version leaves the secret and its
	 * sharing alone (offline-edit-queue).
	 *
	 * @return void
	 *
	 * @spec openspec/specs/offline-edit-queue/spec.md#requirement-concurrent-server-changes-are-never-overwritten-silently
	 */
	public function testTrashWithAStaleBaseChangesNothing(): void {
		$secret = $this->storedSecret();
		$service = $this->serviceOverRealSecretService($secret);
		$this->linkShares->expects($this->never())->method('deleteBySecretId');
		$this->mapper->expects($this->never())->method('update');

		$this->expectException(\OCA\Keepiq\Exception\StaleWriteException::class);
		try {
			$service->trash('s-1', 'alice', '2026-10-01T10:00:00+00:00');
		} finally {
			$this->assertNull($secret->getTrashedAt());
		}
	}//end testTrashWithAStaleBaseChangesNothing()

	/**
	 * A matching base trashes as before.
	 *
	 * @return void
	 */
	public function testTrashWithAMatchingBaseTrashes(): void {
		$secret = $this->storedSecret();
		$service = $this->serviceOverRealSecretService($secret);
		$this->mapper->expects($this->once())->method('update');

		$service->trash('s-1', 'alice', '2026-10-02T10:00:00+00:00');

		$this->assertNotNull($secret->getTrashedAt());
	}//end testTrashWithAMatchingBaseTrashes()
}//end class
