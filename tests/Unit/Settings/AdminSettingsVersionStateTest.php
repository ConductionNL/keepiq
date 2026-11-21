<?php

/**
 * Unit tests for the version card state of the General admin area.
 *
 * @category Test
 * @package  OCA\Keepiq\Tests\Unit\Settings
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

namespace OCA\Keepiq\Tests\Unit\Settings;

use OCA\Keepiq\Settings\AdminSettings;
use OCP\App\IAppManager;
use OCP\AppFramework\Services\IInitialState;
use OCP\IAppConfig;
use OCP\IL10N;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * Which version the General area reports, which configured version, and when
 * it calls the configuration up to date.
 *
 * @covers \OCA\Keepiq\Settings\AdminSettings
 * @uses   \OCA\Keepiq\Settings\AdminAreaSettings
 */
class AdminSettingsVersionStateTest extends TestCase {

	/**
	 * Initial state provided, in call order, as [key, value] pairs.
	 *
	 * @var array<int,array{0:string,1:mixed}>
	 */
	private array $provided = [];

	/**
	 * The General area with the given running and configured versions.
	 *
	 * @param string $running    The installed app version.
	 * @param string $configured The stored `config_version`.
	 *
	 * @return AdminSettings
	 */
	private function general(string $running, string $configured): AdminSettings {
		$l10n = $this->createStub(originalClassName: IL10N::class);
		$l10n->method('t')->willReturnArgument(0);

		$state = $this->createStub(originalClassName: IInitialState::class);
		$state->method('provideInitialState')->willReturnCallback(function (string $key, mixed $value): void {
			$this->provided[] = [$key, $value];
		});

		$apps = $this->createMock(originalClassName: IAppManager::class);
		$apps->expects($this->once())->method('getAppVersion')->with('keepiq')->willReturn($running);

		$config = $this->createMock(originalClassName: IAppConfig::class);
		$config->expects($this->once())
			->method('getValueString')
			->with('keepiq', 'config_version', '')
			->willReturn($configured);

		return new AdminSettings(l10n: $l10n, initialState: $state, appManager: $apps, appConfig: $config);
	}//end general()

	/**
	 * Running and configured versions, and whether that is up to date.
	 *
	 * @return array<string,array{0:string,1:string,2:bool}>
	 */
	public static function versions(): array {
		return [
			'same version'            => ['1.4.0', '1.4.0', true],
			'never configured'        => ['1.4.0', '', false],
			'configured for an older' => ['1.4.0', '1.3.9', false],
			'configured for a newer'  => ['1.4.0', '1.5.0', false],
			'no running version'      => ['', '', false],
		];
	}//end versions()

	/**
	 * The form provides the area key first, then version, configured version
	 * and the up-to-date flag, and the flag is true only for an exact match
	 * with a non-empty configured version.
	 *
	 * @param string $running    The installed app version.
	 * @param string $configured The stored `config_version`.
	 * @param bool   $upToDate   The expected flag.
	 *
	 * @return void
	 */
	#[DataProvider('versions')]
	public function testTheVersionCardState(string $running, string $configured, bool $upToDate): void {
		$form = $this->general(running: $running, configured: $configured)->getForm();

		$this->assertSame(
			expected: [
				['area-general', true],
				['version', $running],
				['configuredVersion', $configured],
				['isUpToDate', $upToDate],
			],
			actual: $this->provided
		);
		$this->assertSame(expected: ['area' => 'general'], actual: $form->getParams());
	}//end testTheVersionCardState()

	/**
	 * The name, priority and section are read without touching the version
	 * sources, so the delegation page stays cheap.
	 *
	 * @return void
	 */
	public function testDescribingTheAreaReadsNoVersion(): void {
		$apps = $this->createMock(originalClassName: IAppManager::class);
		$apps->expects($this->never())->method('getAppVersion');
		$config = $this->createMock(originalClassName: IAppConfig::class);
		$config->expects($this->never())->method('getValueString');
		$l10n = $this->createMock(originalClassName: IL10N::class);
		$l10n->expects($this->once())->method('t')->with('General')->willReturn('Algemeen');

		$area = new AdminSettings(
			l10n: $l10n,
			initialState: $this->createStub(originalClassName: IInitialState::class),
			appManager: $apps,
			appConfig: $config
		);

		$this->assertSame(expected: 'Algemeen', actual: $area->getName());
		$this->assertSame(expected: 10, actual: $area->getPriority());
		$this->assertSame(expected: 'general', actual: $area->getArea());
		$this->assertSame(expected: 'keepiq', actual: $area->getSection());
		$this->assertSame(expected: [], actual: $this->provided);
	}//end testDescribingTheAreaReadsNoVersion()
}//end class
