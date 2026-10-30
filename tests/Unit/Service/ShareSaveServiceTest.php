<?php

/**
 * Unit tests for ShareSaveService.
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
use OCA\Keepiq\Db\EncryptionSuiteMapper;
use OCA\Keepiq\Db\FederatedShare;
use OCA\Keepiq\Db\FederatedShareMapper;
use OCA\Keepiq\Db\GroupShare;
use OCA\Keepiq\Db\GroupShareMapper;
use OCA\Keepiq\Db\Secret;
use OCA\Keepiq\Db\SecretDelegationMapper;
use OCA\Keepiq\Db\SecretMapper;
use OCA\Keepiq\Db\ShareSaveStage;
use OCA\Keepiq\Db\ShareSaveStageMapper;
use OCA\Keepiq\Db\ShareTarget;
use OCA\Keepiq\Db\ShareTargetMapper;
use OCA\Keepiq\Exception\ForbiddenException;
use OCA\Keepiq\Exception\StaleWriteException;
use OCA\Keepiq\Service\FederatedNotificationDelivery;
use OCA\Keepiq\Service\FederatedShareService;
use OCA\Keepiq\Db\SecretVersion;
use OCA\Keepiq\Exception\NotFoundException;
use OCA\Keepiq\Exception\PartnerUpgradeRequiredException;
use OCA\Keepiq\Service\SecretService;
use OCA\Keepiq\Service\SecretVersionService;
use OCA\Keepiq\Service\ShareAuditTrail;
use OCA\Keepiq\Service\ShareSave\ShareSaveHistory;
use OCA\Keepiq\Service\ShareAuthorizationService;
use OCA\Keepiq\Service\ShareSave\IncompleteShareSaveException;
use OCA\Keepiq\Service\ShareSave\ShareSaveRefusal;
use OCA\Keepiq\Service\ShareSave\ShareSaveRequest;
use OCA\Keepiq\Service\ShareSave\ShareSaveScope;
use OCA\Keepiq\Service\ShareSave\ShareSaveStages;
use OCA\Keepiq\Service\ShareSaveService;
use OCA\Keepiq\Service\ShareSync\ShareSyncGuards;
use OCA\Keepiq\Service\ShareSync\ShareWriteContext;
use OCA\Keepiq\Service\ShareSyncService;
use OCP\AppFramework\Db\DoesNotExistException;
use OCP\IDBConnection;
use OCP\IGroupManager;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use RuntimeException;
use Throwable;

/**
 * Alice owns `src`, shared with Bob (`c-bob`) and Carol (`c-carol`) and with
 * Dave at a partner instance (`fs-1`).
 */
class ShareSaveServiceTest extends TestCase {
	private const BASE = '2026-10-01T10:00:00+00:00';
	private const BOB_BASE = '2026-10-01T09:00:00+00:00';

	private SecretService&MockObject $secrets;
	private SecretMapper&MockObject $secretMapper;
	private ShareTargetMapper&MockObject $targets;
	private FederatedShareMapper&MockObject $federatedMapper;
	private EncryptionSuiteMapper&MockObject $suites;
	private ShareAuthorizationService&MockObject $auth;
	private FederatedShareService&MockObject $federated;
	private FederatedNotificationDelivery&MockObject $delivery;
	private ShareAuditTrail&MockObject $audit;
	private SecretVersionService&MockObject $versionHistory;
	private ShareSaveStageMapper&MockObject $stageMapper;
	private IDBConnection&MockObject $db;

	/** @var array<string,Secret> */
	private array $rows = [];

	/** @var array<string,int> The stored version of each row, as a timestamp */
	private array $versions = [];

	/** @var array<int,ShareTarget> */
	private array $shareTargets = [];

	/** @var array<int,FederatedShare> */
	private array $partnerShares = [];

	private ?ShareTarget $lateTarget = null;

	/** The holder whose share row is gone when read again under the lock */
	private ?string $goneUnderLock = null;

	/** Whether the caller left the `admin` group before the lock */
	private bool $leftGroupUnderLock = false;

	/** Whether the share with the `admin` group was revoked before the lock */
	private bool $groupShareRevokedUnderLock = false;

	/** @var array<int,array{id:string,key:?string,updatedAt:?DateTime,totp:?string,passkeys:?string}> Rows written by the copy fan-out */
	private array $copyWrites = [];

	/** @var array<int,array{id:string,data:array<string,mixed>,savedAt:?DateTime}> */
	private array $rowSaves = [];

	/** @var array<string,ShareSaveStage> */
	private array $chunks = [];

	/** @var list<array<string,mixed>|null> The patch each partner write was given */
	private array $partnerPatches = [];

	/** @var list<array<string,?string>> The head's kept fields each partner write is held to */
	private array $partnerKept = [];

	/** @var array<int,string> What happened, in order */
	private array $log = [];

	protected function setUp(): void {
		$this->secrets = $this->createMock(SecretService::class);
		$this->secretMapper = $this->createMock(SecretMapper::class);
		$this->targets = $this->createMock(ShareTargetMapper::class);
		$this->federatedMapper = $this->createMock(FederatedShareMapper::class);
		$this->suites = $this->createMock(EncryptionSuiteMapper::class);
		$this->auth = $this->createMock(ShareAuthorizationService::class);
		$this->federated = $this->createMock(FederatedShareService::class);
		$this->delivery = $this->createMock(FederatedNotificationDelivery::class);
		$this->audit = $this->createMock(ShareAuditTrail::class);
		$this->versionHistory = $this->createPartialMock(
			SecretVersionService::class,
			['snapshot', 'getVersion', 'recordRestored', 'logRestored', 'compromiseFlagOpen']
		);
		$this->stageMapper = $this->createMock(ShareSaveStageMapper::class);
		$this->db = $this->createMock(IDBConnection::class);

		$this->addRow(id: 'src', owner: 'alice', updatedAt: self::BASE);
		$this->addRow(id: 'c-bob', owner: 'bob', updatedAt: self::BOB_BASE);
		$this->addRow(id: 'c-carol', owner: 'carol', updatedAt: self::BOB_BASE);
		$this->shareTargets = [$this->target(copyId: 'c-bob', userId: 'bob'), $this->target(copyId: 'c-carol', userId: 'carol')];
		$this->partnerShares = [$this->partnerShare(id: 'fs-1', status: FederatedShare::STATUS_ACTIVE)];

		$this->wireMappers();
		$this->wireWrites();
	}

	private function addRow(string $id, string $owner, string $updatedAt): Secret {
		$row = new Secret();
		$row->setId($id);
		$row->setName('Router');
		$row->setOwnerType('user');
		$row->setOwnerId($owner);
		$row->setKey('OLD-'.$id);
		$row->setUpdatedAt(new DateTime($updatedAt));
		$this->rows[$id] = $row;
		$this->versions[$id] = (new DateTime($updatedAt))->getTimestamp();

		return $row;
	}

	private function target(string $copyId, string $userId): ShareTarget {
		$target = new ShareTarget();
		$target->setId('st-'.$copyId);
		$target->setSourceSecretId('src');
		$target->setSecretId($copyId);
		$target->setTargetUserId($userId);

		return $target;
	}

	private function partnerShare(string $id, string $status): FederatedShare {
		$share = new FederatedShare();
		$share->setId($id);
		$share->setSourceSecretId('src');
		$share->setOwnerId('alice');
		$share->setRecipientCloudId('dave@partner.example');
		$share->setStatus($status);

		return $share;
	}

	private function wireMappers(): void {
		$this->secretMapper->method('findById')->willReturnCallback(
			fn (string $id): Secret => isset($this->rows[$id]) ? clone $this->rows[$id] : throw new DoesNotExistException('none')
		);
		$this->secretMapper->method('findByIds')->willReturnCallback(
			fn (array $ids): array => array_intersect_key($this->rows, array_flip($ids))
		);
		$this->secretMapper->method('advanceVersion')->willReturnCallback(
			function (string $id, DateTime $base, DateTime $savedAt): int {
				$this->log[] = 'lock '.$id;
				if (($this->versions[$id] ?? null) !== $base->getTimestamp()) {
					return 0;
				}

				$this->versions[$id] = $savedAt->getTimestamp();
				return 1;
			}
		);
		$this->targets->method('findBySourceSecret')->willReturnCallback(
			function (): array {
				// A share that appears between the pre-check and the lock.
				if ($this->lateTarget !== null && in_array('begin', $this->log, true) === true) {
					return [...$this->shareTargets, $this->lateTarget];
				}

				return $this->shareTargets;
			}
		);
		$this->targets->method('findByRecipientSecret')->willReturnCallback(
			function (string $copyId): ShareTarget {
				foreach ($this->shareTargets as $target) {
					if ($target->getSecretId() === $copyId) {
						return $target;
					}
				}

				throw new DoesNotExistException('not a copy');
			}
		);
		$this->targets->method('findBySourceSecretAndTargetUser')->willReturnCallback(
			function (string $sourceSecretId, string $targetUserId): ShareTarget {
				if ($targetUserId === $this->goneUnderLock && in_array('begin', $this->log, true) === true) {
					throw new DoesNotExistException('no copy');
				}

				foreach ($this->shareTargets as $target) {
					if ($target->getSourceSecretId() === $sourceSecretId && $target->getTargetUserId() === $targetUserId) {
						return $target;
					}
				}

				throw new DoesNotExistException('no copy');
			}
		);
		$this->federatedMapper->method('findBySourceSecret')->willReturnCallback(fn (): array => $this->partnerShares);
		$this->secrets->method('loadForUpdate')->willReturnCallback(fn (string $id): Secret => $this->rows[$id]);

		$this->stageMapper->method('insert')->willReturnCallback(
			function (ShareSaveStage $chunk): ShareSaveStage {
				$this->chunks[$chunk->getId()] = $chunk;
				return $chunk;
			}
		);
		$this->stageMapper->method('findLiveByStage')->willReturnCallback(
			fn (string $stageId): array => array_values(
				array_filter($this->chunks, static fn (ShareSaveStage $c): bool => $c->getStageId() === $stageId)
			)
		);
		$this->stageMapper->method('deleteByStage')->willReturnCallback(
			function (string $stageId): int {
				$this->log[] = 'drop stage';
				$before = count($this->chunks);
				$this->chunks = array_filter($this->chunks, static fn (ShareSaveStage $c): bool => $c->getStageId() !== $stageId);
				return $before - count($this->chunks);
			}
		);
	}

