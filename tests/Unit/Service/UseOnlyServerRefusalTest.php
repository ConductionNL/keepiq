<?php

declare(strict_types=1);

namespace OCA\Keepiq\Tests\Unit\Service;

use DateTime;
use OCA\Keepiq\Db\BulkGrantShareTargetMapper;
use OCA\Keepiq\Db\EncryptionSuiteMapper;
use OCA\Keepiq\Db\GroupShareMapper;
use OCA\Keepiq\Db\LinkShareMapper;
use OCA\Keepiq\Db\Secret;
use OCA\Keepiq\Db\SecretDelegationMapper;
use OCA\Keepiq\Db\SecretMapper;
use OCA\Keepiq\Db\SecretVersion;
use OCA\Keepiq\Db\SecretVersionMapper;
use OCA\Keepiq\Db\ShareTargetMapper;
use OCA\Keepiq\Exception\ForbiddenException;
use OCA\Keepiq\Exception\NotFoundException;
use OCA\Keepiq\Service\GroupShareService;
use OCA\Keepiq\Service\LinkShareService;
use OCA\Keepiq\Service\MigrationService;
use OCA\Keepiq\Service\NotificationService;
use OCA\Keepiq\Service\OnwardShareGuard;
use OCA\Keepiq\Service\SecretService;
use OCA\Keepiq\Service\SecretTypeService;
use OCA\Keepiq\Service\SecretVersionAccessGuard;
use OCA\Keepiq\Service\ShareRestriction;
use OCA\Keepiq\Service\ShareRevocationService;
use OCA\Keepiq\Service\WriteLockService;
use OCP\IGroupManager;
use OCP\Share\IManager as IShareManager;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

/**
 * The holder-side refusals of a use-only copy (no edit, no version reveal,
 * no link, no group share) and the read-path end of an expiring copy
 * (sharing-use-only-and-expiring-shares tasks 3.1, 3.2 and 5.1).
 *
 * @spec openspec/changes/sharing-use-only-and-expiring-shares/specs/use-only-shares/spec.md#requirement-the-server-refuses-what-it-can-enforce
 */
class UseOnlyServerRefusalTest extends TestCase {

	/** @var SecretMapper&MockObject */
	private SecretMapper $secrets;

	private Secret $copy;

	protected function setUp(): void {
		$this->secrets = $this->createMock(SecretMapper::class);
		$this->copy = new Secret();
		$this->copy->setId('copy');
		$this->copy->setName('Supplier portal');
		$this->copy->setOwnerType('user');
		$this->copy->setOwnerId('bob');
		$this->secrets->method('findById')->willReturn($this->copy);
	}

	private function secretService(): SecretService {
		return new SecretService(
			mapper: $this->secrets,
			typeService: $this->createMock(SecretTypeService::class),
			suiteMapper: $this->createMock(EncryptionSuiteMapper::class),
			migrationService: $this->createMock(MigrationService::class),
			linkShareService: $this->createMock(LinkShareService::class),
			logger: $this->createMock(LoggerInterface::class),
		);
	}

	/**
	 * Bob cannot edit his use-only copy, not even its name.
	 *
	 * @return void
	 */
	public function testTheHolderCannotEditAUseOnlyCopy(): void {
		$this->copy->setUseOnly(true);
		$this->secrets->expects($this->never())->method('update');
		$this->expectException(ForbiddenException::class);

		$this->secretService()->update('copy', ['name' => 'Mine now'], 'bob');
	}

	/**
	 * A copy whose access ended answers as an unknown secret.
	 *
	 * @return void
	 */
	public function testAnExpiredCopyAnswersAsUnknown(): void {
		$this->copy->setAccessExpiresAt(new DateTime('-1 second'));
		$this->expectException(NotFoundException::class);

		$this->secretService()->get('copy', 'bob');
	}

	/**
	 * Before its end it is served as usual.
	 *
	 * @return void
	 */
	public function testACopyBeforeItsEndIsServed(): void {
		$this->copy->setAccessExpiresAt(new DateTime('+1 hour'));

		$this->assertSame($this->copy, $this->secretService()->get('copy', 'bob'));
	}

	/**
	 * Bob gets no version ciphertext of his use-only copy.
	 *
	 * @return void
	 */
	public function testVersionsOfAUseOnlyCopyAreRefused(): void {
		$this->copy->setUseOnly(true);
		$version = new SecretVersion();
		$version->setSecretId('copy');
		$versions = $this->createMock(SecretVersionMapper::class);
		$versions->method('findById')->willReturn($version);
		$guard = new SecretVersionAccessGuard($versions, $this->secrets, $this->createMock(EncryptionSuiteMapper::class));

		$this->expectExceptionMessage('Versions of a use-only copy are not available');
		$guard->requireReadableVersion('v-1', 'bob');
	}

	/**
	 * Bob cannot make a public link from his use-only copy.
	 *
	 * @return void
	 */
	public function testALinkFromAUseOnlyCopyIsRefused(): void {
		$this->copy->setUseOnly(true);
		$links = $this->createMock(LinkShareMapper::class);
		$links->expects($this->never())->method('insert');
		$service = new LinkShareService(
			mapper: $links,
			logger: $this->createMock(LoggerInterface::class),
			writeLockService: $this->createMock(WriteLockService::class),
			secretMapper: $this->secrets,
		);

		$this->expectExceptionMessage(OnwardShareGuard::REFUSAL);
		$service->create('copy', 'BLOB', 'SALT', 'suite-1', 1, null, 'bob');
	}

	/**
	 * Bob cannot share his expiring copy with a group.
	 *
	 * @return void
	 */
	public function testAGroupShareFromAnExpiringCopyIsRefused(): void {
		$this->copy->setAccessExpiresAt(new DateTime('+1 day'));
		$groupShares = $this->createMock(GroupShareMapper::class);
		$groupShares->expects($this->never())->method('insert');
		$service = new GroupShareService(
			mapper: $groupShares,
			shareTargetMapper: $this->createMock(ShareTargetMapper::class),
			bulkGrantMapper: $this->createMock(BulkGrantShareTargetMapper::class),
			secretMapper: $this->secrets,
			suiteMapper: $this->createMock(EncryptionSuiteMapper::class),
			delegationMapper: $this->createMock(SecretDelegationMapper::class),
			groupManager: $this->createMock(IGroupManager::class),
			notificationService: $this->createMock(NotificationService::class),
			logger: $this->createMock(LoggerInterface::class),
			shareManager: $this->createMock(IShareManager::class),
			revocationService: $this->createMock(ShareRevocationService::class),
		);

		$this->expectExceptionMessage(OnwardShareGuard::REFUSAL);
		$service->createGroupShare('copy', 'friends', 'bob', new ShareRestriction());
	}
}
