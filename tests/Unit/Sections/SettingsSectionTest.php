<?php

/**
 * Unit tests for Keepiq's admin settings section.
 *
 * @category Test
 * @package  OCA\Keepiq\Tests\Unit\Sections
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

namespace OCA\Keepiq\Tests\Unit\Sections;

use OCA\Keepiq\Sections\SettingsSection;
use OCP\IURLGenerator;
use OCP\Settings\IIconSection;
use PHPUnit\Framework\TestCase;

/**
 * The section's id, name, priority and icon, and that it resolves nothing until asked.
 *
 * @covers \OCA\Keepiq\Sections\SettingsSection
 */
class SettingsSectionTest extends TestCase {

	/**
	 * The section is an icon section, so Nextcloud renders it with its icon.
	 *
	 * @return void
	 */
	public function testItIsAnIconSection(): void {
		self::assertInstanceOf(IIconSection::class, new SettingsSection($this->createMock(IURLGenerator::class)));
	}//end testItIsAnIconSection()

	/**
	 * The id is the app id the admin settings point at, under a fixed name and slot.
	 *
	 * @return void
	 */
	public function testIdNameAndPriority(): void {
		$section = new SettingsSection($this->createMock(IURLGenerator::class));

		self::assertSame('keepiq', $section->getID());
		self::assertSame('Keepiq', $section->getName());
		self::assertSame(75, $section->getPriority());
	}//end testIdNameAndPriority()

	/**
	 * The icon is the app's dark SVG, resolved once through the URL generator.
	 *
	 * @return void
	 */
	public function testTheIconIsTheAppsDarkSvg(): void {
		$urls = $this->createMock(IURLGenerator::class);
		$urls->expects(self::once())
			->method('imagePath')
			->with('keepiq', 'app-dark.svg')
			->willReturn('/custom_apps/keepiq/img/app-dark.svg');

		self::assertSame('/custom_apps/keepiq/img/app-dark.svg', (new SettingsSection($urls))->getIcon());
	}//end testTheIconIsTheAppsDarkSvg()

	/**
	 * Building the section resolves no url.
	 *
	 * @return void
	 */
	public function testConstructionResolvesNothing(): void {
		$urls = $this->createMock(IURLGenerator::class);
		$urls->expects(self::never())->method('imagePath');

		self::assertSame(75, (new SettingsSection($urls))->getPriority());
	}//end testConstructionResolvesNothing()
}//end class
