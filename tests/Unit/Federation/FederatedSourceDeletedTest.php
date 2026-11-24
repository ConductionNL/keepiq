<?php

/**
 * Deleting a secret for good ends its partner shares: a purge from the trash, the trash retention job and an account deletion each revoke the shares of the secret before it goes,
 * and the partner is told with SHARE_UNSHARED to delete its copy.
 *
 * @category Test
 * @package  OCA\Keepiq\Tests\Unit\Federation
 *
 * @author    Conduction Development Team <dev@conductio.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git-id>
 *
 * @link https://conduction.nl
 *
 * @spec openspec/specs/federated-sharing/spec.md#scenario-deleting-the-secret-for-good-removes-bobs-copy
 */

declare(strict_types=1);

namespace OCA\Keepiq\Tests\Unit\Federation;

use DateTime;
use OCA\Keepiq\BackgroundJob\PurgeTrashedSecretsJob;
use OCA\Keepiq\Db\DashboardSettingMapper;
use OCA\Keepiq\Db\EmergencyContactMapper;
use OCA\Keepiq\Db\EncryptionSuiteMapper;
use OCA\Keepiq\Db\FederatedShare;
use OCA\Keepiq\Db\FederatedShareMapper;
use OCA\Keepiq\Db\FederationPartner;
use OCA\Keepiq\Db\FederationPartnerMapper;
use OCA\Keepiq\Db\FolderMapper;
use OCA\Keepiq\Db\GroupShareMapper;
use OCA\Keepiq\Db\LinkShareMapper;
use OCA\Keepiq\Db\PasskeyMapper;
use OCA\Keepiq\Db\Secret;
use OCA\Keepiq\Db\SecretDelegation;
use OCA\Keepiq\Db\SecretDelegationMapper;
use OCA\Keepiq\Db\SecretMapper;
use OCA\Keepiq\Db\SecretRequestMapper;
use OCA\Keepiq\Db\SecretTrashMapper;
use OCA\Keepiq\Db\ShareSaveStageMapper;
use OCA\Keepiq\Db\ShareTarget;
use OCA\Keepiq\Db\ShareTargetMapper;
use OCA\Keepiq\Db\SuiteMigrationMapper;
use OCA\Keepiq\Event\Audit\AuditEvent;
use OCA\Keepiq\Event\Audit\AuditEventTypes;
use OCA\Keepiq\Event\Audit\AuditMetadataPolicy;
use OCA\Keepiq\Service\AccountDeletionService;
use OCA\Keepiq\Service\AccountShareCleanupService;
use OCA\Keepiq\Service\AccountSuiteCleanupService;
use OCA\Keepiq\Service\AttachmentService;
use OCA\Keepiq\Service\AuditService;
use OCA\Keepiq\Service\FederatedNotificationDelivery;
use OCA\Keepiq\Service\FederatedShareAuditTrail;
use OCA\Keepiq\Service\FederatedShareMessenger;
use OCA\Keepiq\Service\FederatedShareService;
use OCA\Keepiq\Service\FederationPartnerService;
use OCA\Keepiq\Service\FederationRootService;
use OCA\Keepiq\Service\LinkShareService;
use OCA\Keepiq\Service\MigrationService;
use OCA\Keepiq\Service\OwnedSecretCleanup;
use OCA\Keepiq\Service\PartnerShareRevoker;
use OCA\Keepiq\Service\RecipientCopyCleanup;
use OCA\Keepiq\Service\RecipientSecretCopyFactory;
use OCA\Keepiq\Service\RecipientSecretCopyService;
use OCA\Keepiq\Service\SecretChildDataCleaner;
use OCA\Keepiq\Service\SecretOwnerTransferService;
use OCA\Keepiq\Service\SecretService;
use OCA\Keepiq\Service\SecretSharingRevoker;
use OCA\Keepiq\Service\SecretTrashService;
use OCA\Keepiq\Service\SecretTypeService;
use OCA\Keepiq\Service\ShareAuthorizationService;
use OCA\Keepiq\Service\ShareRevocationService;
use OCA\Keepiq\Service\ShareSaveRequiredGuard;
use OCA\Keepiq\Service\ViewOnlyCopyGuard;
use OCA\Keepiq\Tests\Support\ShareRowJudgeFixture;
use OCP\AppFramework\Db\DoesNotExistException;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\EventDispatcher\IEventDispatcher;
use OCP\Federation\ICloudFederationFactory;
use OCP\Federation\ICloudFederationProviderManager;
use OCP\Http\Client\IClientService;
use OCP\Http\Client\IResponse;
use OCP\IAppConfig;
use OCP\IConfig;
use OCP\IDBConnection;
use OCP\IUserManager;
use OCP\Security\ISecureRandom;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

require_once __DIR__ . '/FederationFixtures.php';
require_once __DIR__ . '/OcmDoubles.php';

class FederatedSourceDeletedTest extends TestCase {
	use FederationFixtures;
	use ShareRowJudgeFixture;

	/** @var array<string,FederatedShare> The partner shares the database holds */
	private array $rows = [];

	/** @var array<string,Secret> The secrets the database holds */
	private array $secrets = [];

	/** @var FakeCloudFederationNotification[] */
	private array $sent = [];

	/** @var AuditEvent[] */
	private array $audit = [];

	/** @var list<array{sent:int,rows:array<string,array{status:string,pending:?string}>}> The partner shares as each delete of secrets found them */
	private array $atSourceDelete = [];

	/** Whether the partner's server takes a notification */
	private bool $reachable = true;