	private function wireWrites(): void {
		$this->secretMapper->method('update')->willReturnCallback(
			function (Secret $copy): Secret {
				$this->log[] = 'copy '.$copy->getId();
				$this->copyWrites[] = [
					'id' => $copy->getId(),
					'key' => $copy->getKey(),
					'keyUpdatedAt' => $copy->getKeyUpdatedAt(),
					'updatedAt' => $copy->getUpdatedAt(),
					'totp' => $copy->getTotp(),
					'passkeys' => $copy->getPasskeys(),
				];
				return $copy;
			}
		);
		$this->secrets->method('update')->willReturnCallback(
			function (string $id, array $data, string $userId, ?DateTime $savedAt = null): Secret {
				$this->log[] = 'row '.$id;
				$this->rowSaves[] = ['id' => $id, 'data' => $data, 'savedAt' => $savedAt];
				$row = clone $this->rows[$id];
				$row->setUpdatedAt($savedAt ?? new DateTime());
				return $row;
			}
		);
		$this->federated->method('writeCiphertext')->willReturnCallback(
			function (FederatedShare $row, string $certFingerprint, array $ciphertext, DateTime $savedAt): FederatedShare {
				$this->log[] = 'partner '.$row->getId();
				return $row;
			}
		);
		$this->db->method('beginTransaction')->willReturnCallback(function (): void {
			$this->log[] = 'begin';
		});
		$this->db->method('commit')->willReturnCallback(function (): void {
			$this->log[] = 'commit';
		});
		$this->db->method('rollBack')->willReturnCallback(function (): void {
			$this->log[] = 'rollback';
		});
	}

	private function service(?ShareAuthorizationService $auth = null): ShareSaveService {
		$guards = new ShareSyncGuards(
			targets: $this->targets,
			secrets: $this->secretMapper,
			suites: $this->suites,
			auth: ($auth ?? $this->auth),
		);
		$sync = new ShareSyncService(
			mapper: $this->targets,
			secretMapper: $this->secretMapper,
			db: $this->db,
			auth: ($auth ?? $this->auth),
			versionService: $this->versionHistory,
			guards: $guards,
			contexts: new ShareWriteContext(targets: $this->targets, suites: $this->suites, auth: ($auth ?? $this->auth)),
		);

		return new ShareSaveService(
			secrets: $this->secrets,
			secretMapper: $this->secretMapper,
			sync: $sync,
			federatedShares: $this->federated,
			delivery: $this->delivery,
			history: new ShareSaveHistory(versions: $this->versionHistory, auditTrail: $this->audit),
			scope: new ShareSaveScope(
				targets: $this->targets,
				secrets: $this->secretMapper,
				federatedShares: $this->federatedMapper,
				sync: $sync,
				guards: $guards,
			),
			stages: new ShareSaveStages(mapper: $this->stageMapper),
			db: $this->db,
		);
	}

	/**
	 * Alice's one-call save.
	 *
	 * @param array<string,mixed> $overrides Body fields to replace
	 *
	 * @return ShareSaveRequest
	 */
	private function ownerRequest(array $overrides = []): ShareSaveRequest {
		return new ShareSaveRequest(array_merge([
			'baseUpdatedAt' => self::BASE,
			'patch' => ['key' => 'K-ALICE', 'login' => null],
			'copies' => [
				['secretId' => 'c-bob', 'key' => 'K-BOB', 'login' => null],
				['secretId' => 'c-carol', 'key' => 'K-CAROL', 'login' => null],
			],
			'federated' => [[
				'shareId' => 'fs-1',
				'certFingerprint' => str_repeat('a', 64),
				'key' => 'K-DAVE',
				'login' => null,
				'additionalFields' => null,
			]],
		], $overrides));
	}

	/**
	 * Bob's save through his copy.
	 *
	 * @param array<string,mixed> $overrides Body fields to replace
	 *
	 * @return ShareSaveRequest
	 */
	private function bobRequest(array $overrides = []): ShareSaveRequest {
		return new ShareSaveRequest(array_merge([
			'baseUpdatedAt' => self::BOB_BASE,
			'sourceBaseUpdatedAt' => self::BASE,
			'patch' => ['key' => 'K-BOB'],
			'copies' => [
				['secretId' => 'src', 'key' => 'K-ALICE'],
				['secretId' => 'c-carol', 'key' => 'K-CAROL'],
			],
		], $overrides));
	}

	private function bobWritesOnAGrade(): void {
		$this->auth->method('isOwnerOrDelegate')->willReturn(false);
		$this->auth->method('resolveGrade')->willReturn('write');
	}

	/**
	 * The real authorization, with Bob's copy from Alice's share with the
	 * `admin` group, whose members are `$members`. Carol's copy is a direct share.
	 *
	 * @param list<string> $members The members of `admin`
	 *
	 * @return ShareAuthorizationService
	 */
	private function groupAuth(array $members): ShareAuthorizationService {
		$this->shareTargets[0]->setGroupShareId('gs-admin');
		$groupShare = new GroupShare();
		$groupShare->setId('gs-admin');
		$groupShare->setSecretId('src');
		$groupShare->setGroupId('admin');
		$groupShares = $this->createMock(GroupShareMapper::class);
		$groupShares->method('findBySecret')->willReturnCallback(
			fn (string $secretId): array => ($secretId === 'src' && $this->underLock($this->groupShareRevokedUnderLock) === false)
				? [$groupShare]
				: []
		);
		$groups = $this->createMock(IGroupManager::class);
		$groups->method('isInGroup')->willReturnCallback(
			fn (string $userId, string $groupId): bool => $groupId === 'admin' && in_array($userId, $members, true)
				&& $this->underLock($this->leftGroupUnderLock) === false
		);
		$delegations = $this->createMock(SecretDelegationMapper::class);
		$delegations->method('findActiveBySecretAndUser')->willThrowException(new DoesNotExistException('none'));

		return new ShareAuthorizationService(
			secretMapper: $this->secretMapper,
			delegationMapper: $delegations,
			shareTargetMapper: $this->targets,
			groupShareMapper: $groupShares,
			groupManager: $groups,
		);
	}

	/**
	 * Whether a change set to happen before the lock has happened: the save's
	 * transaction began.
	 *
	 * @param bool $happens Whether the change happens at all
	 *
	 * @return bool
	 */
	private function underLock(bool $happens): bool {
		return $happens === true && in_array('begin', $this->log, true) === true;
	}

	/**
	 * Run a save that must be refused, and return the refusal.
	 *
	 * @param callable():mixed $save The save
	 *
	 * @return Throwable
	 */
	private function refusal(callable $save): Throwable {
		try {
			$save();
		} catch (Throwable $e) {
			return $e;
		}

		$this->fail('The save was not refused');
	}

	public function testTheOwnerSavesTheRowEveryCopyAndThePartnerInOneTransaction(): void {
		$this->delivery->method('deliver')->willReturn(true);

		$result = $this->service()->save(id: 'src', request: $this->ownerRequest(), userId: 'alice');

		$this->assertSame(
			['begin', 'lock src', 'copy c-bob', 'copy c-carol', 'partner fs-1', 'row src', 'commit'],
			$this->log
		);
		$this->assertSame(['id' => 'src', 'data' => ['key' => 'K-ALICE', 'login' => null]], array_slice($this->rowSaves[0], 0, 2));
		$savedAt = $this->rowSaves[0]['savedAt'];
		$this->assertInstanceOf(DateTime::class, $savedAt);
		foreach ($this->copyWrites as $write) {
			$this->assertSame($savedAt->getTimestamp(), $write['updatedAt']?->getTimestamp());
		}

		$this->assertSame(['K-BOB', 'K-CAROL'], array_column($this->copyWrites, 'key'));
		$this->assertSame($savedAt->format(DATE_ATOM), $result['sourceUpdatedAt']);
		$this->assertSame(['written' => 2, 'skipped' => 0], $result['copies']);
		$this->assertSame(
			['written' => 1, 'suspended' => 0, 'notified' => 1, 'queued' => 0, 'ownerOnly' => false],
			$result['federated']
		);
		$this->assertSame('src', $result['secret']['id']);
	}

