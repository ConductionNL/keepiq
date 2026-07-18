<?php

/**
 * Doriath Machine API Discovery Controller
 *
 * Serves the unauthenticated, machine-readable discovery document at
 * `GET /api/v1/app/.well-known/doriath`. A consumer configures one base
 * URL plus its application id and private key, fetches this document, and
 * derives every contract URL (token endpoint, grant type, assertion
 * requirements, secret endpoints, envelope formats) without reading
 * Doriath source. The document carries no instance-private data.
 *
 * @category Controller
 * @package  OCA\Doriath\Controller
 *
 * @author    Conduction Development Team <dev@conductio.nl>
 * @copyright 2024 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git-id>
 *
 * @link https://conduction.nl
 */

declare(strict_types=1);

namespace OCA\Doriath\Controller;

use OCA\Doriath\AppInfo\Application as DoriathApp;
use OCA\Doriath\Service\JwtAuthService;
use OCA\Doriath\Service\MachineSecretEnvelopeService;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http\Attribute\NoCSRFRequired;
use OCP\AppFramework\Http\Attribute\PublicPage;
use OCP\AppFramework\Http\JSONResponse;
use OCP\IAppConfig;
use OCP\IRequest;
use OCP\IURLGenerator;

/**
 * Public discovery endpoint for the machine secret-store API.
 *
 * @spec openspec/changes/openconnector-secret-store-api/specs/secret-store-api/spec.md
 */
class DiscoveryController extends Controller
{

    /**
     * The machine API version this document describes.
     *
     * @var int
     */
    public const API_VERSION = 1;

    /**
     * Constructor for DiscoveryController.
     *
     * @param IRequest        $request      The HTTP request
     * @param IURLGenerator   $urlGenerator The URL generator
     * @param IAppConfig|null $appConfig    The app config (lease policy advert)
     *
     * @return void
     */
    public function __construct(
        IRequest $request,
        private IURLGenerator $urlGenerator,
        private ?IAppConfig $appConfig=null,
    ) {
        parent::__construct(appName: DoriathApp::APP_ID, request: $request);
    }//end __construct()

    /**
     * Return the machine API discovery document.
     *
     * Public (no auth): it reveals only endpoint shapes and assertion
     * requirements, nothing instance-private. The token endpoint is
     * published as an absolute URL so a consumer can use it directly as
     * the assertion `aud` claim when the deployment opts into URL-bound
     * audiences (the default audience string remains the value documented
     * in the consumption recipe).
     *
     * @return JSONResponse
     *
     * @spec openspec/changes/openconnector-secret-store-api/specs/secret-store-api/spec.md
     */
    #[PublicPage]
    #[NoCSRFRequired]
    public function document(): JSONResponse
    {
        $tokenEndpoint = $this->urlGenerator->linkToRoute('doriath.applicationToken.exchange');
        $tokenAbsolute = $this->urlGenerator->getAbsoluteURL($tokenEndpoint);

        return new JSONResponse(
            data: [
                'apiVersion'      => self::API_VERSION,
                'tokenEndpoint'   => $tokenEndpoint,
                'grantType'       => 'urn:ietf:params:oauth:grant-type:jwt-bearer',
                'assertion'       => [
                    'alg'         => 'RS256',
                    'maxLifetime' => JwtAuthService::ACCESS_TOKEN_TTL,
                    'audience'    => JwtAuthService::EXPECTED_AUDIENCE,
                    'audienceUrl' => $tokenAbsolute,
                ],
                'secrets'         => [
                    'list'   => $this->urlGenerator->linkToRoute('doriath.applicationSecrets.index'),
                    'byId'   => $this->urlGenerator->linkToRoute('doriath.applicationSecrets.index').'/{id}',
                    'byName' => $this->urlGenerator->linkToRoute('doriath.applicationSecrets.index').'/by-name/{name}',
                    'create' => $this->urlGenerator->linkToRoute('doriath.applicationSecrets.index'),
                    'update' => $this->urlGenerator->linkToRoute('doriath.applicationSecrets.index').'/{id}',
                ],
                'envelopeFormats' => [MachineSecretEnvelopeService::FORMAT],
                // Machine leases (machine-secret-leases §3.3): additive
                // advert of the instance lease policy — no envelope or
                // addressing change.
                'lease'           => [
                    'supported'  => true,
                    'defaultTtl' => $this->appConfig?->getValueInt(DoriathApp::APP_ID, 'lease_default_ttl_seconds', 900) ?? 900,
                    'maxTtl'     => $this->appConfig?->getValueInt(DoriathApp::APP_ID, 'lease_max_ttl_seconds', 86400) ?? 86400,
                    'renewable'  => $this->appConfig?->getValueBool(DoriathApp::APP_ID, 'lease_renewable', true) ?? true,
                ],
            ]
        );
    }//end document()
}//end class