	/** The status the partner's server answers every notification with, when set */
	private ?int $answer = null;

	/** Runs while a notification is being sent, as a concurrent change would */
	private ?\Closure $duringSend = null;

	/** @var string[] The partners an administrator removed, by id */
	private array $removedPartners = [];

	/** @var string|null A declined share its recipient accepts back just before it would be deleted */
	private ?string $acceptedBackBeforeDelete = null;

	private FederatedNotificationDelivery $delivery;

	private SecretTrashService $trash;

	private PurgeTrashedSecretsJob $retention;

	private AccountDeletionService $accounts;

	private PartnerShareRevoker $revoker;

	private SecretMapper $secretMapper;

	protected function setUp(): void {
		$this->secrets = [
			'src' => $this->secret(id: 'src', ownerId: 'alice'),
		];

		$shares = $this->createMock(FederatedShareMapper::class);
		$shares->method('findById')->willReturnCallback(fn (string $id): FederatedShare => $this->read($id));
		$shares->method('update')->willReturnCallback(
			function (FederatedShare $row): FederatedShare {
				$this->store($row);
				return $row;
			}
		);
		$shares->method('findBySourceSecret')->willReturnCallback(
			fn (string $sourceSecretId): array => $this->readWhere(
				static fn (FederatedShare $row): bool => $row->getSourceSecretId() === $sourceSecretId
			)
		);
		// The join on the secrets table: the shares of the secrets the user owns now.
		$shares->method('findBySourceOwnerUser')->willReturnCallback(
			fn (string $ownerId): array => $this->readWhere(
				fn (FederatedShare $row): bool => ($this->secrets[$row->getSourceSecretId()] ?? null)?->getOwnerType() === 'user'
					&& $this->secrets[$row->getSourceSecretId()]->getOwnerId() === $ownerId
			)
		);
		$shares->method('reassignOwner')->willReturnCallback(
			function (string $sourceSecretId, string $ownerId): int {
				foreach ($this->rows as $row) {
					if ($row->getSourceSecretId() === $sourceSecretId) {
						$row->setOwnerId($ownerId);
					}
				}

				return 1;
			}
		);
		$shares->method('deleteIfStatus')->willReturnCallback(
			function (FederatedShare $row, string $status): int {
				if ($this->acceptedBackBeforeDelete === $row->getId()) {
					$this->rows[$row->getId()]->setStatus(FederatedShare::STATUS_ACTIVE);
				}

				return $this->deleteWhen(id: $row->getId(), when: static fn (FederatedShare $r): bool => $r->getStatus() === $status);
			}
		);
		$shares->method('deleteIfPending')->willReturnCallback(
			fn (FederatedShare $row, string $pending): int => $this->deleteWhen(id: $row->getId(), when: static fn (FederatedShare $r): bool => $r->getPendingNotification() === $pending)
		);
		$shares->method('deleteIfUnchanged')->willReturnCallback(
			fn (FederatedShare $row, string $pending, string $status, ?DateTime $updatedAt): int => $this->deleteWhen(
				id: $row->getId(),
				when: static fn (FederatedShare $r): bool => $r->getPendingNotification() === $pending
					&& $r->getStatus() === $status
					&& $r->getUpdatedAt()?->getTimestamp() === $updatedAt?->getTimestamp()
			)
		);
		$shares->method('updateIfActive')->willReturnCallback(
			fn (FederatedShare $row): int => (($this->rows[$row->getId()] ?? null)?->getStatus() === FederatedShare::STATUS_ACTIVE) ? $this->store($row) : 0
		);
		$shares->method('updateIfUnchanged')->willReturnCallback(
			function (FederatedShare $row, string $pending, string $status, ?DateTime $updatedAt): int {
				$stored = $this->rows[$row->getId()] ?? null;
				if ($stored === null
					|| $stored->getPendingNotification() !== $pending
					|| $stored->getStatus() !== $status
					|| $stored->getUpdatedAt()?->getTimestamp() !== $updatedAt?->getTimestamp()
				) {
					return 0;
				}

				return $this->store($row);
			}
		);
		$shares->method('findDueNotifications')->willReturnCallback(
			fn (DateTime $now, int $limit, string $ending): array => array_slice(
				$this->readWhere(
					static fn (FederatedShare $r): bool => $r->getPendingNotification() !== null
						&& $r->getNextNotifyAt() !== null && $r->getNextNotifyAt() <= $now
						&& ($r->getStatus() === FederatedShare::STATUS_ACTIVE || $r->getPendingNotification() === $ending)
				),
				0,
				$limit
			)
		);

		$secretMapper = $this->secretMapper();

		$factory = $this->createMock(ICloudFederationFactory::class);
		$factory->method('getCloudFederationNotification')->willReturnCallback(static fn () => new FakeCloudFederationNotification());
		$ocm = $this->createMock(ICloudFederationProviderManager::class);
		$ocm->method('sendCloudNotification')->willReturnCallback(
			function (string $remote, FakeCloudFederationNotification $notification): IResponse {
				$this->assertSame('cloud.partner.example', $remote);
				$this->sent[] = $notification;
				if ($this->duringSend !== null) {
					($this->duringSend)();
				}

				$response = $this->createMock(IResponse::class);
				$response->method('getStatusCode')->willReturn($this->answer ?? ($this->reachable ? 201 : 503));
				return $response;
			}
		);
		// No account exists here: the owner's is gone by the time the retry job sends.
		$users = $this->createMock(IUserManager::class);
		$users->method('userExists')->willReturn(false);
		$users->method('get')->willReturn(null);

		$dispatcher = $this->createMock(IEventDispatcher::class);
		$dispatcher->method('dispatchTyped')->willReturnCallback(
			function (AuditEvent $event): void {
				$this->audit[] = $event;
			}
		);
		$audit = new FederatedShareAuditTrail($dispatcher);
		$messenger = new FederatedShareMessenger($ocm, $factory, $this->cloudIdManager(), $users);
		$this->delivery = new FederatedNotificationDelivery($shares, $messenger, $audit, $secretMapper);
		$partners = $this->partners();
		$revoker = new PartnerShareRevoker($shares, $this->delivery, $audit, $partners);

		$secretService = new SecretService(
			mapper: $secretMapper,
			typeService: $this->createMock(SecretTypeService::class),
			suiteMapper: $this->createMock(EncryptionSuiteMapper::class),
			migrationService: $this->createMock(MigrationService::class),
			linkShareService: $this->createMock(LinkShareService::class),
			logger: $this->createMock(LoggerInterface::class),
			db: $this->createMock(IDBConnection::class),
			viewOnlyCopies: $this->createStub(ViewOnlyCopyGuard::class),
			shareSaveRequired: $this->createStub(ShareSaveRequiredGuard::class),
			federatedShares: new FederatedShareService(
				shareMapper: $shares,
				secretMapper: $secretMapper,
				partners: $partners,
				root: $this->createMock(FederationRootService::class),
				cloudIdManager: $this->cloudIdManager(),
				messenger: $messenger,
				random: $this->createMock(ISecureRandom::class),
				delivery: $this->delivery,
				audit: $audit,
			),
		);

		$this->trash = new SecretTrashService(
			mapper: $secretMapper,
			secretService: $secretService,
			sharingRevoker: $this->createMock(SecretSharingRevoker::class),
			auditService: $this->createMock(AuditService::class),
			logger: $this->createMock(LoggerInterface::class),
		);

		$trashed = $this->createMock(SecretTrashMapper::class);
		$trashed->method('findTrashedBefore')->willReturnCallback(
			fn (): array => array_values(array_filter($this->secrets, static fn (Secret $s): bool => $s->getTrashedAt() !== null))
		);
		$appConfig = $this->createMock(IAppConfig::class);
		$appConfig->method('getValueInt')->willReturn(30);
		$this->retention = new PurgeTrashedSecretsJob(
			time: $this->createMock(ITimeFactory::class),
			trashMapper: $trashed,
			secretService: $secretService,
			appConfig: $appConfig,
			logger: $this->createMock(LoggerInterface::class),
		);

		$this->accounts = $this->accountDeletion(secretMapper: $secretMapper, shares: $shares, revoker: $revoker);
		$this->revoker = $revoker;
		$this->secretMapper = $secretMapper;
	}