	public function testPartnersAreToldOnlyAfterTheCommit(): void {
		$this->delivery->expects($this->once())->method('deliver')->willReturnCallback(
			function (FederatedShare $row, string $type): bool {
				$this->assertSame(FederatedNotificationDelivery::SHARE_UPDATED, $type);
				$this->assertSame('commit', end($this->log));
				return true;
			}
		);

		$this->service()->save(id: 'src', request: $this->ownerRequest(), userId: 'alice');
	}

	public function testAFailedNotificationLeavesTheShareQueued(): void {
		$this->delivery->method('deliver')->willReturnCallback(
			static fn (FederatedShare $row): bool => ($row->getId() === 'fs-1') ? false : throw new RuntimeException('down')
		);
		$this->partnerShares[] = $this->partnerShare(id: 'fs-2', status: FederatedShare::STATUS_ACTIVE);
		$federated = [];
		foreach (['fs-1', 'fs-2'] as $shareId) {
			$federated[] = ['shareId' => $shareId, 'certFingerprint' => str_repeat('b', 64), 'key' => 'K', 'login' => null, 'additionalFields' => null];
		}

		$result = $this->service()->save(id: 'src', request: $this->ownerRequest(['federated' => $federated]), userId: 'alice');

		$this->assertSame(['written' => 2, 'suspended' => 0, 'notified' => 0, 'queued' => 2, 'ownerOnly' => false], $result['federated']);
		$this->assertContains('commit', $this->log);
	}

	public function testAPolicyRefusalOfTheRowRollsBackTheCopiesAndTellsNoPartner(): void {
		$this->secrets = $this->createMock(SecretService::class);
		$this->secrets->method('loadForUpdate')->willReturnCallback(fn (string $id): Secret => $this->rows[$id]);
		$this->secrets->method('update')->willThrowException(new ForbiddenException('policy'));
		$this->delivery->expects($this->never())->method('deliver');

		$this->assertInstanceOf(
			ForbiddenException::class,
			$this->refusal(fn () => $this->service()->save(id: 'src', request: $this->ownerRequest(), userId: 'alice'))
		);
		$this->assertSame(['begin', 'lock src', 'copy c-bob', 'copy c-carol', 'partner fs-1', 'rollback'], $this->log);
	}

	public function testAStaleRowIsRefusedBeforeAnythingIsWritten(): void {
		$this->rows['src']->setUpdatedAt(new DateTime('2026-10-01T10:00:05+00:00'));

		$refusal = $this->refusal(fn () => $this->service()->save(id: 'src', request: $this->ownerRequest(), userId: 'alice'));

		$this->assertInstanceOf(StaleWriteException::class, $refusal);
		$this->assertSame('src', $refusal->getCurrent()->getId());
		$this->assertNull($refusal->getSourceUpdatedAt(), 'the owner path has no separate source version');
		$this->assertSame([], $this->log);
	}

	public function testARowThatMovesOnBeforeTheLockRollsBack(): void {
		$this->versions['src']++;

		$refusal = $this->refusal(fn () => $this->service()->save(id: 'src', request: $this->ownerRequest(), userId: 'alice'));

		$this->assertInstanceOf(StaleWriteException::class, $refusal);
		$this->assertNull($refusal->getSourceUpdatedAt());
		$this->assertSame(['begin', 'lock src', 'rollback'], $this->log);
	}

	/**
	 * Only the copy moved; the source is still at the edit's base, which the
	 * refusal names.
	 *
	 * @spec openspec/changes/sharing-atomic-share-save/specs/user-sharing/spec.md#requirement-a-shared-secret-and-every-copy-save-in-one-transaction
	 */
	public function testAStaleCopyWhoseSourceStayedNamesTheSourceBase(): void {
		$this->bobWritesOnAGrade();
		$this->rows['c-bob']->setUpdatedAt(new DateTime('2026-10-01T10:00:07+00:00'));

		$refusal = $this->refusal(fn () => $this->service()->save(id: 'c-bob', request: $this->bobRequest(), userId: 'bob'));

		$this->assertInstanceOf(StaleWriteException::class, $refusal);
		$this->assertSame('c-bob', $refusal->getCurrent()->getId());
		$this->assertSame(self::BASE, $refusal->getSourceUpdatedAt()?->format(DATE_ATOM));
		$this->assertSame([], $this->log);
	}

	/**
	 * The source moved alone past the edit's base, so the conflict never
	 * showed that change: the refusal names the edit's own source base.
	 *
	 * @spec openspec/changes/sharing-atomic-share-save/specs/user-sharing/spec.md#requirement-a-shared-secret-and-every-copy-save-in-one-transaction
	 */
	public function testAStaleCopyDoesNotNameASourceVersionThatMovedAlone(): void {
		$this->bobWritesOnAGrade();
		$this->rows['c-bob']->setUpdatedAt(new DateTime('2026-10-01T10:00:07+00:00'));
		$this->rows['src']->setUpdatedAt(new DateTime('2026-10-01T10:00:09+00:00'));

		$refusal = $this->refusal(fn () => $this->service()->save(id: 'c-bob', request: $this->bobRequest(), userId: 'bob'));

		$this->assertInstanceOf(StaleWriteException::class, $refusal);
		$this->assertSame('c-bob', $refusal->getCurrent()->getId());
		$this->assertSame(self::BASE, $refusal->getSourceUpdatedAt()?->format(DATE_ATOM));
		$this->assertSame([], $this->log);
	}

	/**
	 * A save stamps the source and the copy with one version, so the copy
	 * shown in the conflict carries the source's current version.
	 *
	 * @spec openspec/changes/sharing-atomic-share-save/specs/user-sharing/spec.md#requirement-a-shared-secret-and-every-copy-save-in-one-transaction
	 */
	public function testAStaleCopyNamesTheSourceVersionItWasWrittenWith(): void {
		$this->bobWritesOnAGrade();
		$this->rows['c-bob']->setUpdatedAt(new DateTime('2026-10-01T10:00:07+00:00'));
		$this->rows['src']->setUpdatedAt(new DateTime('2026-10-01T10:00:07+00:00'));

		$refusal = $this->refusal(fn () => $this->service()->save(id: 'c-bob', request: $this->bobRequest(), userId: 'bob'));

		$this->assertInstanceOf(StaleWriteException::class, $refusal);
		$this->assertSame('2026-10-01T10:00:07+00:00', $refusal->getSourceUpdatedAt()?->format(DATE_ATOM));
		$this->assertSame([], $this->log);
	}

	/**
	 * The source was locked and moved before the copy refused, but that move
	 * rolls back, so the version named is the one the source keeps.
	 *
	 * @spec openspec/changes/sharing-atomic-share-save/specs/user-sharing/spec.md#requirement-a-shared-secret-and-every-copy-save-in-one-transaction
	 */
	public function testACopyThatMovesOnBeforeTheLockNamesTheSourceVersionItKeeps(): void {
		$this->bobWritesOnAGrade();
		$this->versions['c-bob']++;

		$refusal = $this->refusal(fn () => $this->service()->save(id: 'c-bob', request: $this->bobRequest(), userId: 'bob'));

		$this->assertInstanceOf(StaleWriteException::class, $refusal);
		$this->assertSame(self::BASE, $refusal->getSourceUpdatedAt()?->format(DATE_ATOM));
		$this->assertSame(['begin', 'lock src', 'lock c-bob', 'rollback'], $this->log);
	}

	public function testTheVersionIsAfterTheBaseEvenWithinTheSameSecond(): void {
		$base = (new DateTime('+1 hour'))->format(DATE_ATOM);
		$this->rows['src']->setUpdatedAt(new DateTime($base));
		$this->versions['src'] = (new DateTime($base))->getTimestamp();

		$this->service()->save(id: 'src', request: $this->ownerRequest(['baseUpdatedAt' => $base]), userId: 'alice');

		$this->assertSame((new DateTime($base))->getTimestamp() + 1, $this->rowSaves[0]['savedAt']?->getTimestamp());
	}

