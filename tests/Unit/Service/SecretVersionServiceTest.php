<?php

/**
 * Unit tests for SecretVersionService (secret-version-history §8).
 *
 * @category Test
 * @package  OCA\Keepiq\Tests\Unit\Service
 *
 * @author    Conduction Development Team <dev@conductio.nl>
 * @copyright 2024 Conduction B.V.
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
use OCA\Keepiq\Db\Secret;
use OCA\Keepiq\Db\SecretMapper;
use OCA\Keepiq\Db\SecretVersion;
use OCA\Keepiq\Db\SecretVersionMapper;
use OCA\Keepiq\Exception\StaleWriteException;
use OCA\Keepiq\Service\SecretVersionAccessGuard;
use OCA\Keepiq\Service\SecretVersionService;
use OCP\AppFramework\Db\DoesNotExistException;
use OCP\IDBConnection;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

/**
 * Tests for SecretVersionService.
 */
class SecretVersionServiceTest extends TestCase {
	private SecretVersionService $service;

	private SecretVersionMapper&MockObject $mapper;

	private SecretMapper&MockObject $secretMapper;

	private EncryptionSuiteMapper&MockObject $suiteMapper;

	private LoggerInterface&MockObject $logger;

	/** @var array<int,string> The transaction calls, in order */
	private array $transaction = [];

	/**
	 * Build the service with fresh mocks.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		parent::setUp();
		$this->mapper = $this->createMock(originalClassName: SecretVersionMapper::class);
		$this->secretMapper = $this->createMock(originalClassName: SecretMapper::class);
		$this->suiteMapper = $this->createMock(originalClassName: EncryptionSuiteMapper::class);
		$this->logger = $this->createMock(originalClassName: LoggerInterface::class);
		$db = $this->createMock(originalClassName: IDBConnection::class);
		foreach (['beginTransaction' => 'begin', 'commit' => 'commit', 'rollBack' => 'rollback'] as $method => $entry) {
			$db->method($method)->willReturnCallback(function () use ($entry): void {
				$this->transaction[] = $entry;
			});
		}

		$this->service = new SecretVersionService(
			mapper: $this->mapper,
			secretMapper: $this->secretMapper,
			accessGuard: new SecretVersionAccessGuard(
				mapper: $this->mapper,
				secretMapper: $this->secretMapper,
				suiteMapper: $this->suiteMapper,
			),
			logger: $this->logger,
			db: $db,
			eventDispatcher: null,
		);
	}//end setUp()

	/**
	 * Build an owned Secret with ciphertext fields.
	 *
	 * @param string $ownerId The owner
	 *
	 * @return Secret
	 */
	private function ownedSecret(string $ownerId = 'alice'): Secret {
		$secret = new Secret();
		$secret->setId('sec-1');
		$secret->setName('Wiki');
		$secret->setUrl('https://wiki.example.com');
		$secret->setKey('HEAD_CIPHERTEXT');
		$secret->setLogin('HEAD_LOGIN_CIPHERTEXT');
		$secret->setEncryptionSuiteId('suite-1');
		$secret->setOwnerType('user');
		$secret->setOwnerId($ownerId);
		return $secret;
	}//end ownedSecret()

	/**
	 * snapshot copies the pre-update ciphertext verbatim with the next
	 * version number — no decryption anywhere.
	 *
	 * @return void
	 */
	public function testSnapshotCopiesCiphertextVerbatim(): void {
		$this->mapper->method('nextVersionNumber')->willReturn(3);
		$inserted = null;
		$this->mapper->method('insert')->willReturnCallback(
			static function (SecretVersion $version) use (&$inserted) {
				$inserted = $version;
				return $version;
			}
		);

		$version = $this->service->snapshot(preUpdate: $this->ownedSecret(), actorType: 'user', actorId: 'alice');

		$this->assertSame(3, $version->getVersionNumber());
		$this->assertSame('HEAD_CIPHERTEXT', $inserted->getKey());
		$this->assertSame('HEAD_LOGIN_CIPHERTEXT', $inserted->getLogin());
		$this->assertSame('suite-1', $inserted->getEncryptionSuiteId());
		$this->assertSame('user', $inserted->getActorType());
	}//end testSnapshotCopiesCiphertextVerbatim()

