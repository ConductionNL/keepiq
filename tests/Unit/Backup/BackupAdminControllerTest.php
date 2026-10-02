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
		foreach (['index', 'run'] as $name) {
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
		$controller = new BackupAdminController(request: $this->createMock(IRequest::class), backups: $backups);

		$data = $controller->index()->getData();

		$this->assertSame([['name' => 'a.zip', 'size' => 3, 'createdAt' => 1, 'encrypted' => false]], $data['archives']);
		$methods = array_map(static fn ($m) => $m->getName(), (new ReflectionClass(BackupAdminController::class))->getMethods());
		$this->assertSame(['__construct', 'index', 'run'], array_values(array_filter($methods, static fn ($m) => in_array($m, ['__construct', 'index', 'run', 'download', 'show'], true))));
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
		$controller = new BackupAdminController(request: $this->createMock(IRequest::class), backups: $backups);

		$this->assertSame(202, $controller->run()->getStatus());
	}//end testRunRequestsABackup()
}//end class
