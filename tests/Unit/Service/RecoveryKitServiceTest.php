<?php

/**
 * Unit tests for a user's own recovery kit: create, replace, fetch, spend,
 * revoke, the owner-only reach and the audit trail, over in-memory tables.
 *
 * @category Test
 * @package  OCA\Keepiq\Tests\Unit\Service
 *
 * @author    Conduction Development Team <dev@conductio.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @link https://conduction.nl
 *
 * @spec openspec/changes/crypto-recovery-kit/specs/vault-recovery-kit/spec.md
 */

declare(strict_types=1);

namespace OCA\Keepiq\Tests\Unit\Service;

use InvalidArgumentException;
use OCA\Keepiq\Controller\RecoveryKitController;
use OCA\Keepiq\Db\EncryptionSuite;
use OCA\Keepiq\Db\EncryptionSuiteMapper;
use OCA\Keepiq\Db\RecoveryKit;
use OCA\Keepiq\Db\RecoveryKitMapper;
use OCA\Keepiq\Exception\NotFoundException;
use OCA\Keepiq\Kernel\Contracts\RecoveryAuditInterface;
use OCA\Keepiq\Service\RecoveryKitService;
use OCP\AppFramework\Db\DoesNotExistException;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\Attribute\NoAdminRequired;
use OCP\AppFramework\Http\Attribute\UserRateLimit;
use OCP\IRequest;
use OCP\IUser;
use OCP\IUserSession;
use PHPUnit\Framework\TestCase;
use ReflectionMethod;

/**
 * Bob has suite suite-bob; mallory has suite suite-mallory; nobody has none.
 *
 * @covers \OCA\Keepiq\Service\RecoveryKitService
 * @covers \OCA\Keepiq\Controller\RecoveryKitController
 * @uses   \OCA\Keepiq\Db\RecoveryKit
 */
class RecoveryKitServiceTest extends TestCase {

	/**
	 * The in-memory kits, by id.
	 *
	 * @var array<string,RecoveryKit>
	 */
	private array $kits = [];

	/**
	 * Every audit record: actor, event type, object, metadata.
	 *
	 * @var array<int,array{0: string, 1: string, 2: string, 3: array<string,mixed>}>
	 */
	private array $audit = [];

	/**
	 * The active suite per user.
	 *
	 * @var array<string,string>
	 */
	private array $suites = ['bob' => 'suite-bob', 'mallory' => 'suite-mallory'];

	/**
	 * A kit is stored for the user's own active suite, the answer never
	 * carries the wrapped copy, and the creation is audited with the suite only.
	 *
	 * @return void
	 */
	public function testAKitIsStoredForTheUsersActiveSuite(): void {
		$result = $this->service()->create(userId: 'bob', wrappedKey: 'wrapped-1', kdf: '{"salt":"s1"}');

		$this->assertSame('suite-bob', $result['kit']['suiteId']);
		$this->assertNotNull($result['kit']['createdAt']);
		$this->assertArrayNotHasKey('wrappedKey', $result['kit']);
		$this->assertCount(1, $this->kits);
		$kit = array_values($this->kits)[0];
		$this->assertSame(
			['bob', 'suite-bob', 'wrapped-1', '{"salt":"s1"}'],
			[$kit->getUserId(), $kit->getSuiteId(), $kit->getWrappedKey(), $kit->getKdf()]
		);
		$this->assertSame([['bob', RecoveryAuditInterface::KIT_CREATED, 'suite-bob', []]], $this->audit);
	}//end testAKitIsStoredForTheUsersActiveSuite()

	/**
	 * A new kit replaces the old copy, which is revoked in the trail, so the
	 * old code has nothing left to unwrap.
	 *
	 * @return void
	 */
	public function testANewKitReplacesTheOldOne(): void {
		$service = $this->service();
		$service->create(userId: 'bob', wrappedKey: 'wrapped-1', kdf: 'kdf-1');
		$service->create(userId: 'bob', wrappedKey: 'wrapped-2', kdf: 'kdf-2');

		$this->assertCount(1, $this->kits);
		$this->assertSame(['suiteId' => 'suite-bob', 'wrappedKey' => 'wrapped-2', 'kdf' => 'kdf-2'], $service->fetch(userId: 'bob'));
		$this->assertSame(
			[RecoveryAuditInterface::KIT_CREATED, RecoveryAuditInterface::KIT_REVOKED, RecoveryAuditInterface::KIT_CREATED],
			array_column($this->audit, 1)
		);
	}//end testANewKitReplacesTheOldOne()

