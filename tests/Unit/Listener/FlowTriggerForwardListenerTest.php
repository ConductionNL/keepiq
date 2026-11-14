<?php

/**
 * Unit tests for FlowTriggerForwardListener: vault events start flows, fail-soft.
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

use OCA\Keepiq\Db\SiemQueueItemMapper;
use OCA\Keepiq\Db\SiemSinkMapper;
use OCA\Keepiq\Event\Audit\AuditEvent;
use OCA\Keepiq\Event\Audit\AuditEventTypes;
use OCA\Keepiq\Listener\FlowTriggerForwardListener;
use OCA\Keepiq\Service\Flow\VaultFlowTriggerCatalog;
use OCA\Keepiq\Service\SiemService;
use OCA\Keepiq\Service\SiemSinkService;
use OCA\Keepiq\Service\SiemTransport;
use OCP\App\IAppManager;
use OCP\EventDispatcher\Event;
use OCP\IAppConfig;
use OCP\IGroupManager;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\UsesClass;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;
use RuntimeException;

/**
 * Tests for FlowTriggerForwardListener.
 */
#[CoversClass(FlowTriggerForwardListener::class)]
#[UsesClass(VaultFlowTriggerCatalog::class)]
#[UsesClass(SiemService::class)]
#[UsesClass(AuditEvent::class)]
class FlowTriggerForwardListenerTest extends TestCase {
	/**
	 * A stand-in for OpenRegister's FlowTriggerService that records calls.
	 *
	 * @var object
	 */
	private object $flows;

	/**
	 * Build the stand-in flow trigger service.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		$this->flows = new class {
			/** @var array<int,array<int,mixed>> */
			public array $calls = [];

			/** @var bool */
			public bool $throws = false;

