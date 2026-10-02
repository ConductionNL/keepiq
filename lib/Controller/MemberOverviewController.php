<?php

/**
 * Keepiq Member Overview Controller
 *
 * `GET /api/v1/admin/members`: the administrator's list of users with their
 * vault status (admin-member-overview-and-offboarding D4). Admin only: the
 * `#[AuthorizedAdminSetting]` gate refuses everyone else in Nextcloud's
 * middleware, before this controller runs. Metadata only.
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

use InvalidArgumentException;
use OCA\Keepiq\AppInfo\Application;
use OCA\Keepiq\Service\MemberOverviewService;
use OCA\Keepiq\Settings\AdminSettings;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\Attribute\AuthorizedAdminSetting;
use OCP\AppFramework\Http\JSONResponse;
use OCP\IRequest;

/**
 * Admin-only member overview.
 */
class MemberOverviewController extends Controller {
	/**
	 * Constructor.
	 *
	 * @param IRequest $request The request
	 * @param MemberOverviewService $service The member overview service
	 *
	 * @return void
	 *
	 * @spec exclude Constructor wiring only.
	 */
	public function __construct(
		IRequest $request,
		private MemberOverviewService $service,
	) {
		parent::__construct(appName: Application::APP_ID, request: $request);
	}//end __construct()

	/**
	 * List users with their vault status, paged and filtered.
	 *
	 * @param string $status Vault status filter, or '' for every status
	 * @param string $search Search on user id or display name
	 * @param int $limit Page size (default 50, at most 200)
	 * @param int $offset Rows to skip
	 *
	 * @AuthorizedAdminSetting(AdminSettings::class)
	 *
	 * @return JSONResponse
	 *
	 * @spec openspec/changes/admin-member-overview-and-offboarding/tasks.md#2.2
	 */
	#[AuthorizedAdminSetting(AdminSettings::class)]
	public function index(
		string $status = '',
		string $search = '',
		int $limit = MemberOverviewService::DEFAULT_LIMIT,
		int $offset = 0,
	): JSONResponse {
		try {
			return new JSONResponse(
				data: $this->service->list(status: $status, search: $search, limit: $limit, offset: $offset)
			);
		} catch (InvalidArgumentException $e) {
			return new JSONResponse(data: ['message' => $e->getMessage()], statusCode: Http::STATUS_BAD_REQUEST);
		}
	}//end index()
}//end class
