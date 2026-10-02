<?php

declare(strict_types=1);

namespace OCA\Keepiq\Tests\Unit\Controller;

use DateTime;
use OCA\Keepiq\Controller\ShareController;
use OCA\Keepiq\Controller\UseOnlyController;
use OCA\Keepiq\Db\EncryptionSuite;
use OCA\Keepiq\Db\EncryptionSuiteMapper;
use OCA\Keepiq\Db\GroupShareMapper;
use OCA\Keepiq\Db\Secret;
use OCA\Keepiq\Db\SecretDelegationMapper;
use OCA\Keepiq\Db\SecretMapper;
use OCA\Keepiq\Db\ShareTarget;
use OCA\Keepiq\Db\ShareTargetMapper;
use OCA\Keepiq\Event\Audit\AuditEvent;
use OCA\Keepiq\Service\DirectShareRegistrar;
use OCA\Keepiq\Service\NotificationService;
use OCA\Keepiq\Service\RecipientSecretCopyFactory;
use OCA\Keepiq\Service\ShareAuthorizationService;
use OCA\Keepiq\Service\ShareRestrictionResolver;
use OCA\Keepiq\Service\ShareRevocationService;
use OCA\Keepiq\Service\ShareService;
use OCA\Keepiq\Service\ShareSyncService;
use OCA\Keepiq\Service\TeamFolderQueryService;
use OCA\Keepiq\Service\UseOnlyUseRecorder;
use OCP\AppFramework\Db\DoesNotExistException;
use OCP\AppFramework\Http;
use OCP\EventDispatcher\IEventDispatcher;
use OCP\IDBConnection;
use OCP\IGroupManager;
use OCP\IRequest;
use OCP\IUser;
use OCP\IUserSession;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

/**
 * The server-side refusals of use-only and expiring shares, driven through
 * the controllers with the real services behind them
 * (sharing-use-only-and-expiring-shares tasks 2.1, 3.1, 3.2 and 3.3).
 *
 * Alice owns `src`. Bob holds `copy`, his recipient copy of it. Mallory
 * holds nothing.
 *
 * @spec openspec/changes/sharing-use-only-and-expiring-shares/specs/use-only-shares/spec.md#requirement-the-server-refuses-what-it-can-enforce
 */
class UseOnlyShareRefusalTest extends TestCase {

	/** @var ShareTargetMapper&MockObject */
	private ShareTargetMapper $targets;

	/** @var SecretMapper&MockObject */
	private SecretMapper $secrets;

	/** @var EncryptionSuiteMapper&MockObject */
	private EncryptionSuiteMapper $suites;

	/** @var array<string,Secret> */
	private array $rows = [];

	private ShareTarget $share;

	protected function setUp(): void {
		$this->targets = $this->createMock(ShareTargetMapper::class);
		$this->secrets = $this->createMock(SecretMapper::class);
		$this->suites  = $this->createMock(EncryptionSuiteMapper::class);

		$this->rows['src']  = $this->secret('src', 'alice');
		$this->rows['copy'] = $this->secret('copy', 'bob');
		$this->secrets->method('findById')->willReturnCallback(
			function (string $id): Secret {
				return $this->rows[$id] ?? throw new DoesNotExistException('no');
			}
		);

		$suite = new EncryptionSuite();
		$suite->setCertificate('CERT');
		$this->suites->method('findActiveByOwner')->willReturn($suite);

		$this->share = new ShareTarget();
		$this->share->setId('share-1');
		$this->share->setSourceSecretId('src');
		$this->share->setTargetUserId('bob');
		$this->share->setSecretId('copy');
		$this->targets->method('findById')->willReturn($this->share);
		$this->targets->method('findBySourceSecretAndTargetUser')->willThrowException(new DoesNotExistException('none'));
		$this->targets->method('findByRecipientSecret')->willReturnCallback(
			fn (string $id): ShareTarget => ($id === 'copy') ? $this->share : throw new DoesNotExistException('none')
		);
	}

