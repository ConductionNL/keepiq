<?php

/**
 * A read-only copy from another organisation cannot be changed or passed on
 * by its holder, through any route (sharing-federated-recipients task 3.4).
 *
 * @category Test
 * @package  OCA\Keepiq\Tests\Unit\Federation
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

namespace OCA\Keepiq\Tests\Unit\Federation;

use DateTime;
use OCA\Keepiq\Controller\DelegationController;
use OCA\Keepiq\Controller\GroupShareController;
use OCA\Keepiq\Controller\LinkShareController;
use OCA\Keepiq\Controller\SecretUpdateController;
use OCA\Keepiq\Controller\ShareController;
use OCA\Keepiq\Db\BulkGrantShareTargetMapper;
use OCA\Keepiq\Db\EncryptionSuite;
use OCA\Keepiq\Db\EncryptionSuiteMapper;
use OCA\Keepiq\Db\GroupShareMapper;
use OCA\Keepiq\Db\LinkShareMapper;
use OCA\Keepiq\Db\Secret;
use OCA\Keepiq\Db\SecretDelegationMapper;
use OCA\Keepiq\Db\SecretMapper;
use OCA\Keepiq\Db\ShareTargetMapper;
use OCA\Keepiq\Service\DelegationAuthorizer;
use OCA\Keepiq\Service\DelegationService;
use OCA\Keepiq\Service\DirectShareRegistrar;
use OCA\Keepiq\Service\EncryptionSuiteService;
use OCA\Keepiq\Service\GroupShareService;
use OCA\Keepiq\Service\LinkShareService;
use OCA\Keepiq\Service\MigrationService;
use OCA\Keepiq\Service\NotificationService;
use OCA\Keepiq\Service\RecipientSecretCopyFactory;
use OCA\Keepiq\Service\SecretService;
use OCA\Keepiq\Service\SecretTypeService;
use OCA\Keepiq\Service\ShareAuthorizationService;
use OCA\Keepiq\Service\ShareRestrictionResolver;
use OCA\Keepiq\Service\ShareRevocationService;
use OCA\Keepiq\Service\ShareService;
use OCA\Keepiq\Service\ShareSyncService;
use OCA\Keepiq\Service\TeamFolderQueryService;
use OCA\Keepiq\Service\WriteLockService;
use OCP\AppFramework\Db\DoesNotExistException;
use OCP\AppFramework\Http;
use OCP\IDBConnection;
use OCP\IGroupManager;
use OCP\IRequest;
use OCP\IURLGenerator;
use OCP\IUser;
use OCP\IUserSession;
use OCP\Share\IManager as IShareManager;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

/**
 * Bob holds a read-only copy of a secret Alice shared from another instance.
 *
 * @spec openspec/changes/sharing-federated-recipients/specs/federated-sharing/spec.md#requirement-remote-copies-are-read-only
 */
class ReadOnlyFederatedCopyTest extends TestCase {

	/** @var SecretMapper&MockObject */
	private SecretMapper $secrets;

	/** @var ShareTargetMapper&MockObject */
	private ShareTargetMapper $targets;

	/** @var EncryptionSuiteMapper&MockObject */
	private EncryptionSuiteMapper $suites;

	private Secret $copy;

	protected function setUp(): void {
		$this->secrets = $this->createMock(SecretMapper::class);
		$this->targets = $this->createMock(ShareTargetMapper::class);
		$this->suites  = $this->createMock(EncryptionSuiteMapper::class);

		// Bob owns the row; it is nobody's local share copy. Only the
		// read-only flag stands between him and every write below.
		$this->copy = new Secret();
		$this->copy->setId('copy');
		$this->copy->setName('Supplier portal');
		$this->copy->setOwnerType('user');
		$this->copy->setOwnerId('bob');
		$this->copy->setUpdatedAt(new DateTime('2026-10-01T00:00:00Z'));
		$this->copy->setReadOnly(true);
		$this->copy->setFederatedSource('alice@cloud.city.example');
		$this->secrets->method('findById')->willReturn($this->copy);

		$suite = new EncryptionSuite();
		$suite->setId('suite-bob');
		$suite->setCertificate('CERT');
		$this->suites->method('findActiveByOwner')->willReturn($suite);

		$this->targets->method('findBySourceSecretAndTargetUser')->willThrowException(new DoesNotExistException('none'));
		$this->targets->method('findByRecipientSecret')->willThrowException(new DoesNotExistException('not a copy'));
		$this->targets->method('findBySourceSecret')->willReturn([]);

		// Nothing may be written anywhere.
		$this->secrets->expects($this->never())->method('update');
		$this->secrets->expects($this->never())->method('insert');
		$this->targets->expects($this->never())->method('insert');
	}

