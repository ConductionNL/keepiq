<?php

/**
 * Keepiq flow trigger forward listener
 *
 * Fires every audited vault event whose trigger group an administrator
 * enabled into OpenRegister's flow trigger service, with the SIEM payload.
 *
 * @category Listener
 * @package  OCA\Keepiq\Listener
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

namespace OCA\Keepiq\Listener;

use OCA\Keepiq\Event\Audit\AuditEvent;
use OCA\Keepiq\Kernel\AppIdentity;
use OCA\Keepiq\Kernel\Contracts\SiemServiceInterface;
use OCA\Keepiq\Service\Flow\VaultFlowTriggerCatalog;
use OCP\App\IAppManager;
use OCP\EventDispatcher\Event;
use OCP\EventDispatcher\IEventListener;
use OCP\IAppConfig;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * Forward audit events to OpenRegister flows (clients-vault-event-flow-triggers).
 *
 * Fail-soft like the other audit stream consumers: nothing it does can break
 * the audited action. Without OpenRegister it returns before touching any
 * OpenRegister class (ADR-006); the service is fetched by class name string.
 *
 * @implements IEventListener<AuditEvent>
 */
class FlowTriggerForwardListener implements IEventListener {

	/**
	 * The OpenRegister service that queues flow runs, by name so Keepiq never
	 * references an OpenRegister type.
	 *
	 * @var string
	 */
	public const FLOW_TRIGGER_SERVICE = 'OCA\\OpenRegister\\Service\\Flow\\FlowTriggerService';

	/**
	 * The app config key holding the enabled trigger groups (JSON list).
	 *
	 * @var string
	 */
	public const GROUPS_CONFIG_KEY = 'flow_trigger_groups';

	/**
	 * Constructor for FlowTriggerForwardListener.
	 *
	 * @param SiemServiceInterface    $siemService The SIEM payload builder
	 * @param VaultFlowTriggerCatalog $catalog     The trigger catalog
	 * @param IAppConfig              $appConfig   The app config (enabled groups)
	 * @param IAppManager             $appManager  The app manager (OpenRegister state)
	 * @param ContainerInterface      $container   The container (flow trigger service)
	 * @param LoggerInterface         $logger      The logger
	 *
	 * @return void
	 */
	public function __construct(
		private SiemServiceInterface $siemService,
		private VaultFlowTriggerCatalog $catalog,
		private IAppConfig $appConfig,
		private IAppManager $appManager,
		private ContainerInterface $container,
		private LoggerInterface $logger,
	) {
	}//end __construct()

	/**
	 * Fire `keepiq.<event type>` with an empty subject and the SIEM payload
	 * as `context.payload`, when OpenRegister is enabled and the event's group
	 * is switched on.
	 *
	 * @param Event $event The dispatched event
	 *
	 * @return void
	 *
	 * @spec openspec/changes/clients-vault-event-flow-triggers/specs/vault-event-flow-triggers/spec.md#requirement-vault-events-fire-flow-triggers
	 * @spec openspec/changes/clients-vault-event-flow-triggers/tasks.md#task-1.2
	 */
	public function handle(Event $event): void {
		if ($event instanceof AuditEvent === false) {
			return;
		}

		try {
			if ($this->appManager->isEnabledForUser('openregister') === false) {
				return;
			}

			$eventType = $event->getEventType();
			if ($this->isGroupEnabled(eventType: $eventType) === false) {
				return;
			}

			$payload = $this->siemService->buildPayload(event: $event);
			if ($payload === null) {
				return;
			}

			$user = null;
			if ($event->getActorType() === 'user') {
				$user = $event->getActorId();
			}

			$service = $this->container->get(self::FLOW_TRIGGER_SERVICE);
			$service->fire(
				$this->catalog->triggerId(eventType: $eventType),
				[],
				$user,
				['payload' => $payload]
			);
		} catch (Throwable $exception) {
			// Fail-soft: a flow engine failure must never break the audited action.
			$this->logger->error(
				'Keepiq: flow trigger forward failed: ' . $exception->getMessage(),
				['app' => AppIdentity::APP_ID]
			);
		}//end try
	}//end handle()

	/**
	 * Whether the administrator enabled the group of an event type.
	 *
	 * @param string $eventType The audit event type
	 *
	 * @return bool
	 */
	private function isGroupEnabled(string $eventType): bool {
		$group = $this->catalog->groupOf(eventType: $eventType);
		if ($group === null) {
			return false;
		}

		$stored = $this->appConfig->getValueString(AppIdentity::APP_ID, self::GROUPS_CONFIG_KEY, '[]');

		return in_array($group, $this->catalog->parseEnabledGroups(stored: $stored), true);
	}//end isGroupEnabled()
}//end class
