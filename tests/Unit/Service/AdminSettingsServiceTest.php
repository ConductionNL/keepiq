<?php

/**
 * Unit tests for the auto-confirm policy switch (admin-auto-confirm-members §1.1).
 *
 * @category Test
 * @package  OCA\Keepiq\Tests\Unit\Service
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

namespace OCA\Keepiq\Tests\Unit\Service;

use OCA\Keepiq\Event\Audit\AuditEvent;
use OCA\Keepiq\Event\Audit\AuditEventTypes;
use OCA\Keepiq\Service\AdminSettingsService;
use OCP\App\IAppManager;
use OCP\EventDispatcher\IEventDispatcher;
use OCP\IAppConfig;
use OCP\IUser;
use OCP\IUserSession;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;

/**
 * The switch is off by default, readable by every user, written by the
 * admin settings and audited with a before and after value.
 */
class AdminSettingsServiceTest extends TestCase {
	/** @var IAppConfig&MockObject */
	private IAppConfig $appConfig;

	/** @var array<string,bool> */
	private array $bools = [];

	/** @var array<int,AuditEvent> */
	private array $events = [];

	private AdminSettingsService $service;

	/**
	 * Build the real settings service over an in-memory bool store.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		$this->appConfig = $this->createMock(IAppConfig::class);
		$this->appConfig->method('getValueInt')->willReturnArgument(2);
		$this->appConfig->method('getValueString')->willReturnArgument(2);
		$this->appConfig->method('getValueBool')->willReturnCallback(
			fn (string $app, string $key, bool $default): bool => ($this->bools[$key] ?? $default)
		);
		$this->appConfig->method('setValueBool')->willReturnCallback(
			function (string $app, string $key, bool $value): bool {
				$this->bools[$key] = $value;
				return true;
			}
		);

		$dispatcher = $this->createMock(IEventDispatcher::class);
		$dispatcher->method('dispatchTyped')->willReturnCallback(function (object $event): void {
			if ($event instanceof AuditEvent) {
				$this->events[] = $event;
			}
		});
		$admin = $this->createMock(IUser::class);
		$admin->method('getUID')->willReturn('admin');
		$session = $this->createMock(IUserSession::class);
		$session->method('getUser')->willReturn($admin);

		$this->service = new AdminSettingsService(
			appConfig: $this->appConfig,
			appManager: $this->createMock(IAppManager::class),
			container: $this->createMock(ContainerInterface::class),
			userSession: $session,
			logger: $this->createMock(LoggerInterface::class),
			eventDispatcher: $dispatcher,
		);
	}//end setUp()

	/**
	 * Off by default, in both the admin payload and the user policy.
	 *
	 * @return void
	 */
	public function testAutoConfirmIsOffByDefault(): void {
		$this->assertFalse($this->service->getAdminSettings()['team_folder_auto_confirm']);
		$this->assertFalse($this->service->getPolicy()['team_folder_auto_confirm']);
	}//end testAutoConfirmIsOffByDefault()

	/**
	 * Scenario "Administrator turns on automatic confirmation": the policy
	 * reads true and one policy audit event records before and after.
	 *
	 * @return void
	 */
	public function testSwitchingOnIsStoredExposedAndAudited(): void {
		$this->service->updateAdminSettings(['team_folder_auto_confirm' => true]);

		$this->assertTrue($this->service->getPolicy()['team_folder_auto_confirm']);
		$this->assertCount(1, $this->events);
		$this->assertSame(AuditEventTypes::PASSWORD_POLICY_UPDATED, $this->events[0]->getEventType());
		$metadata = $this->events[0]->getMetadata();
		$this->assertSame(['team_folder_auto_confirm' => false], $metadata['before']);
		$this->assertSame(['team_folder_auto_confirm' => true], $metadata['after']);
	}//end testSwitchingOnIsStoredExposedAndAudited()
}//end class