	public function testAMissingCopyIsAnsweredWithTheCurrentLists(): void {
		$request = $this->ownerRequest(['copies' => [['secretId' => 'c-bob', 'key' => 'K-BOB', 'login' => null]]]);

		$refusal = $this->refusal(fn () => $this->service()->save(id: 'src', request: $request, userId: 'alice'));

		$this->assertInstanceOf(IncompleteShareSaveException::class, $refusal);
		$body = $refusal->getBody();
		$this->assertSame('incomplete', $body['error']);
		$this->assertSame('owner', $body['path']);
		$this->assertSame(self::BASE, $body['sourceUpdatedAt']);
		$this->assertSame(['c-carol'], $body['copies']['missing']);
		$this->assertSame(
			[
				['secretId' => 'c-bob', 'holder' => 'recipient', 'targetUserId' => 'bob'],
				['secretId' => 'c-carol', 'holder' => 'recipient', 'targetUserId' => 'carol'],
			],
			$body['copies']['required']
		);
		$this->assertSame([['shareId' => 'fs-1', 'recipientCloudId' => 'dave@partner.example']], $body['federated']['required']);
		$this->assertSame([], $this->log);
	}

	public function testAnUnexpectedCopyOrPartnerIsRefused(): void {
		$request = $this->ownerRequest([
			'copies' => [
				['secretId' => 'c-bob', 'key' => 'K-BOB', 'login' => null],
				['secretId' => 'c-carol', 'key' => 'K-CAROL', 'login' => null],
				['secretId' => 'src', 'key' => 'K-ALICE', 'login' => null],
			],
			'suspend' => [['shareId' => 'fs-9', 'reason' => 'unverified']],
		]);

		$body = $this->refusal(fn () => $this->service()->save(id: 'src', request: $request, userId: 'alice'))->getBody();

		$this->assertSame(['src'], $body['copies']['unexpected']);
		$this->assertSame(['fs-9'], $body['federated']['unexpected']);
	}

	public function testAMissingPartnerShareIsRefused(): void {
		$body = $this->refusal(
			fn () => $this->service()->save(id: 'src', request: $this->ownerRequest(['federated' => []]), userId: 'alice')
		)->getBody();

		$this->assertSame(['fs-1'], $body['federated']['missing']);
	}

	public function testOnlyActivePartnerSharesOfTheCallerAreRequired(): void {
		$this->partnerShares[] = $this->partnerShare(id: 'fs-suspended', status: FederatedShare::STATUS_SUSPENDED);
		$this->delivery->method('deliver')->willReturn(true);

		$result = $this->service()->save(id: 'src', request: $this->ownerRequest(), userId: 'alice');

		$this->assertSame(1, $result['federated']['written']);
	}

	public function testACopyWhoseAccessEndedIsNotRequiredAndNotWritten(): void {
		$this->rows['c-carol']->setAccessExpiresAt(new DateTime('-1 minute'));

		$withoutCarol = $this->ownerRequest(['copies' => [['secretId' => 'c-bob', 'key' => 'K-BOB', 'login' => null]]]);
		$this->service()->save(id: 'src', request: $withoutCarol, userId: 'alice');
		$this->assertSame(['c-bob'], array_column($this->copyWrites, 'id'));

		$this->copyWrites = [];
		$this->versions['src'] = (new DateTime(self::BASE))->getTimestamp();
		$this->service()->save(id: 'src', request: $this->ownerRequest(), userId: 'alice');
		$this->assertSame(['c-bob'], array_column($this->copyWrites, 'id'));
	}

	public function testASkippedCopyIsAcceptedOnlyWhenItsHolderHasNoVaultKey(): void {
		$this->suites->method('countActiveByOwner')->willReturnCallback(
			static fn (string $ownerType, string $ownerId): int => ($ownerId === 'carol') ? 0 : 1
		);
		$this->delivery->method('deliver')->willReturn(true);

		$result = $this->service()->save(
			id: 'src',
			request: $this->ownerRequest([
				'copies' => [['secretId' => 'c-bob', 'key' => 'K-BOB', 'login' => null]],
				'skipped' => [['secretId' => 'c-carol', 'reason' => 'no_vault_key']],
			]),
			userId: 'alice'
		);
		$this->assertSame(['written' => 1, 'skipped' => 1], $result['copies']);

		$body = $this->refusal(fn () => $this->service()->save(
			id: 'src',
			request: $this->ownerRequest([
				'copies' => [['secretId' => 'c-carol', 'key' => 'K-CAROL', 'login' => null]],
				'skipped' => [['secretId' => 'c-bob', 'reason' => 'no_vault_key']],
			]),
			userId: 'alice'
		))->getBody();
		$this->assertSame(['c-bob'], $body['copies']['skipRefused']);
	}

	public function testTheOwnerSuspendsAPartnerShareTheBrowserCouldNotVerify(): void {
		$this->federated->expects($this->once())->method('suspend')->with('fs-1', 'alice', 'unverified');
		$this->delivery->expects($this->never())->method('deliver');

		$result = $this->service()->save(
			id: 'src',
			request: $this->ownerRequest(['federated' => [], 'suspend' => [['shareId' => 'fs-1', 'reason' => 'unverified']]]),
			userId: 'alice'
		);

		$this->assertSame(['written' => 0, 'suspended' => 1, 'notified' => 0, 'queued' => 0, 'ownerOnly' => false], $result['federated']);
	}

	public function testAShareAddedWhileWaitingForTheLockMakesTheSaveIncomplete(): void {
		$this->addRow(id: 'c-erin', owner: 'erin', updatedAt: self::BOB_BASE);
		$this->lateTarget = $this->target(copyId: 'c-erin', userId: 'erin');

		$refusal = $this->refusal(fn () => $this->service()->save(id: 'src', request: $this->ownerRequest(), userId: 'alice'));

		$this->assertInstanceOf(IncompleteShareSaveException::class, $refusal);
		$this->assertSame(['c-erin'], $refusal->getBody()['copies']['missing']);
		$this->assertSame(['begin', 'lock src', 'rollback'], $this->log);
	}

	public function testACopyEditorWritesTheSourceAndTheOtherCopiesAndIsAudited(): void {
		$this->bobWritesOnAGrade();
		$this->audit->expects($this->once())->method('recordTeamWriteSync')->with('bob', 'src', 'Router');
		$this->federated->expects($this->never())->method('writeCiphertext');

		$result = $this->service()->save(id: 'c-bob', request: $this->bobRequest(), userId: 'bob');

		$this->assertSame(['begin', 'lock src', 'lock c-bob', 'copy src', 'copy c-carol', 'row c-bob', 'commit'], $this->log);
		$this->assertSame('c-bob', $this->rowSaves[0]['id']);
		$this->assertSame(['written' => 2, 'skipped' => 0], $result['copies']);
		$this->assertTrue($result['federated']['ownerOnly']);
		$this->assertSame(0, $result['federated']['written']);
	}

	public function testACopyEditorMustNameTheSourceAndNotTheirOwnCopy(): void {
		$this->bobWritesOnAGrade();
		$request = $this->bobRequest(['copies' => [
			['secretId' => 'c-bob', 'key' => 'K-BOB'],
			['secretId' => 'c-carol', 'key' => 'K-CAROL'],
		]]);

		$body = $this->refusal(fn () => $this->service()->save(id: 'c-bob', request: $request, userId: 'bob'))->getBody();

		$this->assertSame('copy', $body['path']);
		$this->assertSame(['src'], $body['copies']['missing']);
		$this->assertSame(['c-bob'], $body['copies']['unexpected']);
		$this->assertSame(['secretId' => 'src', 'holder' => 'owner', 'targetUserId' => null], $body['copies']['required'][0]);
		$this->assertSame([], $body['federated']['required']);
	}

	public function testACopyEditorNeverWritesPartnerShares(): void {
		$this->bobWritesOnAGrade();
		$request = $this->bobRequest(['suspend' => [['shareId' => 'fs-1', 'reason' => 'unverified']]]);

		$refusal = $this->refusal(fn () => $this->service()->save(id: 'c-bob', request: $request, userId: 'bob'));

		$this->assertSame('federated_owner_only', $refusal->getError());
		$this->assertSame(400, $refusal->getStatus());
	}

	public function testACopyEditorNamesTheSourceVersion(): void {
		$this->bobWritesOnAGrade();

		$refusal = $this->refusal(
			fn () => $this->service()->save(id: 'c-bob', request: $this->bobRequest(['sourceBaseUpdatedAt' => null]), userId: 'bob')
		);

		$this->assertSame('source_base_required', $refusal->getError());
	}

	public function testACopyHolderWithoutAWriteGradeIsSentToTheirOwnRow(): void {
		$this->auth->method('isOwnerOrDelegate')->willReturn(false);
		$this->auth->method('resolveGrade')->willReturn('read');

		$refusal = $this->refusal(fn () => $this->service()->save(id: 'c-bob', request: $this->bobRequest(), userId: 'bob'));

		$this->assertSame('not_allowed', $refusal->getError());
		$this->assertSame(403, $refusal->getStatus());
	}

	public function testAGradeLoweredBeforeTheLockRefusesTheSaveAndRollsBack(): void {
		$this->auth->method('isOwnerOrDelegate')->willReturn(false);
		$this->auth->method('resolveGrade')->willReturnOnConsecutiveCalls('write', 'read');
		$this->audit->expects($this->never())->method('recordTeamWriteSync');

		$refusal = $this->refusal(fn () => $this->service()->save(id: 'c-bob', request: $this->bobRequest(), userId: 'bob'));

		$this->assertSame(['not_allowed', 403], [$refusal->getError(), $refusal->getStatus()]);
		$this->assertSame(['begin', 'lock src', 'lock c-bob', 'rollback'], $this->log);
	}

