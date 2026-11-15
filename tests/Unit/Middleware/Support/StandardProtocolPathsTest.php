<?php

/**
 * Unit tests for the standard protocol paths and the app-relative path reduction.
 *
 * @category Test
 * @package  OCA\Keepiq\Tests\Unit\Middleware\Support
 * @author   Conduction Development Team <info@conduction.nl>
 * @license  EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 * @link     https://conduction.nl
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Keepiq\Tests\Unit\Middleware\Support;

use OCA\Keepiq\Middleware\Support\StandardProtocolPaths;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * A path is reduced to the part after the app root, and only the health and
 * metrics endpoints count as standard protocol paths.
 *
 * @covers \OCA\Keepiq\Middleware\Support\StandardProtocolPaths
 */
class StandardProtocolPathsTest extends TestCase {

	/**
	 * Paths and their app-relative form.
	 *
	 * @return array<string,array{0:string,1:string}>
	 */
	public static function relativePaths(): array {
		return [
			'already relative'        => ['/api/health', '/api/health'],
			'missing leading slash'   => ['api/health', '/api/health'],
			'many leading slashes'    => ['///api/health', '/api/health'],
			'request path'            => ['/index.php/apps/keepiq/api/things', '/api/things'],
			'app root without index'  => ['/apps/keepiq/api/metrics', '/api/metrics'],
			'bare app root'           => ['/apps/keepiq', '/apps/keepiq'],
			'other app'               => ['/apps/keepiqx/api/health', '/apps/keepiqx/api/health'],
			'app root ending in slash' => ['/apps/keepiq/', '/'],
		];
	}//end relativePaths()

	/**
	 * Each path reduces to the expected app-relative path.
	 *
	 * @param string $path     The path.
	 * @param string $relative The expected app-relative path.
	 *
	 * @return void
	 */
	#[DataProvider('relativePaths')]
	public function testAPathReducesToItsAppRelativeForm(string $path, string $relative): void {
		$this->assertSame(expected: $relative, actual: StandardProtocolPaths::appRelative(path: $path));
	}//end testAPathReducesToItsAppRelativeForm()

	/**
	 * Paths and whether they are standard protocol paths.
	 *
	 * @return array<string,array{0:string,1:bool}>
	 */
	public static function protocolPaths(): array {
		return [
			'health'                => ['/api/health', true],
			'metrics'               => ['/api/metrics', true],
			'health under app root' => ['/index.php/apps/keepiq/api/health', true],
			'health without slash'  => ['api/health', true],
			'health with suffix'    => ['/api/health/deep', false],
			'health trailing slash' => ['/api/health/', false],
			'versioned health'      => ['/api/v1/health', false],
			'other endpoint'        => ['/api/things', false],
			'other app health'      => ['/apps/other/api/health', false],
		];
	}//end protocolPaths()

	/**
	 * Only the declared patterns, matched on the app-relative path, are standard protocol paths.
	 *
	 * @param string $path     The path.
	 * @param bool   $standard Whether it is a standard protocol path.
	 *
	 * @return void
	 */
	#[DataProvider('protocolPaths')]
	public function testOnlyTheDeclaredEndpointsAreStandard(string $path, bool $standard): void {
		$this->assertSame(expected: $standard, actual: StandardProtocolPaths::matches(path: $path));
	}//end testOnlyTheDeclaredEndpointsAreStandard()

	/**
	 * The declared patterns are exactly the health check and the metrics endpoint.
	 *
	 * @return void
	 */
	public function testThePatternsAreHealthAndMetrics(): void {
		$this->assertSame(
			expected: ['Health check' => '#^/api/health$#', 'Prometheus metrics' => '#^/api/metrics$#'],
			actual: StandardProtocolPaths::PATTERNS
		);
	}//end testThePatternsAreHealthAndMetrics()
}//end class
