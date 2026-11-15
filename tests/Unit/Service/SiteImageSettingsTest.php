<?php

/**
 * The site icon and site preview templates: empty by default, an https
 * address with its placeholder when set, every given template checked
 * before any is written.
 *
 * @category Test
 * @package  OCA\Keepiq\Tests\Unit\Service
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git-id>
 *
 * @link https://conduction.nl
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Keepiq\Tests\Unit\Service;

use InvalidArgumentException;
use OCA\Keepiq\Service\SiteImageSettings;
use OCP\IAppConfig;
use PHPUnit\Framework\TestCase;

/**
 * @covers \OCA\Keepiq\Service\SiteImageSettings
 */
class SiteImageSettingsTest extends TestCase {

	/**
	 * The values written, by key.
	 *
	 * @var array<string,string>
	 */
	private array $written = [];

	/**
	 * Settings over an app config holding the given values.
	 *
	 * @param array<string,string> $stored The stored values
	 *
	 * @return SiteImageSettings
	 */
	private function settings(array $stored=[]): SiteImageSettings {
		$config = $this->createMock(IAppConfig::class);
		$config->method('getValueString')->willReturnCallback(
			static fn (string $app, string $key, string $default = ''): string => ($stored[$key] ?? $default)
		);
		$config->method('setValueString')->willReturnCallback(
			function (string $app, string $key, string $value): bool {
				$this->assertSame('keepiq', $app);
				$this->written[$key] = $value;
				return true;
			}
		);

		return new SiteImageSettings(appConfig: $config);
	}//end settings()

	/**
	 * Both templates read as empty on a fresh instance.
	 *
	 * @return void
	 */
	public function testBothTemplatesAreEmptyByDefault(): void {
		$this->assertSame(['favicon_service_url' => '', 'site_preview_service_url' => ''], $this->settings()->read());
	}//end testBothTemplatesAreEmptyByDefault()

	/**
	 * A valid template is stored trimmed; '' and null clear one; an absent key is left alone.
	 *
	 * @return void
	 */
	public function testValidTemplatesAreStoredAndEmptyClears(): void {
		$this->settings()->update(['site_preview_service_url' => ' https://preview.example/shot?u={url} ']);
		$this->assertSame(['site_preview_service_url' => 'https://preview.example/shot?u={url}'], $this->written);

		$this->written = [];
		$this->settings()->update(['favicon_service_url' => '', 'site_preview_service_url' => null]);
		$this->assertSame(['favicon_service_url' => '', 'site_preview_service_url' => ''], $this->written);
	}//end testValidTemplatesAreStoredAndEmptyClears()

	/**
	 * Plain http, a missing or wrong placeholder, a missing host and non-text are refused.
	 *
	 * @return void
	 */
	public function testInvalidTemplatesAreRefused(): void {
		$cases = [
			['site_preview_service_url' => 'http://preview.example/{url}'],
			['site_preview_service_url' => 'https://preview.example/{domain}'],
			['favicon_service_url' => 'https://icons.example/{url}'],
			['favicon_service_url' => 'https:///{domain}'],
			['favicon_service_url' => ['https://icons.example/{domain}']],
			['site_preview_service_url' => 'https://p.example/' . str_repeat('a', SiteImageSettings::MAX_LENGTH) . '{url}'],
		];
		foreach ($cases as $case) {
			try {
				$this->settings()->update($case);
				$this->fail('accepted ' . json_encode($case));
			} catch (InvalidArgumentException $e) {
				$this->assertStringContainsString((string)array_key_first($case), $e->getMessage());
			}
		}

		$this->assertSame([], $this->written);
	}//end testInvalidTemplatesAreRefused()

	/**
	 * One bad template stops the other from being written too.
	 *
	 * @return void
	 */
	public function testNothingIsWrittenWhenOneTemplateIsBad(): void {
		try {
			$this->settings()->update(['favicon_service_url' => 'https://icons.example/{domain}', 'site_preview_service_url' => 'ftp://x/{url}']);
			$this->fail('accepted a bad preview template');
		} catch (InvalidArgumentException) {
			$this->assertSame([], $this->written);
		}
	}//end testNothingIsWrittenWhenOneTemplateIsBad()
}//end class
