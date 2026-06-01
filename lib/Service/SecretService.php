<?php

/**
 * Doriath Secret Service
 *
 * Business logic for Secret CRUD: encryption-suite linkage, revoked-suite
 * blocking, write-lock enforcement, list/search/sort/pagination.
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
use OCA\Doriath\Db\Secret;
use OCA\Doriath\Db\SecretMapper;
use OCA\Doriath\Exception\ConflictException;
use OCA\Doriath\Exception\ForbiddenException;
use Psr\Log\LoggerInterface;
use Ramsey\Uuid\Uuid;

/**
 * Business logic for Secret lifecycle.
 */
class SecretService
{
    /**
     * Constructor for SecretService.
     *
     * @param SecretMapper      $mapper           The secret mapper
     * @param SecretTypeService $typeService      The secret type service
     * @param SecretSuiteGuard  $suiteGuard       The encryption-suite guard
     * @param MigrationService  $migrationService The migration service
     * @param LoggerInterface   $logger           The logger
     *
     * @return void
     */
    public function __construct(
        private SecretMapper $mapper,
        private SecretTypeService $typeService,
        private SecretSuiteGuard $suiteGuard,
        private MigrationService $migrationService,
        private LoggerInterface $logger,
    ) {
    }//end __construct()

    /**
     * Create a secret for a user.
     *
     * @param array<string,mixed> $data   The secret payload (encrypted blobs + metadata)
     * @param string              $userId The user UID
     *
     * @return Secret
     *
     * @throws InvalidArgumentException On missing required fields or bad type
     * @throws ForbiddenException       When the active suite is revoked/compromised
     * @throws ConflictException        When a write lock is active
     */
    public function create(array $data, string $userId): Secret
    {
        $this->assertNotWriteLocked(userId: $userId);

        $name = trim((string) ($data['name'] ?? ''));
        if ($name === '') {
            throw new InvalidArgumentException('A secret name is required');
        }

        $key = $data['key'] ?? null;
        if ($key === null || $key === '') {
            throw new InvalidArgumentException('An encrypted key value is required');
        }

        $suite = $this->suiteGuard->getActiveSuiteOrFail(userId: $userId);
        if ($this->suiteGuard->isStatusBlocked(status: $suite->getStatus()) === true) {
            throw new ForbiddenException(message: 'Your encryption suite is '.$suite->getStatus());
        }

        $typeId = $this->typeService->resolveTypeForUser($data['typeId'] ?? null, $userId);

        $url = null;
        if (isset($data['url']) === true) {
            $url = (string) $data['url'];
        }

        $login = null;
        if (isset($data['login']) === true) {
            $login = (string) $data['login'];
        }

        $additionalFields = null;
        if (isset($data['additionalFields']) === true) {
            $additionalFields = (string) $data['additionalFields'];
        }

        $secret = new Secret();
        $secret->setId(Uuid::uuid4()->toString());
        $secret->setName($name);
        $secret->setUrl($url);
        $secret->setTypeId($typeId);

        $folderId = ($data['folderId'] ?? null);
        if ($folderId === '') {
            $folderId = null;
        }

        $secret->setFolderId($folderId);
        $secret->setSecretKey((string) $key);
        $secret->setLogin($login);
        $secret->setAdditionalFields($additionalFields);
        $secret->setEncryptionSuiteId($suite->getId());
        $secret->setOwnerType('user');
        $secret->setOwnerId($userId);
        $secret->setCreatedAt(new DateTime());

        $this->mapper->insert($secret);
        return $secret;
    }//end create()