	/**
	 * A missing or oversized value, or a user without an active suite, stores nothing.
	 *
	 * @return void
	 */
	public function testAnUnusableKitIsRefused(): void {
		$cases = [
			'wrappedKey is missing or too large' => ['bob', ' ', 'kdf'],
			'kdf is missing or too large' => ['bob', 'wrapped', ''],
			'wrappedKey is missing or too large ' => ['bob', str_repeat('a', RecoveryKitService::MAX_LENGTH + 1), 'kdf'],
			'You have no active encryption suite' => ['nobody', 'wrapped', 'kdf'],
		];
		foreach ($cases as $message => [$user, $wrapped, $kdf]) {
			try {
				$this->service()->create(userId: $user, wrappedKey: $wrapped, kdf: $kdf);
				$this->fail('Stored: ' . $message);
			} catch (InvalidArgumentException $exception) {
				$this->assertSame(trim($message), $exception->getMessage());
			}
		}

		$this->assertSame([], $this->kits);
		$this->assertSame([], $this->audit);
	}//end testAnUnusableKitIsRefused()

	/**
	 * Another user reaches only their own copy: with bob's kit stored,
	 * mallory's fetch through the route finds nothing, and the kit stays.
	 *
	 * @return void
	 */
	public function testAnotherUserCannotFetchTheCopy(): void {
		$this->service()->create(userId: 'bob', wrappedKey: 'wrapped-1', kdf: 'kdf-1');

		$response = $this->controllerFor(uid: 'mallory')->fetch();

		$this->assertSame(Http::STATUS_NOT_FOUND, $response->getStatus());
		$this->assertSame(['message' => 'Not found'], $response->getData());
		$this->assertStringNotContainsString('wrapped-1', (string)json_encode($response->getData()));
		$this->assertSame('wrapped-1', $this->controllerFor(uid: 'bob')->fetch()->getData()['wrappedKey']);
	}//end testAnotherUserCannotFetchTheCopy()

	/**
	 * A kit for an older suite is not offered once the user has a new one.
	 *
	 * @return void
	 */
	public function testAKitForAnOldSuiteIsNotOffered(): void {
		$this->service()->create(userId: 'bob', wrappedKey: 'wrapped-1', kdf: 'kdf-1');
		$this->suites['bob'] = 'suite-bob-2';

		$this->assertSame(['kit' => null], $this->service()->status(userId: 'bob'));
		$this->expectException(NotFoundException::class);
		$this->service()->fetch(userId: 'bob');
	}//end testAKitForAnOldSuiteIsNotOffered()

	/**
	 * After a recovery the spent copy is deleted and its use audited; a
	 * second spend finds nothing.
	 *
	 * @return void
	 */
	public function testASpentKitIsDeletedAndRecorded(): void {
		$service = $this->service();
		$service->create(userId: 'bob', wrappedKey: 'wrapped-1', kdf: 'kdf-1');

		$service->spend(userId: 'bob');

		$this->assertSame([], $this->kits);
		$this->assertSame(['bob', RecoveryAuditInterface::KIT_USED, 'suite-bob', []], $this->audit[1]);
		$this->expectException(NotFoundException::class);
		$service->spend(userId: 'bob');
	}//end testASpentKitIsDeletedAndRecorded()

	/**
	 * Revoking deletes the user's copies and records each; without one it does nothing.
	 *
	 * @return void
	 */
	public function testRevokeDeletesTheUsersOwnCopyOnly(): void {
		$service = $this->service();
		$service->create(userId: 'bob', wrappedKey: 'wrapped-1', kdf: 'kdf-1');
		$service->create(userId: 'mallory', wrappedKey: 'wrapped-m', kdf: 'kdf-m');
		$this->audit = [];

		$service->revoke(userId: 'bob');
		$service->revoke(userId: 'nobody');

		$this->assertSame(['mallory'], array_map(static fn (RecoveryKit $kit): string => $kit->getUserId(), array_values($this->kits)));
		$this->assertSame([['bob', RecoveryAuditInterface::KIT_REVOKED, 'suite-bob', []]], $this->audit);
	}//end testRevokeDeletesTheUsersOwnCopyOnly()

	/**
	 * The audit trail keeps the suite as the object and no metadata at all,
	 * so nothing of the copy or the code can reach it.
	 *
	 * @return void
	 */
	public function testTheAuditTrailCarriesTheSuiteOnly(): void {
		$this->service()->create(userId: 'bob', wrappedKey: 'wrapped-1', kdf: 'kdf-1');

		$this->assertSame([['bob', RecoveryAuditInterface::KIT_CREATED, 'suite-bob', []]], $this->audit);
		$this->assertSame(
			['recovery_kit.created', 'recovery_kit.used', 'recovery_kit.revoked'],
			[RecoveryAuditInterface::KIT_CREATED, RecoveryAuditInterface::KIT_USED, RecoveryAuditInterface::KIT_REVOKED]
		);
	}//end testTheAuditTrailCarriesTheSuiteOnly()

