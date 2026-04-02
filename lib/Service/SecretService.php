<?php

/**
 * Doriath Secret Service
 *
 * Business logic for Secret lifecycle: create, read, update, delete, list and search.
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
use InvalidArgumentException;
use OCA\Doriath\Db\FolderMapper;
use OCA\Doriath\Db\Secret;
use OCA\Doriath\Db\SecretMapper;
use OCP\AppFramework\Db\DoesNotExistException;
use OCP\AppFramework\OCS\OCSForbiddenException;
use Psr\Log\LoggerInterface;
use Ramsey\Uuid\Uuid;

/**
 * Business logic for Secret lifecycle: create, read, update, delete, list and search.
 */
class SecretService
{
    /**
     * Constructor for SecretService.
     *
     * @param SecretMapper           $secretMapper     The secret mapper
     * @param SecretTypeService      $typeService      The secret type service
     * @param EncryptionSuiteService $suiteService     The encryption suite service
     * @param MigrationService       $migrationService The migration service
     * @param FolderMapper           $folderMapper     The folder mapper (for validation)
     * @param LoggerInterface        $logger           The logger interface
     *
     * @return void
     */
    public function __construct(
        private SecretMapper $secretMapper,
        private SecretTypeService $typeService,
        private EncryptionSuiteService $suiteService,
        private MigrationService $migrationService,
        private FolderMapper $folderMapper,
        private LoggerInterface $logger,
    ) {
    }//end __construct()

    /**
     * Create a new secret.
     *
     * Checks for an active write lock (compromise recovery in progress) before
     * writing. If no type_id is provided the default 'login' system type is used.
     * If a folder_id is provided the folder must be owned by the user.
     *
     * @param array<string,mixed> $data   Secret field values (name, url, key, login, etc.)
     * @param string              $userId The Nextcloud user ID
     *
     * @return Secret
     *
     * @throws OCSForbiddenException    When the user is write-locked due to migration
     * @throws InvalidArgumentException When the folder does not belong to the user
     * @throws DoesNotExistException    When the active encryption suite is not found
     */
    public function create(array $data, string $userId): Secret
    {
        if ($this->migrationService->isWriteLocked(ownerType: 'user', ownerId: $userId) === true) {
            throw new OCSForbiddenException(
                'Write operations are locked during compromise recovery migration'
            );
        }

        $activeSuite = $this->suiteService->getActiveSuite(ownerType: 'user', ownerId: $userId);

        // Default to the system login type when no type is specified.
        $typeId = $data['typeId'] ?? null;
        if ($typeId === null) {
            $loginType = $this->typeService->getSystemLoginType();
            $typeId    = $loginType->getId();
        }

        // Validate folder ownership.
        $folderId = $data['folderId'] ?? null;
        if ($folderId !== null) {
            $folder = $this->folderMapper->findById(id: $folderId);
            if ($folder->getOwnerId() !== $userId) {
                throw new InvalidArgumentException('Folder does not belong to the current user');
            }
        }

        $secret = new Secret();
        $secret->setId(Uuid::uuid4()->toString());
        $secret->setName($data['name']);
        $secret->setUrl($data['url'] ?? null);
        $secret->setTypeId($typeId);
        $secret->setFolderId($folderId);
        $secret->setKey($data['key'] ?? null);
        $secret->setLogin($data['login'] ?? null);
        $secret->setAdditionalFields($data['additionalFields'] ?? null);
        $secret->setEncryptionSuiteId($activeSuite->getId());
        $secret->setOwnerType('user');
        $secret->setOwnerId($userId);
        $secret->setCreatedAt(new DateTime());
        $secret->setUpdatedAt(new DateTime());

        $this->secretMapper->insert($secret);

        $this->logger->info("Doriath: Secret '{$secret->getName()}' created for user {$userId}");

        return $secret;
    }//end create()

