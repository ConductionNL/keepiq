<?php

/**
 * Doriath Offline Manifest Service
 *
 * Assembles the consolidated offline-cache snapshot (offline-readonly-cache
 * §1.3): one owner-scoped payload — active suite blob + KDF params, every
 * secret's RSA ciphertext, the folder tree, the available secret types, and a
 * server `syncedAt` — that the client commits to IndexedDB in a single atomic
 * transaction.
 *
 * @category Service
 * @package  OCA\Doriath\Service
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

namespace OCA\Doriath\Service;

use DateTime;
use OCA\Doriath\Db\EncryptionSuiteMapper;
use OCA\Doriath\Db\FolderMapper;
use OCA\Doriath\Db\Secret;
use OCA\Doriath\Db\SecretMapper;
use OCA\Doriath\Db\SecretTypeMapper;
use OCP\AppFramework\Db\DoesNotExistException;

/**
 * Builds the owner-scoped offline snapshot.
 *
 * This service reads through the mappers directly and NEVER decrypts: the
 * secret key/login/additionalFields are already ciphertext (ADR-003), and the
 * plaintext name/url metadata is encrypted at rest client-side. It is a bulk
 * cache sync, not an individual reveal, so it emits no `secret.read` audit
 * event.
 *
 * It lives apart from `OfflineController` so the controller keeps only the two
 * transport-level decisions it actually owns — is the caller authenticated,
 * and is the org-wide switch on — while the snapshot's shape (which mappers,
 * in which order, serialised how) is assembled and tested here.
 */
class OfflineManifestService
{
    /**
     * Constructor for OfflineManifestService.
     *
     * @param EncryptionSuiteMapper $suiteMapper  The suite mapper
     * @param SecretMapper          $secretMapper The secret mapper
     * @param FolderMapper          $folderMapper The folder mapper
     * @param SecretTypeMapper      $typeMapper   The secret type mapper
     *
     * @return void
     */
    public function __construct(
        private EncryptionSuiteMapper $suiteMapper,
        private SecretMapper $secretMapper,
        private FolderMapper $folderMapper,
        private SecretTypeMapper $typeMapper,
    ) {
    }//end __construct()

    /**
     * Build the consolidated offline snapshot for one user.
     *
     * Every read is keyed by `('user', $userId)` so the snapshot can only ever
     * contain rows the caller already owns — there is no cross-owner path
     * through this method.
     *
     * @param string $userId The owning Nextcloud user ID
     *
     * @return array<string,mixed> The manifest payload
     *
     * @throws DoesNotExistException When the user has no active encryption
     *                               suite — the caller maps this to a 404,
     *                               because a snapshot without the suite blob
     *                               cannot be decrypted offline and is worse
     *                               than no snapshot at all.
     *
     * @spec openspec/specs/offline-readonly-cache/spec.md#requirement-online-sessions-write-through-an-encrypted-local-snapshot
     */
    public function buildForUser(string $userId): array
    {
        $suite = $this->suiteMapper->findActiveByOwner('user', $userId)->jsonSerialize();

        $secrets = array_map(
            static fn (Secret $secret) => $secret->jsonSerialize(),
            $this->secretMapper->findByOwner(ownerType: 'user', ownerId: $userId)
        );

        $folders = array_map(
            static fn ($folder) => $folder->jsonSerialize(),
            $this->folderMapper->findByOwner('user', $userId)
        );

        // Secret types the list schema needs to render type badges offline.
        $types = array_map(
            static fn ($type) => $type->jsonSerialize(),
            $this->typeMapper->findAvailableForUser($userId)
        );

        return [
            'suite'    => $suite,
            'secrets'  => $secrets,
            'folders'  => $folders,
            'types'    => $types,
            'syncedAt' => (new DateTime())->format('c'),
        ];

    }//end buildForUser()
}//end class