	public function testASourceMovedOutOfItsTeamFolderBeforeTheLockRefusesTheSave(): void {
		$this->rows['src']->setFolderId('team');
		$this->auth->method('isOwnerOrDelegate')->willReturn(false);
		$this->auth->method('resolveGrade')->willReturnCallback(
			function (Secret $secret): string {
				$grade = ($secret->getFolderId() === 'team') ? 'write' : 'read';
				// The source leaves the team folder right after resolve() read it.
				$this->rows['src']->setFolderId('private');
				return $grade;
			}
		);

		$refusal = $this->refusal(fn () => $this->service()->save(id: 'c-bob', request: $this->bobRequest(), userId: 'bob'));

		$this->assertSame(['not_allowed', 403], [$refusal->getError(), $refusal->getStatus()]);
		$this->assertSame(['begin', 'lock src', 'lock c-bob', 'rollback'], $this->log);
	}

	public function testADelegationRevokedBeforeTheLockSavesAsTheWriteGradeMember(): void {
		// A delegate at resolve(), a write-grade member under the lock: Bob
		// still writes, and is audited as he writes now.
		$this->auth->method('isOwnerOrDelegate')->willReturnOnConsecutiveCalls(true, false);
		$this->auth->method('resolveGrade')->willReturn('write');
		$this->audit->expects($this->once())->method('recordTeamWriteSync')->with('bob', 'src', 'Router', 'team-write sync');

		$this->service()->save(id: 'c-bob', request: $this->bobRequest(), userId: 'bob');

		$this->assertSame(['begin', 'lock src', 'lock c-bob', 'copy src', 'copy c-carol', 'row c-bob', 'commit'], $this->log);
	}

	public function testADelegateWritingThroughTheirCopyIsAuditedOnTheSource(): void {
		$this->auth->method('isOwnerOrDelegate')->willReturnCallback(
			static fn (Secret $secret, string $userId): bool => $userId === 'bob'
		);
		$this->audit->expects($this->once())->method('recordTeamWriteSync')->with('bob', 'src', 'Router', 'delegate sync');

		$this->service()->save(id: 'c-bob', request: $this->bobRequest(), userId: 'bob');

		$this->assertSame('commit', end($this->log));
	}

	public function testACopyEditorsAuditNamesTheHoldersLeftOut(): void {
		$this->bobWritesOnAGrade();
		$this->suites->method('countActiveByOwner')->willReturnCallback(
			static fn (string $ownerType, string $ownerId): int => ($ownerId === 'carol') ? 0 : 1
		);
		$this->audit->expects($this->once())->method('recordTeamWriteSync')
			->with('bob', 'src', 'Router', 'team-write sync', ['carol']);

		$result = $this->service()->save(
			id: 'c-bob',
			request: $this->bobRequest([
				'copies' => [['secretId' => 'src', 'key' => 'K-ALICE']],
				'skipped' => [['secretId' => 'c-carol', 'reason' => 'no_vault_key']],
			]),
			userId: 'bob'
		);

		$this->assertSame(['written' => 1, 'skipped' => 1], $result['copies']);
	}

	public function testACopyEditorsAuditNamesTheOwnerWhenTheSourceIsLeftOut(): void {
		$this->bobWritesOnAGrade();
		$this->suites->method('countActiveByOwner')->willReturnCallback(
			static fn (string $ownerType, string $ownerId): int => ($ownerId === 'alice') ? 0 : 1
		);
		$this->audit->expects($this->once())->method('recordTeamWriteSync')
			->with('bob', 'src', 'Router', 'team-write sync', ['alice']);

		$result = $this->service()->save(
			id: 'c-bob',
			request: $this->bobRequest([
				'copies' => [['secretId' => 'c-carol', 'key' => 'K-CAROL']],
				'skipped' => [['secretId' => 'src', 'reason' => 'no_vault_key']],
			]),
			userId: 'bob'
		);

		$this->assertSame(['written' => 1, 'skipped' => 1], $result['copies']);
	}

	/**
	 * Log the snapshots the copy fan-out takes, as `snapshot <id> <key> <actor>`.
	 *
	 * @return void
	 */
	private function logSnapshots(): void {
		$this->versionHistory->method('snapshot')->willReturnCallback(
			function (Secret $preUpdate, string $actorType, string $actorId): SecretVersion {
				$this->log[] = 'snapshot '.$preUpdate->getId().' '.$preUpdate->getKey().' '.$actorType.':'.$actorId;
				return new SecretVersion();
			}
		);
	}

	public function testAGroupMemberWritesTheSourceAndTheOtherCopiesAndIsAuditedAsAGroupWrite(): void {
		$auth = $this->groupAuth(members: ['bob']);
		$this->logSnapshots();
		$this->audit->expects($this->once())->method('recordTeamWriteSync')->with('bob', 'src', 'Router', 'group-write sync');
		$this->federated->expects($this->never())->method('writeCiphertext');

		$result = $this->service($auth)->save(id: 'c-bob', request: $this->bobRequest(), userId: 'bob');

		// Alice's value before Bob's save is kept as a version of her source, Bob as its actor.
		$this->assertSame(
			['begin', 'lock src', 'lock c-bob', 'snapshot src OLD-src user:bob', 'copy src', 'copy c-carol', 'row c-bob', 'commit'],
			$this->log
		);
		$this->assertSame(['written' => 2, 'skipped' => 0], $result['copies']);
		$this->assertTrue($result['federated']['ownerOnly']);
	}

	public function testAWriteGradeMembersSaveKeepsTheSourcesPreviousValueAsAVersion(): void {
		$this->bobWritesOnAGrade();
		$this->logSnapshots();

		$this->service()->save(id: 'c-bob', request: $this->bobRequest(), userId: 'bob');

		$this->assertSame(
			['begin', 'lock src', 'lock c-bob', 'snapshot src OLD-src user:bob', 'copy src', 'copy c-carol', 'row c-bob', 'commit'],
			$this->log
		);
	}

	public function testTheOwnersSaveTakesNoSnapshotInTheCopyFanOut(): void {
		// The owner's row is snapshotted by SecretService::update(); the copies keep no version.
		$this->delivery->method('deliver')->willReturn(true);
		$this->versionHistory->expects($this->never())->method('snapshot');

		$this->service()->save(id: 'src', request: $this->ownerRequest(), userId: 'alice');
	}

	public function testAMemberWhoLeftTheGroupIsRefusedThoughTheirCopyRemains(): void {
		// The leave cleanup failed: Bob's copy and its share row are still there.
		$auth = $this->groupAuth(members: []);
		$this->audit->expects($this->never())->method('recordTeamWriteSync');

		$refusal = $this->refusal(fn () => $this->service($auth)->save(id: 'c-bob', request: $this->bobRequest(), userId: 'bob'));

		$this->assertSame(['not_allowed', 403], [$refusal->getError(), $refusal->getStatus()]);
		$this->assertSame([], $this->log);
	}

	public function testAShareRowRemovedBeforeTheLockRefusesTheSave(): void {
		// Bob's leave cleanup removes his share row after resolve() read it.
		$auth = $this->groupAuth(members: ['bob']);
		$this->goneUnderLock = 'bob';
		$this->audit->expects($this->never())->method('recordTeamWriteSync');

		$refusal = $this->refusal(fn () => $this->service($auth)->save(id: 'c-bob', request: $this->bobRequest(), userId: 'bob'));

		$this->assertSame(['not_allowed', 403], [$refusal->getError(), $refusal->getStatus()]);
		$this->assertSame(['begin', 'lock src', 'lock c-bob', 'rollback'], $this->log);
	}

	public function testAMemberWhoLeftTheGroupBeforeTheLockIsRefused(): void {
		// Bob was in `admin` at resolve(); his leave commits before the lock.
		$auth = $this->groupAuth(members: ['bob']);
		$this->leftGroupUnderLock = true;
		$this->audit->expects($this->never())->method('recordTeamWriteSync');

		$refusal = $this->refusal(fn () => $this->service($auth)->save(id: 'c-bob', request: $this->bobRequest(), userId: 'bob'));

		$this->assertSame(['not_allowed', 403], [$refusal->getError(), $refusal->getStatus()]);
		$this->assertSame(['begin', 'lock src', 'lock c-bob', 'rollback'], $this->log);
	}

	public function testAGroupShareRevokedBeforeTheLockRefusesTheSave(): void {
		// Alice revokes the `admin` share after resolve() read it; Bob's copy is still there.
		$auth = $this->groupAuth(members: ['bob']);
		$this->groupShareRevokedUnderLock = true;
		$this->audit->expects($this->never())->method('recordTeamWriteSync');

		$refusal = $this->refusal(fn () => $this->service($auth)->save(id: 'c-bob', request: $this->bobRequest(), userId: 'bob'));

		$this->assertSame(['not_allowed', 403], [$refusal->getError(), $refusal->getStatus()]);
		$this->assertSame(['begin', 'lock src', 'lock c-bob', 'rollback'], $this->log);
	}

