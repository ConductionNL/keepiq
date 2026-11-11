<?php

/**
 * Team folder fan-out tests.
 *
 * Pins what TeamFolderShareService::registerFanOutShares() makes of each
 * submitted row: the share row and copy it creates (allowed), every skip
 * and its reason (incomplete, outside the folder, for the owner, existing,
 * no copy minted), one notification per new recipient, the restriction
 * hook on each minted row, and the rollback when a write fails.
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

use OCA\Keepiq\Db\BulkGrantShareTargetMapper;
use OCA\Keepiq\Db\EncryptionSuite;
use OCA\Keepiq\Db\EncryptionSuiteMapper;
use OCA\Keepiq\Db\Secret;
use OCA\Keepiq\Db\SecretMapper;
use OCA\Keepiq\Db\ShareTarget;
use OCA\Keepiq\Db\ShareTargetMapper;
use OCA\Keepiq\Db\TeamFolder;
use OCA\Keepiq\Service\NotificationService;
use OCA\Keepiq\Service\RecipientSecretCopyService;
use OCA\Keepiq\Service\SecretTypeService;
use OCA\Keepiq\Service\ShareRestrictionResolver;
use OCA\Keepiq\Service\ShareRowJudge;
use OCA\Keepiq\Service\TeamFolderShareService;
use OCP\AppFramework\Db\DoesNotExistException;
use OCP\IDBConnection;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

/**
 * Tests the fan-out of a team folder to its members.
 */
class TeamFolderFanOutTest extends TestCase {

	/**
	 * @var ShareTargetMapper&MockObject
	 */
	private ShareTargetMapper&MockObject $targets;

	/**
	 * @var IDBConnection&MockObject
	 */
	private IDBConnection&MockObject $db;

	/**
	 * @var SecretMapper&MockObject
	 */
	private SecretMapper&MockObject $secrets;

	/**
	 * Everything recorded, in order.
	 *
	 * @var list<string>
	 */
	private array $log = [];

	/**
	 * The share rows inserted.
	 *
	 * @var list<ShareTarget>
	 */
	private array $inserted = [];

	/**
	 * The service under test.
	 *
	 * @var TeamFolderShareService
	 */
	private TeamFolderShareService $service;

	/**
	 * Wire the service over mocked mappers; bob and carol hold suites, dan does not.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		$this->targets = $this->createMock(ShareTargetMapper::class);
		$this->targets->method('findBySourceSecretAndTargetUser')->willReturnCallback(
			function (string $sourceSecretId, string $targetUserId): ShareTarget {
				if ($targetUserId === 'erin') {
					return new ShareTarget();
				}

				throw new DoesNotExistException('none');
			}
		);
		$this->targets->method('insert')->willReturnCallback(
			function (ShareTarget $target): ShareTarget {
				$this->inserted[] = $target;
				$this->log[] = 'insert share '.$target->getTargetUserId();
				return $target;
			}
		);

		$source = new Secret();
		$source->setId('sec-1');
		$source->setName('Root');
		$source->setOwnerType('user');
		$source->setOwnerId('alice');
		$this->secrets = $this->createMock(SecretMapper::class);
		$this->secrets->method('findById')->willReturnCallback(
			fn (string $id): Secret => in_array($id, ['sec-1', 'sec-2'], true) ? $source : throw new DoesNotExistException('gone')
		);
		$this->secrets->method('insert')->willReturnCallback(
			function (Secret $copy): Secret {
				if ($copy->getOwnerId() === 'boom') {
					throw new \RuntimeException('write failed');
				}

				$this->log[] = 'insert copy '.$copy->getOwnerId();
				return $copy;
			}
		);
		$suites = $this->createMock(EncryptionSuiteMapper::class);
		$suites->method('findActiveByOwner')->willReturnCallback(
			static function (string $ownerType, string $ownerId): EncryptionSuite {
				if ($ownerId === 'dan') {
					throw new DoesNotExistException('none');
				}

				return new EncryptionSuite();
			}
		);
		$types = $this->createMock(SecretTypeService::class);
		$types->method('resolveTypeForSecret')->willReturn('type-login');

		$notifications = $this->createMock(NotificationService::class);
		$notifications->method('notify')->willReturnCallback(
			function (...$args): bool {
				$this->log[] = 'notify '.json_encode($args);
				return true;
			}
		);
		$restrictions = $this->createMock(ShareRestrictionResolver::class);
		$restrictions->method('resolveMinted')->willReturnCallback(
			function (ShareTarget $target): bool {
				$this->log[] = 'restrict '.$target->getTargetUserId();
				return true;
			}
		);
		$this->db = $this->createMock(IDBConnection::class);

		$this->service = new TeamFolderShareService(
			shareTargetMapper: $this->targets,
			rowJudge: $this->createMock(ShareRowJudge::class),
			bulkGrantMapper: $this->createMock(BulkGrantShareTargetMapper::class),
			copies: new RecipientSecretCopyService(secretMapper: $this->secrets, suiteMapper: $suites, typeService: $types),
			notificationService: $notifications,
			db: $this->db,
			logger: $this->createMock(LoggerInterface::class),
			restrictions: $restrictions,
		);
	}//end setUp()

	/**
	 * The team folder alice owns.
	 *
	 * @return TeamFolder
	 */
	private function teamFolder(): TeamFolder {
		$teamFolder = new TeamFolder();
		$teamFolder->setId('tf-1');
		$teamFolder->setOwnerId('alice');
		return $teamFolder;
	}//end teamFolder()