	/**
	 * list is owner-only and yields an empty list for inaccessible
	 * secrets (no existence oracle).
	 *
	 * @return void
	 */
	public function testListOwnerOnlyNoOracle(): void {
		$this->secretMapper->method('findById')->willReturn($this->ownedSecret(ownerId: 'alice'));
		$this->mapper->method('findBySecret')->willReturn([new SecretVersion()]);

		$this->assertCount(1, $this->service->list(secretId: 'sec-1', userId: 'alice'));
		$this->assertSame([], $this->service->list(secretId: 'sec-1', userId: 'mallory'));
	}//end testListOwnerOnlyNoOracle()

	/**
	 * A version wrapped by a revoked suite is refused (matching head-read).
	 *
	 * @return void
	 */
	public function testRevokedSuiteVersionReadRefused(): void {
		$version = new SecretVersion();
		$version->setId('v-1');
		$version->setSecretId('sec-1');
		$version->setEncryptionSuiteId('suite-old');
		$this->mapper->method('findById')->willReturn($version);
		$this->secretMapper->method('findById')->willReturn($this->ownedSecret());

		$suite = new EncryptionSuite();
		$suite->setId('suite-old');
		$suite->setStatus('revoked');
		$this->suiteMapper->method('findById')->willReturn($suite);

		$this->expectException(InvalidArgumentException::class);
		$this->expectExceptionMessageMatches('/locked/');
		$this->service->getVersion(versionId: 'v-1', userId: 'alice');
	}//end testRevokedSuiteVersionReadRefused()

	/**
	 * restore snapshots the current head FIRST, then sets the head's
	 * fields to the version's stored ciphertext.
	 *
	 * @return void
	 */
	public function testRestoreSnapshotsHeadThenSetsFields(): void {
		$version = new SecretVersion();
		$version->setId('v-1');
		$version->setSecretId('sec-1');
		$version->setVersionNumber(2);
		$version->setName('Old wiki');
		$version->setKey('OLD_CIPHERTEXT');
		$version->setEncryptionSuiteId('suite-1');
		$this->mapper->method('findById')->willReturn($version);

		$suite = new EncryptionSuite();
		$suite->setId('suite-1');
		$suite->setStatus('active');
		$this->suiteMapper->method('findById')->willReturn($suite);

		$head = $this->ownedSecret();
		$this->secretMapper->method('findById')->willReturn($head);

		$snapshots = [];
		$this->mapper->method('nextVersionNumber')->willReturn(5);
		$this->mapper->method('insert')->willReturnCallback(
			static function (SecretVersion $snap) use (&$snapshots) {
				$snapshots[] = $snap;
				return $snap;
			}
		);
		$updated = null;
		$this->secretMapper->method('update')->willReturnCallback(
			static function (Secret $secret) use (&$updated) {
				$updated = $secret;
				return $secret;
			}
		);

		$this->logger->expects($this->once())->method('info')->willReturnCallback(function (): void {
			$this->transaction[] = 'log';
		});

		$result = $this->service->restore(versionId: 'v-1', userId: 'alice');

		// The log line is written only after the restore committed.
		$this->assertSame(['begin', 'commit', 'log'], $this->transaction);

		// The head's PRE-restore ciphertext was snapshotted first.
		$this->assertCount(1, $snapshots);
		$this->assertSame('HEAD_CIPHERTEXT', $snapshots[0]->getKey());
		// The head now carries the version's stored ciphertext.
		$this->assertSame('OLD_CIPHERTEXT', $updated->getKey());
		$this->assertSame('Old wiki', $result->getName());
	}//end testRestoreSnapshotsHeadThenSetsFields()

	/**
	 * A head at 10:00:05 with version v-1 of it to restore.
	 *
	 * @return Secret The head
	 */
	private function restorableHead(): Secret {
		$version = new SecretVersion();
		$version->setId('v-1');
		$version->setSecretId('sec-1');
		$version->setKey('OLD_CIPHERTEXT');
		$version->setEncryptionSuiteId('suite-1');
		$this->mapper->method('findById')->willReturn($version);

		$suite = new EncryptionSuite();
		$suite->setId('suite-1');
		$suite->setStatus('active');
		$this->suiteMapper->method('findById')->willReturn($suite);

		$head = $this->ownedSecret();
		$head->setUpdatedAt(new DateTime('2026-10-01T10:00:05+00:00'));
		$this->secretMapper->method('findById')->willReturn($head);

		return $head;
	}//end restorableHead()