    /**
     * Retrieve a secret by ID.
     *
     * Validates ownership. If the secret's encryption suite is revoked or
     * compromised a forbidden exception is thrown to signal the client that
     * the credential data must not be displayed.
     *
     * @param string $id     The secret ID
     * @param string $userId The Nextcloud user ID
     *
     * @return Secret
     *
     * @throws DoesNotExistException When the secret does not exist
     * @throws OCSForbiddenException When the secret belongs to a revoked or compromised suite
     * @throws InvalidArgumentException When the user does not own the secret
     */
    public function get(string $id, string $userId): Secret
    {
        $secret = $this->secretMapper->findById(id: $id);

        if ($secret->getOwnerId() !== $userId) {
            throw new InvalidArgumentException('You do not own this secret');
        }

        try {
            $suite  = $this->suiteService->getSuite(id: $secret->getEncryptionSuiteId());
            $status = $suite->getStatus();
            if ($status === 'revoked' || $status === 'compromised') {
                throw new OCSForbiddenException(
                    'This secret is encrypted with a '.$status.' suite and cannot be accessed'
                );
            }
        } catch (DoesNotExistException) {
            // Suite missing — treat as blocked.
            throw new OCSForbiddenException(
                'The encryption suite for this secret no longer exists'
            );
        }

        return $secret;
    }//end get()

    /**
     * Update an existing secret.
     *
     * Only fields present in $data are updated; absent keys are left unchanged.
     * Checks for an active write lock before writing.
     *
     * @param string              $id     The secret ID
     * @param array<string,mixed> $data   Fields to update
     * @param string              $userId The Nextcloud user ID
     *
     * @return Secret
     *
     * @throws OCSForbiddenException    When the user is write-locked due to migration
     * @throws InvalidArgumentException When the user does not own the secret
     * @throws DoesNotExistException    When the secret does not exist
     */
    public function update(string $id, array $data, string $userId): Secret
    {
        if ($this->migrationService->isWriteLocked(ownerType: 'user', ownerId: $userId) === true) {
            throw new OCSForbiddenException(
                'Write operations are locked during compromise recovery migration'
            );
        }

        $secret = $this->secretMapper->findById(id: $id);

        if ($secret->getOwnerId() !== $userId) {
            throw new InvalidArgumentException('You do not own this secret');
        }

        if (array_key_exists(key: 'name', array: $data) === true) {
            $secret->setName($data['name']);
        }

        if (array_key_exists(key: 'url', array: $data) === true) {
            $secret->setUrl($data['url']);
        }

        if (array_key_exists(key: 'typeId', array: $data) === true) {
            $secret->setTypeId($data['typeId']);
        }

        if (array_key_exists(key: 'folderId', array: $data) === true) {
            $secret->setFolderId($data['folderId']);
        }

        if (array_key_exists(key: 'key', array: $data) === true) {
            $secret->setKey($data['key']);
        }

        if (array_key_exists(key: 'login', array: $data) === true) {
            $secret->setLogin($data['login']);
        }

        if (array_key_exists(key: 'additionalFields', array: $data) === true) {
            $secret->setAdditionalFields($data['additionalFields']);
        }

        $secret->setUpdatedAt(new DateTime());

        $this->secretMapper->update($secret);

        $this->logger->info("Doriath: Secret {$id} updated by {$userId}");

        return $secret;
    }//end update()

    /**
     * Delete a secret.
     *
     * @param string $id     The secret ID
     * @param string $userId The Nextcloud user ID
     *
     * @return void
     *
     * @throws InvalidArgumentException When the user does not own the secret
     * @throws DoesNotExistException    When the secret does not exist
     */
    public function delete(string $id, string $userId): void
    {
        $secret = $this->secretMapper->findById(id: $id);

        if ($secret->getOwnerId() !== $userId) {
            throw new InvalidArgumentException('You do not own this secret');
        }

        $this->secretMapper->delete($secret);

        $this->logger->info("Doriath: Secret {$id} deleted by {$userId}");
    }//end delete()

