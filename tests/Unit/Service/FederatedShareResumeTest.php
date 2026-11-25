<?php

/**
 * The owner resumes a suspended partner share with a value encrypted now
 * (sharing-atomic-share-save task 3.4, keepiq#1262).
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
 *
 * @spec openspec/changes/sharing-atomic-share-save/specs/federated-sharing/spec.md#requirement-the-owner-resumes-a-suspended-partner-share
 */

declare(strict_types=1);

namespace OCA\Keepiq\Tests\Unit\Service;

use DateTime;
use DateTimeZone;
use InvalidArgumentException;
use OCA\Keepiq\Db\FederatedShare;
use OCA\Keepiq\Db\FederatedShareMapper;
use OCA\Keepiq\Db\SecretVersionLock;
use OCA\Keepiq\Event\Audit\AuditEventTypes;
use OCA\Keepiq\Exception\NotFoundException;
use OCA\Keepiq\Exception\PartnerUpgradeRequiredException;
use OCA\Keepiq\Service\FederatedNotificationDelivery;
use OCA\Keepiq\Service\FederatedShareAuditTrail;
use OCA\Keepiq\Service\FederatedShareResume;
use OCA\Keepiq\Service\FederatedShareService;
use OCA\Keepiq\Service\ShareSave\ShareSaveRefusal;
use OCP\AppFramework\Db\DoesNotExistException;
use OCP\IDBConnection;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

class FederatedShareResumeTest extends TestCase {
	private const SHARE_ID = '6d1f6c1e-0000-4000-8000-000000000001';
	private const VERSION = '2026-11-25T10:00:00+00:00';
	private const FINGERPRINT = 'aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa';

	private FederatedShare $row;
	private FederatedShareMapper&MockObject $mapper;
	private FederatedShareService&MockObject $shares;
	private SecretVersionLock&MockObject $locks;
	private FederatedNotificationDelivery&MockObject $delivery;
	private FederatedShareAuditTrail&MockObject $audit;
	private IDBConnection&MockObject $db;

	/** @var list<string> What happened, in order. */
	private array $steps = [];

	/** @var list<string> The audit event types recorded. */
	private array $recorded = [];

	/**
	 * A suspended share of alice's secret with bob.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		parent::setUp();
		$this->row = new FederatedShare();
		$this->row->setId(self::SHARE_ID);
		$this->row->setSourceSecretId('secret-1');
		$this->row->setOwnerId('alice');
		$this->row->setRecipientCloudId('bob@cloud.partner.example');
		$this->row->setPartnerId('p-1');
		$this->row->setStatus(FederatedShare::STATUS_SUSPENDED);
		$this->row->setKey('old-ciphertext');

		$this->mapper = $this->createMock(FederatedShareMapper::class);
		$this->mapper->method('findById')->willReturnCallback(
			fn (string $id): FederatedShare => ($id === self::SHARE_ID) ? $this->row : throw new DoesNotExistException('none')
		);
		$this->shares = $this->createMock(FederatedShareService::class);
		$this->locks = $this->createMock(SecretVersionLock::class);
		$this->locks->method('lock')->willReturnCallback(function (string $id): ?DateTime {
			$this->steps[] = 'lock ' . $id;
			return new DateTime(self::VERSION, new DateTimeZone('UTC'));
		});
		$this->delivery = $this->createMock(FederatedNotificationDelivery::class);
		$this->audit = $this->createMock(FederatedShareAuditTrail::class);
		$this->audit->method('recordOutbound')->willReturnCallback(
			function (string $eventType, FederatedShare $row, ?string $actorId): void {
				$this->recorded[] = $eventType;
				$this->assertSame('alice', $actorId);
			}
		);
		$this->db = $this->createMock(IDBConnection::class);
		$this->db->method('beginTransaction')->willReturnCallback(function (): void {
			$this->steps[] = 'begin';
		});
		$this->db->method('commit')->willReturnCallback(function (): void {
			$this->steps[] = 'commit';
		});
		$this->db->method('rollBack')->willReturnCallback(function (): void {
			$this->steps[] = 'rollback';
		});
	}//end setUp()

	/**
	 * The service under test.
	 *
	 * @return FederatedShareResume
	 */
	private function resumes(): FederatedShareResume {
		return new FederatedShareResume(
			shareMapper: $this->mapper,
			shares: $this->shares,
			locks: $this->locks,
			delivery: $this->delivery,
			audit: $this->audit,
			db: $this->db,
		);
	}//end resumes()