	private function secret(string $id, string $owner): Secret {
		$secret = new Secret();
		$secret->setId($id);
		$secret->setName('Supplier portal');
		$secret->setOwnerType('user');
		$secret->setOwnerId($owner);
		$secret->setUpdatedAt(new DateTime('2026-10-01T00:00:00Z'));
		return $secret;
	}

	private function session(string $uid): IUserSession {
		$user = $this->createMock(IUser::class);
		$user->method('getUID')->willReturn($uid);
		$session = $this->createMock(IUserSession::class);
		$session->method('getUser')->willReturn($user);
		return $session;
	}

	private function shareController(string $uid): ShareController {
		$delegations = $this->createMock(SecretDelegationMapper::class);
		$delegations->method('findActiveBySecretAndUser')->willThrowException(new DoesNotExistException('none'));
		$auth = new ShareAuthorizationService(
			secretMapper: $this->secrets,
			delegationMapper: $delegations,
			suiteMapper: $this->suites,
		);
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

		return new ShareController($this->createMock(IRequest::class), $service, $this->session($uid));
	}

	/**
	 * The recipient cannot change the restriction of a share made to them.
	 *
	 * @return void
	 */
	public function testTheRecipientCannotChangeTheRestriction(): void {
		$this->targets->expects($this->never())->method('update');

		$response = $this->shareController('bob')->update('share-1', false, null);

		$this->assertSame(Http::STATUS_FORBIDDEN, $response->getStatus());
	}

	/**
	 * A stranger cannot either.
	 *
	 * @return void
	 */
	public function testANonOwnerCannotChangeTheRestriction(): void {
		$this->targets->expects($this->never())->method('update');

		$response = $this->shareController('mallory')->update('share-1', true, '2099-01-01T00:00:00Z');

		$this->assertSame(Http::STATUS_FORBIDDEN, $response->getStatus());
	}

	/**
	 * The owner can, and the copy follows.
	 *
	 * @return void
	 */
	public function testTheOwnerSetsUseOnlyAndTheCopyFollows(): void {
		$this->targets->expects($this->once())->method('update')->willReturnArgument(0);
		$this->secrets->expects($this->once())->method('update')->with($this->identicalTo($this->rows['copy']));

		$response = $this->shareController('alice')->update('share-1', true, '2099-01-01T00:00:00Z');

		$this->assertSame(Http::STATUS_OK, $response->getStatus());
		$this->assertTrue($this->rows['copy']->getUseOnly());
		$this->assertSame('2099-01-01T00:00:00+00:00', $this->rows['copy']->getAccessExpiresAt()?->format('c'));
	}

	/**
	 * A past end date is a bad request, for the owner too.
	 *
	 * @return void
	 */
	public function testAPastEndDateIsABadRequest(): void {
		$this->targets->expects($this->never())->method('update');
		$this->targets->expects($this->never())->method('insert');

		$controller = $this->shareController('alice');
		$this->assertSame(Http::STATUS_BAD_REQUEST, $controller->update('share-1', false, '2020-01-01T00:00:00Z')->getStatus());
		$this->assertSame(
			Http::STATUS_BAD_REQUEST,
			$controller->create('src', 'carla', 'copy-c', null, false, '2020-01-01T00:00:00Z')->getStatus()
		);
	}

	/**
	 * Bob cannot share his use-only copy onward, by any of the three share
	 * routes, even from a modified client.
	 *
	 * @return void
	 */
	public function testAUseOnlyCopyCannotBeSharedOnward(): void {
		$this->rows['copy']->setUseOnly(true);
		$this->targets->expects($this->never())->method('insert');
		$controller = $this->shareController('bob');

		$this->assertSame(Http::STATUS_BAD_REQUEST, $controller->create('copy', 'bob-private', 'copy-x')->getStatus());

		$batch = $controller->registerBatch(
			[['sourceSecretId' => 'copy', 'targetUserId' => 'bob-private', 'encryptedKey' => 'CIPHER']]
		);
		$this->assertSame('restricted', $batch->getData()['items'][0]['status']);

		$this->assertSame(
			Http::STATUS_BAD_REQUEST,
			$controller->createBatch('copy', [['targetUserId' => 'x', 'recipientSecretId' => 'y']], 'gs-1')->getStatus()
		);
	}

