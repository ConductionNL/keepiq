<?php

/**
 * Unit tests for the org password-policy settings (org-password-policies §6.1/§6.2).
 *
 * @category Test
 * @package  OCA\Keepiq\Tests\Unit\Service
 *
 * @author    Conduction Development Team <dev@conductio.nl>
 * @copyright 2024 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git-id>
 *
 * @link https://conduction.nl
 */

declare(strict_types=1);

namespace OCA\Keepiq\Tests\Unit\Service;

use InvalidArgumentException;
use OCA\Keepiq\Event\Audit\AuditEvent;
use OCA\Keepiq\Event\Audit\AuditEventTypes;
use OCA\Keepiq\Service\SettingsService;
use OCP\App\IAppManager;
use OCP\EventDispatcher\IEventDispatcher;
use OCP\IAppConfig;
use OCP\IConfig;
use OCP\IGroupManager;
use OCP\IUserSession;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;

/**
 * Tests for the policy keys of SettingsService.
 */
class SettingsServicePolicyTest extends TestCase {
	private SettingsService $service;

	private IAppConfig&MockObject $appConfig;

	private IEventDispatcher&MockObject $dispatcher;

	/**
	 * The mutable fake config store.
	 *
	 * @var array<string,mixed>
	 */
	private array $store = [];

	/**
	 * Build the service with a mutable IAppConfig fake.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		parent::setUp();
		$this->store = [];
		$this->appConfig = $this->createMock(originalClassName: IAppConfig::class);
		$this->appConfig->method('getValueBool')->willReturnCallback(
			fn (string $app, string $key, bool $default = false): bool => (bool)($this->store[$key] ?? $default)
		);
		$this->appConfig->method('getValueInt')->willReturnCallback(
			fn (string $app, string $key, int $default = 0): int => (int)($this->store[$key] ?? $default)
		);
		$this->appConfig->method('getValueString')->willReturnCallback(
			fn (string $app, string $key, string $default = ''): string => (string)($this->store[$key] ?? $default)
		);
		$this->appConfig->method('setValueBool')->willReturnCallback(
			function (string $app, string $key, bool $value): bool {
				$this->store[$key] = $value;
				return true;
			}
		);
		$this->appConfig->method('setValueInt')->willReturnCallback(
			function (string $app, string $key, int $value): bool {
				$this->store[$key] = $value;
				return true;
			}
		);
		$this->appConfig->method('setValueString')->willReturnCallback(
			function (string $app, string $key, string $value): bool {
				$this->store[$key] = $value;
				return true;
			}
		);

		$this->dispatcher = $this->createMock(originalClassName: IEventDispatcher::class);

		$this->service = new SettingsService(
			appConfig: $this->appConfig,
			config: $this->createMock(originalClassName: IConfig::class),
			appManager: $this->createMock(originalClassName: IAppManager::class),
			container: $this->createMock(originalClassName: ContainerInterface::class),
			groupManager: $this->createMock(originalClassName: IGroupManager::class),
			userSession: $this->createMock(originalClassName: IUserSession::class),
			logger: $this->createMock(originalClassName: LoggerInterface::class),
			eventDispatcher: $this->dispatcher,
		);
	}//end setUp()

	/**
	 * §1.2: a below-floor generator length is rejected with a clear message.
	 *
	 * @return void
	 */
	public function testRejectsBelowFloorGeneratorLength(): void {
		$this->expectException(InvalidArgumentException::class);
		$this->expectExceptionMessage('generator_min_length must be at least 8');
		$this->service->updateAdminSettings(data: ['generator_min_length' => 6]);
	}//end testRejectsBelowFloorGeneratorLength()

	/**
	 * §1.2: an out-of-range zxcvbn score is rejected.
	 *
	 * @return void
	 */
	public function testRejectsOutOfRangeScore(): void {
		$this->expectException(InvalidArgumentException::class);
		$this->expectExceptionMessage('min_zxcvbn_score must be between 0 and 4');
		$this->service->updateAdminSettings(data: ['min_zxcvbn_score' => 5]);
	}//end testRejectsOutOfRangeScore()

	/**
	 * §1.2: enabling the HIBP block while the breach gate is off is rejected.
	 *
	 * @return void
	 */
	public function testRejectsHibpBlockWithoutBreachGate(): void {
		$this->expectException(InvalidArgumentException::class);
		$this->expectExceptionMessage('breach_check_enabled');
		$this->service->updateAdminSettings(data: ['block_on_hibp_hit' => true]);
	}//end testRejectsHibpBlockWithoutBreachGate()

	/**
	 * §3.1: a valid policy write persists and dispatches
	 * password_policy.updated with before/after values only.
	 *
	 * @return void
	 */
	public function testPolicyWriteDispatchesAuditEvent(): void {
		$captured = null;
		$this->dispatcher->method('dispatchTyped')->willReturnCallback(
			static function ($event) use (&$captured): void {
				$captured = $event;
			}
		);

		$this->store['breach_check_enabled'] = true;
		$result = $this->service->updateAdminSettings(
			data: [
				'policy_enabled' => true,
				'generator_min_length' => 16,
				'block_on_hibp_hit' => true,
			]
		);

		$this->assertTrue($result['policy_enabled']);
		$this->assertSame(16, $result['generator_min_length']);
		$this->assertInstanceOf(AuditEvent::class, $captured);
		$this->assertSame(AuditEventTypes::PASSWORD_POLICY_UPDATED, $captured->getEventType());
		$metadata = $captured->getMetadata();
		$this->assertArrayHasKey('before', $metadata);
		$this->assertArrayHasKey('after', $metadata);
		$this->assertSame(16, $metadata['after']['generator_min_length']);
		// Never any secret material keys.
		$this->assertArrayNotHasKey('key', $metadata);
		$this->assertArrayNotHasKey('value', $metadata);
	}//end testPolicyWriteDispatchesAuditEvent()

	/**
	 * §1.3: the read-only policy exposes exactly the floor + exempt types.
	 *
	 * The two `master_password_*` entries joined this list in #192. They are
	 * floors in the same sense as the generator keys — the numbers the write
	 * dialogs must enforce — and this endpoint is the only one every
	 * authenticated user may read, so `PasswordStrengthMeter` has no other
	 * source for them. The list stays exhaustive on purpose: this assertion
	 * exists to catch an admin-only or secret-bearing key leaking into a
	 * response that any logged-in user can fetch.
	 *
	 * @return void
	 */
	public function testGetPolicyExposesFloorOnly(): void {
		$policy = $this->service->getPolicy();

		$this->assertSame(
			[
				'master_password_min_length',
				'master_password_min_score',
				'policy_enabled',
				'generator_min_length',
				'generator_require_upper',
				'generator_require_lower',
				'generator_require_digit',
				'generator_require_symbol',
				'min_zxcvbn_score',
				'block_on_hibp_hit',
				'policy_exempt_types',
			],
			array_keys($policy)
		);
		$this->assertFalse($policy['policy_enabled']);
		$this->assertContains('note', $policy['policy_exempt_types']);
	}//end testGetPolicyExposesFloorOnly()

	/**
	 * §6.2: a non-policy admin write dispatches NO policy audit event.
	 *
	 * @return void
	 */
	public function testNonPolicyWriteDispatchesNothing(): void {
		$this->dispatcher->expects($this->never())->method('dispatchTyped');
		$this->service->updateAdminSettings(data: ['ca_auto_renew_enabled' => true]);
	}//end testNonPolicyWriteDispatchesNothing()
}//end class