	public function testADirectRecipientIsRefused(): void {
		// Carol's copy is a direct share, and she is in no group it is shared with.
		$auth = $this->groupAuth(members: ['bob']);
		$request = $this->bobRequest(['copies' => [
			['secretId' => 'src', 'key' => 'K-ALICE'],
			['secretId' => 'c-bob', 'key' => 'K-BOB'],
		]]);

		$refusal = $this->refusal(fn () => $this->service($auth)->save(id: 'c-carol', request: $request, userId: 'carol'));

		$this->assertSame(['not_allowed', 403], [$refusal->getError(), $refusal->getStatus()]);
		$this->assertSame([], $this->log);
	}

	public function testASourceMadeUseOnlyBeforeTheLockRefusesTheSave(): void {
		$this->auth->method('isOwnerOrDelegate')->willReturnCallback(
			function (): bool {
				// The source becomes a use-only copy right after resolve() read it.
				$this->rows['src']->setUseOnly(true);
				return false;
			}
		);
		$this->auth->method('resolveGrade')->willReturn('write');

		$refusal = $this->refusal(fn () => $this->service()->save(id: 'c-bob', request: $this->bobRequest(), userId: 'bob'));

		$this->assertInstanceOf(ForbiddenException::class, $refusal);
		$this->assertSame(['begin', 'lock src', 'lock c-bob', 'rollback'], $this->log);
	}

	public function testAStaleSourceIsRefusedWithItsVersion(): void {
		$this->bobWritesOnAGrade();
		$this->rows['src']->setUpdatedAt(new DateTime('2026-10-01T10:00:09+00:00'));

		$refusal = $this->refusal(fn () => $this->service()->save(id: 'c-bob', request: $this->bobRequest(), userId: 'bob'));

		$this->assertSame(
			['error' => 'stale_source', 'message' => $refusal->getMessage(), 'sourceUpdatedAt' => '2026-10-01T10:00:09+00:00'],
			$refusal->getBody()
		);
		$this->assertSame([], $this->log);
	}

	public function testASourceThatMovesOnBeforeTheLockRollsBack(): void {
		$this->bobWritesOnAGrade();
		$this->versions['src']++;

		$refusal = $this->refusal(fn () => $this->service()->save(id: 'c-bob', request: $this->bobRequest(), userId: 'bob'));

		$this->assertSame('stale_source', $refusal->getError());
		$this->assertSame(['begin', 'lock src', 'rollback'], $this->log);
	}

	public function testALargeSaveIsStagedAndCommittedWithTheFinalCall(): void {
		$this->delivery->method('deliver')->willReturn(true);
		$service = $this->service();

		$first = $service->stage(
			id: 'src',
			request: new ShareSaveRequest([
				'baseUpdatedAt' => self::BASE,
				'stage' => ['final' => false],
				'copies' => [['secretId' => 'c-bob', 'key' => 'K-BOB', 'login' => null]],
			]),
			userId: 'alice'
		);
		$this->assertSame(['copies' => 1, 'federated' => 0], $first['staged']);
		$this->assertSame([], $this->log);

		$result = $service->save(
			id: 'src',
			request: $this->ownerRequest([
				'stage' => ['id' => $first['stageId'], 'final' => true],
				'copies' => [['secretId' => 'c-carol', 'key' => 'K-CAROL', 'login' => null]],
			]),
			userId: 'alice'
		);

		$this->assertSame(['written' => 2, 'skipped' => 0], $result['copies']);
		$this->assertSame(['begin', 'lock src', 'copy c-bob', 'copy c-carol', 'partner fs-1', 'row src', 'drop stage', 'commit'], $this->log);
	}

	public function testAStagedCopyMustCarryThePatchFields(): void {
		$service = $this->service();
		$first = $service->stage(
			id: 'src',
			request: new ShareSaveRequest([
				'baseUpdatedAt' => self::BASE,
				'stage' => ['final' => false],
				'copies' => [['secretId' => 'c-bob', 'key' => 'K-BOB']],
			]),
			userId: 'alice'
		);

		$refusal = $this->refusal(fn () => $service->save(
			id: 'src',
			request: $this->ownerRequest([
				'stage' => ['id' => $first['stageId'], 'final' => true],
				'copies' => [['secretId' => 'c-carol', 'key' => 'K-CAROL', 'login' => null]],
			]),
			userId: 'alice'
		));

		$this->assertSame('copy_fields_mismatch', $refusal->getError());
	}

	public function testAnIncompleteFinalCallKeepsTheStageAndAStaleOneDropsIt(): void {
		$service = $this->service();
		$chunk = new ShareSaveRequest([
			'baseUpdatedAt' => self::BASE,
			'stage' => ['final' => false],
			'copies' => [['secretId' => 'c-bob', 'key' => 'K-BOB', 'login' => null]],
		]);
		$stageId = $service->stage(id: 'src', request: $chunk, userId: 'alice')['stageId'];

		$final = $this->ownerRequest(['stage' => ['id' => $stageId, 'final' => true], 'copies' => []]);
		$this->assertSame('incomplete', $this->refusal(fn () => $service->save(id: 'src', request: $final, userId: 'alice'))->getError());
		$this->assertCount(1, $this->chunks);

		$this->rows['src']->setUpdatedAt(new DateTime('2026-10-01T10:00:07+00:00'));
		$this->assertInstanceOf(StaleWriteException::class, $this->refusal(fn () => $service->save(id: 'src', request: $final, userId: 'alice')));
		$this->assertSame([], $this->chunks);
	}

	public function testAStageIsBoundToItsUserRowAndVersion(): void {
		$service = $this->service();
		$stageId = $service->stage(
			id: 'src',
			request: new ShareSaveRequest([
				'baseUpdatedAt' => self::BASE,
				'stage' => ['final' => false],
				'copies' => [['secretId' => 'c-bob', 'key' => 'K-BOB']],
			]),
			userId: 'alice'
		)['stageId'];

		$this->bobWritesOnAGrade();
		$refusal = $this->refusal(fn () => $service->save(
			id: 'c-bob',
			request: $this->bobRequest(['stage' => ['id' => $stageId, 'final' => true]]),
			userId: 'bob'
		));
		$this->assertSame(['stage_mismatch', 409], [$refusal->getError(), $refusal->getStatus()]);

		$unknown = $this->ownerRequest(['stage' => ['id' => '00000000-0000-4000-8000-000000000000', 'final' => true]]);
		$refusal = $this->refusal(fn () => $service->save(id: 'src', request: $unknown, userId: 'alice'));
		$this->assertSame(['stage_unknown', 404], [$refusal->getError(), $refusal->getStatus()]);
	}

	public function testAStageCannotNameMoreRowsThanTheSecretHas(): void {
		$copies = [];
		foreach (['c-bob', 'c-carol', 'c-x', 'c-y'] as $copyId) {
			$copies[] = ['secretId' => $copyId, 'key' => 'K'];
		}

		$refusal = $this->refusal(fn () => $this->service()->stage(
			id: 'src',
			request: new ShareSaveRequest(['baseUpdatedAt' => self::BASE, 'stage' => ['final' => false], 'copies' => $copies]),
			userId: 'alice'
		));

		$this->assertSame('too_many_entries', $refusal->getError());
		$this->assertSame([], $this->chunks);
	}

	public function testAChunkNamingARowTwiceAcrossTheStageIsRefused(): void {
		$service = $this->service();
		$chunk = fn (?string $stageId): ShareSaveRequest => new ShareSaveRequest([
			'baseUpdatedAt' => self::BASE,
			'stage' => array_filter(['id' => $stageId, 'final' => false], static fn ($v): bool => $v !== null),
			'copies' => [['secretId' => 'c-bob', 'key' => 'K-BOB']],
		]);
		$stageId = $service->stage(id: 'src', request: $chunk(null), userId: 'alice')['stageId'];

		$refusal = $this->refusal(fn () => $service->stage(id: 'src', request: $chunk($stageId), userId: 'alice'));

		$this->assertInstanceOf(ShareSaveRefusal::class, $refusal);
		$this->assertSame('duplicate_entry', $refusal->getError());
	}

	public function testAStageHasNoMoreChunksThanItsRowsNeed(): void {
		// Three rows (two copies, one partner share) fit in one chunk of a hundred.
		$service = $this->service();
		$chunk = fn (?string $stageId, string $copyId): ShareSaveRequest => new ShareSaveRequest([
			'baseUpdatedAt' => self::BASE,
			'stage' => array_filter(['id' => $stageId, 'final' => false], static fn ($v): bool => $v !== null),
			'copies' => [['secretId' => $copyId, 'key' => 'K']],
		]);
		$stageId = $service->stage(id: 'src', request: $chunk(null, 'c-bob'), userId: 'alice')['stageId'];

		$refusal = $this->refusal(fn () => $service->stage(id: 'src', request: $chunk($stageId, 'c-carol'), userId: 'alice'));

		$this->assertSame(['too_many_chunks', 400], [$refusal->getError(), $refusal->getStatus()]);
		$this->assertCount(1, $this->chunks);
	}

