<?php

/**
 * Doriath CxpRelayController
 *
 * A minimal, OPAQUE relay for the CXP (FIDO Credential Exchange Protocol)
 * browser-session handshake (cxp-transfer §2.2). Two cooperating Doriath
 * sessions that cannot reach each other directly exchange the CXP request and
 * the HPKE-sealed envelope through this mailbox.
 *
 * The relay is deliberately blind: it stores and returns an opaque string
 * (public keys + HPKE ciphertext only — NEVER plaintext, never key material the
 * server could open with, per ADR-003). It never parses the payload. Slots live
 * in the distributed cache with a short TTL and are one-shot (consumed on read).
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
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\Attribute\NoAdminRequired;
use OCP\AppFramework\Http\Attribute\NoCSRFRequired;
use OCP\AppFramework\Http\JSONResponse;
use OCP\ICache;
use OCP\ICacheFactory;
use OCP\IRequest;
use OCP\Security\ISecureRandom;

/**
 * Opaque mailbox for the CXP browser-session handshake.
 */
class CxpRelayController extends Controller
{
    /**
     * The relay slots. `request` carries the importer's CXP request; `response`
     * carries the exporter's HPKE-sealed envelope.
     *
     * @var string[]
     */
    private const SLOTS = ['request', 'response'];

    /**
     * Slot time-to-live in seconds — a handshake is short-lived.
     */
    private const TTL = 300;

    /**
     * Maximum opaque payload size (bytes). A sealed CXF envelope is bounded; a
     * generous cap prevents the relay being used as arbitrary storage.
     */
    private const MAX_PAYLOAD = 4194304;

    /**
     * The distributed cache backing the mailbox.
     *
     * @var ICache
     */
    private ICache $cache;

    /**
     * Constructor for CxpRelayController.
     *
     * @param IRequest      $request      The request
     * @param ICacheFactory $cacheFactory The cache factory
     * @param ISecureRandom $secureRandom The secure random generator
     *
     * @return void
     */
    public function __construct(
        IRequest $request,
        ICacheFactory $cacheFactory,
        private ISecureRandom $secureRandom,
    ) {
        parent::__construct(appName: Application::APP_ID, request: $request);
        $this->cache = $cacheFactory->createDistributed('doriath_cxp_relay');
    }//end __construct()

    /**
     * Build the cache key for a pairing/slot.
     *
     * @param string $pairingId The pairing id
     * @param string $slot      The slot name
     *
     * @return string
     */
    private function key(string $pairingId, string $slot): string
    {
        return $pairingId.':'.$slot;
    }//end key()

    /**
     * Store an opaque payload in a relay slot. If no pairing id is supplied a
     * fresh one is minted (the importer's first request).
     *
     * @param string|null $pairingId The pairing id (null mints a new one)
     * @param string      $slot      The slot (request|response)
     * @param string      $payload   The opaque payload (public keys + sealed bytes)
     *
     * @return JSONResponse
     */
    #[NoAdminRequired]
    #[NoCSRFRequired]
    public function put(?string $pairingId=null, string $slot='', string $payload=''): JSONResponse
    {
        if (in_array($slot, self::SLOTS, true) === false) {
            return new JSONResponse(data: ['error' => 'invalid slot'], statusCode: Http::STATUS_BAD_REQUEST);
        }

        if ($payload === '' || strlen($payload) > self::MAX_PAYLOAD) {
            return new JSONResponse(data: ['error' => 'invalid payload'], statusCode: Http::STATUS_BAD_REQUEST);
        }

        if ($pairingId === null || $pairingId === '') {
            $pairingId = $this->secureRandom->generate(24, ISecureRandom::CHAR_ALPHANUMERIC);
        } else if (ctype_alnum($pairingId) === false || strlen($pairingId) > 64) {
            return new JSONResponse(data: ['error' => 'invalid pairing id'], statusCode: Http::STATUS_BAD_REQUEST);
        }

        // Store the payload verbatim — the relay never inspects it.
        $this->cache->set($this->key(pairingId: $pairingId, slot: $slot), $payload, self::TTL);

        return new JSONResponse(data: ['pairingId' => $pairingId, 'slot' => $slot]);
    }//end put()

    /**
     * Fetch and consume an opaque payload from a relay slot (one-shot).
     *
     * @param string $pairingId The pairing id
     * @param string $slot      The slot (request|response)
     *
     * @return JSONResponse
     */
    #[NoAdminRequired]
    #[NoCSRFRequired]
    public function get(string $pairingId='', string $slot=''): JSONResponse
    {
        if (in_array($slot, self::SLOTS, true) === false || ctype_alnum($pairingId) === false) {
            return new JSONResponse(data: ['error' => 'invalid request'], statusCode: Http::STATUS_BAD_REQUEST);
        }

        $key     = $this->key(pairingId: $pairingId, slot: $slot);
        $payload = $this->cache->get($key);
        if ($payload === null) {
            return new JSONResponse(data: ['error' => 'not found'], statusCode: Http::STATUS_NOT_FOUND);
        }

        // One-shot: remove on read so a sealed envelope is not left lingering.
        $this->cache->remove($key);

        return new JSONResponse(data: ['payload' => $payload]);
    }//end get()
}//end class
