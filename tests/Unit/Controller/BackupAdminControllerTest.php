<?php

/**
 * Unit tests for BackupAdminController.
 *
 * The admin section lists archives without their disk path, "Back up now"
 * only asks the next cron run, and a settings save answers 400 on a bad
 * value and the stored settings otherwise.
 *
 * @category Test
 * @package  OCA\Keepiq\Tests\Unit\Controller
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

namespace OCA\Keepiq\Tests\Unit\Controller;

use InvalidArgumentException;
use OCA\Keepiq\Backup\BackupService;
use OCA\Keepiq\Backup\BackupSettings;
use OCA\Keepiq\Controller\BackupAdminController;
use OCP\AppFramework\Http;
use OCP\IRequest;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

/**
 * @covers \OCA\Keepiq\Controller\BackupAdminController
 */
class BackupAdminControllerTest extends TestCase {

	/**
	 * The mocked backup service.
	 *
	 * @var BackupService&MockObject
	 */
	private BackupService&MockObject $backups;

	/**
	 * The mocked backup settings.
	 *
	 * @var BackupSettings&MockObject
	 */
	private BackupSettings&MockObject $settings;

	/**
	 * The mocked request.
	 *
	 * @var IRequest&MockObject
	 */
	private IRequest&MockObject $request;

	/**
	 * The settings the mocked store holds.
	 *
	 * @var array<string,mixed>
	 */
	private const SETTINGS = [
		'backup_enabled' => true,
		'backup_interval_hours' => 24,
		'backup_retention_count' => 7,
		'backup_recipient_public_key' => '',
	];

	/**
	 * Build fresh mocks for every test.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		parent::setUp();
		$this->backups = $this->createMock(BackupService::class);
		$this->settings = $this->createMock(BackupSettings::class);
		$this->request = $this->createMock(IRequest::class);
	}//end setUp()

	/**
	 * The controller under test.
	 *
	 * @return BackupAdminController
	 */
	private function controller(): BackupAdminController {
		return new BackupAdminController(
			request: $this->request,
			backups: $this->backups,
			settings: $this->settings,
		);
	}//end controller()

	/**
	 * The index returns settings, status and the archives without their disk paths.
	 *
	 * @return void
	 */
	public function testIndexListsArchivesWithoutTheirDiskPath(): void {
		$status = ['lastRunAt' => 1700000000, 'lastStatus' => 'ok', 'lastError' => '', 'runRequested' => false];
		$this->settings->method('read')->willReturn(self::SETTINGS);
		$this->backups->method('status')->willReturn($status);
		$this->backups->method('listArchives')->willReturn(
			[
				[
					'name' => 'keepiq-2.zip.enc',
					'size' => 2048,
					'createdAt' => 1700000200,
					'encrypted' => true,
					'path' => '/var/data/backups/keepiq-2.zip.enc',
				],
				[
					'name' => 'keepiq-1.zip',
					'size' => 1024,
					'createdAt' => 1700000100,
					'encrypted' => false,
					'path' => '/var/data/backups/keepiq-1.zip',
				],
			]
		);

		$response = $this->controller()->index();

		$this->assertSame(Http::STATUS_OK, $response->getStatus());
		$this->assertSame(
			[
				'settings' => self::SETTINGS,
				'status' => $status,
				'archives' => [
					['name' => 'keepiq-2.zip.enc', 'size' => 2048, 'createdAt' => 1700000200, 'encrypted' => true],
					['name' => 'keepiq-1.zip', 'size' => 1024, 'createdAt' => 1700000100, 'encrypted' => false],
				],
			],
			$response->getData()
		);
	}//end testIndexListsArchivesWithoutTheirDiskPath()

	/**
	 * With no archives the list is empty.
	 *
	 * @return void
	 */
	public function testIndexWithNoArchives(): void {
		$this->settings->method('read')->willReturn(self::SETTINGS);
		$this->backups->method('status')->willReturn([]);
		$this->backups->method('listArchives')->willReturn([]);

		$this->assertSame([], $this->controller()->index()->getData()['archives']);
	}//end testIndexWithNoArchives()

	/**
	 * "Back up now" only requests a run, never backs up in the request, and answers 202.
	 *
	 * @return void
	 */
	public function testRunRequestsTheNextCronRunAndAnswers202(): void {
		$this->backups->expects($this->once())->method('requestRun');
		$this->backups->expects($this->never())->method('createBackup');

		$response = $this->controller()->run();

		$this->assertSame(Http::STATUS_ACCEPTED, $response->getStatus());
		$this->assertSame(['requested' => true], $response->getData());
	}//end testRunRequestsTheNextCronRunAndAnswers202()

	/**
	 * A valid save stores the request parameters and returns the settings read back.
	 *
	 * @return void
	 */
	public function testUpdateStoresTheParamsAndReturnsTheSettings(): void {
		$params = ['backup_interval_hours' => '12'];
		$this->request->method('getParams')->willReturn($params);
		$this->settings->expects($this->once())->method('update')->with($params);
		$this->settings->method('read')->willReturn(self::SETTINGS);

		$response = $this->controller()->update();

		$this->assertSame(Http::STATUS_OK, $response->getStatus());
		$this->assertSame(self::SETTINGS, $response->getData());
	}//end testUpdateStoresTheParamsAndReturnsTheSettings()

	/**
	 * A bad value answers 400 with the validation message and the settings are not read back.
	 *
	 * @return void
	 */
	public function testUpdateAnswers400OnABadValue(): void {
		$this->request->method('getParams')->willReturn(['backup_retention_count' => '0']);
		$this->settings->method('update')
			->willThrowException(new InvalidArgumentException('backup_retention_count must be between 1 and 365'));
		$this->settings->expects($this->never())->method('read');

		$response = $this->controller()->update();

		$this->assertSame(Http::STATUS_BAD_REQUEST, $response->getStatus());
		$this->assertSame(['message' => 'backup_retention_count must be between 1 and 365'], $response->getData());
	}//end testUpdateAnswers400OnABadValue()
}//end class
