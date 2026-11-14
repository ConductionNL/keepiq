<?php

/**
 * Unit tests for WebManifestController (mobile-pwa §6.1).
 *
 * @category Test
 * @package  OCA\Keepiq\Tests\Unit\Controller
 *
 * @author    Conduction Development Team <dev@conductio.nl>
 * @copyright 2024 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git-id>
 *
 * @link https://conduction.nl
 */

declare(strict_types=1);

namespace OCA\Keepiq\Tests\Unit\Controller;

use OCA\Keepiq\Controller\WebManifestController;
use OCP\Defaults;
use OCP\IRequest;
use OCP\IURLGenerator;
use PHPUnit\Framework\TestCase;

/**
 * Tests for the PWA web app manifest.
 */
class WebManifestControllerTest extends TestCase {
	/**
	 * The manifest declares standalone, themed colours, a vault
	 * start_url/scope, maskable + any-purpose icons at 192/512, and a
	 * vault shortcut — served with the correct MIME (§6.1).
	 *
	 * @return void
	 */
	public function testManifestShape(): void {
		$url = $this->createMock(originalClassName: IURLGenerator::class);
		$url->method('linkToRouteAbsolute')->willReturn('https://cloud.example/apps/keepiq/');
		$url->method('linkToRoute')->willReturn('/apps/keepiq/');
		$url->method('imagePath')->willReturnCallback(
			static fn (string $app, string $file): string => '/apps/keepiq/img/' . $file
		);
		$url->method('getAbsoluteURL')->willReturnCallback(
			static fn (string $path): string => 'https://cloud.example' . $path
		);

		$defaults = $this->createMock(originalClassName: Defaults::class);
		$defaults->method('getColorPrimary')->willReturn('#00679e');

		$controller = new WebManifestController(
			request: $this->createMock(originalClassName: IRequest::class),
			urlGenerator: $url,
			defaults: $defaults,
		);

		$response = $controller->manifest();

		// The application/manifest+json MIME is asserted at the live HTTP layer;
		// Response::getHeaders() needs the OC container (CSP defaults), so unit
		// tests assert the manifest body shape only.
		$manifest = json_decode($response->getData(), true);
		$this->assertSame('Keepiq', $manifest['name']);
		$this->assertSame('standalone', $manifest['display']);
		// The instance theme colour, not a fixed brand colour (#755).
		$this->assertSame('#00679e', $manifest['theme_color']);
		$this->assertSame('#00679e', $manifest['background_color']);
		$this->assertStringNotContainsString('—', $manifest['description']);
		$this->assertStringContainsString('/apps/keepiq/', $manifest['start_url']);
		$this->assertSame('/apps/keepiq/', $manifest['scope']);

		// Maskable + any-purpose icons at both 192 and 512.
		$purposes = array_column($manifest['icons'], 'purpose');
		$this->assertContains('maskable', $purposes);
		$this->assertContains('any', $purposes);
		$sizes = array_unique(array_column($manifest['icons'], 'sizes'));
		$this->assertContains('192x192', $sizes);
		$this->assertContains('512x512', $sizes);

		// PNG icons at 192 and 512 for both purposes, and each file exists.
		$png = array_values(array_filter($manifest['icons'], static fn (array $icon): bool => $icon['type'] === 'image/png'));
		$this->assertCount(4, $png);
		foreach ($png as $icon) {
			$file = __DIR__ . '/../../../img/' . basename($icon['src']);
			$this->assertFileExists($file);
			$size = getimagesize($file);
			$this->assertSame($icon['sizes'], $size[0] . 'x' . $size[1]);
		}

		// A vault shortcut.
		$this->assertNotEmpty($manifest['shortcuts']);
		$this->assertStringContainsString('secrets', $manifest['shortcuts'][0]['url']);
	}//end testManifestShape()

	/**
	 * With Nextcloud theming setting a primary colour, the manifest's theme
	 * and background colours are that colour (platform-properties 1.2).
	 *
	 * @return void
	 */
	public function testThemingOnUsesThePrimaryColour(): void {
		$manifest = $this->manifestFor(primary: '#2E7D32');

		$this->assertSame(expected: '#2E7D32', actual: $manifest['theme_color']);
		$this->assertSame(expected: '#2E7D32', actual: $manifest['background_color']);
	}//end testThemingOnUsesThePrimaryColour()

	/**
	 * With theming yielding no usable colour, the Keepiq brand colour is
	 * the fallback (platform-properties 1.2).
	 *
	 * @return void
	 */
	public function testThemingOffFallsBackToTheBrandColour(): void {
		foreach (['', '   ', 'green', '#12345', 'var(--color-primary)'] as $primary) {
			$manifest = $this->manifestFor(primary: $primary);
			$this->assertSame(expected: '#21468B', actual: $manifest['theme_color'], message: 'primary ' . var_export(value: $primary, return: true));
			$this->assertSame(expected: '#21468B', actual: $manifest['background_color']);
		}
	}//end testThemingOffFallsBackToTheBrandColour()

	/**
	 * The shared helper trims a padded theme colour and keeps short hex.
	 *
	 * @return void
	 */
	public function testThemeColorTrimsAndAcceptsShortHex(): void {
		$defaults = $this->createMock(originalClassName: Defaults::class);
		$defaults->method('getColorPrimary')->willReturnOnConsecutiveCalls(' #0a0 ', '#ABCDEF');

		$this->assertSame(expected: '#0a0', actual: WebManifestController::themeColor(defaults: $defaults));
		$this->assertSame(expected: '#ABCDEF', actual: WebManifestController::themeColor(defaults: $defaults));
	}//end testThemeColorTrimsAndAcceptsShortHex()

	/**
	 * Build the manifest with the given primary theme colour.
	 *
	 * @param string $primary What Defaults::getColorPrimary() returns
	 *
	 * @return array<string, mixed> The decoded manifest
	 */
	private function manifestFor(string $primary): array {
		$url = $this->createMock(originalClassName: IURLGenerator::class);
		$url->method('linkToRouteAbsolute')->willReturn(value: 'https://cloud.example/apps/keepiq/');
		$url->method('linkToRoute')->willReturn(value: '/apps/keepiq/');
		$url->method('imagePath')->willReturn(value: '/apps/keepiq/img/pwa-icon.svg');
		$url->method('getAbsoluteURL')->willReturnArgument(argumentIndex: 0);

		$defaults = $this->createMock(originalClassName: Defaults::class);
		$defaults->method('getColorPrimary')->willReturn(value: $primary);

		$controller = new WebManifestController(
			request: $this->createMock(originalClassName: IRequest::class),
			urlGenerator: $url,
			defaults: $defaults,
		);

		return json_decode(json: $controller->manifest()->getData(), associative: true);
	}//end manifestFor()
}//end class