	public function testAPartnerShareRevokedBeforeItsWriteMakesTheSaveIncomplete(): void {
		$this->federated = $this->createMock(FederatedShareService::class);
		$this->federated->method('writeCiphertext')->willReturnCallback(
			function (FederatedShare $row): FederatedShare {
				// The owner revoked it after the check under the lock.
				$this->partnerShares[0]->setStatus(FederatedShare::STATUS_REVOKED);
				throw new NotFoundException('Share not found');
			}
		);
		$this->delivery->expects($this->never())->method('deliver');

		$refusal = $this->refusal(fn () => $this->service()->save(id: 'src', request: $this->ownerRequest(), userId: 'alice'));

		$this->assertInstanceOf(IncompleteShareSaveException::class, $refusal);
		$this->assertSame(['fs-1'], $refusal->getBody()['federated']['unexpected']);
		$this->assertSame(['begin', 'lock src', 'copy c-bob', 'copy c-carol', 'rollback'], $this->log);
	}

	/**
	 * Alice's save of a new TOTP seed and passkeys, for Bob, Carol and Dave.
	 *
	 * @return ShareSaveRequest
	 */
	private function totpRequest(): ShareSaveRequest {
		$fields = ['totp' => 'T', 'passkeys' => null];

		return $this->ownerRequest([
			'patch' => ['key' => 'K-ALICE', 'login' => null] + $fields,
			'copies' => [
				['secretId' => 'c-bob', 'key' => 'K-BOB', 'login' => null] + $fields,
				['secretId' => 'c-carol', 'key' => 'K-CAROL', 'login' => null] + $fields,
			],
			'federated' => [[
				'shareId' => 'fs-1',
				'certFingerprint' => str_repeat('a', 64),
				'key' => 'K-DAVE',
				'login' => null,
				'additionalFields' => null,
				'totp' => 'T-DAVE',
				'passkeys' => null,
			]],
		]);
	}

	/**
	 * The ciphertext each partner write is sent, for one save.
	 *
	 * @param ShareSaveRequest $request The save
	 *
	 * @return list<array<string,mixed>>
	 */
	private function partnerCiphertext(ShareSaveRequest $request): array {
		$sent = [];
		$this->federated = $this->createMock(FederatedShareService::class);
		$this->federated->method('writeCiphertext')->willReturnCallback(
			function (FederatedShare $row, string $certFingerprint, array $ciphertext, DateTime $savedAt, ?array $patch = null, array $kept = []) use (&$sent): FederatedShare {
				$sent[] = $ciphertext;
				$this->partnerPatches[] = $patch;
				$this->partnerKept[] = $kept;
				return $row;
			}
		);

		$this->service()->save(id: 'src', request: $request, userId: 'alice');

		return $sent;
	}

	public function testThePartnerIsSentTheNewFieldsTheEntryCarries(): void {
		$this->assertSame(
			[['key' => 'K-DAVE', 'login' => null, 'additionalFields' => null, 'totp' => 'T-DAVE', 'passkeys' => null]],
			$this->partnerCiphertext(request: $this->totpRequest())
		);
		// The write checks the entry against the patch for a partner that stores the fields.
		$this->assertSame([['key' => 'K-ALICE', 'login' => null, 'totp' => 'T', 'passkeys' => null]], $this->partnerPatches);
	}

	public function testAnOlderClientsPartnerWriteLeavesTheNewFieldsOut(): void {
		$this->assertSame(
			[['key' => 'K-DAVE', 'login' => null, 'additionalFields' => null]],
			$this->partnerCiphertext(request: $this->ownerRequest())
		);
	}

	public function testAPartnerTooOldForTheNewFieldsRefusesTheSaveAndRollsBack(): void {
		$this->federated = $this->createMock(FederatedShareService::class);
		$this->federated->method('writeCiphertext')->willThrowException(new PartnerUpgradeRequiredException(requiredVersion: '9.9.9'));
		$this->delivery->expects($this->never())->method('deliver');

		$refusal = $this->refusal(fn () => $this->service()->save(id: 'src', request: $this->totpRequest(), userId: 'alice'));

		$this->assertInstanceOf(ShareSaveRefusal::class, $refusal);
		$this->assertSame([PartnerUpgradeRequiredException::CODE, 409], [$refusal->getError(), $refusal->getStatus()]);
		$this->assertSame(
			['error' => 'partner_upgrade_required', 'shareId' => 'fs-1', 'requiredVersion' => '9.9.9'],
			array_diff_key($refusal->getBody(), ['message' => true])
		);
		$this->assertSame(['begin', 'lock src', 'copy c-bob', 'copy c-carol', 'rollback'], $this->log);
		$this->assertSame([], $this->rowSaves);
	}

	public function testAPartnerRefusalKeepsTheStage(): void {
		$this->federated = $this->createMock(FederatedShareService::class);
		$this->federated->method('writeCiphertext')->willThrowException(new PartnerUpgradeRequiredException());
		$stageId = $this->service()->stage(
			id: 'src',
			request: new ShareSaveRequest([
				'baseUpdatedAt' => self::BASE,
				'stage' => ['final' => false],
				'copies' => [['secretId' => 'c-bob', 'key' => 'K-BOB', 'login' => null, 'totp' => 'T-BOB', 'passkeys' => null]],
			]),
			userId: 'alice'
		)['stageId'];
		$final = $this->totpRequest();
		$body = [
			'baseUpdatedAt' => self::BASE,
			'stage' => ['id' => $stageId, 'final' => true],
			'patch' => $final->patch,
			'copies' => [$final->entries->copies[1]],
			'federated' => $final->entries->federated,
		];

		$refusal = $this->refusal(fn () => $this->service()->save(id: 'src', request: new ShareSaveRequest($body), userId: 'alice'));

		$this->assertSame('partner_upgrade_required', $refusal instanceof ShareSaveRefusal ? $refusal->getError() : null);
		$this->assertNotContains('drop stage', $this->log);
		$this->assertCount(1, $this->chunks);
	}

	public function testAPartnerShareRevokedBeforeItsSuspendMakesTheSaveIncomplete(): void {
		$this->federated = $this->createMock(FederatedShareService::class);
		$this->federated->method('suspend')->willReturnCallback(
			function (): FederatedShare {
				$this->partnerShares[0]->setStatus(FederatedShare::STATUS_REVOKED);
				throw new NotFoundException('Share not found');
			}
		);
		$this->delivery->expects($this->never())->method('deliver');

		$refusal = $this->refusal(fn () => $this->service()->save(
			id: 'src',
			request: $this->ownerRequest(['federated' => [], 'suspend' => [['shareId' => 'fs-1', 'reason' => 'unverified']]]),
			userId: 'alice'
		));

		$this->assertInstanceOf(IncompleteShareSaveException::class, $refusal);
		$this->assertSame(['fs-1'], $refusal->getBody()['federated']['unexpected']);
		$this->assertSame('rollback', end($this->log));
	}

	/**
	 * A version of Alice's `src`.
	 *
	 * @param string $secretId The row the version belongs to
	 *
	 * @return SecretVersion
	 */
	private function version(string $secretId = 'src'): SecretVersion {
		$version = new SecretVersion();
		$version->setId('v-1');
		$version->setSecretId($secretId);
		$version->setVersionNumber(3);

		return $version;
	}

	private function restoreRequest(): ShareSaveRequest {
		return $this->ownerRequest([
			'restoredFrom' => 'v-1',
			'patch' => ['key' => 'K-OLD', 'login' => null, 'additionalFields' => null, 'name' => 'Old router'],
			'copies' => [
				['secretId' => 'c-bob', 'key' => 'K-BOB', 'login' => null, 'additionalFields' => null],
				['secretId' => 'c-carol', 'key' => 'K-CAROL', 'login' => null, 'additionalFields' => null],
			],
		]);
	}

	public function testARestoreReachesEveryCopyAndIsAuditedInTheTransaction(): void {
		$this->delivery->method('deliver')->willReturn(true);
		$this->versionHistory->method('getVersion')->with('v-1', 'alice')->willReturn($this->version());
		$this->versionHistory->expects($this->once())->method('recordRestored')->willReturnCallback(
			function (Secret $secret, SecretVersion $version, string $userId, ?string $via = null): void {
				// The client re-encrypted the value: the version is its claim.
				$this->assertSame('share-save', $via);
				$this->log[] = 'restored v'.$version->getVersionNumber();
			}
		);
		$this->versionHistory->expects($this->once())->method('logRestored')->willReturnCallback(
			function (Secret $secret, SecretVersion $version, string $userId): void {
				$this->log[] = 'logged v'.$version->getVersionNumber();
			}
		);

		$this->service()->save(id: 'src', request: $this->restoreRequest(), userId: 'alice');

		$this->assertSame(
			['begin', 'lock src', 'copy c-bob', 'copy c-carol', 'partner fs-1', 'row src', 'restored v3', 'commit', 'logged v3'],
			$this->log
		);
		$this->assertSame('Old router', $this->rowSaves[0]['data']['name']);
	}

