<?php

/**
 * Keepiq Backup Admin Controller
 *
 * What the "Vault backups" admin section shows and does
 * (admin-scheduled-vault-backups §4.1): the last result and the archive
 * list (names, sizes, times, encrypted or not), and "Back up now", which asks
 * the next cron run to back up. Admin only. There is deliberately NO route
 * that serves archive content: a download link would let anyone with the
 * admin page copy every vault's ciphertext through the browser (design D6).
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
use OCA\Keepiq\Backup\BackupService;
use OCA\Keepiq\Settings\AdminSettings;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\Attribute\AuthorizedAdminSetting;
use OCP\AppFramework\Http\JSONResponse;
use OCP\IRequest;

/**
 * Admin-only backup status, list and "Back up now".
 */
class BackupAdminController extends Controller {
	/**
	 * Constructor.
	 *
	 * @param IRequest $request The request
	 * @param BackupService $backups The backup service
	 *
	 * @return void
	 *
	 * @spec exclude Constructor wiring only.
	 */
	public function __construct(
		IRequest $request,
		private BackupService $backups,
	) {
		parent::__construct(appName: Application::APP_ID, request: $request);
	}//end __construct()

	/**
	 * The last run and the archive list, without the disk paths and never
	 * any archive content.
	 *
	 * @AuthorizedAdminSetting(AdminSettings::class)
	 *
	 * @return JSONResponse
	 *
	 * @spec openspec/changes/admin-scheduled-vault-backups/tasks.md#4.1
	 */
	#[AuthorizedAdminSetting(AdminSettings::class)]
	public function index(): JSONResponse {
		$archives = array_map(
			static fn (array $archive): array => [
				'name' => $archive['name'],
				'size' => $archive['size'],
				'createdAt' => $archive['createdAt'],
				'encrypted' => $archive['encrypted'],
			],
			$this->backups->listArchives()
		);

		return new JSONResponse(data: ['status' => $this->backups->status(), 'archives' => $archives]);
	}//end index()

	/**
	 * Ask the next cron run to back up.
	 *
	 * @AuthorizedAdminSetting(AdminSettings::class)
	 *
	 * @return JSONResponse
	 *
	 * @spec openspec/changes/admin-scheduled-vault-backups/tasks.md#4.1
	 */
	#[AuthorizedAdminSetting(AdminSettings::class)]
	public function run(): JSONResponse {
		$this->backups->requestRun();

		return new JSONResponse(data: ['requested' => true], statusCode: Http::STATUS_ACCEPTED);
	}//end run()
}//end class