	/**
	 * The partner list: cloud.partner.example, until an administrator removes it.
	 */
	private function partners(): FederationPartnerService {
		$mapper = $this->createMock(FederationPartnerMapper::class);
		$mapper->method('findById')->willReturnCallback(
			fn (string $id): FederationPartner => in_array($id, $this->removedPartners, true)
				? throw new DoesNotExistException('removed')
				: $this->partner('cloud.partner.example', true, true)
		);

		return new FederationPartnerService($mapper, $this->createMock(IClientService::class), $this->createMock(IConfig::class));
	}

	/**
	 * The account deletion cascade over these secrets and partner shares; alice delegated `delegated` to carol.
	 */
	private function accountDeletion(SecretMapper $secretMapper, FederatedShareMapper $shares, PartnerShareRevoker $revoker): AccountDeletionService {
		$delegation = new SecretDelegation();
		$delegation->setSecretId('delegated');
		$delegation->setOriginalOwnerId('alice');
		$delegation->setDelegatedTo('carol');
		$delegations = $this->createMock(SecretDelegationMapper::class);
		$delegations->method('findByOriginalOwner')->willReturnCallback(
			fn (): array => isset($this->secrets['delegated']) ? [$delegation] : []
		);
		$links = $this->createMock(LinkShareMapper::class);

		return new AccountDeletionService(
			secretMapper: $secretMapper,
			folderMapper: $this->createMock(FolderMapper::class),
			linkShareMapper: $links,
			requestMapper: $this->createMock(SecretRequestMapper::class),
			settingMapper: $this->createMock(DashboardSettingMapper::class),
			dispatcher: $this->createMock(IEventDispatcher::class),
			shareCleanup: new AccountShareCleanupService(
				secretMapper: $secretMapper,
				shareMapper: $this->createMock(ShareTargetMapper::class),
				groupShareMapper: $this->createMock(GroupShareMapper::class),
				delegationMapper: $delegations,
				stageMapper: $this->createMock(ShareSaveStageMapper::class),
				ownerTransfer: new SecretOwnerTransferService(
					db: $this->createMock(IDBConnection::class),
					secretMapper: $secretMapper,
					federatedShares: $shares,
					linkShares: $links,
				),
				quarantineScan: $this->clearedQuarantineScan(),
			),
			suiteCleanup: new AccountSuiteCleanupService(
				suiteMapper: $this->createMock(EncryptionSuiteMapper::class),
				migrationMapper: $this->createMock(SuiteMigrationMapper::class),
				emergencyMapper: $this->createMock(EmergencyContactMapper::class),
				passkeyMapper: $this->createMock(PasskeyMapper::class),
			),
			ownSecretCleanup: new OwnedSecretCleanup(
				childData: new SecretChildDataCleaner(secretMapper: $secretMapper),
				partnerShares: $revoker,
			),
		);
	}

