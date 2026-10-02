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
use OCA\Keepiq\Listener\EmergencyAccessSuiteRevocationListener;
use OCA\Keepiq\Listener\EncryptionSuiteRevokedListener;
use OCP\AppFramework\Bootstrap\IRegistrationContext;
use PHPUnit\Framework\TestCase;

/**
 * The revoke event carries no compromise cascade.
 *
 * The administrator's compromise cascade reads the ShareTargets and emergency
 * contacts that the revoke listeners delete. As a listener on the same event it
 * depended on running first, and it still missed the second suite of a
 * migration, whose rows the FIRST revoke had already swept (keepiq#864). It now
 * runs from CompromiseContainmentService, which collects before any revoke.
 * This test keeps it from being wired back onto the event.
 */
class SuiteLifecycleEventRegistrarTest extends TestCase {

	/**
	 * Only the sweep and the emergency-access cleanup listen to a revoke.
	 *
	 * @return void
	 */
	public function testTheRevokeEventHasOnlyTheCleanupListeners(): void {
		$registrations = [];
		$context = $this->createMock(IRegistrationContext::class);
		$context->method('registerEventListener')->willReturnCallback(
			static function (string $event, string $listener, int $priority = 0) use (&$registrations): void {
				$registrations[] = ['event' => $event, 'listener' => $listener];
			}
		);

		(new SuiteLifecycleEventRegistrar())->register(context: $context);

		$onRevoke = array_values(
			array_map(
				static fn (array $registration): string => $registration['listener'],
				array_filter(
					$registrations,
					static fn (array $registration): bool => $registration['event'] === EncryptionSuiteRevokedEvent::class
				)
			)
		);
		sort($onRevoke);

		$this->assertSame(
			[EmergencyAccessSuiteRevocationListener::class, EncryptionSuiteRevokedListener::class],
			$onRevoke
		);

	}//end testTheRevokeEventHasOnlyTheCleanupListeners()

}//end class