	/**
	 * Resume as alice with a value encrypted from `$version`.
	 *
	 * @param string $version The version the browser encrypted from
	 * @param string $userId The caller
	 *
	 * @return FederatedShare
	 */
	private function resume(string $version = self::VERSION, string $userId = 'alice'): FederatedShare {
		return $this->resumes()->resume(
			shareId: self::SHARE_ID,
			userId: $userId,
			certFingerprint: self::FINGERPRINT,
			ciphertext: ['key' => 'new-ciphertext', 'login' => null, 'additionalFields' => null],
			sourceUpdatedAt: $version,
		);
	}//end resume()

	/**
	 * The share goes live with the new value, stored for the version under
	 * the source's lock, and bob is told after the commit.
	 *
	 * @return void
	 */
	public function testASuspendedShareResumesWithTheValueEncryptedNow(): void {
		$this->mapper->expects($this->once())->method('updateIfStatus')->willReturnCallback(
			function (FederatedShare $row, string $status, string $ending): int {
				$this->steps[] = 'activate';
				$this->assertSame(FederatedShare::STATUS_ACTIVE, $row->getStatus());
				$this->assertSame(FederatedShare::STATUS_SUSPENDED, $status);
				$this->assertSame(FederatedNotificationDelivery::SHARE_UNSHARED, $ending);
				return 1;
			}
		);
		$this->shares->expects($this->once())->method('writeCiphertext')->willReturnCallback(
			function (FederatedShare $row, string $certFingerprint, array $ciphertext, DateTime $savedAt): FederatedShare {
				$this->steps[] = 'write';
				$this->assertSame(self::FINGERPRINT, $certFingerprint);
				$this->assertSame('new-ciphertext', $ciphertext['key']);
				$this->assertSame(strtotime(self::VERSION), $savedAt->getTimestamp());
				return $row;
			}
		);
		$this->delivery->expects($this->once())->method('deliver')->willReturnCallback(
			function (FederatedShare $row, string $type): bool {
				$this->steps[] = 'deliver';
				$this->assertSame(FederatedNotificationDelivery::SHARE_UPDATED, $type);
				return true;
			}
		);

		$row = $this->resume();

		$this->assertSame(FederatedShare::STATUS_ACTIVE, $row->getStatus());
		$this->assertSame(['begin', 'lock secret-1', 'activate', 'write', 'commit', 'deliver'], $this->steps);
		$this->assertSame([AuditEventTypes::FEDERATED_SHARE_RESUMED], $this->recorded);
	}//end testASuspendedShareResumesWithTheValueEncryptedNow()

	/**
	 * A share-save landed after the browser read the secret: nothing
	 * changes, and the answer names the current version.
	 *
	 * @return void
	 */
	public function testAValueFromAnOlderVersionIsRefused(): void {
		$this->mapper->expects($this->never())->method('updateIfStatus');
		$this->shares->expects($this->never())->method('writeCiphertext');
		$this->delivery->expects($this->never())->method('deliver');

		try {
			$this->resume(version: '2026-11-25T09:00:00+00:00');
			$this->fail('A stale value was taken');
		} catch (ShareSaveRefusal $refusal) {
			$this->assertSame(409, $refusal->getStatus());
			$this->assertSame('stale_source', $refusal->getBody()['error']);
			$this->assertSame('2026-11-25T10:00:00+00:00', $refusal->getBody()['sourceUpdatedAt']);
		}

		$this->assertSame(['begin', 'lock secret-1', 'rollback'], $this->steps);
		$this->assertSame(FederatedShare::STATUS_SUSPENDED, $this->row->getStatus());
		$this->assertSame([], $this->recorded);
	}//end testAValueFromAnOlderVersionIsRefused()

	/**
	 * @return array<string,array{0:string}>
	 */
	public static function notSuspended(): array {
		return [
			'active' => [FederatedShare::STATUS_ACTIVE],
			'declined, also while suspended' => [FederatedShare::STATUS_DECLINED],
			'revoked' => [FederatedShare::STATUS_REVOKED],
			'failed' => [FederatedShare::STATUS_FAILED],
		];
	}//end notSuspended()

