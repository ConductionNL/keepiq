<?php

/**
 * Unit tests for VaultPolicyService (admin-vault-policies §1.1, §1.2).
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
use OCA\Keepiq\Event\Audit\AuditEvent;
use OCA\Keepiq\Event\Audit\AuditEventTypes;
use OCA\Keepiq\Service\AdminSettingsService;
use OCA\Keepiq\Service\VaultPolicyService;
use OCP\App\IAppManager;
use OCP\EventDispatcher\IEventDispatcher;
use OCP\IAppConfig;
use OCP\IGroupManager;
use OCP\IUser;
use OCP\IUserSession;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;

/**
 * Group scope, validation, audit, and what the browser may see.
 */
class VaultPolicyServiceTest extends TestCase {
	/** @var array<string,mixed> */
	private array $store = [];

	/** @var array<int,AuditEvent> */
	private array $events = [];

	private VaultPolicyService $service;

	private AdminSettingsService $admin;

	/**
	 * In-memory app config; erin is in staff, olaf is not.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		$appConfig = $this->createMock(IAppConfig::class);
		$appConfig->method('getValueBool')->willReturnCallback(
			fn (string $app, string $key, bool $default = false): bool => (bool)($this->store[$key] ?? $default)
		);
		$appConfig->method('getValueString')->willReturnCallback(
			fn (string $app, string $key, string $default = ''): string => (string)($this->store[$key] ?? $default)
		);
		$appConfig->method('getValueInt')->willReturnArgument(2);
		$appConfig->method('setValueBool')->willReturnCallback(function (string $app, string $key, bool $value): bool {
			$this->store[$key] = $value;
			return true;
		});
		$appConfig->method('setValueString')->willReturnCallback(function (string $app, string $key, string $value): bool {
			$this->store[$key] = $value;
			return true;
		});

		$groups = $this->createMock(IGroupManager::class);
		$groups->method('groupExists')->willReturnCallback(static fn (string $gid): bool => $gid === 'staff');
		$groups->method('isInGroup')->willReturnCallback(
			static fn (string $uid, string $gid): bool => $uid === 'erin' && $gid === 'staff'
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

		$this->service = new VaultPolicyService(
			appConfig: $appConfig,
			groupManager: $groups,
			userSession: $session,
			eventDispatcher: $dispatcher,
		);
		$this->admin = new AdminSettingsService(
			appConfig: $appConfig,
			appManager: $this->createMock(IAppManager::class),
			container: $this->createMock(ContainerInterface::class),
			userSession: $session,
			logger: $this->createMock(LoggerInterface::class),
			vaultPolicies: $this->service,
		);
	}//end setUp()

	/**
	 * Every policy is off by default and the default types are the work logins.
	 *
	 * @return void
	 */
	public function testOffByDefault(): void {
		foreach (VaultPolicyService::POLICIES as $policy) {
			$this->assertFalse($this->service->appliesTo(policy: $policy, userId: 'erin'));
		}

		$this->assertSame(['login', 'api_key', 'database'], $this->service->ownershipTypes());
	}//end testOffByDefault()

	/**
	 * Scenario "Administrator scopes the export ban to a group": the ban
	 * applies to a staff member only, and one audit carries before and after.
	 *
	 * @return void
	 */
	public function testGroupScopeAndAudit(): void {
		$this->admin->updateAdminSettings([
			'vault_export_disabled' => true,
			'vault_export_disabled_groups' => ['staff'],
		]);

		$this->assertTrue($this->service->appliesTo(policy: VaultPolicyService::EXPORT_DISABLED, userId: 'erin'));
		$this->assertFalse($this->service->appliesTo(policy: VaultPolicyService::EXPORT_DISABLED, userId: 'olaf'));

		$this->assertCount(1, $this->events);
		$this->assertSame(AuditEventTypes::VAULT_POLICY_UPDATED, $this->events[0]->getEventType());
		$this->assertSame(
			['vault_export_disabled' => false, 'vault_export_disabled_groups' => []],
			$this->events[0]->getMetadata()['before']
		);
		$this->assertSame(
			['vault_export_disabled' => true, 'vault_export_disabled_groups' => ['staff']],
			$this->events[0]->getMetadata()['after']
		);
	}//end testGroupScopeAndAudit()

	/**
	 * An empty group list means every user.
	 *
	 * @return void
	 */
	public function testEmptyScopeCoversEveryone(): void {
		$this->admin->updateAdminSettings(['vault_require_two_factor' => true]);

		$this->assertTrue($this->service->appliesTo(policy: VaultPolicyService::REQUIRE_TWO_FACTOR, userId: 'olaf'));
	}//end testEmptyScopeCoversEveryone()

	/**
	 * Invalid input is refused and nothing is written or audited.
	 *
	 * @return void
	 */
	public function testInvalidValuesAreRefused(): void {
		$bad = [
			['vault_org_ownership_types' => []],
			['vault_org_ownership_types' => ['login', '<script>']],
			['vault_org_ownership_types' => 'login'],
			['vault_export_disabled_groups' => ['no-such-group']],
			['vault_org_ownership' => 'maybe'],
			['vault_org_ownership' => true, 'vault_org_ownership_groups' => ['ghost']],
		];
		foreach ($bad as $data) {
			try {
				$this->admin->updateAdminSettings($data);
				$this->fail('Accepted ' . json_encode($data));
			} catch (InvalidArgumentException) {
				$this->addToAssertionCount(1);
			}
		}

		$this->assertSame([], $this->store);
		$this->assertSame([], $this->events);
	}//end testInvalidValuesAreRefused()

	/**
	 * §1.2: the user policy says whether each policy applies, and never
	 * carries a group list; the admin payload does.
	 *
	 * @return void
	 */
	public function testUserPolicyNeverReturnsGroupLists(): void {
		$this->admin->updateAdminSettings([
			'vault_org_ownership' => true,
			'vault_org_ownership_groups' => ['staff'],
			'vault_org_ownership_types' => ['login'],
		]);

		$policy = $this->admin->getPolicy(userId: 'erin');

		$this->assertTrue($policy['vault_org_ownership']);
		$this->assertFalse($policy['vault_export_disabled']);
		$this->assertSame(['login'], $policy['vault_org_ownership_types']);
		foreach (array_keys($policy) as $key) {
			$this->assertStringEndsNotWith('_groups', $key);
		}

		$this->assertFalse($this->admin->getPolicy(userId: 'olaf')['vault_org_ownership']);
		$this->assertSame(['staff'], $this->admin->getAdminSettings()['vault_org_ownership_groups']);
	}//end testUserPolicyNeverReturnsGroupLists()
}//end class
