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

		$controller = new WebManifestController(
			request: $this->createMock(originalClassName: IRequest::class),
			urlGenerator: $url,
		);

		$response = $controller->manifest();

		// The application/manifest+json MIME is asserted at the live HTTP layer;
		// Response::getHeaders() needs the OC container (CSP defaults), so unit
		// tests assert the manifest body shape only.
		$manifest = json_decode($response->getData(), true);
		$this->assertSame('Keepiq', $manifest['name']);
		$this->assertSame('standalone', $manifest['display']);
		$this->assertSame('#21468B', $manifest['theme_color']);
		$this->assertSame('#21468B', $manifest['background_color']);
		$this->assertStringContainsString('/apps/keepiq/', $manifest['start_url']);
		$this->assertSame('/apps/keepiq/', $manifest['scope']);

		// Maskable + any-purpose icons at both 192 and 512.
		$purposes = array_column($manifest['icons'], 'purpose');
		$this->assertContains('maskable', $purposes);
		$this->assertContains('any', $purposes);
		$sizes = array_unique(array_column($manifest['icons'], 'sizes'));
		$this->assertContains('192x192', $sizes);
		$this->assertContains('512x512', $sizes);

		// A vault shortcut.
		$this->assertNotEmpty($manifest['shortcuts']);
		$this->assertStringContainsString('secrets', $manifest['shortcuts'][0]['url']);
	}//end testManifestShape()
}//end class
