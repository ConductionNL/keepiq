<?php

/**
 * Unit tests for FlowTriggerCatalogListener: vault triggers join the palette.
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

use OCA\Keepiq\Listener\FlowTriggerCatalogListener;
use OCA\Keepiq\Service\Flow\VaultFlowTriggerCatalog;
use OCP\EventDispatcher\Event;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\UsesClass;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use RuntimeException;

/**
 * Tests for FlowTriggerCatalogListener.
 */
#[CoversClass(FlowTriggerCatalogListener::class)]
#[UsesClass(VaultFlowTriggerCatalog::class)]
class FlowTriggerCatalogListenerTest extends TestCase {
	/**
	 * A fake catalog registry event recording addTrigger() calls.
	 *
	 * @param bool $throws Whether addTrigger() throws
	 *
	 * @return Event
	 */
	private function registry(bool $throws = false): Event {
		return new class($throws) extends Event {
			/** @var array<string,array{label:string,group:string}> */
			public array $added = [];

			/**
			 * Constructor.
			 *
			 * @param bool $throws Whether addTrigger() throws
			 */
			public function __construct(private bool $throws) {
				parent::__construct();
			}

			/**
			 * Record a contributed trigger.
			 *
			 * @param string $id    The trigger id
			 * @param string $label The label
			 * @param string $group The palette group
			 *
			 * @return void
			 */
			public function addTrigger(string $id, string $label, string $group): void {
				if ($this->throws === true) {
					throw new RuntimeException('registry closed');
				}

				$this->added[$id] = ['label' => $label, 'group' => $group];
			}
		};
	}//end registry()

	/**
	 * Every vault trigger is added under the Vault group, with its label.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/clients-vault-event-flow-triggers/specs/vault-event-flow-triggers/spec.md#scenario-an-administrator-picks-a-vault-trigger
	 */
	public function testEveryVaultTriggerIsAddedUnderVault(): void {
		$catalog = new VaultFlowTriggerCatalog();
		$event = $this->registry();
		(new FlowTriggerCatalogListener($catalog, $this->createMock(LoggerInterface::class)))->handle($event);

		$this->assertCount(count($catalog->entries()), $event->added);
		$this->assertSame(['label' => 'A share is granted', 'group' => 'Vault'], $event->added['keepiq.share.granted']);
		$this->assertSame(['Vault'], array_values(array_unique(array_column($event->added, 'group'))));
	}//end testEveryVaultTriggerIsAddedUnderVault()

	/**
	 * An event without addTrigger() is ignored; a failing registry is logged.
	 *
	 * @return void
	 */
	public function testOtherEventsAreIgnoredAndFailuresLogged(): void {
		$logger = $this->createMock(LoggerInterface::class);
		$logger->expects($this->once())->method('error')->with(
			'Keepiq: flow trigger catalog contribution failed: registry closed',
			['app' => 'keepiq']
		);
		$listener = new FlowTriggerCatalogListener(new VaultFlowTriggerCatalog(), $logger);
		$listener->handle(new Event());
		$listener->handle($this->registry(throws: true));
	}//end testOtherEventsAreIgnoredAndFailuresLogged()
}//end class
