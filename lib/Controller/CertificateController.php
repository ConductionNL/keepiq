<?php

/**
 * Doriath Certificate Controller
 *
 * Certificate-lifecycle endpoints (certificate-lifecycle §4): the
 * three-source inventory, client-parsed metadata submission for
 * encrypted stored certificates, the guided renewal checklist, and
 * private-CA suite re-issue. Every method declares an explicit auth
 * attribute; owner-scoped methods guard per object in the service (no
 * IDOR). No PEM, private key, or ciphertext is ever emitted.
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

use InvalidArgumentException;
use OCA\Doriath\AppInfo\Application;
use OCA\Doriath\Service\CertificateLifecycleService;
use OCP\AppFramework\Db\DoesNotExistException;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\Attribute\NoAdminRequired;
use OCP\AppFramework\Http\JSONResponse;
use OCP\AppFramework\OCSController;
use OCP\IGroupManager;
use OCP\IRequest;
use OCP\IUserSession;

/**
 * Endpoints for the certificate lifecycle capability.
 */
class CertificateController extends OCSController
{
    /**
     * Constructor for CertificateController.
     *
     * @param IRequest                    $request      The request object
     * @param CertificateLifecycleService $service      The lifecycle service
     * @param IUserSession                $userSession  The user session
     * @param IGroupManager               $groupManager The group manager (admin scope)
     *
     * @return void
     */
    public function __construct(
        IRequest $request,
        private CertificateLifecycleService $service,
        private IUserSession $userSession,
        private IGroupManager $groupManager,
    ) {
        parent::__construct(appName: Application::APP_ID, request: $request);
    }//end __construct()

    /**
     * The calling user's id, or null when unauthenticated.
     *
     * @return string|null
     */
    private function uid(): ?string
    {
        return $this->userSession->getUser()?->getUID();
    }//end uid()

    /**
     * Whether the caller is an admin.
     *
     * @param string $uid The user id
     *
     * @return bool
     */
    private function isAdmin(string $uid): bool
    {
        return $this->groupManager->isAdmin($uid);
    }//end isAdmin()

    /**
     * The certificate inventory: own stored certs + own suite cert;
     * admins additionally see all suites and the CA certificates.
     *
     * @NoAdminRequired
     *
     * @return JSONResponse
     *
     * @spec openspec/changes/certificate-lifecycle/specs/certificate-lifecycle/spec.md#requirement-certificate-inventory
     */
    #[NoAdminRequired]
    public function inventory(): JSONResponse
    {
        $uid = $this->uid();
        if ($uid === null) {
            return new JSONResponse(data: ['message' => 'Unauthenticated'], statusCode: Http::STATUS_FORBIDDEN);
        }

        return new JSONResponse(data: $this->service->inventory(userId: $uid, isAdmin: $this->isAdmin(uid: $uid)));
    }//end inventory()

    /**
     * Submit client-parsed X.509 metadata for an owned certificate-type
     * secret; mirrors notAfter into the secret's expiry reminder.
     *
     * @param string $secretId          The secret UUID
     * @param string $subject           The X.509 subject DN
     * @param string $issuer            The X.509 issuer DN
     * @param string $serial            The certificate serial
     * @param string $fingerprintSha256 The sha256:-prefixed fingerprint
     * @param string $notBefore         ISO validity start
     * @param string $notAfter          ISO validity end
     *
     * @NoAdminRequired
     *
     * @return JSONResponse
     */
    #[NoAdminRequired]
    public function submitMetadata(
        string $secretId,
        string $subject='',
        string $issuer='',
        string $serial='',
        string $fingerprintSha256='',
        string $notBefore='',
        string $notAfter='',
    ): JSONResponse {
        $uid = $this->uid();
        if ($uid === null) {
            return new JSONResponse(data: ['message' => 'Unauthenticated'], statusCode: Http::STATUS_FORBIDDEN);
        }

        try {
            $row = $this->service->submitMetadata(
                secretId: $secretId,
                userId: $uid,
                fields: [
                    'subject'           => $subject,
                    'issuer'            => $issuer,
                    'serial'            => $serial,
                    'fingerprintSha256' => $fingerprintSha256,
                    'notBefore'         => $notBefore,
                    'notAfter'          => $notAfter,
                ],
            );
        } catch (DoesNotExistException) {
            return new JSONResponse(data: ['message' => 'Secret not found'], statusCode: Http::STATUS_NOT_FOUND);
        } catch (InvalidArgumentException $exception) {
            return new JSONResponse(data: ['message' => $exception->getMessage()], statusCode: Http::STATUS_FORBIDDEN);
        }

        return new JSONResponse(data: $row->jsonSerialize());
    }//end submitMetadata()

    /**
     * The guided renewal checklist for an externally-issued stored
     * certificate (marks renewal in the audit trail).
     *
     * @param string $secretId The secret UUID
     *
     * @NoAdminRequired
     *
     * @return JSONResponse
     */
    #[NoAdminRequired]
    public function renewalChecklist(string $secretId): JSONResponse
    {
        $uid = $this->uid();
        if ($uid === null) {
            return new JSONResponse(data: ['message' => 'Unauthenticated'], statusCode: Http::STATUS_FORBIDDEN);
        }

        try {
            return new JSONResponse(data: $this->service->renewalChecklist(secretId: $secretId, userId: $uid));
        } catch (DoesNotExistException) {
            return new JSONResponse(data: ['message' => 'Secret not found'], statusCode: Http::STATUS_NOT_FOUND);
        } catch (InvalidArgumentException $exception) {
            return new JSONResponse(data: ['message' => $exception->getMessage()], statusCode: Http::STATUS_FORBIDDEN);
        }
    }//end renewalChecklist()

    /**
     * Re-issue a suite certificate from the private CA, preserving its
     * existing public key (owner or admin).
     *
     * @param string $suiteId The suite UUID
     *
     * @NoAdminRequired
     *
     * @return JSONResponse
     */
    #[NoAdminRequired]
    public function reissueSuite(string $suiteId): JSONResponse
    {
        $uid = $this->uid();
        if ($uid === null) {
            return new JSONResponse(data: ['message' => 'Unauthenticated'], statusCode: Http::STATUS_FORBIDDEN);
        }

        try {
            $row = $this->service->reissueSuite(suiteId: $suiteId, userId: $uid, isAdmin: $this->isAdmin(uid: $uid));
        } catch (DoesNotExistException) {
            return new JSONResponse(data: ['message' => 'Suite not found'], statusCode: Http::STATUS_NOT_FOUND);
        } catch (InvalidArgumentException $exception) {
            return new JSONResponse(data: ['message' => $exception->getMessage()], statusCode: Http::STATUS_FORBIDDEN);
        } catch (\RuntimeException $exception) {
            return new JSONResponse(data: ['message' => $exception->getMessage()], statusCode: Http::STATUS_CONFLICT);
        }

        return new JSONResponse(data: $row);
    }//end reissueSuite()
}//end class
