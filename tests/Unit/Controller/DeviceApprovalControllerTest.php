<?php

declare(strict_types=1);

namespace OCA\Keepiq\Tests\Unit\Controller;

use DateTime;
use OCA\Keepiq\Controller\DeviceApprovalController;
use OCA\Keepiq\Db\DeviceApproval;
use OCA\Keepiq\Db\DeviceApprovalMapper;
use OCA\Keepiq\Event\Audit\AuditEvent;
use OCA\Keepiq\Service\DeviceApprovalService;
use OCA\Keepiq\Service\NotificationService;
use OCP\AppFramework\Db\DoesNotExistException;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\Attribute\UserRateLimit;
use OCP\EventDispatcher\IEventDispatcher;
use OCP\IAppConfig;
use OCP\IRequest;
use OCP\IUser;
use OCP\IUserSession;
use OCP\Security\ISecureRandom;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use ReflectionMethod;

/**
 * New device approval through the controller with the real service
 * (crypto-new-device-approval tasks 1.2 to 1.6). Alice owns request `r-1`;
 * Mallory is another signed-in user.
 *
 * @spec openspec/changes/crypto-new-device-approval/specs/new-device-approval/spec.md#requirement-approval-seals-the-unlock-key-and-needs-proof-of-the-master-password
 */
class DeviceApprovalControllerTest extends TestCase {

	/** @var DeviceApprovalMapper&MockObject */
	private DeviceApprovalMapper $mapper;

	/** @var NotificationService&MockObject */
	private NotificationService $notifications;

	/** @var array<int,AuditEvent> */
	private array $audits = [];

	private bool $enabled = true;

	private DeviceApproval $row;

	protected function setUp(): void {
		$this->mapper = $this->createMock(DeviceApprovalMapper::class);
		$this->notifications = $this->createMock(NotificationService::class);
		$this->row = new DeviceApproval();
		$this->row->setId('r-1');
		$this->row->setUserId('alice');
		$this->row->setStatus(DeviceApproval::STATUS_PENDING);
		$this->row->setRequestSecretHash(hash('sha256', 'the-secret'));
		$this->row->setExpiresAt(new DateTime('+10 minutes'));
		$this->mapper->method('findById')->willReturnCallback(
			fn (string $id) => ($id === 'r-1') ? $this->row : throw new DoesNotExistException('')
		);
		$this->mapper->method('insert')->willReturnArgument(0);
		$this->mapper->method('update')->willReturnArgument(0);
	}

	private function controller(string $uid, string $secretHeader = ''): DeviceApprovalController {
		$config = $this->createMock(IAppConfig::class);
		$config->method('getValueBool')->willReturnCallback(fn () => $this->enabled);
		$random = $this->createMock(ISecureRandom::class);
		$random->method('generate')->willReturn('the-secret');
		$dispatcher = $this->createMock(IEventDispatcher::class);
		$dispatcher->method('dispatchTyped')->willReturnCallback(
			function ($event): void {
				$this->audits[] = $event;
			}
		);
		$service = new DeviceApprovalService($this->mapper, $this->notifications, $config, $random, $dispatcher);

		$user = $this->createMock(IUser::class);
		$user->method('getUID')->willReturn($uid);
		$session = $this->createMock(IUserSession::class);
		$session->method('getUser')->willReturn($user);
		$request = $this->createMock(IRequest::class);
		$request->method('getRemoteAddress')->willReturn('203.0.113.7');
		$request->method('getHeader')->willReturnCallback(
			static fn (string $name): string => ($name === 'X-Keepiq-Request-Secret') ? $secretHeader : 'Firefox on Linux'
		);

		return new DeviceApprovalController($request, $service, $session);
	}

	public function testARequestIsStoredWithAHashedSecretAndNotified(): void {
		$stored = null;
		$this->mapper = $this->createMock(DeviceApprovalMapper::class);
		$this->mapper->method('insert')->willReturnCallback(
			static function (DeviceApproval $row) use (&$stored) {
				$stored = $row;
				return $row;
			}
		);
		$this->notifications->expects($this->once())->method('notify')
			->with('device_approval_requested', 'alice');

		$response = $this->controller('alice')->create(base64_encode(str_repeat('k', 32)), 'web', 'Firefox on Linux');

		$this->assertSame(Http::STATUS_CREATED, $response->getStatus());
		$this->assertSame('the-secret', $response->getData()['requestSecret']);
		$this->assertNotSame('the-secret', $stored->getRequestSecretHash());
		$this->assertSame('203.0.113.7', $stored->getRequesterIp());
		$this->assertSame(DeviceApproval::STATUS_PENDING, $stored->getStatus());
		$this->assertEqualsWithDelta(900, $stored->getExpiresAt()->getTimestamp() - time(), 5);
	}

