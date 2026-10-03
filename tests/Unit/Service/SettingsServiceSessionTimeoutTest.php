<?php

/**
 * Unit tests for the session-timeout default (crypto-07).
 *
 * @category Test
 * @package  OCA\Keepiq\Tests\Unit\Service
 *
 * @author    Conduction Development Team <dev@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git-id>
 *
 * @link https://conduction.nl
 */

declare(strict_types=1);

namespace OCA\Keepiq\Tests\Unit\Service;

use OCA\Keepiq\Service\SettingsService;
use OCP\App\IAppManager;
use OCP\EventDispatcher\IEventDispatcher;
use OCP\IAppConfig;
use OCP\IConfig;
use OCP\IGroupManager;
use OCP\IUserSession;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;

/**
 * An unset session timeout means ten minutes, what the vault always did in
 * practice. "Nextcloud session" now really means no idle timer, so it must
 * be a choice somebody made, never the silent default.
 *
 * @spec openspec/specs/vault-session-lock/spec.md#requirement-saved-session-timeout
 */
class SettingsServiceSessionTimeoutTest extends TestCase {

	/**
	 * App config values by key.
	 *
	 * @var array<string,string>
	 */
	private array $appValues = [];

	/**
	 * User config values by key.
	 *
	 * @var array<string,string>
	 */
	private array $userValues = [];

	/**
	 * Build the service over config mocks that answer their default when unset.
	 *
	 * @return SettingsService
	 */
	private function service(): SettingsService {
		$appConfig = $this->createMock(originalClassName: IAppConfig::class);
		$appConfig->method('getValueString')->willReturnCallback(
			fn (string $app, string $key, string $default = ''): string => ($this->appValues[$key] ?? $default)
		);
		$appConfig->method('getValueBool')->willReturnCallback(
			static fn (string $app, string $key, bool $default = false): bool => $default
		);
		$appConfig->method('getValueInt')->willReturnCallback(
			static fn (string $app, string $key, int $default = 0): int => $default
		);

		$config = $this->createMock(originalClassName: IConfig::class);
		$config->method('getUserValue')->willReturnCallback(
			fn (string $userId, string $app, string $key, mixed $default = ''): mixed => ($this->userValues[$key] ?? $default)
		);

		return new SettingsService(
			appConfig: $appConfig,
			config: $config,
			appManager: $this->createMock(originalClassName: IAppManager::class),
			container: $this->createMock(originalClassName: ContainerInterface::class),
			groupManager: $this->createMock(originalClassName: IGroupManager::class),
			userSession: $this->createMock(originalClassName: IUserSession::class),
			logger: $this->createMock(originalClassName: LoggerInterface::class),
			eventDispatcher: $this->createMock(originalClassName: IEventDispatcher::class),
		);
	}//end service()

	/**
	 * Neither the user nor the admin chose: ten minutes.
	 *
	 * @return void
	 */
	public function testUnsetTimeoutIsTenMinutesForTheUser(): void {
		$prefs = $this->service()->getUserPreferences(userId: 'alice');

		$this->assertSame('10min', $prefs['session_timeout']);
	}//end testUnsetTimeoutIsTenMinutesForTheUser()

	/**
	 * The admin page shows the same default the user gets.
	 *
	 * @return void
	 */
	public function testUnsetAdminDefaultIsTenMinutes(): void {
		$settings = $this->service()->getAdminSettings();

		$this->assertSame('10min', $settings['default_session_timeout']);
	}//end testUnsetAdminDefaultIsTenMinutes()

	/**
	 * An explicit Nextcloud session choice is kept as it is.
	 *
	 * @return void
	 */
	public function testAnExplicitSessionChoiceIsKept(): void {
		$this->appValues['default_session_timeout'] = '30min';
		$this->userValues['session_timeout'] = 'session';

		$prefs = $this->service()->getUserPreferences(userId: 'alice');

		$this->assertSame('session', $prefs['session_timeout']);
	}//end testAnExplicitSessionChoiceIsKept()

	/**
	 * The admin's choice is the default for a user who made none.
	 *
	 * @return void
	 */
	public function testTheAdminDefaultAppliesToAUserWithoutAChoice(): void {
		$this->appValues['default_session_timeout'] = '30min';

		$prefs = $this->service()->getUserPreferences(userId: 'alice');

		$this->assertSame('30min', $prefs['session_timeout']);
	}//end testTheAdminDefaultAppliesToAUserWithoutAChoice()
}//end class