    /**
     * List secrets for a user with optional folder filter, sorting and pagination.
     *
     * For each secret whose encryption suite is revoked or compromised, the
     * encrypted credential fields (key, login, additionalFields) are nulled out
     * and a 'blocked' flag is added to the serialised array.
     *
     * @param string      $userId    The Nextcloud user ID
     * @param string|null $folderId  Optional folder ID to filter by
     * @param string      $sort      The field to sort by
     * @param string      $direction The sort direction ('ASC' or 'DESC')
     * @param int         $page      The 1-based page number
     * @param int         $limit     The number of results per page
     *
     * @return array{secrets: array<int,array<string,mixed>>, total: int}
     *
     * @SuppressWarnings(PHPMD.LongVariable)
     */
    public function list(
        string $userId,
        ?string $folderId,
        string $sort,
        string $direction,
        int $page,
        int $limit
    ): array {
        $offset  = ($page - 1) * $limit;
        $secrets = $this->secretMapper->findByOwner(
            ownerType: 'user',
            ownerId: $userId,
            folderId: $folderId,
            sort: $sort,
            direction: $direction,
            limit: $limit,
            offset: $offset
        );
        $total   = $this->secretMapper->countByOwner(
            ownerType: 'user',
            ownerId: $userId,
            folderId: $folderId
        );

        $result = [];
        foreach ($secrets as $secret) {
            $serialised = $secret->jsonSerialize();

            try {
                $suite  = $this->suiteService->getSuite(id: $secret->getEncryptionSuiteId());
                $status = $suite->getStatus();
                if ($status === 'revoked' || $status === 'compromised') {
                    $serialised['key']   = null;
                    $serialised['login'] = null;
                    $serialised['additionalFields'] = null;
                    $serialised['blocked']          = true;
                }
            } catch (DoesNotExistException) {
                $serialised['key']   = null;
                $serialised['login'] = null;
                $serialised['additionalFields'] = null;
                $serialised['blocked']          = true;
            }

            $result[] = $serialised;
        }//end foreach

        return [
            'secrets' => $result,
            'total'   => $total,
        ];
    }//end list()

    /**
     * Search secrets for a user using a two-stage SQL + Levenshtein strategy.
     *
     * Stage 1: SQL ILIKE pre-filter via mapper.
     * Stage 2: If fewer than 50 SQL results are found, run a Levenshtein
     *          post-filter over all user secrets and merge the results.
     *
     * Levenshtein thresholds (per D5):
     *  - term length <= 5: max distance 1
     *  - term length > 5: max distance 2
     *
     * @param string $userId The Nextcloud user ID
     * @param string $term   The search term
     * @param int    $page   The 1-based page number
     * @param int    $limit  The number of results per page
     *
     * @return array{secrets: array<int,array<string,mixed>>, total: int}
     *
     * @SuppressWarnings(PHPMD.CyclomaticComplexity)
     */
    public function search(string $userId, string $term, int $page, int $limit): array
    {
        // Stage 1: SQL ILIKE pre-filter.
        $sqlResults = $this->secretMapper->searchByNameOrUrl(userId: $userId, term: $term);

        $mergedById = [];
        foreach ($sqlResults as $secret) {
            $mergedById[$secret->getId()] = $secret;
        }

        // Stage 2: Levenshtein post-filter when SQL results are sparse.
        if (count($sqlResults) < 50) {
            $allSecrets  = $this->secretMapper->findByOwner(
                ownerType: 'user',
                ownerId: $userId,
                folderId: null,
                limit: PHP_INT_MAX,
                offset: 0
            );
            $maxDistance = 2;
            if (strlen($term) <= 5) {
                $maxDistance = 1;
            }

            foreach ($allSecrets as $secret) {
                if (array_key_exists(key: $secret->getId(), array: $mergedById) === true) {
                    continue;
                }

                $distance = levenshtein(string1: strtolower($term), string2: strtolower($secret->getName()));
                if ($distance <= $maxDistance) {
                    $mergedById[$secret->getId()] = $secret;
                }
            }//end foreach
        }//end if

        // Paginate merged results.
        $allMatches = array_values($mergedById);
        $total      = count($allMatches);
        $offset     = ($page - 1) * $limit;
        $pageSlice  = array_slice(array: $allMatches, offset: $offset, length: $limit);

        $result = [];
        foreach ($pageSlice as $secret) {
            $result[] = $secret->jsonSerialize();
        }//end foreach

        return [
            'secrets' => $result,
            'total'   => $total,
        ];
    }//end search()
}//end class