	/**
	 * A restore chosen on an older head changes nothing.
	 *
	 * @return void
	 */
	public function testARestoreOverAMovedHeadIsRefusedUntouched(): void {
		$head = $this->restorableHead();
		$this->mapper->expects($this->never())->method('insert');
		$this->secretMapper->expects($this->never())->method('update');

		try {
			$this->service->restore(versionId: 'v-1', userId: 'alice', baseUpdatedAt: '2026-10-01T10:00:04+00:00');
			$this->fail('A stale base must be refused');
		} catch (StaleWriteException $stale) {
			$this->assertSame($head, $stale->getCurrent());
			$this->assertSame('HEAD_CIPHERTEXT', $head->getKey());
		}
	}//end testARestoreOverAMovedHeadIsRefusedUntouched()

	/**
	 * A restore on the current head goes ahead, whatever zone the base is in.
	 *
	 * @return void
	 */
	public function testARestoreOnTheCurrentHeadGoesAhead(): void {
		$this->restorableHead();
		$this->mapper->method('insert')->willReturnArgument(0);
		$this->secretMapper->expects($this->once())->method('advanceVersion')
			->with('sec-1', $this->callback(
				static fn (DateTime $base): bool => $base->getTimestamp() === (new DateTime('2026-10-01T10:00:05+00:00'))->getTimestamp()
			))
			->willReturn(1);
		$this->secretMapper->expects($this->once())->method('update');

		$restored = $this->service->restore(versionId: 'v-1', userId: 'alice', baseUpdatedAt: '2026-10-01T12:00:05+02:00');

		$this->assertSame('OLD_CIPHERTEXT', $restored->getKey());
		$this->assertGreaterThan((new DateTime('2026-10-01T10:00:05+00:00'))->getTimestamp(), $restored->getUpdatedAt()->getTimestamp());
		$this->assertSame(['begin', 'commit'], $this->transaction);
	}//end testARestoreOnTheCurrentHeadGoesAhead()

	/**
	 * A save that commits after the base check but before the restore's
	 * write moves the head: the compare-and-set finds it gone, and the
	 * restore is refused and rolled back instead of overwriting the save.
	 *
	 * @return void
	 */
	public function testARestoreOverAHeadThatMovesAfterTheCheckIsRefusedUntouched(): void {
		$head = $this->restorableHead();
		$this->secretMapper->expects($this->once())->method('advanceVersion')->willReturn(0);
		$this->mapper->expects($this->never())->method('insert');
		$this->secretMapper->expects($this->never())->method('update');
		$this->logger->expects($this->never())->method('info');

		try {
			$this->service->restore(versionId: 'v-1', userId: 'alice', baseUpdatedAt: '2026-10-01T10:00:05+00:00');
			$this->fail('A head that moved after the check must be refused');
		} catch (StaleWriteException $stale) {
			$this->assertSame('sec-1', $stale->getCurrent()->getId());
			$this->assertSame('HEAD_CIPHERTEXT', $head->getKey());
		}

		$this->assertSame(['begin', 'rollback'], $this->transaction);
	}//end testARestoreOverAHeadThatMovesAfterTheCheckIsRefusedUntouched()

	/**
	 * A head deleted after the base check is refused like a missing version
	 * (400 on the route), not as an unhandled error.
	 *
	 * @return void
	 */
	public function testARestoreOverAHeadDeletedAfterTheCheckIsRefusedAsNotFound(): void {
		$version = new SecretVersion();
		$version->setId('v-1');
		$version->setSecretId('sec-1');
		$version->setEncryptionSuiteId('suite-1');
		$this->mapper->method('findById')->willReturn($version);
		$suite = new EncryptionSuite();
		$suite->setId('suite-1');
		$suite->setStatus('active');
		$this->suiteMapper->method('findById')->willReturn($suite);

		$head = $this->ownedSecret();
		$head->setUpdatedAt(new DateTime('2026-10-01T10:00:05+00:00'));
		$deleted = false;
		$this->secretMapper->method('findById')->willReturnCallback(
			static function () use (&$deleted, $head): Secret {
				if ($deleted === true) {
					throw new DoesNotExistException('gone');
				}

				return $head;
			}
		);
		$this->secretMapper->method('advanceVersion')->willReturnCallback(
			static function () use (&$deleted): int {
				$deleted = true;
				return 0;
			}
		);
		$this->secretMapper->expects($this->never())->method('update');

		try {
			$this->service->restore(versionId: 'v-1', userId: 'alice', baseUpdatedAt: '2026-10-01T10:00:05+00:00');
			$this->fail('A deleted head must be refused');
		} catch (InvalidArgumentException $e) {
			$this->assertSame('Secret not found', $e->getMessage());
		}

		$this->assertSame(['begin', 'rollback'], $this->transaction);
	}//end testARestoreOverAHeadDeletedAfterTheCheckIsRefusedAsNotFound()

