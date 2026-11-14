<?php

/**
 * Unit tests for the flow trigger groups setting of the Audit admin area.
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

use InvalidArgumentException;
use OCA\Keepiq\Service\AdminSettingsService;
use OCA\Keepiq\Service\Flow\VaultFlowTriggerCatalog;
use OCP\IAppConfig;
use OCP\IUserSession;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\UsesClass;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;

/**
 * Tests for the `flow_trigger_groups` audit area key.
 */
#[CoversClass(AdminSettingsService::class)]
#[UsesClass(VaultFlowTriggerCatalog::class)]
class AdminSettingsFlowTriggerGroupsTest extends TestCase {
	/** @var array<string,string> The stored string values, by key. */
	private array $stored = [];

	private AdminSettingsService $service;

	/**
	 * Build the real service over an in-memory app config.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		$appConfig = $this->createMock(IAppConfig::class);
		$appConfig->method('getValueInt')->willReturnArgument(2);
		$appConfig->method('getValueBool')->willReturnArgument(2);
		$appConfig->method('getValueString')->willReturnCallback(
			fn (string $app, string $key, string $default) => $this->stored[$key] ?? $default
		);
		$appConfig->method('setValueString')->willReturnCallback(
			function (string $app, string $key, string $value): bool {
				$this->stored[$key] = $value;
				return true;
			}
		);

		$this->service = new AdminSettingsService(
			appConfig: $appConfig,
			container: $this->createMock(ContainerInterface::class),
			userSession: $this->createMock(IUserSession::class),
			logger: $this->createMock(LoggerInterface::class),
		);
	}//end setUp()

	/**
	 * Every group starts off.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/clients-vault-event-flow-triggers/specs/vault-event-flow-triggers/spec.md#scenario-a-fresh-install-fires-nothing
	 */
	public function testEveryGroupStartsOff(): void {
		$this->assertSame([], $this->service->getAreaSettings(area: 'audit')['flow_trigger_groups']);
	}//end testEveryGroupStartsOff()

	/**
	 * An administrator round-trips the groups through the audit area, in
	 * catalog order, without touching the retention window.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/clients-vault-event-flow-triggers/tasks.md#task-2.1
	 */
	public function testAnAdministratorRoundTripsTheGroups(): void {
		$result = $this->service->updateAreaSettings(
			area: 'audit',
			data: ['flow_trigger_groups' => ['emergency_access', 'sharing']]
		);
		$this->assertSame(['sharing', 'emergency_access'], $result['flow_trigger_groups']);
		$this->assertSame('["sharing","emergency_access"]', $this->stored['flow_trigger_groups']);
		$this->assertSame(365, $result['audit_retention_days']);

		$cleared = $this->service->updateAreaSettings(area: 'audit', data: ['flow_trigger_groups' => []]);
		$this->assertSame([], $cleared['flow_trigger_groups']);
	}//end testAnAdministratorRoundTripsTheGroups()

	/**
	 * An unknown group or a non-list is refused and nothing is stored.
	 *
	 * @return void
	 */
	public function testAnUnknownGroupOrANonListIsRefused(): void {
		foreach ([['sharing', 'everything'], 'sharing', ['a' => 'sharing']] as $value) {
			try {
				$this->service->updateAreaSettings(area: 'audit', data: ['flow_trigger_groups' => $value]);
				$this->fail('flow_trigger_groups ' . json_encode($value) . ' must be refused');
			} catch (InvalidArgumentException $e) {
				$this->assertMatchesRegularExpression('/flow.trigger.group/i', $e->getMessage());
			}
		}

		$this->assertArrayNotHasKey('flow_trigger_groups', $this->stored);
	}//end testAnUnknownGroupOrANonListIsRefused()
}//end class
