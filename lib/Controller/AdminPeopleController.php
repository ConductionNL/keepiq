<?php

/**
 * Keepiq admin API: people and offboarding
 *
 * Part of the versioned admin API under /api/v1/admin (admin-public-api).
 * The People and offboarding area: suite listing and offboarding.
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

use InvalidArgumentException;
use OCA\Keepiq\AppInfo\Application;
use OCA\Keepiq\Db\EncryptionSuite;
use OCA\Keepiq\Db\EncryptionSuiteMapper;
use OCA\Keepiq\Service\TeamFolderService;
use OCA\Keepiq\Settings\PeopleAdminSettings;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\Attribute\AuthorizedAdminSetting;
use OCP\AppFramework\Http\Attribute\UserRateLimit;
use OCP\AppFramework\Http\JSONResponse;
use OCP\IRequest;
use OCP\IUserSession;

/**
 * `GET /api/v1/admin/suites` and `POST /api/v1/admin/offboarding`.
 */
class AdminPeopleController extends Controller {
	/**
	 * The largest suite page a script may ask for.
	 *
	 * @var int
	 */
	private const MAX_LIMIT = 500;

	/**
	 * Constructor.
	 *
	 * @param IRequest $request The request
	 * @param EncryptionSuiteMapper $suites The suite rows
	 * @param TeamFolderService $teamFolders The offboarding entry point the admin screen uses
	 * @param IUserSession $userSession The acting administrator
	 *
	 * @return void
	 *
	 * @spec exclude Constructor wiring only.
	 */
	public function __construct(
		IRequest $request,
		private EncryptionSuiteMapper $suites,
		private TeamFolderService $teamFolders,
		private IUserSession $userSession,
	) {
		parent::__construct(appName: Application::APP_ID, request: $request);
	}//end __construct()

	/**
	 * Active suites, one page, metadata only: never the private key blob or
	 * the certificate.
	 *
	 * @param int $limit Page size, 1 to 500
	 * @param int $offset Rows to skip
	 *
	 * @AuthorizedAdminSetting(PeopleAdminSettings::class)
	 *
	 * @return JSONResponse
	 *
	 * @spec openspec/changes/admin-public-api/tasks.md#1.2
	 */
	#[AuthorizedAdminSetting(PeopleAdminSettings::class)]
	public function suites(int $limit = 100, int $offset = 0): JSONResponse {
		$limit = max(1, min(self::MAX_LIMIT, $limit));
		$offset = max(0, $offset);

		return new JSONResponse(
			data: [
				'limit' => $limit,
				'offset' => $offset,
				'results' => array_map(
					static fn (EncryptionSuite $suite): array => self::suiteRow(suite: $suite),
					$this->suites->findAllActiveWithLimit($limit, $offset)
				),
			]
		);
	}//end suites()

	/**
	 * Offboard a leaver: the same service call as the admin screen, which
	 * also checks the People area itself.
	 *
	 * @param string $leavingUserId The user being offboarded
	 * @param string $successorUserId The user taking over owned team secrets
	 *
	 * @AuthorizedAdminSetting(PeopleAdminSettings::class)
	 *
	 * @return JSONResponse
	 *
	 * @spec openspec/changes/admin-public-api/tasks.md#1.2
	 */
	#[AuthorizedAdminSetting(PeopleAdminSettings::class)]
	#[UserRateLimit(limit: 30, period: 60)]
	public function offboard(string $leavingUserId = '', string $successorUserId = ''): JSONResponse {
		try {
			$summary = $this->teamFolders->offboard(
				leavingUserId: $leavingUserId,
				successorUserId: $successorUserId,
				adminId: (string)$this->userSession->getUser()?->getUID()
			);
		} catch (InvalidArgumentException $exception) {
			return new JSONResponse(data: ['message' => $exception->getMessage()], statusCode: Http::STATUS_BAD_REQUEST);
		}

		return new JSONResponse(data: $summary);
	}//end offboard()

	/**
	 * The metadata of one suite.
	 *
	 * @param EncryptionSuite $suite The suite
	 *
	 * @return array<string,mixed>
	 */
	private static function suiteRow(EncryptionSuite $suite): array {
		$row = $suite->jsonSerialize();

		return [
			'id' => $row['id'],
			'ownerType' => $row['ownerType'],
			'ownerId' => $row['ownerId'],
			'status' => $row['status'],
			'createdAt' => $row['createdAt'],
			'revokedAt' => $row['revokedAt'],
			'revokedBy' => $row['revokedBy'],
			'reinstatedAt' => $row['reinstatedAt'],
			'reinstatedBy' => $row['reinstatedBy'],
		];
	}//end suiteRow()
}//end class
