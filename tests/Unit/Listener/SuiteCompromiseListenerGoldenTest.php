<?php

/**
 * Golden outcomes of the compromise-migration listener.
 *
 * @category Test
 * @package  OCA\Keepiq\Tests\Unit\Listener
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

namespace OCA\Keepiq\Tests\Unit\Listener;

use DateTime;
use OCA\Keepiq\Db\RotationFlag;
use OCA\Keepiq\Db\Secret;
use OCA\Keepiq\Db\SecretMapper;
use OCA\Keepiq\Db\ShareTarget;
use OCA\Keepiq\Db\ShareTargetMapper;
use OCA\Keepiq\Event\SuiteMigrationCompletedEvent;
use OCA\Keepiq\Listener\SuiteCompromiseListener;
use OCA\Keepiq\Service\NotificationService;
use OCA\Keepiq\Service\RotationPolicyService;
use OCA\Keepiq\Tests\Support\ShareRowJudgeFixture;
use OCP\AppFramework\Db\DoesNotExistException;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use RuntimeException;

/**
 * Which owners are told about which secrets, what is stamped and flagged,
 * and what a failure logs, in order.
 *
 * @covers \OCA\Keepiq\Listener\SuiteCompromiseListener
 * @uses   \OCA\Keepiq\Db\RotationFlag
 * @uses   \OCA\Keepiq\Db\Secret
 * @uses   \OCA\Keepiq\Db\ShareTarget
 * @uses   \OCA\Keepiq\Db\StringPrimaryKey
 * @uses   \OCA\Keepiq\Event\SuiteMigrationCompletedEvent
 * @uses   \OCA\Keepiq\Event\SuiteMigrationEvent
 * @uses   \OCA\Keepiq\Listener\CompromiseNotices
 * @uses   \OCA\Keepiq\Service\ShareRowJudge
 * @uses   \OCA\Keepiq\Service\ShareRowVerdict
 */
final class SuiteCompromiseListenerGoldenTest extends TestCase {
	use ShareRowJudgeFixture;

	/**
	 * Every collaborator call, in order.
	 *
	 * @var array<int,array<int,mixed>>
	 */
	private array $calls = [];

	/**
	 * One migration over flagged and unflagged, own and shared, and ownerless
	 * secrets, with one notification per owner.
	 *
	 * @return void
	 */
	public function testNotifiesEachOwnerOnceAboutTheirOwnSecret(): void {
		$this->listener(failNotify: null)->handle(new SuiteMigrationCompletedEvent(oldSuiteId: 'old', newSuiteId: 'new', migrationId: 'mig-1'));

		self::assertSame(
			[
				['findByEncryptionSuiteId', 'new'],
				['flag', 'own-a', 'suite_compromise'],
				['findByRecipientSecret', 'own-a'],
				['notify', 'secret_compromised', 'alice', ['oldSuiteId' => 'old', 'newSuiteId' => 'new', 'migrationId' => 'mig-1', 'secret_id' => 'own-a', 'secret_name' => 'Secret own-a'], 'secret', 'own-a'],
				['flag', 'copy-b', 'suite_compromise'],
				['findByRecipientSecret', 'copy-b'],
				['findById', 'src'],
				['update', 'src'],
				['flag', 'src', 'suite_compromise'],
				['flag', 'nobody', 'suite_compromise'],
				['findByRecipientSecret', 'nobody'],
				['flag', 'own-b', 'suite_compromise'],
				['findByRecipientSecret', 'own-b'],
				['notify', 'secret_compromised', 'bob', ['oldSuiteId' => 'old', 'newSuiteId' => 'new', 'migrationId' => 'mig-1', 'secret_id' => 'own-b', 'secret_name' => 'Secret own-b'], 'secret', 'own-b'],
			],
			$this->calls
		);
	}//end testNotifiesEachOwnerOnceAboutTheirOwnSecret()