	/**
	 * A head deleted between the version check and the restore's own read
	 * is refused like a missing version, not as an unhandled error.
	 *
	 * @return void
	 */
	public function testARestoreOfAHeadDeletedBeforeItsReadIsRefusedAsNotFound(): void {
		$version = new SecretVersion();
		$version->setId('v-1');
		$version->setSecretId('sec-1');
		$version->setEncryptionSuiteId('suite-1');
		$this->mapper->method('findById')->willReturn($version);

		$deleted = false;
		$suite = new EncryptionSuite();
		$suite->setId('suite-1');
		$suite->setStatus('active');
		// The suite gate is getVersion()'s last read; the secret goes right after it.
		$this->suiteMapper->method('findById')->willReturnCallback(
			static function () use (&$deleted, $suite): EncryptionSuite {
				$deleted = true;
				return $suite;
			}
		);
		$head = $this->ownedSecret();
		$this->secretMapper->method('findById')->willReturnCallback(
			static function () use (&$deleted, $head): Secret {
				if ($deleted === true) {
					throw new DoesNotExistException('gone');
				}

				return $head;
			}
		);
		$this->secretMapper->expects($this->never())->method('update');

		try {
			$this->service->restore(versionId: 'v-1', userId: 'alice');
			$this->fail('A deleted head must be refused');
		} catch (InvalidArgumentException $e) {
			$this->assertSame('Secret not found', $e->getMessage());
		}

		$this->assertSame([], $this->transaction);
	}//end testARestoreOfAHeadDeletedBeforeItsReadIsRefusedAsNotFound()

	/**
	 * A restore without a base still moves the head past its stored version,
	 * even within the same second, so an edit made on the pre-restore
	 * version cannot pass the stale check afterwards.
	 *
	 * @return void
	 */
	public function testARestoreWithoutABaseMovesTheHeadPastItsStoredVersion(): void {
		$head = $this->restorableHead();
		$stored = (new DateTime('+1 hour'))->getTimestamp();
		$head->setUpdatedAt(new DateTime('@'.$stored));
		$this->mapper->method('insert')->willReturnArgument(0);
		$this->secretMapper->expects($this->once())->method('advanceVersion')
			->with(
				'sec-1',
				$this->callback(static fn (DateTime $base): bool => $base->getTimestamp() === $stored),
				$this->callback(static fn (DateTime $savedAt): bool => $savedAt->getTimestamp() === $stored + 1)
			)
			->willReturn(1);

		$restored = $this->service->restore(versionId: 'v-1', userId: 'alice');

		$this->assertSame($stored + 1, $restored->getUpdatedAt()->getTimestamp());
	}//end testARestoreWithoutABaseMovesTheHeadPastItsStoredVersion()

	/**
	 * Without a base, a save that commits between the restore's read and its
	 * write is refused too: the restore would overwrite it without a snapshot.
	 *
	 * @return void
	 */
	public function testARestoreWithoutABaseOverAHeadThatMovesAfterItsReadIsRefused(): void {
		$head = $this->restorableHead();
		$this->secretMapper->expects($this->once())->method('advanceVersion')->willReturn(0);
		$this->mapper->expects($this->never())->method('insert');
		$this->secretMapper->expects($this->never())->method('update');

		try {
			$this->service->restore(versionId: 'v-1', userId: 'alice');
			$this->fail('A head that moved after the read must be refused');
		} catch (StaleWriteException $stale) {
			$this->assertSame('sec-1', $stale->getCurrent()->getId());
			$this->assertSame('HEAD_CIPHERTEXT', $head->getKey());
		}

		$this->assertSame(['begin', 'rollback'], $this->transaction);
	}//end testARestoreWithoutABaseOverAHeadThatMovesAfterItsReadIsRefused()

