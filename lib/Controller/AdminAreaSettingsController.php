<?php

/**
 * Keepiq admin area settings controller
 *
 * The admin settings, read and written per admin area (admin-scoped-roles
 * D2, decision of 2 Oct). Each area has one GET and one PUT route, guarded by
 * that area's class, and each write touches only that area's keys. People
 * owns no settings keys, so it has no route here.
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
use OCA\Keepiq\Service\Connection\ConnectionReporter;
use OCA\Keepiq\Service\SettingsService;
use OCA\Keepiq\Settings\AdminSettings;
use OCA\Keepiq\Settings\ApplicationAdminSettings;
use OCA\Keepiq\Settings\AuditAdminSettings;
use OCA\Keepiq\Settings\PolicyAdminSettings;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\Attribute\AuthorizedAdminSetting;
use OCP\AppFramework\Http\Attribute\UserRateLimit;
use OCP\AppFramework\Http\JSONResponse;
use OCP\IRequest;

/**
 * One GET and one PUT per settings-bearing admin area.
 */
class AdminAreaSettingsController extends Controller {
	/**
	 * Constructor.
	 *
	 * @param IRequest $request The request
	 * @param SettingsService $settingsService The settings service
	 * @param ConnectionReporter|null $connectionReporter Asks integriq to look again after a breach check save, or nothing when absent.
	 *
	 * @return void
	 *
	 * @spec exclude Constructor wiring only.
	 */
	public function __construct(
		IRequest $request,
		private SettingsService $settingsService,
		private ?ConnectionReporter $connectionReporter = null,
	) {
		parent::__construct(appName: Application::APP_ID, request: $request);
	}//end __construct()

	/**
	 * Read the General area settings (admin-scoped-roles D2).
	 *
	 * @AuthorizedAdminSetting(AdminSettings::class)
	 *
	 * @return JSONResponse
	 *
	 * @spec openspec/changes/admin-scoped-roles/tasks.md#2.1
	 */
	#[AuthorizedAdminSetting(AdminSettings::class)]
	public function getGeneralSettings(): JSONResponse {
		return $this->readArea(area: 'general');
	}//end getGeneralSettings()

	/**
	 * Write the General area settings (admin-scoped-roles D2).
	 *
	 * A save that wrote `breach_check_enabled` asks integriq to resolve the
	 * breach check connection again (adopt-connection-registry). That never
	 * throws, does nothing without integriq, and never changes the response.
	 *
	 * @AuthorizedAdminSetting(AdminSettings::class)
	 *
	 * @return JSONResponse
	 *
	 * @spec openspec/changes/admin-scoped-roles/tasks.md#2.1
	 * @spec openspec/changes/adopt-connection-registry/specs/admin-integrations/spec.md#requirement-req-keepiq-conn-002-a-save-asks-integriq-to-look-again-and-a-lookup-or-a-drain-reports-what-it-met
	 */
	#[AuthorizedAdminSetting(AdminSettings::class)]
	#[UserRateLimit(limit: 60, period: 60)]
	public function updateGeneralSettings(): JSONResponse {
		$data = $this->request->getParams();
		$response = $this->writeArea(area: 'general', data: $data);

		// The same test AdminSettingsService uses to decide it wrote the key.
		if ($response->getStatus() === Http::STATUS_OK && isset($data['breach_check_enabled']) === true) {
			$this->connectionReporter?->breachCheckSaved();
		}

		return $response;
	}//end updateGeneralSettings()

	/**
	 * Read the Policies area settings (admin-scoped-roles D2).
	 *
	 * @AuthorizedAdminSetting(PolicyAdminSettings::class)
	 *
	 * @return JSONResponse
	 *
	 * @spec openspec/changes/admin-scoped-roles/tasks.md#2.1
	 */
	#[AuthorizedAdminSetting(PolicyAdminSettings::class)]
	public function getPolicySettings(): JSONResponse {
		return $this->readArea(area: 'policies');
	}//end getPolicySettings()

