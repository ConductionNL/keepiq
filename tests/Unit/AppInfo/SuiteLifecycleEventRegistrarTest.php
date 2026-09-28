<?php

/**
 * Tests for the suite-lifecycle listener graph.
 *
 * @category Test
 * @package  OCA\Keepiq\Tests\Unit\AppInfo
 *
 * @author    Conduction Development Team <dev@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://conduction.nl
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Keepiq\Tests\Unit\AppInfo;

use OCA\Keepiq\AppInfo\SuiteLifecycleEventRegistrar;
use OCA\Keepiq\Event\EncryptionSuiteRevokedEvent;
use OCA\Keepiq\Listener\EncryptionSuiteRevokedListener;
use OCA\Keepiq\Listener\SuiteCompromiseOnRevokeListener;
use OCP\AppFramework\Bootstrap\IRegistrationContext;
use PHPUnit\Framework\TestCase;
use Symfony\Component\EventDispatcher\EventDispatcher as SymfonyEventDispatcher;

/**
 * The order two listeners on the revoke event run in is load-bearing.
 *
 * `EncryptionSuiteRevokedListener` deletes every ShareTarget where the revoked
 * user was the recipient. `SuiteCompromiseOnRevokeListener` needs exactly those
 * rows to find the owners of the shared sources it must warn. Run the other way
 * round, the lookup always misses and the warning goes to the revoked user
 * instead (keepiq#802). Unit tests of the listener mock the lookup, so only the
 * registration can pin this.
 */
class SuiteLifecycleEventRegistrarTest extends TestCase {

	/**
	 * The compromise cascade runs before the share-target sweep.
	 *
	 * Nextcloud hands registrations to the dispatcher in the order they were
	 * made (RegistrationContext::delegateEventListenerRegistrations), and its
	 * EventDispatcher forwards each priority to Symfony's dispatcher, which
	 * decides the run order. The test dispatches through Symfony's dispatcher.
	 *
	 * @return void
	 */
	public function testTheCompromiseCascadeRunsBeforeTheShareTargetSweep(): void {
		$registrations = [];
		$context = $this->createMock(IRegistrationContext::class);
		$context->method('registerEventListener')->willReturnCallback(
			static function (string $event, string $listener, int $priority = 0) use (&$registrations): void {
				$registrations[] = ['event' => $event, 'listener' => $listener, 'priority' => $priority];
			}
		);

		(new SuiteLifecycleEventRegistrar())->register(context: $context);

		// Hand the registrations to the REAL Symfony dispatcher that Nextcloud's
		// EventDispatcher::addServiceListener() forwards to, in registration
		// order and with their priorities, and record the order they run in.
		// That observes the dispatcher's own priority and tie-break rules
		// instead of restating them here (#805 review).
		$dispatcher = new SymfonyEventDispatcher();
		$runOrder = [];
		foreach ($registrations as $registration) {
			$dispatcher->addListener(
				$registration['event'],
				static function () use (&$runOrder, $registration): void {
					$runOrder[] = $registration['listener'];
				},
				$registration['priority']
			);
		}

		$dispatcher->dispatch(
			new EncryptionSuiteRevokedEvent(
				suiteId: 'suite-1',
				ownerType: 'user',
				ownerId: 'alice',
				revokedBy: 'admin',
				compromised: true
			),
			EncryptionSuiteRevokedEvent::class
		);

		$cascade = array_search(SuiteCompromiseOnRevokeListener::class, $runOrder, true);
		$sweep = array_search(EncryptionSuiteRevokedListener::class, $runOrder, true);
		$this->assertNotFalse($cascade, 'the compromise cascade must be registered on the revoke event');
		$this->assertNotFalse($sweep, 'the share-target sweep must be registered on the revoke event');
		$this->assertLessThan(
			$sweep,
			$cascade,
			'SuiteCompromiseOnRevokeListener must run before EncryptionSuiteRevokedListener deletes the ShareTargets it reads'
		);

	}//end testTheCompromiseCascadeRunsBeforeTheShareTargetSweep()

}//end class
