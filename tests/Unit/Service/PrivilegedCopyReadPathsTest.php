<?php

/**
 * The read paths that serve a recipient's copy consult the privileged copy gate.
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

use OCA\Keepiq\Controller\ExtensionController;
use OCA\Keepiq\Db\EncryptionSuite;
use OCA\Keepiq\Db\EncryptionSuiteMapper;
use OCA\Keepiq\Db\FolderMapper;
use OCA\Keepiq\Db\Secret;
use OCA\Keepiq\Db\SecretMapper;
use OCA\Keepiq\Db\SecretTypeMapper;
use OCA\Keepiq\Db\ShareTargetMapper;
use OCA\Keepiq\Event\Audit\AuditEventFactory;
use OCA\Keepiq\Exception\ForbiddenException;
use OCA\Keepiq\Exception\PrivilegedAccessRequiredException;
use OCA\Keepiq\Service\AdminSettingsService;
use OCA\Keepiq\Service\LinkShareService;
use OCA\Keepiq\Service\MigrationService;
use OCA\Keepiq\Service\OfflineEditGuard;
use OCA\Keepiq\Service\OfflineManifestService;
use OCA\Keepiq\Service\PrivilegedAccess\PrivilegedCopyGate;
use OCA\Keepiq\Service\SecretService;
use OCA\Keepiq\Service\SecretTypeService;
use OCA\Keepiq\Service\TwoFactorGate;
use OCA\Keepiq\Service\ViewOnlyCopyGuard;
use OCP\App\IAppManager;
use OCP\IDBConnection;
use OCP\IRequest;
use OCP\IUser;
use OCP\IUserSession;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\UsesClass;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

/**
 * @spec openspec/specs/user-sharing/spec.md#requirement-use-of-a-privileged-login-needs-an-approved-time-window
 */
#[CoversClass(SecretService::class)]
#[CoversClass(ExtensionController::class)]
#[CoversClass(OfflineManifestService::class)]
#[UsesClass(Secret::class)]
#[UsesClass(EncryptionSuite::class)]
#[UsesClass(AuditEventFactory::class)]
#[UsesClass(OfflineEditGuard::class)]
#[UsesClass(ForbiddenException::class)]
#[UsesClass(PrivilegedAccessRequiredException::class)]
class PrivilegedCopyReadPathsTest extends TestCase {

	/**
	 * The secrets.
	 *
	 * @var SecretMapper&MockObject
	 */
	private SecretMapper $secrets;

	/**
	 * The suites.
	 *
	 * @var EncryptionSuiteMapper&MockObject
	 */
	private EncryptionSuiteMapper $suites;

	/**
	 * The gate.
	 *
	 * @var PrivilegedCopyGate&MockObject
	 */
	private PrivilegedCopyGate $gate;

	/**
	 * Bob's copy of a privileged login.
	 *
	 * @var Secret
	 */
	private Secret $copy;

	/**
	 * Set up bob's copy behind an active suite, and a gate that withholds it.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		$this->copy = new Secret();
		$this->copy->setId('c1');
		$this->copy->setName('Production database');
		$this->copy->setUrl('https://db.example.com');
		$this->copy->setKey('CIPHERTEXT');
		$this->copy->setEncryptionSuiteId('suite-1');
		$this->copy->setOwnerType('user');
		$this->copy->setOwnerId('bob');

		$suite = new EncryptionSuite();
		$suite->setId('suite-1');
		$suite->setStatus('active');
		$this->suites = $this->createMock(EncryptionSuiteMapper::class);
		$this->suites->method('findById')->willReturn($suite);
		$this->suites->method('findActiveByOwner')->willReturn($suite);

		$this->secrets = $this->createMock(SecretMapper::class);
		$this->secrets->method('findById')->with('c1')->willReturn($this->copy);
		$this->secrets->method('findByOwner')->willReturn([$this->copy]);
		$this->secrets->method('countByOwner')->willReturn(1);
		$this->secrets->method('searchByNameOrUrl')->willReturn([$this->copy]);

		$this->gate = $this->createMock(PrivilegedCopyGate::class);
	}//end setUp()

	/**
	 * The secret service, with the gate wired.
	 *
	 * @return SecretService
	 */
	private function secretService(): SecretService {
		$migrations = $this->createMock(MigrationService::class);
		$migrations->method('isWriteLocked')->willReturn(false);

		return new SecretService(
			mapper: $this->secrets,
			typeService: $this->createMock(SecretTypeService::class),
			suiteMapper: $this->suites,
			migrationService: $migrations,
			linkShareService: $this->createMock(LinkShareService::class),
			logger: $this->createMock(LoggerInterface::class),
			db: $this->createMock(IDBConnection::class),
			viewOnlyCopies: $this->createStub(ViewOnlyCopyGuard::class),
			privilegedGate: $this->gate,
		);
	}//end secretService()