	/**
	 * The secrets table: a delete of secrets records the partner shares as it finds them.
	 */
	private function secretMapper(): SecretMapper {
		$mapper = $this->createMock(SecretMapper::class);
		$mapper->method('findById')->willReturnCallback(
			fn (string $id): Secret => $this->secrets[$id] ?? throw new DoesNotExistException('gone')
		);
		$mapper->method('findByOwner')->willReturnCallback(
			fn (string $ownerType, string $ownerId): array => array_values(
				array_filter($this->secrets, static fn (Secret $s): bool => $s->getOwnerType() === $ownerType && $s->getOwnerId() === $ownerId)
			)
		);
		$mapper->method('reassignOwner')->willReturnCallback(
			function (string $secretId, string $newOwnerId): void {
				$this->secrets[$secretId]->setOwnerId($newOwnerId);
			}
		);
		$mapper->method('delete')->willReturnCallback(
			function (Secret $secret): Secret {
				$this->recordSourceDelete();
				unset($this->secrets[$secret->getId()]);
				return $secret;
			}
		);
		$mapper->method('deleteByOwnerUser')->willReturnCallback(
			function (string $ownerId): int {
				$this->recordSourceDelete();
				$before = count($this->secrets);
				$this->secrets = array_filter($this->secrets, static fn (Secret $s): bool => $s->getOwnerType() !== 'user' || $s->getOwnerId() !== $ownerId);
				return $before - count($this->secrets);
			}
		);

		return $mapper;
	}

	private function secret(string $id, string $ownerId): Secret {
		$secret = new Secret();
		$secret->setId($id);
		$secret->setName('Supplier portal');
		$secret->setOwnerType('user');
		$secret->setOwnerId($ownerId);
		$secret->setTrashedAt(new DateTime('-60 days'));
		return $secret;
	}

	private function share(string $id, string $sourceSecretId, string $status = FederatedShare::STATUS_ACTIVE, ?string $pending = null, int $attempts = 0, ?DateTime $next = null): void {
		$row = new FederatedShare();
		$row->setId($id);
		$row->setSourceSecretId($sourceSecretId);
		$row->setOwnerId('alice');
		$row->setRecipientCloudId('bob@cloud.partner.example');
		$row->setPartnerId('p-cloud.partner.example');
		$row->setRecipientCertFingerprint(str_repeat('a', 64));
		$row->setKey('CIPHER-KEY-FOR-BOB');
		$row->setSharedSecretHash(hash('sha256', 'the-shared-secret'));
		$row->setStatus($status);
		$row->setPendingNotification($pending);
		$row->setNotifyAttempts($attempts);
		if ($next !== null) {
			$row->setNextNotifyAt($next);
		}
		$row->setUpdatedAt(new DateTime('2026-10-01T00:00:00Z'));
		$this->store($row);
	}

	private function store(FederatedShare $row): int {
		$this->rows[$row->getId()] = clone $row;

		return 1;
	}

	private function read(string $id): FederatedShare {
		if (isset($this->rows[$id]) === false) {
			throw new DoesNotExistException('none');
		}

		return clone $this->rows[$id];
	}

	/**
	 * @return FederatedShare[]
	 */
	private function readWhere(\Closure $where): array {
		return array_values(array_map(static fn (FederatedShare $row): FederatedShare => clone $row, array_filter($this->rows, $where)));
	}

	private function deleteWhen(string $id, \Closure $when): int {
		if (isset($this->rows[$id]) === false || $when($this->rows[$id]) === false) {
			return 0;
		}

		unset($this->rows[$id]);
		return 1;
	}

	private function recordSourceDelete(): void {
		$this->atSourceDelete[] = [
			'sent' => count($this->sent),
			'rows' => array_map(
				static fn (FederatedShare $row): array => ['status' => $row->getStatus(), 'pending' => $row->getPendingNotification()],
				$this->rows
			),
		];
	}

	/**
	 * @return string[] The SHARE_UNSHARED notifications sent, by share id
	 */
	private function unsharesSent(): array {
		return array_map(
			function (FakeCloudFederationNotification $notification): string {
				$message = $notification->getMessage();
				$this->assertSame(FederatedNotificationDelivery::SHARE_UNSHARED, $message['notificationType']);
				return $message['providerId'];
			},
			$this->sent
		);
	}

	/**
	 * @return AuditEvent[] The revocations recorded
	 */
	private function revocations(): array {
		return array_values(
			array_filter($this->audit, static fn (AuditEvent $event): bool => $event->getEventType() === AuditEventTypes::FEDERATED_SHARE_REVOKED)
		);
	}

	/**
	 * Delete `src` for good the way the path does.
	 */
	private function deleteForGood(string $path): void {
		match ($path) {
			'purge' => $this->trash->purge('src', 'alice'),
			'retention' => $this->retention->purgeExpired(new DateTime()),
			'account deletion' => $this->accounts->deleteAllFor('alice'),
		};
	}

