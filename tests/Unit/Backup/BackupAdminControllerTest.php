<?php

/**
 * Guard and shape tests for the backup admin endpoints
 * (admin-scheduled-vault-backups §4.1).
 *
 * @category Test
 * @package  OCA\Keepiq\Tests\Unit\Backup
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

namespace OCA\Keepiq\Tests\Unit\Backup;

use OCA\Keepiq\Backup\BackupService;
use OCA\Keepiq\Controller\BackupAdminController;
use OCA\Keepiq\Settings\AdminSettings;
use OCP\AppFramework\Http\Attribute\AuthorizedAdminSetting;
use OCP\AppFramework\Http\Attribute\NoAdminRequired;
use OCP\AppFramework\Http\Attribute\PublicPage;
use OCP\IRequest;
use PHPUnit\Framework\TestCase;
use ReflectionClass;
use ReflectionMethod;

/**
 * Admin only, no disk paths, no download route.
 */
class BackupAdminControllerTest extends TestCase {
	/**
	 * A regular user is refused every endpoint: the middleware reads the
	 * admin-setting guard and nothing widens it.
	 *
	 * @return void
	 */
	public function testEveryEndpointIsAdminOnly(): void {
		foreach (['index', 'run', 'update'] as $name) {
			$method = new ReflectionMethod(BackupAdminController::class, $name);
			$this->assertSame([AdminSettings::class], $method->getAttributes(AuthorizedAdminSetting::class)[0]->getArguments(), $name);
			$this->assertSame([], $method->getAttributes(NoAdminRequired::class), $name);
			$this->assertSame([], $method->getAttributes(PublicPage::class), $name);
		}
	}//end testEveryEndpointIsAdminOnly()

	/**
	 * Scenario "Admin section lists without download": rows carry name,
	 * size, time and encryption, and no route serves an archive.
	 *
	 * @return void
	 */
	public function testListHasNoPathAndNoDownloadRouteExists(): void {
		$backups = $this->createMock(BackupService::class);
		$backups->method('listArchives')->willReturn([
			['name' => 'a.zip', 'size' => 3, 'createdAt' => 1, 'encrypted' => false, 'path' => '/data/appdata_x/keepiq/backups/a.zip'],
		]);
		$backups->method('status')->willReturn(['lastRunAt' => 1, 'lastStatus' => 'ok', 'lastError' => '', 'runRequested' => false]);
		$controller = new BackupAdminController(request: $this->createMock(IRequest::class), backups: $backups, settings: $this->settings());

		$data = $controller->index()->getData();

		$this->assertSame([['name' => 'a.zip', 'size' => 3, 'createdAt' => 1, 'encrypted' => false]], $data['archives']);
		$methods = array_map(static fn ($m) => $m->getName(), (new ReflectionClass(BackupAdminController::class))->getMethods());
		$this->assertSame([], array_values(array_intersect($methods, ['download', 'show', 'file', 'get'])));
		$this->assertDoesNotMatchRegularExpression('#backups/\{#', (string)file_get_contents(__DIR__ . '/../../../appinfo/routes.php'));
	}//end testListHasNoPathAndNoDownloadRouteExists()

	/**
	 * "Back up now" asks the next cron run.
	 *
	 * @return void
	 */
	public function testRunRequestsABackup(): void {
		$backups = $this->createMock(BackupService::class);
		$backups->expects($this->once())->method('requestRun');
		$controller = new BackupAdminController(request: $this->createMock(IRequest::class), backups: $backups, settings: $this->settings());

		$this->assertSame(202, $controller->run()->getStatus());
	}//end testRunRequestsABackup()

	/**
	 * Real backup settings over an in-memory app config.
	 *
	 * @return \OCA\Keepiq\Backup\BackupSettings
	 */
	private function settings(): \OCA\Keepiq\Backup\BackupSettings {
		$store = [];
		$appConfig = $this->createMock(\OCP\IAppConfig::class);
		$appConfig->method('getValueBool')->willReturnCallback(static function ($a, $k, $d = false) use (&$store) {
			return (bool)($store[$k] ?? $d);
		});
		$appConfig->method('getValueInt')->willReturnCallback(static function ($a, $k, $d = 0) use (&$store) {
			return (int)($store[$k] ?? $d);
		});
		$appConfig->method('getValueString')->willReturnCallback(static function ($a, $k, $d = '') use (&$store) {
			return (string)($store[$k] ?? $d);
		});
		foreach (['setValueBool', 'setValueInt', 'setValueString'] as $setter) {
			$appConfig->method($setter)->willReturnCallback(static function ($a, $k, $v) use (&$store) {
				$store[$k] = $v;
				return true;
			});
		}

		return new \OCA\Keepiq\Backup\BackupSettings(appConfig: $appConfig, cipher: new \OCA\Keepiq\Backup\ArchiveCipher());
	}//end settings()

	/**
	 * §2.1: the save stores valid values, refuses a bad one with 400 and
	 * writes nothing, and the list carries the settings.
	 *
	 * @return void
	 */
	public function testSettingsSaveAndRefusal(): void {
		$backups = $this->createMock(BackupService::class);
		$backups->method('listArchives')->willReturn([]);
		$settings = $this->settings();
		$request = $this->createMock(IRequest::class);
		$request->method('getParams')->willReturnOnConsecutiveCalls(
			['backup_enabled' => true, 'backup_interval_hours' => 6],
			['backup_interval_hours' => 0]
		);
		$controller = new BackupAdminController(request: $request, backups: $backups, settings: $settings);

		$this->assertSame(6, $controller->update()->getData()['backup_interval_hours']);
		$this->assertSame(400, $controller->update()->getStatus());
		$this->assertSame(6, $controller->index()->getData()['settings']['backup_interval_hours']);
	}//end testSettingsSaveAndRefusal()
}//end class
