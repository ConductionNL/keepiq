<?php

/**
 * Keepiq Health Controller
 *
 * Public liveness endpoint for uptime monitors and orchestrators
 * (hydra ADR-006). Reports only a status, the app id, the app version and
 * the outcome of two infrastructure checks: never secret material, row
 * counts or identifiers.
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
use OCP\App\IAppManager;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\Attribute\AnonRateLimit;
use OCP\AppFramework\Http\Attribute\NoCSRFRequired;
use OCP\AppFramework\Http\Attribute\PublicPage;
use OCP\AppFramework\Http\JSONResponse;
use OCP\IDBConnection;
use OCP\IRequest;
use OCP\ITempManager;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * Serves GET /api/health.
 *
 * @spec openspec/specs/app-shell/spec.md#requirement-public-health-endpoint
 */
class HealthController extends Controller {
	/**
	 * Constructor for HealthController.
	 *
	 * @param IRequest        $request     The HTTP request
	 * @param IDBConnection   $db          The database connection
	 * @param IAppManager     $appManager  The app manager (installed version)
	 * @param ITempManager    $tempManager The temp manager (filesystem check)
	 * @param LoggerInterface $logger      The logger
	 *
	 * @return void
	 */
	public function __construct(
		IRequest $request,
		private readonly IDBConnection $db,
		private readonly IAppManager $appManager,
		private readonly ITempManager $tempManager,
		private readonly LoggerInterface $logger,
	) {
		parent::__construct(appName: Application::APP_ID, request: $request);
	}//end __construct()

	/**
	 * Report the app's health.
	 *
	 * `database` is critical: a failure answers HTTP 503 with status
	 * `error`. `filesystem` is degraded: a failure answers HTTP 200 with
	 * status `degraded`, so a full temp disk does not page anyone as an
	 * outage. A failed check names only the exception class, never its
	 * message, because this endpoint is public. Anonymous callers are rate
	 * limited at the same 240/min the AppHost endpoint used.
	 *
	 * @return JSONResponse `{status, app, version, checks}`
	 *
	 * @spec openspec/specs/app-shell/spec.md#requirement-public-health-endpoint
	 */
	#[PublicPage]
	#[NoCSRFRequired]
	#[AnonRateLimit(limit: 240, period: 60)]
	public function index(): JSONResponse {
		$checks = [
			'database'   => $this->checkDatabase(),
			'filesystem' => $this->checkFilesystem(),
		];

		$status = 'ok';
		$httpStatus = Http::STATUS_OK;
		if ($checks['database'] !== 'ok') {
			$status = 'error';
			$httpStatus = Http::STATUS_SERVICE_UNAVAILABLE;
		} elseif ($checks['filesystem'] !== 'ok') {
			$status = 'degraded';
		}

		return new JSONResponse(
			data: [
				'status'  => $status,
				'app'     => Application::APP_ID,
				'version' => $this->appManager->getAppVersion(Application::APP_ID),
				'checks'  => $checks,
			],
			statusCode: $httpStatus
		);
	}//end index()

	/**
	 * Run a trivial read against a Keepiq table.
	 *
	 * @return string `ok`, or `failed: <exception class>`
	 *
	 * @spec openspec/specs/app-shell/spec.md#requirement-public-health-endpoint
	 */
	private function checkDatabase(): string {
		try {
			$qb = $this->db->getQueryBuilder();
			$qb->select('id')->from('keepiq_enc_suites')->setMaxResults(1);
			$qb->executeQuery()->closeCursor();
			return 'ok';
		} catch (Throwable $e) {
			$this->logger->error('[HealthController] Database check failed', ['exception' => $e]);
			return 'failed: ' . $this->shortClass(throwable: $e);
		}
	}//end checkDatabase()

	/**
	 * Write and remove a file in the temp directory.
	 *
	 * @return string `ok`, or `failed: <reason>`
	 *
	 * @spec openspec/specs/app-shell/spec.md#requirement-public-health-endpoint
	 */
	private function checkFilesystem(): string {
		try {
			$path = $this->tempManager->getTempBaseDir() . '/keepiq_health_' . bin2hex(random_bytes(8));
			if (@file_put_contents($path, 'health') === false) {
				return 'failed: TempDirectoryNotWritable';
			}

			@unlink($path);
			return 'ok';
		} catch (Throwable $e) {
			$this->logger->warning('[HealthController] Filesystem check failed', ['exception' => $e]);
			return 'failed: ' . $this->shortClass(throwable: $e);
		}
	}//end checkFilesystem()

	/**
	 * The unqualified class name of a throwable.
	 *
	 * @param Throwable $throwable The throwable
	 *
	 * @return string The class name without its namespace
	 */
	private function shortClass(Throwable $throwable): string {
		$class = get_class($throwable);
		$cut = strrpos($class, '\\');
		if ($cut === false) {
			return $class;
		}

		return substr($class, ($cut + 1));
	}//end shortClass()
}//end class