    /**
     * Update a secret owned by the user.
     *
     * @param string              $id     The secret ID
     * @param array<string,mixed> $data   The updated fields
     * @param string              $userId The user UID
     *
     * @return Secret
     *
     * @throws ForbiddenException When not owned
     * @throws ConflictException  When a write lock is active
     */
    public function update(string $id, array $data, string $userId): Secret
    {
        $this->assertNotWriteLocked(userId: $userId);

        $secret = $this->mapper->findById(id: $id);
        $this->assertOwnership(secret: $secret, userId: $userId);

        $this->applyPlaintextUpdates(secret: $secret, data: $data);
        $this->applyEncryptedUpdates(secret: $secret, data: $data);

        if (array_key_exists('typeId', $data) === true && $data['typeId'] !== null && $data['typeId'] !== '') {
            $secret->setTypeId($this->typeService->resolveTypeForUser((string) $data['typeId'], $userId));
        }

        $secret->setUpdatedAt(new DateTime());
        $this->mapper->update($secret);

        return $secret;
    }//end update()

    /**
     * Apply the plaintext field updates (name, url, folderId) to a secret.
     *
     * @param Secret              $secret The secret
     * @param array<string,mixed> $data   The update payload
     *
     * @return void
     */
    private function applyPlaintextUpdates(Secret $secret, array $data): void
    {
        if (array_key_exists('name', $data) === true) {
            $secret->setName((string) $data['name']);
        }

        if (array_key_exists('url', $data) === true) {
            $secret->setUrl($this->nullableString(value: $data['url']));
        }

        if (array_key_exists('folderId', $data) === true) {
            $secret->setFolderId($this->nullableString(value: $data['folderId']));
        }
    }//end applyPlaintextUpdates()

    /**
     * Apply the encrypted-blob updates (key, login, additionalFields) to a secret.
     *
     * @param Secret              $secret The secret
     * @param array<string,mixed> $data   The update payload
     *
     * @return void
     */
    private function applyEncryptedUpdates(Secret $secret, array $data): void
    {
        if (array_key_exists('key', $data) === true && $data['key'] !== null && $data['key'] !== '') {
            $secret->setSecretKey((string) $data['key']);
        }

        if (array_key_exists('login', $data) === true) {
            $secret->setLogin($this->nullableString(value: $data['login']));
        }

        if (array_key_exists('additionalFields', $data) === true) {
            $secret->setAdditionalFields($this->nullableString(value: $data['additionalFields']));
        }
    }//end applyEncryptedUpdates()

    /**
     * Coerce a value to a non-empty string or null.
     *
     * @param mixed $value The raw value
     *
     * @return string|null
     */
    private function nullableString(mixed $value): ?string
    {
        if ($value === null || $value === '') {
            return null;
        }

        return (string) $value;
    }//end nullableString()

    /**
     * Delete a secret owned by the user.
     *
     * @param string $id     The secret ID
     * @param string $userId The user UID
     *
     * @return void
     *
     * @throws ForbiddenException When not owned
     */
    public function delete(string $id, string $userId): void
    {
        $secret = $this->mapper->findById(id: $id);
        $this->assertOwnership(secret: $secret, userId: $userId);

        // Cascade to shares/requests is handled by their own services once
        // those changes land; the secret row itself is removed here.
        $this->mapper->delete($secret);
        $this->logger->info("Doriath: Secret {$id} deleted by {$userId}");
    }//end delete()

    /**
     * Assert the user is not currently write-locked by a compromise migration.
     *
     * @param string $userId The user UID
     *
     * @return void
     *
     * @throws ConflictException When write-locked
     */
    private function assertNotWriteLocked(string $userId): void
    {
        if ($this->migrationService->isWriteLocked('user', $userId) === true) {
            throw new ConflictException(message: "Write locked: a key migration is in progress");
        }
    }//end assertNotWriteLocked()

    /**
     * Assert the user owns the secret.
     *
     * @param Secret $secret The secret
     * @param string $userId The user UID
     *
     * @return void
     *
     * @throws ForbiddenException When ownership does not match
     */
    private function assertOwnership(Secret $secret, string $userId): void
    {
        if ($secret->getOwnerType() !== 'user' || $secret->getOwnerId() !== $userId) {
            throw new ForbiddenException(message: "Access denied: secret belongs to another user");
        }
    }//end assertOwnership()
}//end class
