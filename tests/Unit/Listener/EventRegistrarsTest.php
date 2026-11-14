<?php

/**
 * AuditStreamEventRegistrar, UserLifecycleEventRegistrar and the small
 * registrars: what each registers, and that every listener, middleware,
 * provider and check exists as the right kind of class.
 *
 * @category Test
 * @package  OCA\Keepiq\Tests\Unit\Listener
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @link https://conduction.nl
 */

declare(strict_types=1);

namespace OCA\Keepiq\Tests\Unit\Listener;

use OCA\Keepiq\Event\Audit\AuditEvent;
use OCA\Keepiq\Listener\AuditListener;
use OCA\Keepiq\Listener\AuditStreamEventRegistrar;
use OCA\Keepiq\Listener\HoneyTripwireListener;
use OCA\Keepiq\Listener\SiemForwardListener;
use OCA\Keepiq\Listener\UserLifecycleEventRegistrar;
use OCA\Keepiq\Middleware\MiddlewareRegistrar;
use OCA\Keepiq\Notification\NotificationRegistrar;
use OCA\Keepiq\Search\SearchRegistrar;
use OCA\Keepiq\SetupCheck\SetupCheckRegistrar;
use OCP\AppFramework\Bootstrap\IRegistrationContext;
use OCP\AppFramework\Middleware;
use OCP\EventDispatcher\Event;
use OCP\EventDispatcher\IEventListener;
use PHPUnit\Framework\TestCase;

/**
 * The registrars.
 */
class EventRegistrarsTest extends TestCase {
	/**
	 * Every call made on the registration context, as [method, first argument, second argument].
	 *
	 * @var array<int, array{0: string, 1: mixed, 2: mixed}>
	 */
	private array $calls = [];

	/**
	 * A context that records every registration.
	 *
	 * @return IRegistrationContext
	 */
	private function context(): IRegistrationContext {
		$context = $this->createMock(originalClassName: IRegistrationContext::class);
		foreach (['registerEventListener', 'registerMiddleware', 'registerSearchProvider', 'registerNotifierService', 'registerSetupCheck'] as $method) {
			$context->method($method)->willReturnCallback(
				function (mixed ...$arguments) use ($method): void {
					$this->calls[] = [$method, $arguments[0] ?? null, $arguments[1] ?? null];
				}
			);
		}

		return $context;
	}//end context()

	/**
	 * The listeners registered, as [event, listener] pairs.
	 *
	 * @return array<int, array{0: mixed, 1: mixed}>
	 */
	private function listeners(): array {
		$pairs = [];
		foreach ($this->calls as [$method, $event, $listener]) {
			if ($method === 'registerEventListener') {
				$pairs[] = [$event, $listener];
			}
		}

		return $pairs;
	}//end listeners()

	/**
	 * Every audit event reaches the audit log, the SIEM forwarder and the honey tripwire.
	 *
	 * @return void
	 */
	public function testEveryAuditEventReachesTheThreeAuditListeners(): void {
		(new AuditStreamEventRegistrar())->register(context: $this->context());
		$pairs = $this->listeners();
		$this->assertSame(
			expected: [
				[AuditEvent::class, AuditListener::class],
				[AuditEvent::class, SiemForwardListener::class],
				[AuditEvent::class, HoneyTripwireListener::class],
			],
			actual: array_slice($pairs, 0, 3)
		);
		$this->assertCount(expectedCount: 6, haystack: $pairs);
		foreach (array_slice($pairs, 3) as [$event, $listener]) {
			$this->assertTrue(condition: class_exists($event), message: $event);
			$this->assertSame(expected: AuditListener::class, actual: $listener);
		}
	}//end testEveryAuditEventReachesTheThreeAuditListeners()

	/**
	 * Group membership, account deletion and token invalidation each have their listener.
	 *
	 * @return void
	 */
	public function testTheUserLifecycleListeners(): void {
		(new UserLifecycleEventRegistrar())->register(context: $this->context());
		$pairs = $this->listeners();
		$this->assertCount(expectedCount: 4, haystack: $pairs);
		$this->assertSame(
			expected: ['UserAddedToGroupListener', 'UserRemovedFromGroupListener', 'UserDeletedListener', 'TokenInvalidatedListener'],
			actual: array_map(static fn (array $pair): string => substr((string)strrchr((string)$pair[1], '\\'), 1), $pairs)
		);
	}//end testTheUserLifecycleListeners()

	/**
	 * Every registered listener is an event listener, and every event an event.
	 *
	 * @return void
	 */
	public function testEveryListenerIsAnEventListener(): void {
		$context = $this->context();
		(new AuditStreamEventRegistrar())->register(context: $context);
		(new UserLifecycleEventRegistrar())->register(context: $context);
		foreach ($this->listeners() as [$event, $listener]) {
			$this->assertTrue(condition: is_subclass_of($event, Event::class), message: $event.' is not an event');
			$this->assertTrue(condition: is_subclass_of($listener, IEventListener::class), message: $listener.' is not a listener');
		}
	}//end testEveryListenerIsAnEventListener()

	/**
	 * The API contract middleware is registered alone; the platform middleware in a fixed order.
	 *
	 * @return void
	 */
	public function testTheMiddlewareOrder(): void {
		$registrar = new MiddlewareRegistrar();
		$registrar->registerApiContract(context: $this->context());
		$registrar->registerPlatformMiddleware(context: $this->context());
		$names = [];
		foreach ($this->calls as [$method, $class]) {
			$this->assertSame(expected: 'registerMiddleware', actual: $method);
			$this->assertTrue(condition: is_subclass_of($class, Middleware::class), message: $class);
			$names[] = substr((string)strrchr((string)$class, '\\'), 1);
		}

		$this->assertSame(
			expected: [
				'ApiContractMiddleware',
				'JwtAuthMiddleware',
				'VaultKeyProofMiddleware',
				'OcsRefusalMiddleware',
				'ShareScanPendingMiddleware',
				'ApiVersionMiddleware',
			],
			actual: $names
		);
	}//end testTheMiddlewareOrder()

	/**
	 * The search provider, notifier and setup check are each registered once and exist.
	 *
	 * @return void
	 */
	public function testTheSmallRegistrars(): void {
		$context = $this->context();
		(new SearchRegistrar())->register(context: $context);
		(new NotificationRegistrar())->register(context: $context);
		(new SetupCheckRegistrar())->register(context: $context);
		$this->assertSame(
			expected: ['registerSearchProvider', 'registerNotifierService', 'registerSetupCheck'],
			actual: array_column($this->calls, 0)
		);
		foreach ($this->calls as [, $class]) {
			$this->assertTrue(condition: class_exists($class), message: $class);
		}
	}//end testTheSmallRegistrars()
}//end class