	/**
	 * Every status a share can have when its secret goes, on every path that deletes the secret for good.
	 *
	 * @return array<string,array{0:string,1:string,2:?string,3:int,4:?string,5:string,6?:bool}>
	 */
	public static function statusesOnEveryPath(): array {
		$statuses = [
			'active' => [FederatedShare::STATUS_ACTIVE, null, 0, null, 'revoked'],
			'suspended, its partner still one' => [FederatedShare::STATUS_SUSPENDED, null, 0, null, 'revoked'],
			'suspended, its partner removed' => [FederatedShare::STATUS_SUSPENDED, null, 0, null, 'deleted', true],
			'failed after an update' => [FederatedShare::STATUS_FAILED, FederatedNotificationDelivery::SHARE_UPDATED, 6, null, 'revoked'],
			'failed after an unshare' => [FederatedShare::STATUS_FAILED, FederatedNotificationDelivery::SHARE_UNSHARED, 6, null, 'revoked'],
			'a status this code does not know' => ['paused', null, 0, null, 'revoked'],
			'declined' => [FederatedShare::STATUS_DECLINED, null, 0, null, 'deleted'],
			'revoked with its unshare pending' => [FederatedShare::STATUS_REVOKED, FederatedNotificationDelivery::SHARE_UNSHARED, 2, '-5 minutes', 'kept'],
		];
		$cases = [];
		foreach (['purge', 'retention', 'account deletion'] as $path) {
			foreach ($statuses as $name => $status) {
				$cases[$path . ': ' . $name] = [$path, ...$status];
			}
		}

		return $cases;
	}

	/**
	 * A live share, or one in any other state the partner may still hold a copy for, is revoked and the partner is told to delete it:
	 * now on the owner's purge, by the retry job after the retention job and an account deletion.
	 * A declined share, and a suspended one whose partner was removed, go without a notification, recorded as revoked;
	 * a revoke on its way keeps its pending unshare.
	 */
	#[DataProvider('statusesOnEveryPath')]
	public function testDeletingTheSecretForGoodEndsEachPartnerShare(string $path, string $status, ?string $pending, int $attempts, ?string $next, string $outcome, bool $partnerRemoved = false): void {
		if ($partnerRemoved === true) {
			$this->removedPartners[] = 'p-cloud.partner.example';
		}

		$nextAt = $next === null ? null : new DateTime($next);
		$this->share(id: 'fs-1', sourceSecretId: 'src', status: $status, pending: $pending, attempts: $attempts, next: $nextAt);
		$queued = $path !== 'purge';

		$this->deleteForGood(path: $path);

		$this->assertArrayNotHasKey('src', $this->secrets);
		// When the secret went, its share was no longer live: gone, or revoked with the unshare pending.
		$this->assertCount(1, $this->atSourceDelete);
		$atDelete = $this->atSourceDelete[0]['rows']['fs-1'] ?? null;
		if ($outcome === 'deleted') {
			$this->assertNull($atDelete);
		} else if ($outcome === 'revoked' && $queued === false) {
			$this->assertNull($atDelete);
			$this->assertSame(1, $this->atSourceDelete[0]['sent']);
		} else {
			$this->assertSame(['status' => FederatedShare::STATUS_REVOKED, 'pending' => FederatedNotificationDelivery::SHARE_UNSHARED], $atDelete);
		}

		$expectedActor = match ($path) {
			'retention' => null,
			default => 'alice',
		};
		if ($outcome !== 'kept') {
			$this->assertCount(1, $this->revocations());
			$this->assertSame($expectedActor, $this->revocations()[0]->getActorId());
			$this->assertSame('src', $this->revocations()[0]->getObjectId());
		} else {
			$this->assertSame([], $this->revocations());
		}

		if ($outcome === 'revoked' && $queued === false) {
			// Told now, and the delivered unshare took the row.
			$this->assertSame(['fs-1'], $this->unsharesSent());
			$this->assertSame([], $this->rows);
		}

		if ($outcome === 'revoked' && $queued === true) {
			// Left to the retry job: nothing was sent, and the next run finds it due.
			$this->assertSame([], $this->sent);
			$this->assertSame(FederatedShare::STATUS_REVOKED, $this->rows['fs-1']->getStatus());
			$this->assertSame(FederatedNotificationDelivery::SHARE_UNSHARED, $this->rows['fs-1']->getPendingNotification());
			$this->assertSame(0, $this->rows['fs-1']->getNotifyAttempts());
		}

		if ($outcome === 'kept') {
			$this->assertSame([], $this->sent);
			$this->assertSame($attempts, $this->rows['fs-1']->getNotifyAttempts());
			$this->assertEquals($nextAt, $this->rows['fs-1']->getNextNotifyAt());
		}

		if ($outcome === 'deleted') {
			$this->assertSame([], $this->rows);
		}

		// The retry job finishes what is left, though the secret is gone.
		$this->delivery->retryDue(new DateTime());
		$this->assertSame([], $this->rows);
		$this->assertSame($outcome === 'deleted' ? [] : ['fs-1'], $this->unsharesSent());
	}