	/**
	 * An expiring copy cannot extend itself by being shared onward.
	 *
	 * @return void
	 */
	public function testAnExpiringCopyCannotBeSharedOnward(): void {
		$this->rows['copy']->setAccessExpiresAt(new DateTime('2099-01-01T00:00:00Z'));
		$this->targets->expects($this->never())->method('insert');

		$batch = $this->shareController('bob')->registerBatch(
			[['sourceSecretId' => 'copy', 'targetUserId' => 'bob-private', 'encryptedKey' => 'CIPHER']]
		);

		$this->assertSame('restricted', $batch->getData()['items'][0]['status']);
	}

	/**
	 * Bob cannot overwrite his use-only copy through the sync route.
	 *
	 * @return void
	 */
	public function testAUseOnlyCopyCannotBeSynced(): void {
		$this->rows['copy']->setUseOnly(true);
		$this->secrets->expects($this->never())->method('update');

		$response = $this->shareController('bob')->sync('copy', [['secretId' => 'copy', 'key' => 'NEW']]);

		$this->assertSame(Http::STATUS_CONFLICT, $response->getStatus());
	}

	private function useController(string $uid, IEventDispatcher $dispatcher): UseOnlyController {
		return new UseOnlyController(
			$this->createMock(IRequest::class),
			new UseOnlyUseRecorder($this->secrets, $this->targets, $dispatcher),
			$this->session($uid),
		);
	}

	/**
	 * Only the holder of a use-only copy may report a use of it; everyone
	 * else gets the answer an unknown id gets, and nothing is recorded.
	 *
	 * @return void
	 */
	public function testAUseIsRecordedOnlyForTheHolderOfAUseOnlyCopy(): void {
		$dispatcher = $this->createMock(IEventDispatcher::class);
		$dispatcher->expects($this->never())->method('dispatchTyped');
		$this->secrets->expects($this->never())->method('markUsed');

		$this->rows['copy']->setUseOnly(true);
		$this->assertSame(Http::STATUS_NOT_FOUND, $this->useController('mallory', $dispatcher)->used('copy')->getStatus());
		$this->assertSame(Http::STATUS_NOT_FOUND, $this->useController('alice', $dispatcher)->used('src')->getStatus());
		$this->assertSame(Http::STATUS_NOT_FOUND, $this->useController('bob', $dispatcher)->used('nope')->getStatus());

		$this->rows['copy']->setUseOnly(false);
		$this->assertSame(Http::STATUS_NOT_FOUND, $this->useController('bob', $dispatcher)->used('copy')->getStatus());

		$this->rows['copy']->setUseOnly(true);
		$this->rows['copy']->setAccessExpiresAt(new DateTime('2020-01-01T00:00:00Z'));
		$this->assertSame(Http::STATUS_NOT_FOUND, $this->useController('bob', $dispatcher)->used('copy')->getStatus());
	}

	/**
	 * Bob's use lands in the owner's activity of the source, identifiers only.
	 *
	 * @return void
	 */
	public function testTheOwnerSeesTheUseOnTheSource(): void {
		$this->rows['copy']->setUseOnly(true);
		$recorded = null;
		$dispatcher = $this->createMock(IEventDispatcher::class);
		$dispatcher->expects($this->once())->method('dispatchTyped')->willReturnCallback(
			function (AuditEvent $event) use (&$recorded): void {
				$recorded = $event;
			}
		);

		$response = $this->useController('bob', $dispatcher)->used('copy');

		$this->assertSame(Http::STATUS_OK, $response->getStatus());
		$this->assertInstanceOf(AuditEvent::class, $recorded);
		$this->assertSame('secret.used', $recorded->getEventType());
		$this->assertSame('src', $recorded->getObjectId());
		$this->assertSame('bob', $recorded->getActorId());
		$this->assertSame(['copyId' => 'copy'], $recorded->getMetadata());
	}
}