	/**
	 * Every route is for signed-in users, takes no user id, and the copy's
	 * fetch is rate limited, so wrong codes cannot be tried at will.
	 *
	 * @return void
	 */
	public function testTheRoutesAreTheSessionUsersOwnAndTheFetchIsLimited(): void {
		foreach (['show', 'create', 'fetch', 'spend', 'revoke'] as $method) {
			$reflection = new ReflectionMethod(RecoveryKitController::class, $method);
			$this->assertCount(1, $reflection->getAttributes(NoAdminRequired::class), $method);
			$this->assertNotContains('userId', array_map(static fn ($parameter): string => $parameter->getName(), $reflection->getParameters()), $method);
		}

		$limit = (new ReflectionMethod(RecoveryKitController::class, 'fetch'))->getAttributes(UserRateLimit::class);
		$this->assertCount(1, $limit);
		$this->assertSame(['limit' => 5, 'period' => 3600], $limit[0]->getArguments());
	}//end testTheRoutesAreTheSessionUsersOwnAndTheFetchIsLimited()

	/**
	 * Nobody signed in gets 401; a refused store is 400; create answers 201.
	 *
	 * @return void
	 */
	public function testTheRouteAnswers(): void {
		$this->assertSame(Http::STATUS_UNAUTHORIZED, $this->controllerFor(uid: null)->fetch()->getStatus());
		$this->assertSame(Http::STATUS_BAD_REQUEST, $this->controllerFor(uid: 'bob')->create(wrappedKey: '', kdf: 'kdf')->getStatus());

		$created = $this->controllerFor(uid: 'bob')->create(wrappedKey: 'wrapped-1', kdf: 'kdf-1');
		$this->assertSame(Http::STATUS_CREATED, $created->getStatus());
		$this->assertSame('suite-bob', $created->getData()['kit']['suiteId']);

		$spent = $this->controllerFor(uid: 'bob')->spend();
		$this->assertSame(['kit' => null], $spent->getData());
		$this->assertSame(['kit' => null], $this->controllerFor(uid: 'bob')->revoke()->getData());
		$this->assertSame(['kit' => null], $this->controllerFor(uid: 'bob')->show()->getData());
	}//end testTheRouteAnswers()

	/**
	 * The controller over the real service, signed in as a user or nobody.
	 *
	 * @param string|null $uid The session user.
	 *
	 * @return RecoveryKitController
	 */
	private function controllerFor(?string $uid): RecoveryKitController {
		$session = $this->createMock(IUserSession::class);
		$user = null;
		if ($uid !== null) {
			$user = $this->createMock(IUser::class);
			$user->method('getUID')->willReturn($uid);
		}

		$session->method('getUser')->willReturn($user);

		return new RecoveryKitController(request: $this->createMock(IRequest::class), kits: $this->service(), userSession: $session);
	}//end controllerFor()

	/**
	 * The service over in-memory kits, suites and audit.
	 *
	 * @return RecoveryKitService
	 */
	private function service(): RecoveryKitService {
		$mapper = $this->createMock(RecoveryKitMapper::class);
		$mapper->method('findByUser')->willReturnCallback(
			fn (string $userId): array => array_values(array_filter($this->kits, static fn (RecoveryKit $kit): bool => $kit->getUserId() === $userId))
		);
		$mapper->method('insert')->willReturnCallback(
			function (RecoveryKit $kit): RecoveryKit {
				$this->kits[$kit->getId()] = $kit;
				return $kit;
			}
		);
		$mapper->method('delete')->willReturnCallback(
			function (RecoveryKit $kit): RecoveryKit {
				unset($this->kits[$kit->getId()]);
				return $kit;
			}
		);
		$mapper->method('deleteForUser')->willReturnCallback(
			function (string $userId, string $suiteId=''): int {
				$deleted = 0;
				foreach ($this->kits as $id => $kit) {
					if ($kit->getUserId() === $userId && ($suiteId === '' || $kit->getSuiteId() === $suiteId)) {
						unset($this->kits[$id]);
						$deleted++;
					}
				}

				return $deleted;
			}
		);

		$suites = $this->createMock(EncryptionSuiteMapper::class);
		$suites->method('findActiveByOwner')->willReturnCallback(
			function (string $ownerType, string $ownerId): EncryptionSuite {
				$this->assertSame('user', $ownerType);
				if (isset($this->suites[$ownerId]) === false) {
					throw new DoesNotExistException('none');
				}

				$suite = new EncryptionSuite();
				$suite->setId($this->suites[$ownerId]);
				return $suite;
			}
		);

		$audit = $this->createMock(RecoveryAuditInterface::class);
		$audit->method('record')->willReturnCallback(
			function (string $actorId, string $eventType, string $objectId, array $metadata=[]): void {
				$this->audit[] = [$actorId, $eventType, $objectId, $metadata];
			}
		);

		return new RecoveryKitService(mapper: $mapper, suiteMapper: $suites, audit: $audit);
	}//end service()
}//end class