			/**
			 * Record a fire() call.
			 *
			 * @param string      $event   The trigger id
			 * @param array       $subject The subject
			 * @param string|null $user    The acting user
			 * @param array       $context The context
			 *
			 * @return int
			 */
			public function fire(string $event, array $subject, ?string $user, array $context): int {
				if ($this->throws === true) {
					throw new RuntimeException('engine down');
				}

				$this->calls[] = [$event, $subject, $user, $context];
				return 1;
			}
		};
	}//end setUp()

	/**
	 * Build the listener over the real SIEM payload builder and catalog.
	 *
	 * @param string          $groups       The stored enabled groups (JSON)
	 * @param bool            $openRegister Whether OpenRegister is enabled
	 * @param LoggerInterface $logger       The logger
	 *
	 * @return FlowTriggerForwardListener
	 */
	private function listener(string $groups, bool $openRegister, LoggerInterface $logger): FlowTriggerForwardListener {
		$siem = new SiemService(
			$this->createMock(SiemSinkMapper::class),
			$this->createMock(SiemQueueItemMapper::class),
			$this->createMock(SiemTransport::class),
			$this->createMock(SiemSinkService::class),
			$this->createMock(IGroupManager::class),
			null,
			$this->createMock(LoggerInterface::class),
		);
		$appConfig = $this->createMock(IAppConfig::class);
		$appConfig->method('getValueString')->willReturnCallback(
			fn (string $app, string $key, string $default) => ($app === 'keepiq' && $key === 'flow_trigger_groups') ? $groups : $default
		);
		$appManager = $this->createMock(IAppManager::class);
		$appManager->method('isEnabledForUser')->willReturnCallback(
			fn (string $appId) => ($appId === 'openregister' && $openRegister === true)
		);
		$container = $this->createMock(ContainerInterface::class);
		$container->method('get')->willReturnCallback(
			fn (string $id) => ($id === 'OCA\\OpenRegister\\Service\\Flow\\FlowTriggerService') ? $this->flows : throw new RuntimeException('unexpected ' . $id)
		);

		return new FlowTriggerForwardListener(
			siemService: $siem,
			catalog: new VaultFlowTriggerCatalog(),
			appConfig: $appConfig,
			appManager: $appManager,
			container: $container,
			logger: $logger,
		);
	}//end listener()

	/**
	 * A granted share by a user.
	 *
	 * @return AuditEvent
	 */
	private function shareGranted(): AuditEvent {
		return new AuditEvent(
			actorType: 'user',
			actorId: 'alice',
			eventType: AuditEventTypes::SHARE_GRANTED,
			objectType: 'secret',
			objectId: '42',
			objectName: 'Bank login',
			metadata: ['recipientId' => 'bob', 'password' => 'hunter2', 'username' => 'alice@bank'],
		);
	}//end shareGranted()

	/**
	 * A granted share fires keepiq.share.granted with an empty subject and
	 * the SIEM payload, without the item name or any secret material.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/clients-vault-event-flow-triggers/specs/vault-event-flow-triggers/spec.md#scenario-a-granted-share-starts-a-flow
	 */
	public function testAGrantedShareStartsAFlow(): void {
		$logger = $this->createMock(LoggerInterface::class);
		$logger->expects($this->never())->method('error');
		$this->listener('["sharing"]', true, $logger)->handle($this->shareGranted());

		$this->assertCount(1, $this->flows->calls);
		[$trigger, $subject, $user, $context] = $this->flows->calls[0];
		$this->assertSame('keepiq.share.granted', $trigger);
		$this->assertSame([], $subject);
		$this->assertSame('alice', $user);
		$payload = $context['payload'];
		$this->assertSame('share.granted', $payload['eventType']);
		$this->assertSame('alice', $payload['actorId']);
		$this->assertSame('42', $payload['objectId']);
		$this->assertSame('bob', $payload['metadata']['recipientId']);
		$this->assertArrayHasKey('occurredAt', $payload);
		$encoded = (string)json_encode($payload);
		$this->assertStringNotContainsString('Bank login', $encoded);
		$this->assertStringNotContainsString('hunter2', $encoded);
		$this->assertStringNotContainsString('alice@bank', $encoded);
	}//end testAGrantedShareStartsAFlow()

	/**
	 * Nothing fires while the event's group is off, by default included.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/clients-vault-event-flow-triggers/specs/vault-event-flow-triggers/spec.md#scenario-a-fresh-install-fires-nothing
	 */
	public function testNothingFiresWhileTheGroupIsOff(): void {
		$logger = $this->createMock(LoggerInterface::class);
		$this->listener('[]', true, $logger)->handle($this->shareGranted());
		$this->listener('["emergency_access"]', true, $logger)->handle($this->shareGranted());
		$this->listener('["emergency_access"]', true, $logger)->handle(
			new AuditEvent(actorType: 'user', actorId: 'alice', eventType: AuditEventTypes::SECRET_CREATED, objectType: 'secret', objectId: '7')
		);
		$this->assertSame([], $this->flows->calls);

		$this->listener('["emergency_access"]', true, $logger)->handle(
			new AuditEvent(actorType: 'user', actorId: 'bob', eventType: AuditEventTypes::EMERGENCY_ACCESS_REQUESTED, objectType: 'emergency_access', objectId: '3')
		);
		$this->assertSame('keepiq.emergency_access.requested', $this->flows->calls[0][0]);
	}//end testNothingFiresWhileTheGroupIsOff()

	/**
	 * Without OpenRegister nothing is fetched, fired or logged.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/clients-vault-event-flow-triggers/specs/vault-event-flow-triggers/spec.md#scenario-a-vault-on-a-plain-nextcloud
	 */
	public function testWithoutOpenRegisterNothingHappens(): void {
		$logger = $this->createMock(LoggerInterface::class);
		$logger->expects($this->never())->method($this->anything());
		$this->listener('["sharing"]', false, $logger)->handle($this->shareGranted());
		$this->assertSame([], $this->flows->calls);
	}//end testWithoutOpenRegisterNothingHappens()

	/**
	 * A throwing flow engine is logged and does not reach the caller.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/clients-vault-event-flow-triggers/specs/vault-event-flow-triggers/spec.md#scenario-a-broken-flow-engine-does-not-block-a-share
	 */
	public function testABrokenFlowEngineIsLoggedNotThrown(): void {
		$this->flows->throws = true;
		$logger = $this->createMock(LoggerInterface::class);
		$logger->expects($this->once())->method('error')->with(
			'Keepiq: flow trigger forward failed: engine down',
			['app' => 'keepiq']
		);
		$this->listener('["sharing"]', true, $logger)->handle($this->shareGranted());
	}//end testABrokenFlowEngineIsLoggedNotThrown()

	/**
	 * A non-user actor fires with no user; other events are ignored.
	 *
	 * @return void
	 */
	public function testANonUserActorFiresWithoutAUserAndOtherEventsAreIgnored(): void {
		$logger = $this->createMock(LoggerInterface::class);
		$listener = $this->listener('["applications"]', true, $logger);
		$listener->handle(new Event());
		$listener->handle(
			new AuditEvent(actorType: 'application', actorId: 'app-1', eventType: AuditEventTypes::APPLICATION_REGISTERED, objectType: 'application', objectId: '9')
		);
		$this->assertCount(1, $this->flows->calls);
		$this->assertNull($this->flows->calls[0][2]);
	}//end testANonUserActorFiresWithoutAUserAndOtherEventsAreIgnored()
}//end class
