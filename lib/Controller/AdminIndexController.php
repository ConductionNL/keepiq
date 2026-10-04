<?php

/**
 * Keepiq admin API: the index
 *
 * Part of the versioned admin API under /api/v1/admin (admin-public-api).
 * The index lists the served versions and every v1 path.
 * Each method is guarded by one admin area and calls the service the admin
 * screen calls; it holds no logic beyond parameter mapping.
 *
 * @category Controller
 * @package  OCA\Keepiq\Controller
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

namespace OCA\Keepiq\Controller;

use OCA\Keepiq\AppInfo\Application;
use OCA\Keepiq\Service\AdminAreaAuthorizer;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\Attribute\NoAdminRequired;
use OCP\AppFramework\Http\JSONResponse;
use OCP\IRequest;
use OCP\IUserSession;

/**
 * `GET /api/v1/admin`: the version and every path.
 */
class AdminIndexController extends Controller {
	/**
	 * The admin API version this index describes.
	 *
	 * @var int
	 */
	public const API_VERSION = 1;

	/**
	 * Every v1 path, with its method and admin area. Force revocation and
	 * suite reinstatement are left out on purpose: both need a fresh password
	 * confirmation that a stored app password cannot give (design D4).
	 *
	 * @var array<int,array{method:string,path:string,area:string}>
	 */
	public const PATHS = [
		['method' => 'GET', 'path' => '/api/v1/admin', 'area' => 'any'],
		['method' => 'GET', 'path' => '/api/v1/admin/policies', 'area' => 'policies'],
		['method' => 'PUT', 'path' => '/api/v1/admin/policies', 'area' => 'policies'],
		['method' => 'GET', 'path' => '/api/v1/admin/members', 'area' => 'people'],
		['method' => 'GET', 'path' => '/api/v1/admin/suites', 'area' => 'people'],
		['method' => 'POST', 'path' => '/api/v1/admin/offboarding', 'area' => 'people'],
		['method' => 'GET', 'path' => '/api/v1/admin/applications', 'area' => 'applications'],
		['method' => 'POST', 'path' => '/api/v1/admin/applications', 'area' => 'applications'],
		['method' => 'GET', 'path' => '/api/v1/admin/applications/{id}', 'area' => 'applications'],
		['method' => 'DELETE', 'path' => '/api/v1/admin/applications/{id}', 'area' => 'applications'],
		['method' => 'POST', 'path' => '/api/v1/admin/applications/{id}/approve', 'area' => 'applications'],
		['method' => 'POST', 'path' => '/api/v1/admin/applications/{id}/reject', 'area' => 'applications'],
		['method' => 'GET', 'path' => '/api/v1/admin/applications/{id}/lease-policy', 'area' => 'applications'],
		['method' => 'PUT', 'path' => '/api/v1/admin/applications/{id}/lease-policy', 'area' => 'applications'],
		['method' => 'GET', 'path' => '/api/v1/admin/audit', 'area' => 'audit'],
		['method' => 'GET', 'path' => '/api/v1/admin/compliance/reports', 'area' => 'audit'],
		['method' => 'POST', 'path' => '/api/v1/admin/compliance/reports', 'area' => 'audit'],
		['method' => 'GET', 'path' => '/api/v1/admin/compliance/reports/{id}', 'area' => 'audit'],
		['method' => 'GET', 'path' => '/api/v1/admin/siem/sinks', 'area' => 'audit'],
		['method' => 'POST', 'path' => '/api/v1/admin/siem/sinks', 'area' => 'audit'],
		['method' => 'PUT', 'path' => '/api/v1/admin/siem/sinks/{id}', 'area' => 'audit'],
		['method' => 'DELETE', 'path' => '/api/v1/admin/siem/sinks/{id}', 'area' => 'audit'],
	];

	/**
	 * Constructor.
	 *
	 * @param IRequest $request The request
	 * @param IUserSession $userSession The session user
	 * @param AdminAreaAuthorizer $areas The admin areas the caller holds
	 *
	 * @return void
	 *
	 * @spec exclude Constructor wiring only.
	 */
	public function __construct(
		IRequest $request,
		private IUserSession $userSession,
		private AdminAreaAuthorizer $areas,
	) {
		parent::__construct(appName: Application::APP_ID, request: $request);
	}//end __construct()

	/**
	 * The admin API index, for any holder of at least one admin area. One
	 * attribute names one class, so "any area" is checked here instead.
	 *
	 * @NoAdminRequired
	 *
	 * @return JSONResponse
	 *
	 * @spec openspec/specs/admin-api/spec.md#requirement-versioned-admin-api-index
	 */
	#[NoAdminRequired]
	public function index(): JSONResponse {
		$userId = $this->userSession->getUser()?->getUID();
		$held = [];
		if ($userId !== null) {
			$held = $this->areas->areasOf(userId: $userId);
		}

		if ($held === []) {
			return new JSONResponse(data: ['message' => 'The admin API needs a Keepiq admin area'], statusCode: Http::STATUS_FORBIDDEN);
		}

		return new JSONResponse(
			data: [
				'apiVersion' => self::API_VERSION,
				'versions' => ['v1'],
				'areas' => $held,
				'paths' => self::PATHS,
				'notOffered' => [
					'suite force revocation and reinstatement: both need a fresh password confirmation, which an app password cannot give',
				],
			]
		);
	}//end index()
}//end class