	public function testCreationIsRefusedWhenSwitchedOffOrMalformed(): void {
		$this->mapper->expects($this->never())->method('insert');
		$this->assertSame(Http::STATUS_BAD_REQUEST, $this->controller('alice')->create('short', 'web', 'x')->getStatus());
		$this->assertSame(
			Http::STATUS_BAD_REQUEST,
			$this->controller('alice')->create(base64_encode(str_repeat('k', 32)), 'cli', 'x')->getStatus()
		);
		$this->enabled = false;
		$this->assertSame(
			Http::STATUS_FORBIDDEN,
			$this->controller('alice')->create(base64_encode(str_repeat('k', 32)), 'web', 'x')->getStatus()
		);
	}

	public function testCreationIsRateLimitedToThreeAnHour(): void {
		$attributes = (new ReflectionMethod(DeviceApprovalController::class, 'create'))->getAttributes(UserRateLimit::class);
		$this->assertCount(1, $attributes);
		$this->assertSame(['limit' => 3, 'period' => 3600], $attributes[0]->getArguments());
	}

	public function testAnotherUsersRequestAnswersLikeAnUnknownOne(): void {
		$this->mapper->expects($this->never())->method('update');
		$foreign = $this->controller('mallory');
		$unknown = $foreign->approve('nope', 'SEALED');
		$this->assertSame(Http::STATUS_NOT_FOUND, $unknown->getStatus());
		$this->assertSame($unknown->getData(), $foreign->approve('r-1', 'SEALED')->getData());
		$this->assertSame(Http::STATUS_NOT_FOUND, $foreign->deny('r-1')->getStatus());
		$this->assertSame(Http::STATUS_NOT_FOUND, $this->controller('mallory', 'the-secret')->show('r-1')->getStatus());
		$this->assertSame(DeviceApproval::STATUS_PENDING, $this->row->getStatus());
	}

	public function testAnExpiredRequestCannotBeApproved(): void {
		$this->row->setExpiresAt(new DateTime('-1 second'));
		$this->mapper->expects($this->never())->method('update');
		$this->assertSame(Http::STATUS_NOT_FOUND, $this->controller('alice')->approve('r-1', 'SEALED')->getStatus());
	}

	public function testApprovalStoresTheSealedKeyAndPickupReleasesItOnce(): void {
		$this->assertSame(Http::STATUS_BAD_REQUEST, $this->controller('alice')->approve('r-1', '')->getStatus());
		$this->assertSame(Http::STATUS_OK, $this->controller('alice')->approve('r-1', 'SEALED')->getStatus());
		$this->assertSame(DeviceApproval::STATUS_APPROVED, $this->row->getStatus());

		$this->assertSame(Http::STATUS_NOT_FOUND, $this->controller('alice', 'wrong')->show('r-1')->getStatus());
		$this->assertSame(Http::STATUS_NOT_FOUND, $this->controller('alice')->show('r-1')->getStatus());

		$first = $this->controller('alice', 'the-secret')->show('r-1')->getData();
		$this->assertSame(['status' => 'approved', 'sealedUnlockKey' => 'SEALED'], $first);
		$this->assertNull($this->row->getSealedUnlockKey());

		$second = $this->controller('alice', 'the-secret')->show('r-1')->getData();
		$this->assertSame(['status' => 'consumed'], $second);
	}

	public function testADeniedRequestNeverReleasesAKey(): void {
		$this->assertSame(Http::STATUS_OK, $this->controller('alice')->deny('r-1')->getStatus());
		$this->assertSame(Http::STATUS_NOT_FOUND, $this->controller('alice')->approve('r-1', 'SEALED')->getStatus());
		$this->assertSame(['status' => 'denied'], $this->controller('alice', 'the-secret')->show('r-1')->getData());
	}

	public function testTheJobExpiresAndDropsAnUnclaimedKeyAndAuditsHoldNoKey(): void {
		$this->row->setStatus(DeviceApproval::STATUS_APPROVED);
		$this->row->setSealedUnlockKey('SEALED');
		$mapper = $this->createMock(DeviceApprovalMapper::class);
		$mapper->method('findLapsed')->willReturn([$this->row]);
		$mapper->method('update')->willReturnArgument(0);
		$dispatcher = $this->createMock(IEventDispatcher::class);
		$dispatcher->method('dispatchTyped')->willReturnCallback(
			function ($event): void {
				$this->audits[] = $event;
			}
		);
		$service = new DeviceApprovalService(
			$mapper,
			$this->notifications,
			$this->createMock(IAppConfig::class),
			$this->createMock(ISecureRandom::class),
			$dispatcher
		);

		$this->assertSame(1, $service->expireLapsed(new DateTime()));
		$this->assertSame(DeviceApproval::STATUS_EXPIRED, $this->row->getStatus());
		$this->assertNull($this->row->getSealedUnlockKey());
		foreach ($this->audits as $event) {
			$this->assertStringNotContainsString('SEALED', json_encode($event->getMetadata()));
		}
		$this->assertSame('device_approval.expired', end($this->audits)->getEventType());
	}
}