	/**
	 * A restore that fails after the audit is rolled back and not logged.
	 *
	 * @return void
	 */
	public function testARestoreThatRollsBackIsNotLogged(): void {
		$this->restorableHead();
		$this->mapper->method('insert')->willReturnArgument(0);
		$this->secretMapper->method('advanceVersion')->willReturn(1);
		$this->secretMapper->method('update')->willThrowException(new \RuntimeException('deadlock'));
		$this->logger->expects($this->never())->method('info');

		try {
			$this->service->restore(versionId: 'v-1', userId: 'alice');
			$this->fail('The failed write must reach the caller');
		} catch (\RuntimeException $e) {
			$this->assertSame('deadlock', $e->getMessage());
		}

		$this->assertSame(['begin', 'rollback'], $this->transaction);
	}//end testARestoreThatRollsBackIsNotLogged()

	/**
	 * Recording a restore audits it without logging; a share-save logs it
	 * through logRestored() after its commit, like restore() does.
	 *
	 * @return void
	 */
	public function testARecordedRestoreIsLoggedOnlyByLogRestored(): void {
		$version = new SecretVersion();
		$version->setVersionNumber(4);
		$this->logger->expects($this->once())->method('info')
			->with($this->stringContains('sec-1 restored to version 4 by alice'));

		$this->service->recordRestored(secret: $this->ownedSecret(), version: $version, userId: 'alice');
		$this->service->logRestored(secret: $this->ownedSecret(), version: $version, userId: 'alice');
	}//end testARecordedRestoreIsLoggedOnlyByLogRestored()

	/**
	 * A restore through the share-save is audited as the client's claim of
	 * the version; the restore route's own event carries no such mark.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/sharing-atomic-share-save/specs/user-sharing/spec.md#requirement-a-restore-reaches-every-copy-in-the-same-transaction
	 */
	public function testAShareSaveRestoreIsAuditedAsTheClientsClaim(): void {
		$version = new SecretVersion();
		$version->setVersionNumber(4);
		$metadata = [];
		$dispatcher = $this->createMock(\OCP\EventDispatcher\IEventDispatcher::class);
		$dispatcher->method('dispatchTyped')->willReturnCallback(
			static function (\OCA\Keepiq\Event\Audit\AuditEvent $event) use (&$metadata): void {
				$metadata[] = $event->getMetadata();
			}
		);
		$service = new SecretVersionService(
			mapper: $this->mapper,
			secretMapper: $this->secretMapper,
			accessGuard: $this->createMock(SecretVersionAccessGuard::class),
			logger: $this->logger,
			db: $this->createMock(IDBConnection::class),
			eventDispatcher: $dispatcher,
		);

		$service->recordRestored(secret: $this->ownedSecret(), version: $version, userId: 'alice', via: 'share-save');
		$service->recordRestored(secret: $this->ownedSecret(), version: $version, userId: 'alice');

		$this->assertSame([['versionNumber' => 4, 'via' => 'share-save'], ['versionNumber' => 4]], $metadata);
	}//end testAShareSaveRestoreIsAuditedAsTheClientsClaim()

	/**
	 * deleteForSecret cascades to the mapper (idempotent by contract).
	 *
	 * @return void
	 */
	public function testDeleteForSecretCascades(): void {
		$this->mapper->expects($this->once())->method('deleteBySecret')->with(secretId: 'sec-1');
		$this->service->deleteForSecret(secretId: 'sec-1');
	}//end testDeleteForSecretCascades()

	/**
	 * A version of a foreign or missing secret is indistinguishable from
	 * a missing version.
	 *
	 * @return void
	 */
	public function testForeignVersionNotFound(): void {
		$version = new SecretVersion();
		$version->setId('v-1');
		$version->setSecretId('sec-1');
		$this->mapper->method('findById')->willReturn($version);
		$this->secretMapper->method('findById')->willThrowException(new DoesNotExistException(''));

		$this->expectException(InvalidArgumentException::class);
		$this->expectExceptionMessage('Version not found');
		$this->service->getVersion(versionId: 'v-1', userId: 'alice');
	}//end testForeignVersionNotFound()
}//end class
