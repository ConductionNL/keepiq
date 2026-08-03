<?php

/**
 * Doriath ExtensionController
 *
 * The thin server surface the browser extension needs on top of the existing
 * always-E2E secret endpoints (browser-extension-autofill §1). The extension is
 * a SECOND end-to-end client (ADR-003): it authenticates with the paired
 * Nextcloud app-password, and this controller returns only the SAME encrypted
 * blobs the web client gets — plaintext `name`/`url` for matching, ciphertext
 * `key`/`login`/`additionalFields` that only the extension can decrypt. There is
 * NO server-side decrypt path here.
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

use OCA\Doriath\AppInfo\Application;
use OCA\Doriath\Db\SecretMapper;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\Attribute\NoAdminRequired;
use OCP\AppFramework\Http\Attribute\NoCSRFRequired;
use OCP\AppFramework\Http\JSONResponse;
use OCP\IRequest;
use OCP\IUserSession;

/**
 * Pairing + URL-match surface for the browser extension.
 */
class ExtensionController extends Controller
{
    /**
     * Maximum match rows returned in one lookup.
     */
    private const MATCH_LIMIT = 200;

    /**
     * Multi-label public suffixes for the coarse registrable-domain term. This
     * is only a SUPERSET filter — the extension re-ranks precisely client-side,
     * so an over-broad term merely widens the candidate list, never mis-fills.
     *
     * @var string[]
     */
    private const MULTI_LABEL_SUFFIXES = [
        'co.uk',
        'org.uk',
        'gov.uk',
        'ac.uk',
        'co.jp',
        'com.au',
        'net.au',
        'com.br',
        'co.nz',
        'co.za',
        'com.mx',
        'co.in',
        'gov.nl',
    ];

    /**
     * Constructor for ExtensionController.
     *
     * @param IRequest     $request      The request
     * @param SecretMapper $secretMapper The secret mapper
     * @param IUserSession $userSession  The user session
     *
     * @return void
     */
    public function __construct(
        IRequest $request,
        private SecretMapper $secretMapper,
        private IUserSession $userSession,
    ) {
        parent::__construct(appName: Application::APP_ID, request: $request);
    }//end __construct()

    /**
     * Resolve the session/app-password user id, or null when unauthenticated.
     *
     * @return string|null
     */
    private function uid(): ?string
    {
        $user = $this->userSession->getUser();
        if ($user === null) {
            return null;
        }

        return $user->getUID();
    }//end uid()

    /**
     * Pair: confirm the app-password authenticates and advertise the extension
     * capabilities. No new long-lived Doriath credential is minted — the pairing
     * IS the Nextcloud app-password, revocable from NC security settings.
     *
     * @return JSONResponse
     */
    #[NoAdminRequired]
    #[NoCSRFRequired]
    public function pair(): JSONResponse
    {
        $uid = $this->uid();
        if ($uid === null) {
            return new JSONResponse(data: ['error' => 'unauthorized'], statusCode: Http::STATUS_UNAUTHORIZED);
        }

        return new JSONResponse(
            data: [
                'ok'           => true,
                'user'         => $uid,
                'apiVersion'   => 1,
                'capabilities' => ['match', 'autofill', 'passkey-provider', 'totp'],
            ]
        );
    }//end pair()

    /**
     * Unpair: pairing is the NC app-password, so unpairing is revoking it in
     * Nextcloud security settings. This endpoint is a no-op acknowledgement the
     * extension calls to clear its own local state.
     *
     * @return JSONResponse
     */
    #[NoAdminRequired]
    #[NoCSRFRequired]
    public function unpair(): JSONResponse
    {
        if ($this->uid() === null) {
            return new JSONResponse(data: ['error' => 'unauthorized'], statusCode: Http::STATUS_UNAUTHORIZED);
        }

        return new JSONResponse(data: ['ok' => true, 'note' => 'Revoke the app-password in Nextcloud security settings to fully unpair.']);
    }//end unpair()

    /**
     * Coarse registrable domain (eTLD+1 approximation) of a host, used as the
     * superset match term. The extension refines precisely client-side.
     *
     * @param string $host The hostname
     *
     * @return string
     */
    private function registrableDomain(string $host): string
    {
        $host = strtolower(trim($host));
        if ($host === '' || strpos($host, '.') === false) {
            return $host;
        }

        $parts   = explode('.', $host);
        $lastTwo = implode('.', array_slice($parts, -2));
        if (count($parts) >= 3 && in_array($lastTwo, self::MULTI_LABEL_SUFFIXES, true) === true) {
            return implode('.', array_slice($parts, -3));
        }

        return $lastTwo;
    }//end registrableDomain()

    /**
     * Match: return the caller's secret rows whose plaintext `url`/`name` match
     * the given host. Returns BLOBS ONLY (ciphertext `key`/`login`); the
     * extension decrypts the chosen one client-side (browser-extension-autofill
     * §1.2).
     *
     * @param string $host The active tab host or origin
     *
     * @return JSONResponse
     */
    #[NoAdminRequired]
    #[NoCSRFRequired]
    public function match(string $host=''): JSONResponse
    {
        $uid = $this->uid();
        if ($uid === null) {
            return new JSONResponse(data: ['error' => 'unauthorized'], statusCode: Http::STATUS_UNAUTHORIZED);
        }

        $host = strtolower(trim($host));
        // Strip a scheme/path if a full origin was passed.
        $host = preg_replace('#^[a-z]+://#', '', $host) ?? $host;
        $host = explode('/', $host)[0];
        if ($host === '') {
            return new JSONResponse(data: ['error' => 'host is required'], statusCode: Http::STATUS_BAD_REQUEST);
        }

        $term    = $this->registrableDomain(host: $host);
        $secrets = $this->secretMapper->searchByNameOrUrl(ownerType: 'user', ownerId: $uid, term: $term, limit: self::MATCH_LIMIT);

        $items = array_map(static fn ($secret) => $secret->jsonSerialize(), $secrets);

        return new JSONResponse(data: ['items' => $items, 'host' => $host, 'term' => $term]);
    }//end match()
}//end class
