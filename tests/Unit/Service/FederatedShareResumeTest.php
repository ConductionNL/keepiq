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
 * @spec openspec/specs/federated-sharing/spec.md#requirement-the-owner-resumes-a-suspended-partner-share
 */

declare(strict_types=1);

namespace OCA\Keepiq\Tests\Unit\Service;

use Closure;
use DateTime;
use DateTimeZone;
use InvalidArgumentException;
use OCA\Keepiq\Db\FederatedShare;
use OCA\Keepiq\Db\FederatedShareMapper;
use OCA\Keepiq\Db\SecretVersionLock;
use OCA\Keepiq\Exception\NotFoundException;
use OCA\Keepiq\Service\FederatedNotificationDelivery;
use OCA\Keepiq\Service\FederatedShareResume;
use OCA\Keepiq\Service\FederatedShareService;
use OCA\Keepiq\Service\ShareSave\ShareSaveRefusal;
use OCP\AppFramework\Db\DoesNotExistException;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

class FederatedShareResumeTest extends TestCase {
	private const SHARE_ID = '6d1f6c1e-0000-4000-8000-000000000001';
	private const VERSION = '2026-11-25T10:00:00+00:00';
	private const FINGERPRINT = 'aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa';

	/**
	 * Alice's share with Bob, suspended.
	 *
	 * @var FederatedShare
	 */
	private FederatedShare $row;

	/**
	 * The share rows.
	 *
	 * @var FederatedShareMapper&MockObject
	 */
	private FederatedShareMapper&MockObject $mapper;

	/**
	 * Makes the share live and stores the value.
	 *
	 * @var FederatedShareService&MockObject
	 */
	private FederatedShareService&MockObject $shares;

	/**
	 * Tells Bob's server.
	 *
	 * @var FederatedNotificationDelivery&MockObject
	 */
	private FederatedNotificationDelivery&MockObject $delivery;

	/**
	 * The source's version under its lock; null when it is gone.
	 *
	 * @var DateTime|null
	 */
	private ?DateTime $version = null;

	/**
	 * What happened, in order.
	 *
	 * @var list<string>
	 */
	private array $steps = [];

	/**
	 * A suspended share of Alice's secret with Bob.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		parent::setUp();
		$this->row = new FederatedShare();
		$this->row->setId(self::SHARE_ID);
		$this->row->setSourceSecretId('secret-1');
		$this->row->setOwnerId('alice');
		$this->row->setStatus(FederatedShare::STATUS_SUSPENDED);
		$this->version = new DateTime(self::VERSION, new DateTimeZone('UTC'));

		$this->mapper = $this->createMock(originalClassName: FederatedShareMapper::class);
		$this->mapper->method('findById')->willReturnCallback(callback: function (string $id): FederatedShare {
			if ($id !== self::SHARE_ID) {
				throw new DoesNotExistException(msg: 'none');
			}

			return $this->row;
		});
		$this->shares = $this->createMock(originalClassName: FederatedShareService::class);
		$this->delivery = $this->createMock(originalClassName: FederatedNotificationDelivery::class);
		$this->delivery->method('deliver')->willReturnCallback(callback: function (FederatedShare $row, string $type): bool {
			$this->steps[] = 'deliver ' . $type;
			return true;
		});
	}//end setUp()

	/**
	 * The service under test, with a lock that records its transaction.
	 *
	 * @return FederatedShareResume
	 */
	private function resumes(): FederatedShareResume {
		$locks = $this->createMock(originalClassName: SecretVersionLock::class);
		$locks->method('whileLocked')->willReturnCallback(callback: function (string $id, Closure $work): mixed {
			$this->steps[] = 'lock ' . $id;
			try {
				$result = $work($this->version);
			} catch (\Throwable $exception) {
				$this->steps[] = 'rollback';
				throw $exception;
			}

			$this->steps[] = 'commit';
			return $result;
		});

		return new FederatedShareResume(shareMapper: $this->mapper, shares: $this->shares, locks: $locks, delivery: $this->delivery);
	}//end resumes()

	/**
	 * Resume as a user with a value encrypted from a version.
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
			body: ['id' => self::SHARE_ID, 'certFingerprint' => self::FINGERPRINT, 'sourceUpdatedAt' => $version, 'key' => 'new', 'login' => 'l', 'totp' => 7],
		);
	}//end resume()

	/**
	 * The share goes live with the new value for the version under the
	 * source's lock, and Bob is told after the commit.
	 *
	 * @return void
	 */
	public function testASuspendedShareResumesWithTheValueEncryptedNow(): void {
		$this->shares->expects($this->once())->method('resumeSuspended')->willReturnCallback(
			callback: function (FederatedShare $row, string $certFingerprint, array $ciphertext, DateTime $savedAt): FederatedShare {
				$this->steps[] = 'resume';
				$this->assertSame(expected: self::FINGERPRINT, actual: $certFingerprint);
				$this->assertSame(
					expected: ['key' => 'new', 'login' => 'l', 'additionalFields' => null, 'totp' => null, 'passkeys' => null],
					actual: $ciphertext
				);
				$this->assertSame(expected: strtotime(self::VERSION), actual: $savedAt->getTimestamp());
				$row->setStatus(FederatedShare::STATUS_ACTIVE);
				return $row;
			}
		);

		$row = $this->resume();

		$this->assertSame(expected: FederatedShare::STATUS_ACTIVE, actual: $row->getStatus());
		$this->assertSame(expected: ['lock secret-1', 'resume', 'commit', 'deliver SHARE_UPDATED'], actual: $this->steps);
	}//end testASuspendedShareResumesWithTheValueEncryptedNow()