	/**
	 * A failing notification stops the walk and is logged.
	 *
	 * @return void
	 */
	public function testAFailingNotificationStopsTheWalkAndIsLogged(): void {
		$this->listener(failNotify: 'alice')->handle(new SuiteMigrationCompletedEvent(oldSuiteId: 'old', newSuiteId: 'new', migrationId: 'mig-1'));

		self::assertSame(
			[
				['findByEncryptionSuiteId', 'new'],
				['flag', 'own-a', 'suite_compromise'],
				['findByRecipientSecret', 'own-a'],
				['notify', 'secret_compromised', 'alice', ['oldSuiteId' => 'old', 'newSuiteId' => 'new', 'migrationId' => 'mig-1', 'secret_id' => 'own-a', 'secret_name' => 'Secret own-a'], 'secret', 'own-a'],
				['warning', 'Keepiq: SuiteCompromiseListener failed: notifications down', ['app' => 'keepiq']],
			],
			$this->calls
		);
	}//end testAFailingNotificationStopsTheWalkAndIsLogged()

	/**
	 * The listener over recording collaborators.
	 *
	 * @param string|null $failNotify The recipient whose notification throws, or null
	 *
	 * @return SuiteCompromiseListener
	 */
	private function listener(?string $failNotify): SuiteCompromiseListener {
		$secrets = [
			$this->secret(id: 'own-a', ownerId: 'alice', compromised: true),
			$this->secret(id: 'clean', ownerId: 'alice', compromised: false),
			$this->secret(id: 'copy-b', ownerId: 'bob', compromised: true),
			$this->secret(id: 'nobody', ownerId: '', compromised: true),
			$this->secret(id: 'own-b', ownerId: 'bob', compromised: true),
		];
		$source = $this->secret(id: 'src', ownerId: 'alice', compromised: false);
		$secretMapper = $this->createMock(SecretMapper::class);
		$secretMapper->method('findByEncryptionSuiteId')->willReturnCallback(function (string $suiteId) use ($secrets): array {
			$this->calls[] = ['findByEncryptionSuiteId', $suiteId];
			return $secrets;
		});
		$secretMapper->method('findById')->willReturnCallback(function (string $id) use ($source): Secret {
			$this->calls[] = ['findById', $id];
			return $source;
		});
		$secretMapper->method('update')->willReturnCallback(function (Secret $secret): Secret {
			$this->calls[] = ['update', $secret->getId()];
			return $secret;
		});
		$targets = $this->createMock(ShareTargetMapper::class);
		$targets->method('findByRecipientSecret')->willReturnCallback(function (string $secretId): ShareTarget {
			$this->calls[] = ['findByRecipientSecret', $secretId];
			if ($secretId !== 'copy-b') {
				throw new DoesNotExistException('not a copy');
			}

			$row = new ShareTarget();
			$row->setId('row-1');
			$row->setSourceSecretId('src');
			$row->setTargetUserId('bob');
			$row->setSecretId('copy-b');
			return $row;
		});
		$rotation = $this->createMock(RotationPolicyService::class);
		$rotation->method('flag')->willReturnCallback(function (string $secretId, string $reason): RotationFlag {
			$this->calls[] = ['flag', $secretId, $reason];
			return new RotationFlag();
		});
		$notifications = $this->createMock(NotificationService::class);
		$notifications->method('notify')->willReturnCallback(
			function (string $subject, string $recipientId, array $params, string $objectType, string $objectId) use ($failNotify): bool {
				$this->calls[] = ['notify', $subject, $recipientId, $params, $objectType, $objectId];
				if ($recipientId === $failNotify) {
					throw new RuntimeException('notifications down');
				}

				return true;
			}
		);
		$logger = $this->createMock(LoggerInterface::class);
		$logger->method('warning')->willReturnCallback(function (string $message, array $context = []): void {
			$this->calls[] = ['warning', $message, $context];
		});

		return new SuiteCompromiseListener(
			secretMapper: $secretMapper,
			rowJudge: $this->shareRowJudge(targets: $targets, secrets: $secretMapper),
			notificationService: $notifications,
			logger: $logger,
			rotationService: $rotation
		);
	}//end listener()

	/**
	 * A secret.
	 *
	 * @param string $id          The id
	 * @param string $ownerId     The owner
	 * @param bool   $compromised Whether it is stamped possibly compromised
	 *
	 * @return Secret
	 */
	private function secret(string $id, string $ownerId, bool $compromised): Secret {
		$secret = new Secret();
		$secret->setId($id);
		$secret->setName('Secret ' . $id);
		$secret->setOwnerType('user');
		$secret->setOwnerId($ownerId);
		if ($compromised === true) {
			$secret->setPossiblyCompromisedAt(new DateTime('2026-01-01T00:00:00Z'));
		}

		return $secret;
	}//end secret()
}//end class