	/**
	 * A single fetch outside the window is refused before it is read or marked used.
	 *
	 * @return void
	 */
	public function testTheDetailReadIsRefusedBeforeAnyUse(): void {
		$this->gate->expects($this->once())->method('assertServable')
			->with($this->copy, 'bob', 'web')
			->willThrowException(new PrivilegedAccessRequiredException(message: PrivilegedCopyGate::REASON));
		$this->secrets->expects($this->never())->method('markUsed');

		$this->expectException(PrivilegedAccessRequiredException::class);
		$this->secretService()->get(id: 'c1', userId: 'bob');
	}//end testTheDetailReadIsRefusedBeforeAnyUse()

	/**
	 * The vault list serves what the gate leaves of each row, without auditing.
	 *
	 * @return void
	 */
	public function testTheListServesWhatTheGateLeaves(): void {
		$this->gate->expects($this->once())->method('servable')
			->with($this->copy, $this->copy->jsonSerialize(), 'bob', 'web')
			->willReturn(['id' => 'c1', 'blocked' => true, 'privilegedAccess' => 'privileged_access_required']);

		$result = $this->secretService()->list(userId: 'bob', folderId: null, sort: 'name', direction: 'asc', page: 1, limit: 50);

		$this->assertSame([['id' => 'c1', 'blocked' => true, 'privilegedAccess' => 'privileged_access_required', 'tags' => []]], $result['items']);
	}//end testTheListServesWhatTheGateLeaves()

	/**
	 * A row its suite already blocks carries no ciphertext and skips the gate.
	 *
	 * @return void
	 */
	public function testASuiteBlockedRowSkipsTheGate(): void {
		$revoked = new EncryptionSuite();
		$revoked->setId('suite-1');
		$revoked->setStatus('revoked');
		$this->suites = $this->createMock(EncryptionSuiteMapper::class);
		$this->suites->method('findById')->willReturn($revoked);
		$this->gate->expects($this->never())->method('servable');

		$result = $this->secretService()->list(userId: 'bob', folderId: null, sort: 'name', direction: 'asc', page: 1, limit: 50);

		$this->assertTrue($result['items'][0]['blocked']);
	}//end testASuiteBlockedRowSkipsTheGate()

	/**
	 * The browser extension's fill match asks the gate, auditing, per row.
	 *
	 * @return void
	 */
	public function testTheExtensionMatchAsksTheGateAndAudits(): void {
		$user = $this->createMock(IUser::class);
		$user->method('getUID')->willReturn('bob');
		$session = $this->createMock(IUserSession::class);
		$session->method('getUser')->willReturn($user);
		$this->gate->expects($this->once())->method('servable')
			->with($this->copy, $this->copy->jsonSerialize(), 'bob', 'extension', true)
			->willReturn(['id' => 'c1', 'blocked' => true]);

		$controller = new ExtensionController(
			request: $this->createMock(IRequest::class),
			secretMapper: $this->secrets,
			userSession: $session,
			adminSettings: $this->createMock(AdminSettingsService::class),
			appManager: $this->createMock(IAppManager::class),
			privilegedGate: $this->gate,
		);

		$this->assertSame([['id' => 'c1', 'blocked' => true]], $controller->match(host: 'db.example.com')->getData()['items']);
	}//end testTheExtensionMatchAsksTheGateAndAudits()

	/**
	 * The offline snapshot caches only what the gate leaves.
	 *
	 * @return void
	 */
	public function testTheOfflineSnapshotCachesWhatTheGateLeaves(): void {
		$shareTargets = $this->createMock(ShareTargetMapper::class);
		$shareTargets->method('findByTargetUser')->willReturn([]);
		$this->gate->expects($this->once())->method('servable')
			->with($this->copy, $this->copy->jsonSerialize(), 'bob', 'offline')
			->willReturn(['id' => 'c1', 'blocked' => true]);

		$service = new OfflineManifestService(
			suiteMapper: $this->suites,
			secretMapper: $this->secrets,
			folderMapper: $this->createMock(FolderMapper::class),
			typeMapper: $this->createMock(SecretTypeMapper::class),
			twoFactor: $this->createMock(TwoFactorGate::class),
			shareTargets: $shareTargets,
			privilegedGate: $this->gate,
		);

		$this->assertSame([['id' => 'c1', 'blocked' => true, 'sourceUpdatedAt' => null]], $service->buildForUser(userId: 'bob')['secrets']);
	}//end testTheOfflineSnapshotCachesWhatTheGateLeaves()
}//end class
