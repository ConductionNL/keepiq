<?php

/**
 * Keepiq application certificate controller
 *
 * `GET /api/v1/app/certificate`: the authenticated application's own active
 * certificate and its fingerprint (client libraries and Kubernetes
 * follow-up, keepiq#776/#777). A client compares the fingerprint with the
 * `certificateFingerprint` of every envelope it receives, so it can refuse
 * a secret encrypted to another key without the operator configuring the
 * certificate by hand.
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

use OCA\Keepiq\AppInfo\Application as KeepiqApp;
use OCA\Keepiq\Db\EncryptionSuiteMapper;
use OCA\Keepiq\Service\MachineSecretEnvelopeService;
use OCP\AppFramework\Db\DoesNotExistException;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\Attribute\AnonRateLimit;
use OCP\AppFramework\Http\Attribute\NoCSRFRequired;
use OCP\AppFramework\Http\Attribute\PublicPage;
use OCP\AppFramework\Http\JSONResponse;
use OCP\IRequest;

/**
 * The calling application's own certificate, on the Bearer machine surface.
 */
class ApplicationCertificateController extends ApplicationApiController {
	/**
	 * Constructor.
	 *
	 * @param IRequest $request The request
	 * @param EncryptionSuiteMapper $suites The application's suites
	 * @param MachineSecretEnvelopeService $envelopes The fingerprint the envelopes carry
	 *
	 * @return void
	 *
	 * @spec exclude Constructor wiring only.
	 */
	public function __construct(
		IRequest $request,
		private EncryptionSuiteMapper $suites,
		private MachineSecretEnvelopeService $envelopes,
	) {
		parent::__construct(appName: KeepiqApp::APP_ID, request: $request);
	}//end __construct()

	/**
	 * The application's active certificate (public, PEM), its suite and the
	 * `sha256:` fingerprint over its DER, computed exactly as the envelope's
	 * `certificateFingerprint`. Only the Bearer-authenticated application
	 * itself reaches its own certificate; JwtAuthMiddleware binds it.
	 *
	 * @PublicPage
	 * @NoCSRFRequired
	 *
	 * @return JSONResponse
	 *
	 * @spec openspec/changes/app-own-certificate/tasks.md#1.1
	 */
	#[PublicPage]
	#[NoCSRFRequired]
	#[AnonRateLimit(limit: 30, period: 60)]
	public function show(): JSONResponse {
		$application = $this->getApplication();
		if ($application === null) {
			return new JSONResponse(data: ['message' => 'Bearer token required'], statusCode: Http::STATUS_UNAUTHORIZED);
		}

		try {
			$suite = $this->suites->findActiveByOwner('application', (string)$application->getId());
		} catch (DoesNotExistException) {
			return new JSONResponse(data: ['message' => 'No active encryption suite'], statusCode: Http::STATUS_NOT_FOUND);
		}

		return new JSONResponse(
			data: [
				'applicationId' => $application->getId(),
				'suiteId' => $suite->getId(),
				'certificate' => $suite->getCertificate(),
				'certificateFingerprint' => $this->envelopes->certificateFingerprint(suiteId: (string)$suite->getId()),
			]
		);
	}//end show()
}//end class
