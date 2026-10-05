<?php

/**
 * Keepiq Metrics Controller
 *
 * Prometheus scrape endpoint (hydra ADR-006), restricted to admins and to
 * users delegated Keepiq's General admin area. Exposes aggregate counts
 * only: never an identifier, key material or owner.
 *
 * The output is byte-compatible with what OpenRegister's AppHost engine
 * produced for Keepiq (same series, HELP texts, label order and content
 * type), so existing scrape configs and dashboards keep working.
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
use OCA\Keepiq\Settings\AdminSettings;
use OCP\App\IAppManager;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http\Attribute\AuthorizedAdminSetting;
use OCP\AppFramework\Http\Attribute\NoCSRFRequired;
use OCP\AppFramework\Http\TextPlainResponse;
use OCP\DB\QueryBuilder\IQueryBuilder;
use OCP\IConfig;
use OCP\IDBConnection;
use OCP\IRequest;

/**
 * Serves GET /api/metrics.
 *
 * @spec openspec/specs/app-shell/spec.md#requirement-admin-only-metrics-endpoint
 */
class MetricsController extends Controller {
	/**
	 * Prometheus text exposition format 0.0.4.
	 *
	 * @var string
	 */
	public const CONTENT_TYPE = 'text/plain; version=0.0.4; charset=utf-8';

	/**
	 * Constructor for MetricsController.
	 *
	 * @param IRequest      $request    The HTTP request
	 * @param IDBConnection $db         The database connection
	 * @param IAppManager   $appManager The app manager (installed version)
	 * @param IConfig       $config     The system config (Nextcloud version)
	 *
	 * @return void
	 */
	public function __construct(
		IRequest $request,
		private readonly IDBConnection $db,
		private readonly IAppManager $appManager,
		private readonly IConfig $config,
	) {
		parent::__construct(appName: Application::APP_ID, request: $request);
	}//end __construct()

	/**
	 * Render the metrics.
	 *
	 * `#[AuthorizedAdminSetting]` admits admins and users delegated the
	 * General admin area, and is the explicit posture hydra gate-30 accepts
	 * for an admin-only scrape endpoint. `#[NoCSRFRequired]` because a
	 * scraper authenticates with an app password and sends no CSRF token.
	 *
	 * @return TextPlainResponse The Prometheus exposition
	 *
	 * @spec openspec/specs/app-shell/spec.md#requirement-admin-only-metrics-endpoint
	 */
	#[AuthorizedAdminSetting(settings: AdminSettings::class)]
	#[NoCSRFRequired]
	public function index(): TextPlainResponse {
		$lines = [
			'# HELP keepiq_info Application information',
			'# TYPE keepiq_info gauge',
			'keepiq_info{version="' . $this->escapeLabel(value: $this->appManager->getAppVersion(Application::APP_ID))
				. '",php_version="' . $this->escapeLabel(value: PHP_VERSION)
				. '",nextcloud_version="' . $this->escapeLabel(value: $this->config->getSystemValueString('version', 'unknown'))
				. '"} 1',
			'',
			'# HELP keepiq_up Whether the application is up',
			'# TYPE keepiq_up gauge',
			'keepiq_up 1',
			'',
			'# HELP keepiq_suites_total Total number of active encryption suites',
			'# TYPE keepiq_suites_total gauge',
			'keepiq_suites_total ' . $this->countActiveSuites(),
			'',
		];

		$response = new TextPlainResponse(implode("\n", $lines) . "\n");
		$response->addHeader('Content-Type', self::CONTENT_TYPE);
		return $response;
	}//end index()

	/**
	 * Count the active encryption suites with one SQL COUNT.
	 *
	 * @return int The number of suites with status `active`
	 *
	 * @spec openspec/specs/app-shell/spec.md#requirement-admin-only-metrics-endpoint
	 */
	private function countActiveSuites(): int {
		$qb = $this->db->getQueryBuilder();
		$qb->select($qb->func()->count('*', 'total'))
			->from('keepiq_enc_suites')
			->where($qb->expr()->eq('status', $qb->createNamedParameter('active', IQueryBuilder::PARAM_STR)));
		$result = $qb->executeQuery();
		$count = (int)$result->fetchOne();
		$result->closeCursor();
		return $count;
	}//end countActiveSuites()

	/**
	 * Escape a Prometheus label value (backslash, double quote, newline).
	 *
	 * @param string $value The raw value
	 *
	 * @return string The escaped value
	 */
	private function escapeLabel(string $value): string {
		return str_replace(['\\', '"', "\n"], ['\\\\', '\\"', '\\n'], $value);
	}//end escapeLabel()
}//end class