	/**
	 * A share-save landed after the browser read the secret: nothing changes,
	 * and the answer names the current version.
	 *
	 * @return void
	 */
	public function testAValueFromAnOlderVersionIsRefused(): void {
		$this->shares->expects($this->never())->method('resumeSuspended');

		try {
			$this->resume(version: '2026-11-25T09:00:00+00:00');
			$this->fail(message: 'A stale value was taken');
		} catch (ShareSaveRefusal $refusal) {
			$this->assertSame(expected: 409, actual: $refusal->getStatus());
			$this->assertSame(expected: 'stale_source', actual: $refusal->getBody()['error']);
			$this->assertSame(expected: self::VERSION, actual: $refusal->getBody()['sourceUpdatedAt']);
		}

		$this->assertSame(expected: ['lock secret-1', 'rollback'], actual: $this->steps);
	}//end testAValueFromAnOlderVersionIsRefused()

	/**
	 * A secret that is gone resumes nothing.
	 *
	 * @return void
	 */
	public function testAGoneSecretResumesNothing(): void {
		$this->version = null;
		$this->shares->expects($this->never())->method('resumeSuspended');

		$this->expectException(exception: NotFoundException::class);
		$this->resume();
	}//end testAGoneSecretResumesNothing()

	/**
	 * A revoke or a decline that landed meanwhile wins, and Bob is told
	 * nothing.
	 *
	 * @return void
	 */
	public function testAShareThatChangedMeanwhileIsNotFound(): void {
		$this->shares->method('resumeSuspended')->willThrowException(exception: new NotFoundException(message: 'Share not found'));

		try {
			$this->resume();
			$this->fail(message: 'A revoked share resumed');
		} catch (NotFoundException) {
			$this->assertSame(expected: ['lock secret-1', 'rollback'], actual: $this->steps);
		}
	}//end testAShareThatChangedMeanwhileIsNotFound()

	/**
	 * The statuses that never resume.
	 *
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
	 * Only a suspended share resumes; one Bob declined while it was suspended
	 * is declined and stays so.
	 *
	 * @param string $status The share's status
	 *
	 * @return void
	 *
	 * @dataProvider notSuspended
	 *
	 * @spec openspec/specs/federated-sharing/spec.md#scenario-a-decline-while-suspended-ends-the-share
	 */
	public function testOnlyASuspendedShareResumes(string $status): void {
		$this->row->setStatus($status);

		try {
			$this->resume();
			$this->fail(message: 'A ' . $status . ' share resumed');
		} catch (NotFoundException) {
			$this->assertSame(expected: [], actual: $this->steps);
		}
	}//end testOnlyASuspendedShareResumes()

	/**
	 * Someone else's share is not found.
	 *
	 * @return void
	 */
	public function testAnotherUsersShareIsNotFound(): void {
		$this->expectException(exception: NotFoundException::class);
		$this->resume(userId: 'mallory');
	}//end testAnotherUsersShareIsNotFound()

	/**
	 * A share id that does not exist is not found.
	 *
	 * @return void
	 */
	public function testAMissingShareIsNotFound(): void {
		$this->expectException(exception: NotFoundException::class);
		$this->resumes()->resume(shareId: 'nope', userId: 'alice', body: ['sourceUpdatedAt' => self::VERSION, 'key' => 'k']);
	}//end testAMissingShareIsNotFound()

	/**
	 * Versions a resume cannot name.
	 *
	 * @return array<string,array{0:mixed}>
	 */
	public static function badVersions(): array {
		return ['absent' => [null], 'empty' => [''], 'not a time' => ['yesterday-ish?'], 'no string' => [42]];
	}//end badVersions()

	/**
	 * A resume must name the version it encrypted from.
	 *
	 * @param mixed $version What the browser sent
	 *
	 * @return void
	 *
	 * @dataProvider badVersions
	 */
	public function testAResumeWithoutAVersionIsInvalid(mixed $version): void {
		try {
			$this->resumes()->resume(shareId: self::SHARE_ID, userId: 'alice', body: ['sourceUpdatedAt' => $version, 'key' => 'k']);
			$this->fail(message: 'A resume without a version was taken');
		} catch (InvalidArgumentException $exception) {
			$this->assertSame(expected: 'invalid', actual: $exception->getMessage());
			$this->assertSame(expected: [], actual: $this->steps);
		}
	}//end testAResumeWithoutAVersionIsInvalid()
}//end class
