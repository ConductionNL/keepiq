<?php

/**
 * Doriath Offline Controller
 *
 * The consolidated offline-cache manifest (offline-readonly-cache §1.3):
 * one owner-scoped snapshot — active suite blob + KDF params, every
 * secret's RSA ciphertext, the folder tree, and a server syncedAt — that
 * the client commits to IndexedDB in a single atomic transaction. The
 * manifest reads through the mappers directly and NEVER decrypts: the
 * secret key/login/additionalFields are already ciphertext (ADR-003),
 * and the plaintext name/url metadata is encrypted at rest client-side.
 * It is a bulk cache sync, not an individual reveal, so it emits no
 * secret.read audit event.
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
use OCA\Doriath\Db\EncryptionSuiteMapper;
use OCA\Doriath\Db\FolderMapper;
use OCA\Doriath\Db\Secret;
use OCA\Doriath\Db\SecretMapper;
use OCP\AppFramework\Db\DoesNotExistException;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\Attribute\NoAdminRequired;
use OCP\AppFramework\Http\JSONResponse;
use OCP\AppFramework\OCSController;
use OCP\IAppConfig;
use OCP\IRequest;
use OCP\IUserSession;

/**
 * The owner-scoped offline-cache manifest endpoint.
 */
class OfflineController extends OCSController
{
    /**
     * Constructor for OfflineController.
     *
     * @param IRequest              $request      The request object
     * @param EncryptionSuiteMapper $suiteMapper  The suite mapper
     * @param SecretMapper          $secretMapper The secret mapper
     * @param FolderMapper          $folderMapper The folder mapper
     * @param IAppConfig            $appConfig    The app config (off switch)
     * @param IUserSession          $userSession  The user session
     *
     * @return void
     */
    public function __construct(
        IRequest $request,
        private EncryptionSuiteMapper $suiteMapper,
        private SecretMapper $secretMapper,
        private FolderMapper $folderMapper,
        private IAppConfig $appConfig,
        private IUserSession $userSession,
    ) {
        parent::__construct(appName: Application::APP_ID, request: $request);
    }//end __construct()

    /**
     * The consolidated offline snapshot for the calling user.
     *
     * @NoAdminRequired
     *
     * @return JSONResponse
     *
     * @spec openspec/changes/offline-readonly-cache/specs/offline-readonly-cache/spec.md#requirement-offline-manifest
     */
    #[NoAdminRequired]
    public function manifest(): JSONResponse
    {
        $user = $this->userSession->getUser();
        if ($user === null) {
            return new JSONResponse(data: ['message' => 'Unauthenticated'], statusCode: Http::STATUS_FORBIDDEN);
        }

        if ($this->appConfig->getValueBool(Application::APP_ID, 'offline_cache_enabled', true) === false) {
            return new JSONResponse(
                data: ['message' => 'Offline caching is disabled by the administrator'],
                statusCode: Http::STATUS_FORBIDDEN
            );
        }

        $userId = $user->getUID();

        try {
            $suite = $this->suiteMapper->findActiveByOwner('user', $userId)->jsonSerialize();
        } catch (DoesNotExistException) {
            return new JSONResponse(data: ['message' => 'No active encryption suite'], statusCode: Http::STATUS_NOT_FOUND);
        }

        $secrets = array_map(
            static fn (Secret $secret) => $secret->jsonSerialize(),
            $this->secretMapper->findByOwner(ownerType: 'user', ownerId: $userId)
        );

        $folders = array_map(
            static fn ($folder) => $folder->jsonSerialize(),
            $this->folderMapper->findByOwner('user', $userId)
        );

        return new JSONResponse(
            data: [
                'suite'    => $suite,
                'secrets'  => $secrets,
                'folders'  => $folders,
                'syncedAt' => (new \DateTime())->format('c'),
            ]
        );
    }//end manifest()
}//end class