	/**
	 * An account deletion leaves alone the partner shares of a secret handed to a delegate, and a share whose own owner is the user but whose secret an earlier transfer gave to someone else;
	 * it ends the shares of the user's own secrets only.
	 */
	public function testAnAccountDeletionLeavesTheSharesOfSecretsThatStayWithSomeoneElse(): void {
		$this->secrets['delegated'] = $this->secret(id: 'delegated', ownerId: 'alice');
		$this->secrets['dave-now'] = $this->secret(id: 'dave-now', ownerId: 'dave');
		$this->share(id: 'fs-own', sourceSecretId: 'src');
		$this->share(id: 'fs-delegated', sourceSecretId: 'delegated');
		$this->share(id: 'fs-left-behind', sourceSecretId: 'dave-now');

		$this->accounts->deleteAllFor('alice');

		$this->assertSame(['delegated', 'dave-now'], array_keys($this->secrets));
		$this->assertSame('carol', $this->secrets['delegated']->getOwnerId());
		$this->assertSame(
			['status' => FederatedShare::STATUS_ACTIVE, 'pending' => null, 'owner' => 'carol'],
			['status' => $this->rows['fs-delegated']->getStatus(), 'pending' => $this->rows['fs-delegated']->getPendingNotification(), 'owner' => $this->rows['fs-delegated']->getOwnerId()]
		);
		$this->assertSame(
			['status' => FederatedShare::STATUS_ACTIVE, 'pending' => null, 'owner' => 'alice'],
			['status' => $this->rows['fs-left-behind']->getStatus(), 'pending' => $this->rows['fs-left-behind']->getPendingNotification(), 'owner' => $this->rows['fs-left-behind']->getOwnerId()]
		);
		$this->assertSame(FederatedShare::STATUS_REVOKED, $this->rows['fs-own']->getStatus());
		$this->assertCount(1, $this->revocations());
		$this->assertSame('src', $this->revocations()[0]->getObjectId());

		$this->delivery->retryDue(new DateTime());
		$this->assertSame(['fs-own'], $this->unsharesSent());
		$this->assertSame(['fs-delegated', 'fs-left-behind'], array_keys($this->rows));
	}

	/**
	 * The retry job sends a queued unshare and then removes the share, after the secret and its owner's account are both gone:
	 * the notification needs only the share row.
	 */
	public function testTheRetryJobSendsAQueuedUnshareAfterTheSecretAndItsOwnerAreGone(): void {
		$this->share(id: 'fs-1', sourceSecretId: 'src');
		$this->accounts->deleteAllFor('alice');
		$this->assertSame([], $this->secrets);
		$this->assertSame([], $this->sent);

		$this->assertSame(1, $this->delivery->retryDue(new DateTime()));

		$message = $this->sent[0]->getMessage();
		$this->assertSame(FederatedNotificationDelivery::SHARE_UNSHARED, $message['notificationType']);
		$this->assertSame('fs-1', $message['providerId']);
		$this->assertSame('alice@cloud.here.example', $message['notification']['sender']);
		$this->assertSame(hash('sha256', 'the-shared-secret'), $message['notification']['sharedSecret']);
		$this->assertSame([], $this->rows);
	}

	/**
	 * @return array<string,array{0:string}>
	 */
	public static function everyPath(): array {
		return ['purge' => ['purge'], 'retention' => ['retention'], 'account deletion' => ['account deletion']];
	}

	/**
	 * A declined share whose recipient accepts it back while the secret is being deleted is not deleted silently: it is revoked, and the partner told.
	 */
	#[DataProvider('everyPath')]
	public function testADeclinedShareAcceptedBackBeforeItIsDeletedIsRevoked(string $path): void {
		$this->share(id: 'fs-1', sourceSecretId: 'src', status: FederatedShare::STATUS_DECLINED);
		$this->acceptedBackBeforeDelete = 'fs-1';

		$this->deleteForGood(path: $path);

		$this->assertCount(1, $this->revocations());
		$this->delivery->retryDue(new DateTime());
		$this->assertSame(['fs-1'], $this->unsharesSent());
		$this->assertSame([], $this->rows);
	}

	/**
	 * Run the retry job each time the share is due, until it is due after `$until` or gone.
	 *
	 * @return list<array{at:DateTime,wait:?int}> Each attempt, and the wait it left before the next
	 */
	private function retryUntil(DateTime $until): array {
		$attempts = [];
		while (($this->rows['fs-1'] ?? null)?->getNextNotifyAt() !== null && $this->rows['fs-1']->getNextNotifyAt() <= $until) {
			$due = clone $this->rows['fs-1']->getNextNotifyAt();
			$this->delivery->retryDue($due);
			$next = ($this->rows['fs-1'] ?? null)?->getNextNotifyAt();
			$attempts[] = ['at' => $due, 'wait' => $next === null ? null : $next->getTimestamp() - $due->getTimestamp()];
		}

		return $attempts;
	}

	/**
	 * @return AuditEvent[] The give-ups recorded
	 */
	private function failures(): array {
		return array_values(
			array_filter($this->audit, static fn (AuditEvent $event): bool => $event->getEventType() === AuditEventTypes::FEDERATED_SHARE_FAILED)
		);
	}