	private function session(): IUserSession {
		$user = $this->createMock(IUser::class);
		$user->method('getUID')->willReturn('bob');
		$session = $this->createMock(IUserSession::class);
		$session->method('getUser')->willReturn($user);
		return $session;
	}

	private function auth(): ShareAuthorizationService {
		$delegations = $this->createMock(SecretDelegationMapper::class);
		$delegations->method('findActiveBySecretAndUser')->willThrowException(new DoesNotExistException('none'));
		return new ShareAuthorizationService(
			secretMapper: $this->secrets,
			delegationMapper: $delegations,
			suiteMapper: $this->suites,
			shareTargetMapper: $this->targets,
		);
	}

	private function shareController(): ShareController {
		$auth = $this->auth();
		$groupShares = $this->createMock(GroupShareMapper::class);
		$groupShares->method('findBySecret')->willReturn([]);
		$teamFolders = $this->createMock(TeamFolderQueryService::class);
		$teamFolders->method('coveringMemberships')->willReturn([]);
		$resolver = new ShareRestrictionResolver(
			secretMapper: $this->secrets,
			shareTargetMapper: $this->targets,
			groupShareMapper: $groupShares,
			teamFolders: $teamFolders,
			groupManager: $this->createMock(IGroupManager::class),
		);
		$notifications = $this->createMock(NotificationService::class);
		$db = $this->createMock(IDBConnection::class);

		$service = new ShareService(
			mapper: $this->targets,
			db: $db,
			notificationService: $notifications,
			auth: $auth,
			directRegistrar: new DirectShareRegistrar(
				mapper: $this->targets,
				secretMapper: $this->secrets,
				copyFactory: new RecipientSecretCopyFactory(secretMapper: $this->secrets, suiteMapper: $this->suites),
				notificationService: $notifications,
				groupShareMapper: $groupShares,
				restrictions: $resolver,
			),
			syncService: new ShareSyncService(
				mapper: $this->targets,
				secretMapper: $this->secrets,
				suiteMapper: $this->suites,
				db: $db,
				auth: $auth,
			),
			revocationService: new ShareRevocationService(
				mapper: $this->targets,
				secretMapper: $this->secrets,
				db: $db,
				logger: $this->createMock(LoggerInterface::class),
				auth: $auth,
			),
			restrictions: $resolver,
		);

		return new ShareController($this->createMock(IRequest::class), $service, $this->session());
	}

	/**
	 * PUT /api/v1/secrets/{id}: Bob cannot change his copy, not even its name.
	 *
	 * @return void
	 */
	public function testUpdateIsForbidden(): void {
		$request = $this->createMock(IRequest::class);
		$request->method('getParam')->willReturnCallback(
			static fn (string $name, mixed $default = null): mixed => ($name === 'name') ? 'Mine now' : $default
		);
		$service = new SecretService(
			mapper: $this->secrets,
			typeService: $this->createMock(SecretTypeService::class),
			suiteMapper: $this->suites,
			migrationService: $this->createMock(MigrationService::class),
			linkShareService: $this->createMock(LinkShareService::class),
			logger: $this->createMock(LoggerInterface::class),
		);

		$response = (new SecretUpdateController($request, $service, $this->session()))->update('copy');

		$this->assertSame(Http::STATUS_FORBIDDEN, $response->getStatus());
		$this->assertSame('A copy from another organisation is read-only', $response->getData()['message']);
	}

	/**
	 * PUT /api/v1/secrets/{id}/sync: Bob cannot push new values through it.
	 *
	 * @return void
	 */
	public function testSyncIsForbidden(): void {
		$response = $this->shareController()->sync('copy', [['secretId' => 'copy', 'encryptedKey' => 'NEW']]);

		$this->assertSame(Http::STATUS_FORBIDDEN, $response->getStatus());
	}

