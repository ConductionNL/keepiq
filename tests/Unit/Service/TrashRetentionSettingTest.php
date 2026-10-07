<?php

/**
 * Unit tests for the trash retention admin setting (vault-trash-and-archive D4).
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
 * The retention is 1 to 365 days, 30 by default.
 */
class TrashRetentionSettingTest extends TestCase {
	/** @var IAppConfig&MockObject */
	private IAppConfig $appConfig;

	private AdminSettingsService $service;

	/**
	 * Build the real settings service.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		$this->appConfig = $this->createMock(IAppConfig::class);
		$this->service = new AdminSettingsService(
			appConfig: $this->appConfig,
			container: $this->createMock(ContainerInterface::class),
			userSession: $this->createMock(IUserSession::class),
			logger: $this->createMock(LoggerInterface::class),
		);
	}//end setUp()

	/**
	 * The default is 30 days.
	 *
	 * @return void
	 */
	public function testDefaultIsThirtyDays(): void {
		$this->appConfig->method('getValueInt')->willReturnArgument(2);
		$this->appConfig->method('getValueString')->willReturnArgument(2);
		$this->appConfig->method('getValueBool')->willReturnArgument(2);

		$this->assertSame(30, $this->service->getAdminSettings()['trash_retention_days']);
	}//end testDefaultIsThirtyDays()

	/**
	 * A value inside the range is stored.
	 *
	 * @return void
	 */
	public function testValueInRangeIsStored(): void {
		$this->appConfig->expects($this->once())->method('setValueInt')->with('keepiq', 'trash_retention_days', 90);
		$this->appConfig->method('getValueInt')->willReturnArgument(2);
		$this->appConfig->method('getValueString')->willReturnArgument(2);
		$this->appConfig->method('getValueBool')->willReturnArgument(2);

		$this->service->updateAdminSettings(['trash_retention_days' => 90]);
	}//end testValueInRangeIsStored()

	/**
	 * Zero and anything above a year are refused.
	 *
	 * @return void
	 */
	public function testOutOfRangeIsRefused(): void {
		foreach ([0, 366] as $days) {
			try {
				$this->service->updateAdminSettings(['trash_retention_days' => $days]);
				$this->fail("{$days} days was accepted");
			} catch (InvalidArgumentException $e) {
				$this->assertStringContainsString('trash_retention_days', $e->getMessage());
			}
		}
	}//end testOutOfRangeIsRefused()
}//end class