	/**
	 * With the secret gone no owner can revoke again, so an unreachable partner is retried past the sixth attempt, at most 6 hours apart,
	 * still on day 29, and only after 30 days is the share dropped, recorded as failed.
	 */
	public function testAnUnreachablePartnerIsRetriedForThirtyDaysThenTheShareIsDropped(): void {
		$this->share(id: 'fs-1', sourceSecretId: 'src');
		$this->reachable = false;
		$this->deleteForGood(path: 'retention');
		$revokedAt = clone $this->rows['fs-1']->getUpdatedAt();

		$attempts = $this->retryUntil(until: (clone $revokedAt)->modify('+29 days'));

		$waits = array_column($attempts, 'wait');
		$this->assertSame([60, 120, 240, 480, 960, 1920, 3840, 7680, 15360, 21600, 21600], array_slice($waits, 0, 11));
		$this->assertLessThanOrEqual(21600, max($waits));
		$this->assertNotContains(null, $waits);
		$this->assertGreaterThan(100, count($attempts));
		$this->assertSame(FederatedShare::STATUS_REVOKED, $this->rows['fs-1']->getStatus());
		$this->assertSame(FederatedNotificationDelivery::SHARE_UNSHARED, $this->rows['fs-1']->getPendingNotification());
		$this->assertSame(count($attempts), $this->rows['fs-1']->getNotifyAttempts());
		$this->assertSame([], $this->failures());

		// Day 29 has its attempts too.
		$onDay29 = array_filter($attempts, static fn (array $attempt): bool => $attempt['at'] > (clone $revokedAt)->modify('+28 days'));
		$this->assertGreaterThanOrEqual(4, count($onDay29));

		// Up to the 30th day it is still retried.
		$thirtyDays = (clone $revokedAt)->modify('+30 days');
		$this->retryUntil(until: (clone $thirtyDays)->modify('-1 second'));
		$this->assertArrayHasKey('fs-1', $this->rows);
		$this->assertGreaterThanOrEqual($thirtyDays, $this->rows['fs-1']->getNextNotifyAt());

		// The first attempt after 30 days that fails drops the share.
		$this->retryUntil(until: (clone $thirtyDays)->modify('+6 hours'));
		$this->assertSame([], $this->rows);
		$this->assertCount(1, $this->failures());
		$failure = $this->failures()[0];
		$this->assertNull($failure->getActorId());
		$this->assertSame('src', $failure->getObjectId());
		$this->assertSame(FederatedNotificationDelivery::SHARE_UNSHARED, $failure->getMetadata()['notification']);
		$this->assertSame('source_deleted', $failure->getMetadata()['reason']);
		$this->assertSame([], array_diff(array_keys($failure->getMetadata()), AuditMetadataPolicy::WHITELIST[AuditEventTypes::FEDERATED_SHARE_FAILED]));
	}

	/**
	 * A partner that refuses the unshare of a deleted secret outright, with a 400 on every try, is not asked again for 30 days:
	 * the sixth refusal drops the share, recorded as failed with reason source_deleted, and nothing more is sent.
	 */
	public function testAPartnerThatRefusesTheUnshareIsDroppedAfterSixTries(): void {
		$this->share(id: 'fs-1', sourceSecretId: 'src');
		$this->answer = 400;
		$this->deleteForGood(path: 'retention');
		$revokedAt = clone $this->rows['fs-1']->getUpdatedAt();

		$attempts = $this->retryUntil(until: (clone $revokedAt)->modify('+30 days'));

		$this->assertCount(FederatedNotificationDelivery::MAX_ATTEMPTS, $attempts);
		$this->assertCount(FederatedNotificationDelivery::MAX_ATTEMPTS, $this->unsharesSent());
		$this->assertSame([], $this->rows);
		$this->assertCount(1, $this->failures());
		$this->assertSame(
			['federatedShareId' => 'fs-1', 'recipientCloudId' => 'bob@cloud.partner.example', 'partnerId' => 'p-cloud.partner.example', 'notification' => FederatedNotificationDelivery::SHARE_UNSHARED, 'reason' => 'source_deleted'],
			$this->failures()[0]->getMetadata()
		);
	}

	/**
	 * A 429 or a 5xx is no refusal: the partner may take the unshare later, so it is still retried past the sixth attempt.
	 *
	 * @return array<string,array{0:int}>
	 */
	public static function answersThatAreNoRefusal(): array {
		return ['too many requests' => [429], 'server error' => [500]];
	}

	#[DataProvider('answersThatAreNoRefusal')]
	public function testAPartnerThatIsNotReadyIsStillRetriedPastTheSixthTry(int $answer): void {
		$this->share(id: 'fs-1', sourceSecretId: 'src');
		$this->answer = $answer;
		$this->deleteForGood(path: 'retention');
		$revokedAt = clone $this->rows['fs-1']->getUpdatedAt();

		$attempts = $this->retryUntil(until: (clone $revokedAt)->modify('+1 day'));

		$this->assertGreaterThan(FederatedNotificationDelivery::MAX_ATTEMPTS, count($attempts));
		$this->assertArrayHasKey('fs-1', $this->rows);
		$this->assertSame([], $this->failures());
	}

	/**
	 * The paths that delete a user's copy of a shared secret for good together with its share.
	 *
	 * @return array<string,array{0:string}>
	 */
	public static function copyDeletions(): array {
		return [
			'the source\'s shares end (revoke, trash or purge of the source)' => ['source shares end'],
			'a team-folder share ends' => ['team-folder share ends'],
		];
	}

