<?php

declare(strict_types=1);

namespace OCA\Keepiq\Tests\Unit\Service;

use InvalidArgumentException;
use OCA\Keepiq\Db\EncryptionSuite;
use OCA\Keepiq\Db\EncryptionSuiteMapper;
use OCA\Keepiq\Db\Secret;
use OCA\Keepiq\Db\SecretDelegationMapper;
use OCA\Keepiq\Db\SecretMapper;
use OCA\Keepiq\Db\ShareTargetMapper;
use OCA\Keepiq\Service\ShareAuthorizationService;
use OCA\Keepiq\Service\ShareSyncService;
use OCA\Keepiq\Service\TeamFolderService;
use OCP\AppFramework\Db\DoesNotExistException;
use OCP\IDBConnection;
use PHPUnit\Framework\TestCase;

/**
 * A manager can run a value update fan-out like an editor; a viewer cannot
 * (sharing-team-folder-manager-role task 1.2).
 *
 * @spec openspec/changes/archive/2026-10-04-sharing-team-folder-manager-role/tasks.md#task-1.2
 */
class ManagerWritesLikeAnEditorTest extends TestCase {

	private function sync(string $grade): ShareSyncService {
		$source = new Secret();
		$source->setId('src');
		$source->setOwnerType('user');
		$source->setOwnerId('alice');
		$secrets = $this->createMock(SecretMapper::class);
		$secrets->method('findById')->willReturn($source);
		$delegations = $this->createMock(SecretDelegationMapper::class);
		$delegations->method('findActiveBySecretAndUser')->willThrowException(new DoesNotExistException(''));
		$teamFolders = $this->createMock(TeamFolderService::class);
		$teamFolders->method('resolveGrade')->willReturn($grade);
		$targets = $this->createMock(ShareTargetMapper::class);
		$targets->method('findBySourceSecret')->willReturn([]);
		$targets->method('findByRecipientSecret')->willThrowException(new DoesNotExistException(''));
		$suite = new EncryptionSuite();
		$suite->setCertificate('OWNER-CERT');
		$suites = $this->createMock(EncryptionSuiteMapper::class);
		$suites->method('findActiveByOwner')->willReturn($suite);

		return new ShareSyncService(
			mapper: $targets,
			secretMapper: $secrets,
			suiteMapper: $suites,
			db: $this->createMock(IDBConnection::class),
			auth: new ShareAuthorizationService(
				secretMapper: $secrets,
				delegationMapper: $delegations,
				suiteMapper: $this->createMock(EncryptionSuiteMapper::class),
				teamFolderService: $teamFolders,
			),
		);
	}

	public function testAManagerMaySync(): void {
		$this->assertSame(1, $this->sync('manage')->syncUpdate('src', [['secretId' => 'src', 'key' => 'NEW']], '', 'olga'));
	}

	public function testAViewerMayNot(): void {
		$this->expectException(InvalidArgumentException::class);
		$this->sync('read')->syncUpdate('src', [['secretId' => 'src', 'key' => 'NEW']], '', 'vic');
	}

	public function testAManagerGetsTheOwnerCertificateForTheFanOut(): void {
		$context = $this->sync('manage')->writeContext('src', 'olga');
		$this->assertSame('manage', $context['effectiveGrade']);
		$this->assertSame('OWNER-CERT', $context['ownerCertificate']);
	}
}
