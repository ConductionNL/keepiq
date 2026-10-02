<?php

/**
 * Unit tests for the per-area admin settings read and write
 * (admin-scoped-roles D2, decision of 2 Oct: one route per area).
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
use OCP\App\IAppManager;
use OCP\IAppConfig;
use OCP\IUserSession;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;

/**
 * Each area reads and writes only its own keys.
 */
class AdminSettingsAreaTest extends TestCase {
	/** @var IAppConfig&MockObject */
	private IAppConfig $appConfig;

	/** @var array<string,mixed> Every key written, by key. */
	private array $written = [];

	private AdminSettingsService $service;

	/**
	 * Build the real service over an app config that records writes.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		$this->appConfig = $this->createMock(IAppConfig::class);
		$this->appConfig->method('getValueInt')->willReturnArgument(2);
		$this->appConfig->method('getValueString')->willReturnArgument(2);
		$this->appConfig->method('getValueBool')->willReturnArgument(2);
		$record = function (string $app, string $key, mixed $value): bool {
			$this->written[$key] = $value;
			return true;
		};
		$this->appConfig->method('setValueInt')->willReturnCallback($record);
		$this->appConfig->method('setValueString')->willReturnCallback($record);
		$this->appConfig->method('setValueBool')->willReturnCallback($record);

		$this->service = new AdminSettingsService(
			appConfig: $this->appConfig,
			appManager: $this->createMock(IAppManager::class),
			container: $this->createMock(ContainerInterface::class),
			userSession: $this->createMock(IUserSession::class),
			logger: $this->createMock(LoggerInterface::class),
		);
	}//end setUp()

	/**
	 * The four settings areas split the admin payload: no key in two areas,
	 * and no key in none (the CA status is General's read-only extra).
	 *
	 * @return void
	 *
	 * @spec openspec/changes/admin-scoped-roles/tasks.md#2.1
	 */
	public function testTheAreasPartitionTheAdminSettings(): void {
		$seen = [];
		foreach (AdminSettingsService::SETTINGS_AREAS as $area) {
			foreach (array_keys($this->service->getAreaSettings(area: $area)) as $key) {
				$this->assertArrayNotHasKey($key, $seen, $key . ' is in both ' . ($seen[$key] ?? '') . ' and ' . $area);
				$seen[$key] = $area;
			}
		}

		$all = array_keys($this->service->getAdminSettings());
		sort($all);
		$covered = array_keys($seen);
		sort($covered);
		$this->assertSame($all, $covered);
	}//end testTheAreasPartitionTheAdminSettings()

	/**
	 * Version and trash retention are Policies, attachment limits General
	 * (decision of 2 Oct).
	 *
	 * @return void
	 *
	 * @spec openspec/changes/admin-scoped-roles/tasks.md#2.1
	 */
	public function testRetentionIsPoliciesAndAttachmentLimitsAreGeneral(): void {
		$policies = $this->service->getAreaSettings(area: 'policies');
		$general = $this->service->getAreaSettings(area: 'general');

		foreach (['version_retention_count', 'version_retention_days', 'trash_retention_days', 'min_password_length', 'policy_enabled', 'team_folder_auto_confirm'] as $key) {
			$this->assertArrayHasKey($key, $policies);
		}

		foreach (['attachment_max_bytes', 'breach_check_enabled', 'ca_status'] as $key) {
			$this->assertArrayHasKey($key, $general);
		}

		$this->assertSame(['audit_retention_days'], array_keys($this->service->getAreaSettings(area: 'audit')));
	}//end testRetentionIsPoliciesAndAttachmentLimitsAreGeneral()

	/**
	 * An area write refuses a key of another area and writes nothing: a
	 * policy key through the Audit route never lands (red before: the one
	 * combined write stored it for anyone holding the whole section).
	 *
	 * @return void
	 *
	 * @spec openspec/changes/admin-scoped-roles/tasks.md#2.1
	 */
	public function testAnAreaWriteRefusesAKeyOfAnotherArea(): void {
		try {
			$this->service->updateAreaSettings(area: 'audit', data: ['audit_retention_days' => 400, 'min_password_length' => 14]);
			$this->fail('a policy key through the audit area must be refused');
		} catch (InvalidArgumentException $e) {
			$this->assertStringContainsString('min_password_length belongs to the policies area', $e->getMessage());
		}

		$this->assertSame([], $this->written);
	}//end testAnAreaWriteRefusesAKeyOfAnotherArea()

	/**
	 * General cannot carry a lease key, and Policies cannot carry a General one.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/admin-scoped-roles/tasks.md#2.1
	 */
	public function testEveryAreaRefusesForeignKeys(): void {
		foreach ([['general', 'lease_renewable'], ['policies', 'breach_check_enabled'], ['applications', 'trash_retention_days']] as [$area, $key]) {
			try {
				$this->service->updateAreaSettings(area: $area, data: [$key => true]);
				$this->fail($key . ' must be refused by ' . $area);
			} catch (InvalidArgumentException $e) {
				$this->assertStringContainsString($key . ' belongs to', $e->getMessage());
			}
		}

		$this->assertSame([], $this->written);
	}//end testEveryAreaRefusesForeignKeys()

	/**
	 * An own-area write stores the area's keys and returns only that area.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/admin-scoped-roles/tasks.md#2.1
	 */
	public function testAnAreaWriteStoresItsOwnKeys(): void {
		$result = $this->service->updateAreaSettings(area: 'policies', data: ['trash_retention_days' => 60, 'version_retention_count' => 5]);

		$this->assertSame(60, $this->written['trash_retention_days']);
		$this->assertSame(5, $this->written['version_retention_count']);
		$this->assertArrayNotHasKey('lease_renewable', $result);
		$this->assertArrayNotHasKey('audit_retention_days', $result);
	}//end testAnAreaWriteStoresItsOwnKeys()

	/**
	 * An unknown area is refused.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/admin-scoped-roles/tasks.md#2.1
	 */
	public function testAnUnknownAreaIsRefused(): void {
		$this->expectException(InvalidArgumentException::class);
		$this->service->updateAreaSettings(area: 'people', data: []);
	}//end testAnUnknownAreaIsRefused()
}//end class