	/**
	 * Each row is created or skipped with its reason; each new recipient is told once.
	 *
	 * @return void
	 */
	public function testEveryRowIsCreatedOrSkippedWithItsReason(): void {
		$this->db->expects($this->once())->method('beginTransaction');
		$this->db->expects($this->once())->method('commit');
		$this->db->expects($this->never())->method('rollBack');

		$result = $this->service->registerFanOutShares(
			teamFolder: $this->teamFolder(),
			shares: [
				['sourceSecretId' => 'sec-1', 'targetUserId' => 'bob', 'encryptedKey' => 'K-B', 'encryptedLogin' => '', 'encryptedAdditionalFields' => 'F'],
				['sourceSecretId' => 'sec-2', 'targetUserId' => 'bob', 'encryptedKey' => 'K-B2'],
				['sourceSecretId' => 'sec-1', 'targetUserId' => 'carol', 'encryptedKey' => 'K-C'],
				['sourceSecretId' => 'sec-1', 'targetUserId' => 'carol', 'encryptedKey' => ''],
				['sourceSecretId' => '', 'targetUserId' => 'carol', 'encryptedKey' => 'K'],
				['targetUserId' => 'carol', 'encryptedKey' => 'K'],
				['sourceSecretId' => 'sec-9', 'targetUserId' => 'carol', 'encryptedKey' => 'K'],
				['sourceSecretId' => 'sec-1', 'targetUserId' => 'alice', 'encryptedKey' => 'K'],
				['sourceSecretId' => 'sec-1', 'targetUserId' => 'erin', 'encryptedKey' => 'K'],
				['sourceSecretId' => 'sec-1', 'targetUserId' => 'dan', 'encryptedKey' => 'K'],
				['sourceSecretId' => 'sec-3', 'targetUserId' => 'bob', 'encryptedKey' => 'K'],
			],
			subtreeSecretIds: ['sec-1' => true, 'sec-2' => true, 'sec-3' => true],
			userId: 'alice'
		);

		$this->assertSame(3, $result['created']);
		$this->assertSame(['bob', 'bob', 'carol'], array_column($result['rows'], 'targetUserId'));
		$this->assertSame(['sec-1', 'sec-2', 'sec-1'], array_column($result['rows'], 'sourceSecretId'));
		$this->assertSame(
			[
				['sourceSecretId' => 'sec-1', 'targetUserId' => 'carol', 'reason' => 'incomplete'],
				['sourceSecretId' => '', 'targetUserId' => 'carol', 'reason' => 'incomplete'],
				['sourceSecretId' => '', 'targetUserId' => 'carol', 'reason' => 'incomplete'],
				['sourceSecretId' => 'sec-9', 'targetUserId' => 'carol', 'reason' => 'not_in_folder'],
				['sourceSecretId' => 'sec-1', 'targetUserId' => 'alice', 'reason' => 'not_in_folder'],
				['sourceSecretId' => 'sec-1', 'targetUserId' => 'erin', 'reason' => 'exists'],
				['sourceSecretId' => 'sec-1', 'targetUserId' => 'dan', 'reason' => 'no_suite'],
				['sourceSecretId' => 'sec-3', 'targetUserId' => 'bob', 'reason' => 'not_found'],
			],
			$result['skipped']
		);

		foreach ($result['rows'] as $index => $row) {
			$target = $this->inserted[$index];
			$this->assertSame($row['recipientSecretId'], $target->getSecretId());
			$this->assertSame($row['sourceSecretId'], $target->getSourceSecretId());
			$this->assertSame($row['targetUserId'], $target->getTargetUserId());
			$this->assertSame('tf-1', $target->getTeamFolderId());
			$this->assertSame('alice', $target->getCreatedBy());
			$this->assertNotNull($target->getCreatedAt());
			$this->assertMatchesRegularExpression('/^[0-9a-f-]{36}$/', $target->getId());
		}

		$notify = '["team_folder_shared","%s",{"teamFolderId":"tf-1","sharedBy":"alice"},"team_folder","tf-1"]';
		$this->assertSame(
			[
				'insert copy bob',
				'insert share bob',
				'restrict bob',
				'insert copy bob',
				'insert share bob',
				'restrict bob',
				'insert copy carol',
				'insert share carol',
				'restrict carol',
				'notify '.sprintf($notify, 'bob'),
				'notify '.sprintf($notify, 'carol'),
			],
			$this->log
		);
	}//end testEveryRowIsCreatedOrSkippedWithItsReason()

	/**
	 * A failing write rolls the whole fan-out back and tells nobody.
	 *
	 * @return void
	 */
	public function testAFailingWriteRollsBackAndNotifiesNobody(): void {
		$this->db->expects($this->once())->method('beginTransaction');
		$this->db->expects($this->never())->method('commit');
		$this->db->expects($this->once())->method('rollBack');
		try {
			$this->service->registerFanOutShares(
				teamFolder: $this->teamFolder(),
				shares: [
					['sourceSecretId' => 'sec-1', 'targetUserId' => 'bob', 'encryptedKey' => 'K'],
					['sourceSecretId' => 'sec-1', 'targetUserId' => 'boom', 'encryptedKey' => 'K'],
				],
				subtreeSecretIds: ['sec-1' => true],
				userId: 'alice'
			);
			$this->fail('The failed write was swallowed');
		} catch (\RuntimeException $e) {
			$this->assertSame('write failed', $e->getMessage());
		}

		$this->assertSame(['insert copy bob', 'insert share bob', 'restrict bob'], $this->log);
	}//end testAFailingWriteRollsBackAndNotifiesNobody()
}//end class