	/**
	 * Write the Policies area settings (admin-scoped-roles D2).
	 *
	 * @AuthorizedAdminSetting(PolicyAdminSettings::class)
	 *
	 * @return JSONResponse
	 *
	 * @spec openspec/changes/admin-scoped-roles/tasks.md#2.1
	 */
	#[AuthorizedAdminSetting(PolicyAdminSettings::class)]
	#[UserRateLimit(limit: 60, period: 60)]
	public function updatePolicySettings(): JSONResponse {
		return $this->writeArea(area: 'policies', data: $this->request->getParams());
	}//end updatePolicySettings()

	/**
	 * Read the Applications area settings (admin-scoped-roles D2).
	 *
	 * @AuthorizedAdminSetting(ApplicationAdminSettings::class)
	 *
	 * @return JSONResponse
	 *
	 * @spec openspec/changes/admin-scoped-roles/tasks.md#2.1
	 */
	#[AuthorizedAdminSetting(ApplicationAdminSettings::class)]
	public function getApplicationSettings(): JSONResponse {
		return $this->readArea(area: 'applications');
	}//end getApplicationSettings()

	/**
	 * Write the Applications area settings (admin-scoped-roles D2).
	 *
	 * @AuthorizedAdminSetting(ApplicationAdminSettings::class)
	 *
	 * @return JSONResponse
	 *
	 * @spec openspec/changes/admin-scoped-roles/tasks.md#2.1
	 */
	#[AuthorizedAdminSetting(ApplicationAdminSettings::class)]
	#[UserRateLimit(limit: 60, period: 60)]
	public function updateApplicationSettings(): JSONResponse {
		return $this->writeArea(area: 'applications', data: $this->request->getParams());
	}//end updateApplicationSettings()

	/**
	 * Read the Audit area settings (admin-scoped-roles D2).
	 *
	 * @AuthorizedAdminSetting(AuditAdminSettings::class)
	 *
	 * @return JSONResponse
	 *
	 * @spec openspec/changes/admin-scoped-roles/tasks.md#2.1
	 */
	#[AuthorizedAdminSetting(AuditAdminSettings::class)]
	public function getAuditSettings(): JSONResponse {
		return $this->readArea(area: 'audit');
	}//end getAuditSettings()

	/**
	 * Write the Audit area settings (admin-scoped-roles D2).
	 *
	 * @AuthorizedAdminSetting(AuditAdminSettings::class)
	 *
	 * @return JSONResponse
	 *
	 * @spec openspec/changes/admin-scoped-roles/tasks.md#2.1
	 */
	#[AuthorizedAdminSetting(AuditAdminSettings::class)]
	#[UserRateLimit(limit: 60, period: 60)]
	public function updateAuditSettings(): JSONResponse {
		return $this->writeArea(area: 'audit', data: $this->request->getParams());
	}//end updateAuditSettings()

	/**
	 * One area's settings as a response.
	 *
	 * @param string $area The area key
	 *
	 * @return JSONResponse
	 */
	private function readArea(string $area): JSONResponse {
		return new JSONResponse(data: $this->settingsService->getAreaSettings(area: $area));
	}//end readArea()

	/**
	 * Write one area's keys; a key of another area or an out-of-bounds value
	 * answers 400 and writes nothing of the failing group.
	 *
	 * @param string $area The area key
	 * @param array<string,mixed> $data The request parameters
	 *
	 * @return JSONResponse
	 */
	private function writeArea(string $area, array $data): JSONResponse {
		try {
			$result = $this->settingsService->updateAreaSettings(area: $area, data: $data);
		} catch (InvalidArgumentException $e) {
			return new JSONResponse(
				data: ['message' => $e->getMessage()],
				statusCode: Http::STATUS_BAD_REQUEST
			);
		}

		return new JSONResponse(data: $result);
	}//end writeArea()
}//end class