	public function testARestoreThatFailsAfterItsAuditIsNotLogged(): void {
		$this->bobWritesOnAGrade();
		$this->versionHistory->method('getVersion')->willReturn($this->version(secretId: 'c-bob'));
		$this->versionHistory->expects($this->once())->method('recordRestored')->willReturnCallback(
			function (): void {
				$this->log[] = 'restored';
			}
		);
		$this->audit->method('recordTeamWriteSync')->willThrowException(new RuntimeException('audit insert failed'));
		$this->versionHistory->expects($this->never())->method('logRestored');

		$refusal = $this->refusal(
			fn () => $this->service()->save(id: 'c-bob', request: $this->bobRequest([
				'restoredFrom' => 'v-1',
				'patch' => ['key' => 'K-OLD', 'login' => null, 'additionalFields' => null],
				'copies' => [
					['secretId' => 'src', 'key' => 'K-ALICE', 'login' => null, 'additionalFields' => null],
					['secretId' => 'c-carol', 'key' => 'K-CAROL', 'login' => null, 'additionalFields' => null],
				],
			]), userId: 'bob')
		);

		$this->assertSame('audit insert failed', $refusal->getMessage());
		$this->assertSame(['begin', 'lock src', 'lock c-bob', 'copy src', 'copy c-carol', 'row c-bob', 'restored', 'rollback'], $this->log);
	}

	public function testARestoreOfAVersionOfAnotherRowOrARefusedOneIsRefused(): void {
		$calls = 0;
		$this->versionHistory->method('getVersion')->willReturnCallback(
			function () use (&$calls): SecretVersion {
				$calls++;
				return ($calls === 1) ? $this->version(secretId: 'other') : throw new InvalidArgumentException('This version is locked');
			}
		);
		$this->versionHistory->expects($this->never())->method('recordRestored');

		foreach (['Version not found', 'This version is locked'] as $message) {
			$refusal = $this->refusal(fn () => $this->service()->save(id: 'src', request: $this->restoreRequest(), userId: 'alice'));
			$this->assertSame(['version_refused', 400, $message], [$refusal->getError(), $refusal->getStatus(), $refusal->getMessage()]);
		}

		$this->assertSame([], $this->log);
	}

	/**
	 * Alice's restore of `v-1` whose patch, copies and partner share carry these new fields.
	 *
	 * @param array<string,mixed> $fields The `totp` and `passkeys` sent
	 *
	 * @return ShareSaveRequest
	 */
	private function restoreWith(array $fields): ShareSaveRequest {
		return $this->ownerRequest([
			'restoredFrom' => 'v-1',
			'patch' => ['key' => 'K-OLD', 'login' => null, 'additionalFields' => null] + $fields,
			'copies' => [
				['secretId' => 'c-bob', 'key' => 'K-BOB', 'login' => null, 'additionalFields' => null] + $fields,
				['secretId' => 'c-carol', 'key' => 'K-CAROL', 'login' => null, 'additionalFields' => null] + $fields,
			],
			'federated' => [[
				'shareId' => 'fs-1',
				'certFingerprint' => str_repeat('a', 64),
				'key' => 'K-DAVE',
				'login' => null,
				'additionalFields' => null,
			] + $fields],
		]);
	}

	/**
	 * A version made before the fields existed holds none: the head and every copy keep theirs. The partner share
	 * is written as sent, held to the head's own values (FederatedValueFieldsTest refuses one that does not match).
	 */
	public function testARestoreOfAVersionWithoutTheNewFieldsLeavesThemOutOfTheHeadAndCopies(): void {
		$this->versionHistory->method('getVersion')->willReturn($this->version());
		$this->rows['c-bob']->setTotp('BOB-TOTP');
		$this->rows['src']->setTotp('HEAD-TOTP');

		$sent = $this->partnerCiphertext(request: $this->restoreWith(fields: ['totp' => 'T', 'passkeys' => 'P']));

		$this->assertSame(['key' => 'K-OLD', 'login' => null, 'additionalFields' => null], $this->rowSaves[0]['data']);
		$this->assertSame([['BOB-TOTP', null], [null, null]], array_map(static fn (array $w): array => [$w['totp'], $w['passkeys']], $this->copyWrites));
		$this->assertSame([['key' => 'K-DAVE', 'login' => null, 'additionalFields' => null, 'totp' => 'T', 'passkeys' => 'P']], $sent);
		$this->assertSame([['key' => 'K-OLD', 'login' => null, 'additionalFields' => null]], $this->partnerPatches);
		$this->assertSame([['totp' => 'HEAD-TOTP', 'passkeys' => null]], $this->partnerKept);
	}

	/**
	 * A version made after the fields existed holds `''` for an empty one: the restore clears a field the head holds
	 * on the head, every copy and the partner share, also from a client that left it out; one the head does not hold
	 * is left out of the save.
	 */
	public function testARestoreOfAVersionThatHeldTheFieldsEmptyClearsThemEverywhere(): void {
		$version = $this->version();
		$version->setTotp('');
		$version->setPasskeys('');
		$this->versionHistory->method('getVersion')->willReturn($version);
		$this->rows['c-bob']->setTotp('BOB-TOTP');
		$this->rows['src']->setTotp('HEAD-TOTP');

		$sent = $this->partnerCiphertext(request: $this->restoreWith(fields: []));

		$this->assertSame('', $this->rowSaves[0]['data']['totp']);
		$this->assertArrayNotHasKey('passkeys', $this->rowSaves[0]['data']);
		$this->assertSame([[null, null], [null, null]], array_map(static fn (array $w): array => [$w['totp'], $w['passkeys']], $this->copyWrites));
		$this->assertNull($sent[0]['totp']);
		$this->assertArrayNotHasKey('passkeys', $sent[0]);
		$this->assertSame([['passkeys' => null]], $this->partnerKept);
	}

	/**
	 * A copy whose `suite_compromise` flag is still open reads as flagged on a restore even with its warning
	 * cleared: the old key written back gets no change time. A copy with no such flag gets the time of the restore.
	 */
	public function testARestoreLeavesNoKeyAgeOnACopyWithAnOpenCompromiseFlag(): void {
		$this->versionHistory->method('getVersion')->willReturn($this->version());
		$this->versionHistory->method('compromiseFlagOpen')->willReturnCallback(static fn (string $id): bool => $id === 'c-bob');

		$this->partnerCiphertext(request: $this->restoreWith(fields: []));

		$ages = [];
		foreach ($this->copyWrites as $write) {
			$ages[$write['id']] = $write['keyUpdatedAt'];
		}

		$this->assertNull($ages['c-bob']);
		$this->assertNotNull($ages['c-carol']);
	}

	public function testARestoreThatLeavesOutAFieldTheVersionHoldsIsRefusedBeforeAnythingIsWritten(): void {
		$version = $this->version();
		$version->setPasskeys('V-PASSKEYS');
		$this->versionHistory->method('getVersion')->willReturn($version);
		$this->versionHistory->expects($this->never())->method('recordRestored');

		$refusal = $this->refusal(fn () => $this->service()->save(id: 'src', request: $this->restoreWith(fields: ['totp' => 'T']), userId: 'alice'));

		$this->assertInstanceOf(ShareSaveRefusal::class, $refusal);
		$this->assertSame(['invalid_body', 400, 'passkeys'], [$refusal->getError(), $refusal->getStatus(), $refusal->getBody()['field']]);
		$this->assertSame([], $this->log);
	}

	public function testARestoreCarryingTheFieldsTheVersionHoldsWritesThemEverywhere(): void {
		$version = $this->version();
		$version->setTotp('V-TOTP');
		$version->setPasskeys('V-PASSKEYS');
		$this->versionHistory->method('getVersion')->willReturn($version);

		$sent = $this->partnerCiphertext(request: $this->restoreWith(fields: ['totp' => 'T', 'passkeys' => 'P']));

		$this->assertSame(['T', 'P'], [$this->rowSaves[0]['data']['totp'], $this->rowSaves[0]['data']['passkeys']]);
		$this->assertSame([['T', 'P'], ['T', 'P']], array_map(static fn (array $w): array => [$w['totp'], $w['passkeys']], $this->copyWrites));
		$this->assertSame(['T', 'P'], [$sent[0]['totp'], $sent[0]['passkeys']]);
	}

	public function testARestoreFromAnOlderClientOfAVersionWithoutTheNewFieldsWorksAsBefore(): void {
		$this->versionHistory->method('getVersion')->willReturn($this->version());
		$request = $this->restoreWith(fields: []);

		$sent = $this->partnerCiphertext(request: $request);

		$this->assertSame($request->patch, $this->rowSaves[0]['data']);
		$this->assertSame(['K-BOB', 'K-CAROL'], array_column($this->copyWrites, 'key'));
		$this->assertSame([['key' => 'K-DAVE', 'login' => null, 'additionalFields' => null]], $sent);
	}
}
