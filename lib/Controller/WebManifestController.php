<?php

/**
 * Keepiq Web App Manifest Controller
 *
 * Serves the Keepiq PWA web app manifest (mobile-pwa §1) with the
 * correct `application/manifest+json` MIME type. This is distinct from
 * the internal `src/manifest.json` page/router manifest — different
 * consumer, different schema (W3C Web App Manifest). Public + CSRF-free
 * because the browser fetches a `<link rel="manifest">` target without
 * app cookies/token. Registers no service worker; installability relies
 * on Nextcloud's instance service worker (§D2).
 *
 * @category Controller
 * @package  OCA\Keepiq\Controller
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

namespace OCA\Keepiq\Controller;

use OCA\Keepiq\AppInfo\Application;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\Attribute\AnonRateLimit;
use OCP\AppFramework\Http\Attribute\NoCSRFRequired;
use OCP\AppFramework\Http\Attribute\PublicPage;
use OCP\AppFramework\Http\DataDisplayResponse;
use OCP\Defaults;
use OCP\IRequest;
use OCP\IURLGenerator;

/**
 * Serves the PWA web app manifest.
 */
class WebManifestController extends Controller {
	/**
	 * Constructor for WebManifestController.
	 *
	 * @param IRequest $request The HTTP request
	 * @param IURLGenerator $urlGenerator Builds absolute asset + scope URLs
	 * @param Defaults $defaults The instance theming, for the browser chrome colour
	 *
	 * @return void
	 */
	public function __construct(
		IRequest $request,
		private IURLGenerator $urlGenerator,
		private Defaults $defaults,
	) {
		parent::__construct(appName: Application::APP_ID, request: $request);
	}//end __construct()

	/**
	 * The Keepiq web app manifest.
	 *
	 * @PublicPage
	 * @NoCSRFRequired
	 *
	 * @return DataDisplayResponse
	 *
	 * @spec openspec/specs/mobile-pwa/spec.md#requirement-installable-web-app-manifest
	 * @spec openspec/specs/mobile-pwa/spec.md#requirement-maskable-and-themed-app-icons
	 */
	#[PublicPage]
	#[NoCSRFRequired]
	#[AnonRateLimit(limit: 240, period: 60)]
	public function manifest(): DataDisplayResponse {
		// A path, not the retired '#/secrets' hash form — the SPA's
		// createWebHistory router never reads the fragment.
		$vaultUrl = $this->urlGenerator->linkToRouteAbsolute('keepiq.dashboard.page') . 'secrets';
		$startUrl = $this->urlGenerator->linkToRouteAbsolute('keepiq.dashboard.page');
		$scope = $this->urlGenerator->linkToRoute('keepiq.dashboard.page');
		$maskable = $this->urlGenerator->getAbsoluteURL($this->urlGenerator->imagePath(Application::APP_ID, 'pwa-icon-maskable.svg'));
		$anyIcon = $this->urlGenerator->getAbsoluteURL($this->urlGenerator->imagePath(Application::APP_ID, 'pwa-icon.svg'));
		// Raster copies: some mobile browsers only offer to install with PNG
		// icons at 192 and 512 pixels.
		$pngIcon = fn (string $name): string => $this->urlGenerator->getAbsoluteURL(
			$this->urlGenerator->imagePath(Application::APP_ID, $name)
		);
		// The instance theme colour (nldesign or the admin's theming), so an
		// organisation theme reaches the browser chrome too.
		$themeColor = $this->defaults->getColorPrimary();

		$manifest = [
			'name' => 'Keepiq',
			'short_name' => 'Keepiq',
			'description' => 'Encrypted secrets manager and zero-knowledge vault.',
			'display' => 'standalone',
			'theme_color' => $themeColor,
			'background_color' => $themeColor,
			'start_url' => $startUrl,
			'scope' => $scope,
			'orientation' => 'portrait-primary',
			'icons' => [
				['src' => $anyIcon, 'sizes' => '192x192', 'type' => 'image/svg+xml', 'purpose' => 'any'],
				['src' => $anyIcon, 'sizes' => '512x512', 'type' => 'image/svg+xml', 'purpose' => 'any'],
				['src' => $maskable, 'sizes' => '192x192', 'type' => 'image/svg+xml', 'purpose' => 'maskable'],
				['src' => $maskable, 'sizes' => '512x512', 'type' => 'image/svg+xml', 'purpose' => 'maskable'],
				['src' => $pngIcon('pwa-icon-192.png'), 'sizes' => '192x192', 'type' => 'image/png', 'purpose' => 'any'],
				['src' => $pngIcon('pwa-icon-512.png'), 'sizes' => '512x512', 'type' => 'image/png', 'purpose' => 'any'],
				['src' => $pngIcon('pwa-icon-maskable-192.png'), 'sizes' => '192x192', 'type' => 'image/png', 'purpose' => 'maskable'],
				['src' => $pngIcon('pwa-icon-maskable-512.png'), 'sizes' => '512x512', 'type' => 'image/png', 'purpose' => 'maskable'],
			],
			'shortcuts' => [
				[
					'name' => 'Open vault',
					'url' => $vaultUrl,
					'icons' => [['src' => $anyIcon, 'sizes' => '192x192', 'type' => 'image/svg+xml']],
				],
			],
		];

		return new DataDisplayResponse(
			data: (string)json_encode($manifest, JSON_UNESCAPED_SLASHES),
			statusCode: Http::STATUS_OK,
			headers: [
				'Content-Type' => 'application/manifest+json',
				'Cache-Control' => 'public, max-age=3600',
			]
		);
	}//end manifest()
}//end class
