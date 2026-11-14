<?php

/**
 * Keepiq flow trigger catalog listener
 *
 * Contributes the vault triggers to OpenRegister's flow trigger catalog, so
 * the flow builder's trigger palette lists them under "Vault".
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

use OCA\Keepiq\Kernel\AppIdentity;
use OCA\Keepiq\Service\Flow\VaultFlowTriggerCatalog;
use OCP\EventDispatcher\Event;
use OCP\EventDispatcher\IEventListener;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * Add the vault triggers to OpenRegister's catalog (design D6).
 *
 * Bound by class name string to OpenRegister's `RegisterFlowTriggersEvent`,
 * so no OpenRegister type appears here; the event is recognised by its
 * `addTrigger(id, label, group)` method.
 *
 * @implements IEventListener<Event>
 */
class FlowTriggerCatalogListener implements IEventListener {

	/**
	 * OpenRegister's catalog extension event, by name.
	 *
	 * @var string
	 */
	public const REGISTER_EVENT = 'OCA\\OpenRegister\\Event\\RegisterFlowTriggersEvent';

	/**
	 * Constructor for FlowTriggerCatalogListener.
	 *
	 * @param VaultFlowTriggerCatalog $catalog The trigger catalog
	 * @param LoggerInterface         $logger  The logger
	 *
	 * @return void
	 */
	public function __construct(
		private VaultFlowTriggerCatalog $catalog,
		private LoggerInterface $logger,
	) {
	}//end __construct()

	/**
	 * Add every vault trigger with its label under the `Vault` group.
	 *
	 * @param Event $event The catalog registration event
	 *
	 * @return void
	 *
	 * @spec openspec/changes/clients-vault-event-flow-triggers/specs/vault-event-flow-triggers/spec.md#requirement-the-flow-builder-lists-vault-triggers
	 * @spec openspec/changes/clients-vault-event-flow-triggers/tasks.md#task-1.4
	 */
	public function handle(Event $event): void {
		if (method_exists($event, 'addTrigger') === false) {
			return;
		}

		try {
			foreach ($this->catalog->entries() as $entry) {
				$event->addTrigger($entry['id'], $entry['label'], VaultFlowTriggerCatalog::PALETTE_GROUP);
			}
		} catch (Throwable $exception) {
			// Fail-soft: a catalog contribution must never break the flow builder.
			$this->logger->error(
				'Keepiq: flow trigger catalog contribution failed: ' . $exception->getMessage(),
				['app' => AppIdentity::APP_ID]
			);
		}
	}//end handle()
}//end class
