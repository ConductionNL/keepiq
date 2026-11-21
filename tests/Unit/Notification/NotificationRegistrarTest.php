<?php

/**
 * Unit tests for NotificationRegistrar.
 *
 * @category Test
 * @package  OCA\Keepiq\Tests\Unit\Notification
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

namespace OCA\Keepiq\Tests\Unit\Notification;

use OCA\Keepiq\Notification\KeepiqNotifier;
use OCA\Keepiq\Notification\NotificationRegistrar;
use OCP\AppFramework\Bootstrap\IRegistrationContext;
use OCP\Notification\INotifier;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

/**
 * The registrar must hand Nextcloud exactly one notifier, and it must be the
 * one that renders Keepiq's subjects: any other class leaves every Keepiq
 * notification unrendered.
 */
#[CoversClass(NotificationRegistrar::class)]
class NotificationRegistrarTest extends TestCase {

	/**
	 * register() registers KeepiqNotifier as the notifier service, once, and
	 * registers nothing else.
	 *
	 * @return void
	 */
	public function testRegistersKeepiqNotifierOnceAndNothingElse(): void {
		$registered = [];
		$context = $this->createMock(originalClassName: IRegistrationContext::class);
		$context->expects($this->once())
			->method('registerNotifierService')
			->willReturnCallback(
				static function (string $class) use (&$registered): void {
					$registered[] = $class;
				}
			);
		$context->expects($this->never())->method('registerService');
		$context->expects($this->never())->method('registerEventListener');
		$context->expects($this->never())->method('registerMiddleware');

		(new NotificationRegistrar())->register(context: $context);

		$this->assertSame(expected: [KeepiqNotifier::class], actual: $registered);
	}//end testRegistersKeepiqNotifierOnceAndNothingElse()

	/**
	 * The class handed to Nextcloud is a real INotifier, so the notification
	 * manager can instantiate and call it.
	 *
	 * @return void
	 */
	public function testTheRegisteredClassIsANotifier(): void {
		$registered = null;
		$context = $this->createMock(originalClassName: IRegistrationContext::class);
		$context->method('registerNotifierService')->willReturnCallback(
			static function (string $class) use (&$registered): void {
				$registered = $class;
			}
		);

		(new NotificationRegistrar())->register(context: $context);

		$this->assertIsString(actual: $registered);
		$this->assertContains(needle: INotifier::class, haystack: class_implements($registered));
	}//end testTheRegisteredClassIsANotifier()
}//end class
