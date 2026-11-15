<?php

/**
 * Unit tests for AuditStreamEventRegistrar's flow trigger bindings.
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

use OCA\Keepiq\Event\Audit\AuditEvent;
use OCA\Keepiq\Listener\AuditListener;
use OCA\Keepiq\Listener\AuditStreamEventRegistrar;
use OCA\Keepiq\Listener\FlowTriggerCatalogListener;
use OCA\Keepiq\Listener\FlowTriggerForwardListener;
use OCA\Keepiq\Listener\HoneyTripwireListener;
use OCA\Keepiq\Listener\SiemForwardListener;
use OCP\AppFramework\Bootstrap\IRegistrationContext;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

/**
 * Tests for AuditStreamEventRegistrar.
 */
#[CoversClass(AuditStreamEventRegistrar::class)]
class AuditStreamEventRegistrarTest extends TestCase {
	/**
	 * The forwarder sits next to the SIEM listener on the AuditEvent, and the
	 * catalog listener on OpenRegister's catalog event, by name.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/clients-vault-event-flow-triggers/tasks.md#task-1.3
	 */
	public function testTheFlowListenersAreBoundNextToTheSiemListener(): void {
		$bound = [];
		$context = $this->createMock(IRegistrationContext::class);
		$context->method('registerEventListener')->willReturnCallback(
			function (string $event, string $listener) use (&$bound): void {
				$bound[] = [$event, $listener];
			}
		);
		(new AuditStreamEventRegistrar())->register(context: $context);

		$this->assertContains([AuditEvent::class, AuditListener::class], $bound);
		$this->assertContains([AuditEvent::class, SiemForwardListener::class], $bound);
		$this->assertContains([AuditEvent::class, HoneyTripwireListener::class], $bound);
		$this->assertContains([AuditEvent::class, FlowTriggerForwardListener::class], $bound);
		$this->assertContains(
			['OCA\\OpenRegister\\Event\\RegisterFlowTriggersEvent', FlowTriggerCatalogListener::class],
			$bound
		);
		$this->assertSame(1, count(array_keys(array_column($bound, 1), FlowTriggerForwardListener::class)));
	}//end testTheFlowListenersAreBoundNextToTheSiemListener()
}//end class