	/**
	 * Only a suspended share resumes; a share bob declined while it was
	 * suspended is declined and stays so.
	 *
	 * @param string $status The share's status
	 *
	 * @return void
	 *
	 * @dataProvider notSuspended
	 *
	 * @spec openspec/changes/sharing-atomic-share-save/specs/federated-sharing/spec.md#scenario-a-decline-while-suspended-ends-the-share
	 */
	public function testOnlyASuspendedShareResumes(string $status): void {
		$this->row->setStatus($status);
		$this->db->expects($this->never())->method('beginTransaction');
		$this->delivery->expects($this->never())->method('deliver');

		$this->expectException(NotFoundException::class);
		$this->resume();
	}//end testOnlyASuspendedShareResumes()

	/**
	 * Someone else's share, and one that is gone, are not found.
	 *
	 * @return void
	 */
	public function testAnotherUsersShareIsNotFound(): void {
		$this->db->expects($this->never())->method('beginTransaction');

		$this->expectException(NotFoundException::class);
		$this->resume(userId: 'mallory');
	}//end testAnotherUsersShareIsNotFound()

	/**
	 * A share id that does not exist is not found.
	 *
	 * @return void
	 */
	public function testAMissingShareIsNotFound(): void {
		$this->expectException(NotFoundException::class);
		$this->resumes()->resume(
			shareId: 'nope',
			userId: 'alice',
			certFingerprint: self::FINGERPRINT,
			ciphertext: ['key' => 'k', 'login' => null, 'additionalFields' => null],
			sourceUpdatedAt: self::VERSION,
		);
	}//end testAMissingShareIsNotFound()

	/**
	 * A revoke or a decline that landed while the browser encrypted wins.
	 *
	 * @return void
	 */
	public function testARevokeThatLandedMeanwhileWins(): void {
		$this->mapper->method('updateIfStatus')->willReturn(0);
		$this->shares->expects($this->never())->method('writeCiphertext');
		$this->delivery->expects($this->never())->method('deliver');

		try {
			$this->resume();
			$this->fail('A revoked share resumed');
		} catch (NotFoundException) {
			$this->assertSame(['begin', 'lock secret-1', 'rollback'], $this->steps);
		}

		$this->assertSame([], $this->recorded);
	}//end testARevokeThatLandedMeanwhileWins()

	/**
	 * A value the partner cannot store undoes the status change too.
	 *
	 * @return void
	 */
	public function testARefusedValueLeavesTheShareSuspended(): void {
		$this->mapper->method('updateIfStatus')->willReturn(1);
		$this->shares->method('writeCiphertext')->willThrowException(new PartnerUpgradeRequiredException());
		$this->delivery->expects($this->never())->method('deliver');

		try {
			$this->resume();
			$this->fail('A refused value was taken');
		} catch (PartnerUpgradeRequiredException) {
			$this->assertSame(['begin', 'lock secret-1', 'rollback'], $this->steps);
		}

		$this->assertSame([], $this->recorded);
	}//end testARefusedValueLeavesTheShareSuspended()

	/**
	 * A secret that is gone resumes nothing.
	 *
	 * @return void
	 */
	public function testAGoneSecretResumesNothing(): void {
		$locks = $this->createMock(SecretVersionLock::class);
		$locks->method('lock')->willReturn(null);
		$this->locks = $locks;
		$this->mapper->expects($this->never())->method('updateIfStatus');

		$this->expectException(NotFoundException::class);
		$this->resume();
	}//end testAGoneSecretResumesNothing()

	/**
	 * @return array<string,array{0:string}>
	 */
	public static function badVersions(): array {
		return ['empty' => [''], 'not a time' => ['yesterday-ish?']];
	}//end badVersions()

	/**
	 * A resume must name the version it encrypted from.
	 *
	 * @param string $version What the browser sent
	 *
	 * @return void
	 *
	 * @dataProvider badVersions
	 */
	public function testAResumeWithoutAVersionIsInvalid(string $version): void {
		$this->db->expects($this->never())->method('beginTransaction');

		$this->expectException(InvalidArgumentException::class);
		$this->resume(version: $version);
	}//end testAResumeWithoutAVersionIsInvalid()
}//end class
