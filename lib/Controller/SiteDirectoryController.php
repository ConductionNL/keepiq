<?php

/**
 * Keepiq Site Directory Controller
 *
 * Serves the stored site directory copy to signed-in users, so the password
 * health report can match logins against it in the browser
 * (health-site-security-checks D2). The route takes nothing from the browser:
 * no host, address, flag or count. 404 while the admin switch is off or no copy
 * was fetched yet; an ETag lets the browser keep its cached copy.
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
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @spec openspec/changes/archive/2026-11-17-health-site-security-checks/tasks.md#task-2.2
 */

declare(strict_types=1);

namespace OCA\Keepiq\Controller;

use OCA\Keepiq\Kernel\AppIdentity;
use OCA\Keepiq\Service\SiteDirectoryService;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\Attribute\NoAdminRequired;
use OCP\AppFramework\Http\DataDisplayResponse;
use OCP\AppFramework\Http\DataResponse;
use OCP\AppFramework\Http\Response;
use OCP\IRequest;
use OCP\IUserSession;

/**
 * GET /api/v1/site-directory.
 *
 * @spec openspec/changes/archive/2026-11-17-health-site-security-checks/tasks.md#task-2.2
 */
class SiteDirectoryController extends Controller {

	/**
	 * Constructor.
	 *
	 * @param IRequest             $request     The request
	 * @param SiteDirectoryService $directory   The stored copy
	 * @param IUserSession         $userSession The signed-in user
	 *
	 * @return void
	 */
	public function __construct(
		IRequest $request,
		private SiteDirectoryService $directory,
		private IUserSession $userSession,
	) {
		parent::__construct(appName: AppIdentity::APP_ID, request: $request);
	}//end __construct()

	/**
	 * The site directory copy, the same for every user.
	 *
	 * @return Response 200 with the copy and its ETag, 304 when the browser holds
	 *                  it already, 401 without a user, 404 while switched off or
	 *                  before the first refresh.
	 *
	 * @spec openspec/specs/health-site-checks/spec.md#requirement-the-site-directory-is-fetched-whole-and-taught-nothing
	 */
	#[NoAdminRequired]
	public function index(): Response {
		if ($this->userSession->getUser() === null) {
			return new DataResponse(data: ['message' => 'Unauthorized'], statusCode: Http::STATUS_UNAUTHORIZED);
		}

		$copy = null;
		if ($this->directory->isEnabled() === true) {
			$copy = $this->directory->current();
		}

		if ($copy === null) {
			return new DataResponse(data: ['message' => 'The site directory is not available'], statusCode: Http::STATUS_NOT_FOUND);
		}

		$etag = hash('sha256', $copy);
		if (trim($this->request->getHeader('If-None-Match'), " \t\"") === $etag) {
			$notModified = new Response();
			$notModified->setStatus(Http::STATUS_NOT_MODIFIED);
			$notModified->setETag($etag);

			return $notModified;
		}

		$response = new DataDisplayResponse($copy, Http::STATUS_OK, ['Content-Type' => 'application/json']);
		$response->setETag($etag);

		return $response;
	}//end index()
}//end class
