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
	 * made, and the dispatcher runs higher priorities first and equal priorities
	 * in insertion order. This resolves the registrar's calls the same way.
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

		$onRevoke = array_values(array_filter(
			$registrations,
			static fn (array $r): bool => $r['event'] === EncryptionSuiteRevokedEvent::class
		));
		$order = array_keys($onRevoke);
		usort(
			$order,
			static fn (int $a, int $b): int => [$onRevoke[$b]['priority'], $a] <=> [$onRevoke[$a]['priority'], $b]
		);
		$runOrder = array_map(static fn (int $i): string => $onRevoke[$i]['listener'], $order);

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