	/**
	 * Bob shared his full-access copy of Alice's secret with a partner. When the copy is deleted for good with its share,
	 * the partner share ends before the copy goes, revoked with SHARE_UNSHARED pending, and the retry job tells the partner.
	 */
	#[DataProvider('copyDeletions')]
	public function testAPartnerSharedCopyDeletedForGoodEndsItsPartnerShare(string $path): void {
		$copy = $this->secret(id: 'copy-bob', ownerId: 'bob');
		$copy->setTrashedAt(null);
		$this->secrets['copy-bob'] = $copy;
		$this->share(id: 'fs-bob', sourceSecretId: 'copy-bob');
		$this->rows['fs-bob']->setOwnerId('bob');
		$target = new ShareTarget();
		$target->setId('st-bob');
		$target->setSourceSecretId('src');
		$target->setSecretId('copy-bob');
		$target->setTargetUserId('bob');

		if ($path === 'source shares end') {
			$targets = $this->createMock(ShareTargetMapper::class);
			$targets->method('findBySourceSecret')->willReturn([$target]);
			(new ShareRevocationService(
				mapper: $targets,
				secretMapper: $this->secretMapper,
				db: $this->createMock(IDBConnection::class),
				logger: $this->createMock(LoggerInterface::class),
				auth: $this->createMock(ShareAuthorizationService::class),
				quarantineScan: $this->clearedQuarantineScan(),
				copyCleanup: new RecipientCopyCleanup(attachments: $this->createMock(AttachmentService::class), partnerShares: $this->revoker),
			))->deleteAllForSecret(sourceSecretId: 'src');
		} else {
			(new RecipientSecretCopyService(
				secretMapper: $this->secretMapper,
				copies: $this->createStub(RecipientSecretCopyFactory::class),
				partnerShares: $this->revoker,
			))->deleteByShare(share: $target);
		}

		$this->assertArrayNotHasKey('copy-bob', $this->secrets);
		// When the copy went, its partner share had ended already.
		$this->assertSame([['sent' => 0, 'rows' => ['fs-bob' => ['status' => FederatedShare::STATUS_REVOKED, 'pending' => FederatedNotificationDelivery::SHARE_UNSHARED]]]], $this->atSourceDelete);
		$this->assertSame(FederatedShare::STATUS_REVOKED, $this->rows['fs-bob']->getStatus());
		$this->assertSame(FederatedNotificationDelivery::SHARE_UNSHARED, $this->rows['fs-bob']->getPendingNotification());
		$this->assertCount(1, $this->revocations());
		$this->assertSame('copy-bob', $this->revocations()[0]->getObjectId());
		$this->assertNull($this->revocations()[0]->getActorId());

		$this->assertSame(1, $this->delivery->retryDue(new DateTime('+1 minute')));
		$this->assertSame(['fs-bob'], $this->unsharesSent());
		$this->assertSame([], $this->rows);
	}

	/**
	 * @return array<string,array{0:string}>
	 */
	public static function changesDuringTheLastAttempt(): array {
		return [
			'a decline deleted the share' => ['deleted'],
			'the share was written again' => ['written'],
		];
	}

	/**
	 * A share that changed while the attempt after 30 days was under way is not dropped by it, and no failure is recorded.
	 */
	#[DataProvider('changesDuringTheLastAttempt')]
	public function testADropLeavesAShareThatChangedDuringTheAttempt(string $change): void {
		$this->share(id: 'fs-1', sourceSecretId: 'src');
		$this->reachable = false;
		$this->deleteForGood(path: 'retention');
		$thirtyDays = (clone $this->rows['fs-1']->getUpdatedAt())->modify('+30 days');
		$this->retryUntil(until: (clone $thirtyDays)->modify('-1 second'));
		$writtenAt = (clone $thirtyDays)->modify('+1 second');
		$this->duringSend = function () use ($change, $writtenAt): void {
			if ($change === 'deleted') {
				unset($this->rows['fs-1']);
				return;
			}

			$this->rows['fs-1']->setUpdatedAt($writtenAt);
		};

		$this->delivery->retryDue(clone $this->rows['fs-1']->getNextNotifyAt());

		$this->assertSame([], $this->failures());
		if ($change === 'deleted') {
			$this->assertSame([], $this->rows);
			return;
		}

		$this->assertArrayHasKey('fs-1', $this->rows);
		$this->assertEquals($writtenAt, $this->rows['fs-1']->getUpdatedAt());
		$this->assertSame(FederatedNotificationDelivery::SHARE_UNSHARED, $this->rows['fs-1']->getPendingNotification());
	}

	/**
	 * An unshare that arrives after many failed attempts still removes the share, and nothing is recorded as failed.
	 */
	public function testAnUnshareThatArrivesAfterManyAttemptsRemovesTheShare(): void {
		$this->share(id: 'fs-1', sourceSecretId: 'src');
		$this->reachable = false;
		$this->deleteForGood(path: 'retention');
		$revokedAt = clone $this->rows['fs-1']->getUpdatedAt();
		$this->retryUntil(until: (clone $revokedAt)->modify('+3 days'));
		$this->assertGreaterThan(FederatedNotificationDelivery::MAX_ATTEMPTS, $this->rows['fs-1']->getNotifyAttempts());

		$this->reachable = true;
		$this->assertSame(1, $this->delivery->retryDue(clone $this->rows['fs-1']->getNextNotifyAt()));

		$this->assertSame([], $this->rows);
		$this->assertSame([], $this->failures());
	}

	/**
	 * An unshare whose secret still exists, here in the trash, is given up after the sixth attempt as before: its owner sees the share failed.
	 */
	public function testAnUnshareWhoseSecretStillExistsIsGivenUpAfterSixAttempts(): void {
		$revokedAt = new DateTime('2026-10-01T00:00:00Z');
		$this->share(id: 'fs-1', sourceSecretId: 'src', status: FederatedShare::STATUS_REVOKED, pending: FederatedNotificationDelivery::SHARE_UNSHARED, next: $revokedAt);
		$this->reachable = false;

		$attempts = $this->retryUntil(until: (clone $revokedAt)->modify('+1 day'));

		$this->assertArrayHasKey('src', $this->secrets);
		$this->assertCount(FederatedNotificationDelivery::MAX_ATTEMPTS, $attempts);
		$this->assertSame(FederatedShare::STATUS_FAILED, $this->rows['fs-1']->getStatus());
		$this->assertNull($this->rows['fs-1']->getNextNotifyAt());
		$this->assertCount(1, $this->failures());
		$this->assertSame(
			['federatedShareId' => 'fs-1', 'recipientCloudId' => 'bob@cloud.partner.example', 'partnerId' => 'p-cloud.partner.example', 'notification' => FederatedNotificationDelivery::SHARE_UNSHARED],
			$this->failures()[0]->getMetadata()
		);
	}
}
