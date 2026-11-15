<?php

/**
 * Edge cases of the `/api/v1/` aliases: duplicates, missing keys and fallbacks.
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

use OCA\Keepiq\Middleware\Support\ApiPathAliases;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * An alias is never added twice, a routes array without routes stays as it is,
 * and only a catch-all `/api/{x}` route is the API fallback.
 *
 * @covers \OCA\Keepiq\Middleware\Support\ApiPathAliases
 * @uses   \OCA\Keepiq\Middleware\Support\StandardProtocolPaths
 */
class ApiPathAliasesEdgesTest extends TestCase {

	/**
	 * A route whose versioned twin is already declared gets no second alias, also when the
	 * declared twin spells its verb in lower case or omits the leading slash.
	 *
	 * @return void
	 */
	public function testAnAlreadyDeclaredTwinIsNotAddedAgain(): void {
		$routes = [
			'routes' => [
				['name' => 'things#index', 'url' => '/api/things', 'verb' => 'GET'],
				['name' => 'things#indexV1', 'url' => 'api/v1/things', 'verb' => 'get'],
				['name' => 'things#create', 'url' => '/api/things', 'verb' => 'POST'],
			],
		];

		$this->assertSame(
			expected: [
				['name' => 'things#index', 'url' => '/api/things', 'verb' => 'GET'],
				['name' => 'things#indexV1', 'url' => 'api/v1/things', 'verb' => 'get'],
				['name' => 'things#create', 'url' => '/api/v1/things', 'verb' => 'POST', 'postfix' => 'v1'],
				['name' => 'things#create', 'url' => '/api/things', 'verb' => 'POST'],
			],
			actual: ApiPathAliases::withVersionedAliases(routes: $routes)['routes']
		);
	}//end testAnAlreadyDeclaredTwinIsNotAddedAgain()

	/**
	 * A route without a verb counts as GET when it is matched against a declared twin.
	 *
	 * @return void
	 */
	public function testARouteWithoutAVerbIsMatchedAsGet(): void {
		$routes = [
			'routes' => [
				['name' => 'things#index', 'url' => '/api/things'],
				['name' => 'things#indexV1', 'url' => '/api/v1/things', 'verb' => 'GET'],
			],
		];

		$this->assertSame(expected: $routes, actual: ApiPathAliases::withVersionedAliases(routes: $routes));
	}//end testARouteWithoutAVerbIsMatchedAsGet()

	/**
	 * With no versioned route declared, the aliases go first; other top-level keys are kept.
	 *
	 * @return void
	 */
	public function testWithoutVersionedRoutesTheAliasesGoFirst(): void {
		$routes = [
			'ocs'    => [['name' => 'ocs#x', 'url' => '/x', 'verb' => 'GET']],
			'routes' => [
				['name' => 'page#index', 'url' => '/', 'verb' => 'GET'],
				['name' => 'things#show', 'url' => '/api/things/{id}', 'verb' => 'DELETE'],
			],
		];

		$aliased = ApiPathAliases::withVersionedAliases(routes: $routes);

		$this->assertSame(expected: $routes['ocs'], actual: $aliased['ocs']);
		$this->assertSame(
			expected: [
				['name' => 'things#show', 'url' => '/api/v1/things/{id}', 'verb' => 'DELETE', 'postfix' => 'v1'],
				['name' => 'page#index', 'url' => '/', 'verb' => 'GET'],
				['name' => 'things#show', 'url' => '/api/things/{id}', 'verb' => 'DELETE'],
			],
			actual: $aliased['routes']
		);
	}//end testWithoutVersionedRoutesTheAliasesGoFirst()

	/**
	 * A routes array without a `routes` key gets an empty route list and nothing else changes.
	 *
	 * @return void
	 */
	public function testARoutesArrayWithoutRoutesGetsAnEmptyList(): void {
		$this->assertSame(
			expected: ['ocs' => [], 'routes' => []],
			actual: ApiPathAliases::withVersionedAliases(routes: ['ocs' => []])
		);
	}//end testARoutesArrayWithoutRoutesGetsAnEmptyList()

	/**
	 * Routes and whether they are the API fallback.
	 *
	 * @return array<string,array{0:array<string,mixed>,1:bool}>
	 */
	public static function fallbacks(): array {
		return [
			'catch-all'                  => [['url' => '/api/{path}', 'requirements' => ['path' => '.+']], true],
			'catch-all without slash'    => [['url' => 'api/{rest}', 'requirements' => ['rest' => '.*']], true],
			'single segment by default'  => [['url' => '/api/{path}'], false],
			'requirement for other name' => [['url' => '/api/{path}', 'requirements' => ['other' => '.+']], false],
			'digits only'                => [['url' => '/api/{id}', 'requirements' => ['id' => '\d+']], false],
			'deeper placeholder'         => [['url' => '/api/things/{path}', 'requirements' => ['path' => '.+']], false],
			'no placeholder'             => [['url' => '/api/things'], false],
			'no url'                     => [[], false],
		];
	}//end fallbacks()

	/**
	 * Only `/api/{x}` whose requirement also matches a path with a slash is the API fallback.
	 *
	 * @param array<string,mixed> $route    The route.
	 * @param bool                $fallback Whether it is the API fallback.
	 *
	 * @return void
	 */
	#[DataProvider('fallbacks')]
	public function testOnlyACatchAllIsTheApiFallback(array $route, bool $fallback): void {
		$this->assertSame(expected: $fallback, actual: ApiPathAliases::isApiFallback(route: $route));
	}//end testOnlyACatchAllIsTheApiFallback()

	/**
	 * Paths and their successor.
	 *
	 * @return array<string,array{0:string,1:string|null}>
	 */
	public static function successors(): array {
		return [
			'api root'              => ['/api/', '/api/v1/'],
			'upper-case version'    => ['/api/V2/things', null],
			'version too long'      => ['/api/v1234/things', '/api/v1/v1234/things'],
			'version as last part'  => ['/api/zgw/v1', null],
			'metrics under app'     => ['/apps/keepiq/api/metrics', null],
			'no api prefix'         => ['/apiary/things', null],
			'bare api'              => ['/api', null],
		];
	}//end successors()

	/**
	 * Each path has the expected successor, and is deprecated exactly when it has one.
	 *
	 * @param string      $path      The path.
	 * @param string|null $successor The expected successor.
	 *
	 * @return void
	 */
	#[DataProvider('successors')]
	public function testTheSuccessorOfAnEdgePath(string $path, ?string $successor): void {
		$this->assertSame(expected: $successor, actual: ApiPathAliases::successorOf(path: $path));
		$this->assertSame(expected: $successor !== null, actual: ApiPathAliases::isDeprecated(path: $path));
	}//end testTheSuccessorOfAnEdgePath()
}//end class