	/**
	 * POST /api/v1/secrets/{id}/shares, .../shares/batch and the bulk
	 * register: Bob cannot share it onward with a user.
	 *
	 * @return void
	 */
	public function testSharingOnwardIsForbidden(): void {
		$controller = $this->shareController();

		$this->assertSame(Http::STATUS_FORBIDDEN, $controller->create('copy', 'carla', 'copy-c')->getStatus());
		$this->assertSame(
			Http::STATUS_FORBIDDEN,
			$controller->createBatch('copy', [['targetUserId' => 'carla', 'recipientSecretId' => 'copy-c']], 'gs-1')->getStatus()
		);

		$batch = $controller->registerBatch(
			[['sourceSecretId' => 'copy', 'targetUserId' => 'carla', 'encryptedKey' => 'CIPHER']]
		);
		$this->assertSame('restricted', $batch->getData()['items'][0]['status']);
	}

	/**
	 * POST /api/v1/secrets/{id}/group-shares: not with a group either.
	 *
	 * @return void
	 */
	public function testAGroupShareIsForbidden(): void {
		$groupShares = $this->createMock(GroupShareMapper::class);
		$groupShares->expects($this->never())->method('insert');
		$service = new GroupShareService(
			mapper: $groupShares,
			shareTargetMapper: $this->targets,
			bulkGrantMapper: $this->createMock(BulkGrantShareTargetMapper::class),
			secretMapper: $this->secrets,
			suiteMapper: $this->suites,
			delegationMapper: $this->createMock(SecretDelegationMapper::class),
			groupManager: $this->createMock(IGroupManager::class),
			notificationService: $this->createMock(NotificationService::class),
			logger: $this->createMock(LoggerInterface::class),
			shareManager: $this->createMock(IShareManager::class),
			revocationService: $this->createMock(ShareRevocationService::class),
		);

		$response = (new GroupShareController($this->createMock(IRequest::class), $service, $this->session()))
			->create('copy', 'friends');

		$this->assertSame(Http::STATUS_FORBIDDEN, $response->getStatus());
	}

	/**
	 * POST /api/v1/secrets/{id}/delegations: Bob cannot hand it to someone.
	 *
	 * @return void
	 */
	public function testADelegationIsForbidden(): void {
		$delegations = $this->createMock(SecretDelegationMapper::class);
		$delegations->expects($this->never())->method('insert');
		$service = new DelegationService(
			mapper: $delegations,
			authorizer: new DelegationAuthorizer(secretMapper: $this->secrets),
		);

		$response = (new DelegationController($this->createMock(IRequest::class), $service, $this->session()))
			->create('copy', 'mallory');

		$this->assertSame(Http::STATUS_FORBIDDEN, $response->getStatus());
	}

	/**
	 * POST /api/v1/secrets/{id}/link-shares: no public link from it.
	 *
	 * @return void
	 */
	public function testALinkShareIsForbidden(): void {
		$links = $this->createMock(LinkShareMapper::class);
		$links->expects($this->never())->method('insert');
		$service = new LinkShareService(
			mapper: $links,
			logger: $this->createMock(LoggerInterface::class),
			writeLockService: $this->createMock(WriteLockService::class),
			shareAuth: $this->auth(),
		);
		$suites = $this->createMock(EncryptionSuiteService::class);
		$suite = new EncryptionSuite();
		$suite->setId('suite-bob');
		$suites->method('getActiveSuite')->willReturn($suite);

		$response = (new LinkShareController(
			$this->createMock(IRequest::class),
			$service,
			$suites,
			$this->session(),
			$this->createMock(IURLGenerator::class),
		))->create('copy', 'BLOB', 'SALT');

		$this->assertSame(Http::STATUS_FORBIDDEN, $response->getStatus());
	}

	/**
	 * The client learns the copy is read-only and where it came from.
	 *
	 * @return void
	 */
	public function testTheCopySaysItIsReadOnly(): void {
		$json = $this->copy->jsonSerialize();

		$this->assertTrue($json['readOnly']);
		$this->assertSame('alice@cloud.city.example', $json['federatedSource']);
		$this->assertTrue($this->copy->jsonSerializeBlocked('suite_revoked')['readOnly']);
	}
}
