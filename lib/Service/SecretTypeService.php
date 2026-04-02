<?php

/**
 * Doriath Secret Type Service
 *
 * Business logic for SecretType lifecycle: create, update, delete.
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
use OCA\Doriath\Db\SecretMapper;
use OCA\Doriath\Db\SecretType;
use OCA\Doriath\Db\SecretTypeMapper;
use OCP\AppFramework\Db\DoesNotExistException;
use Psr\Log\LoggerInterface;
use Ramsey\Uuid\Uuid;

/**
 * Business logic for SecretType lifecycle: create, update, delete.
 */
class SecretTypeService
{
    /**
     * Constructor for SecretTypeService.
     *
     * @param SecretTypeMapper $typeMapper   The secret type mapper
     * @param SecretMapper     $secretMapper The secret mapper (for fallback on delete)
     * @param LoggerInterface  $logger       The logger interface
     *
     * @return void
     */
    public function __construct(
        private SecretTypeMapper $typeMapper,
        private SecretMapper $secretMapper,
        private LoggerInterface $logger,
    ) {
    }//end __construct()

    /**
     * Returns system types, global types and the user's own types.
     *
     * @param string $userId The Nextcloud user ID
     *
     * @return SecretType[]
     */
    public function getAvailableTypes(string $userId): array
    {
        return $this->typeMapper->findAvailableForUser(userId: $userId);
    }//end getAvailableTypes()

    /**
     * Returns the system 'login' type used as the default for secrets.
     *
     * @return SecretType
     *
     * @throws DoesNotExistException When the system login type does not exist
     */
    public function getSystemLoginType(): SecretType
    {
        return $this->typeMapper->findByName(name: 'login');
    }//end getSystemLoginType()

    /**
     * Create a new secret type.
     *
     * Scope must be 'user' or 'global' — 'system' types cannot be created via the API.
     * The name must be globally unique.
     *
     * @param string      $name    The slug identifier
     * @param string      $label   The human-readable label
     * @param string      $scope   The scope ('user' or 'global')
     * @param string|null $ownerId The Nextcloud user ID (required for scope 'user')
     *
     * @return SecretType
     *
     * @throws InvalidArgumentException When scope is 'system' or name already exists
     */
    public function createType(string $name, string $label, string $scope, ?string $ownerId): SecretType
    {
        if ($scope === 'system') {
            throw new InvalidArgumentException('Cannot create system-scoped secret types via the API');
        }

        if (in_array(needle: $scope, haystack: ['user', 'global'], strict: true) === false) {
            throw new InvalidArgumentException('Scope must be "user" or "global"');
        }

        // Check name uniqueness — DoesNotExistException means name is available.
        try {
            $this->typeMapper->findByName(name: $name);
            throw new InvalidArgumentException("A secret type with name '{$name}' already exists");
        } catch (DoesNotExistException) {
            // Name is available — proceed.
        }

        $type = new SecretType();
        $type->setId(Uuid::uuid4()->toString());
        $type->setName($name);
        $type->setLabel($label);
        $type->setScope($scope);
        $type->setOwnerId($ownerId);
        $type->setCreatedAt(new DateTime());

        $this->typeMapper->insert($type);

        $this->logger->info("Doriath: SecretType '{$name}' created with scope '{$scope}'");

        return $type;
    }//end createType()

    /**
     * Update the label of an existing secret type.
     *
     * System types cannot be updated. For user-scoped types the caller must be
     * the owner; for global-scoped types any admin may update them.
     *
     * @param string $id     The secret type ID
     * @param string $label  The new human-readable label
     * @param string $userId The Nextcloud user ID performing the update
     *
     * @return SecretType
     *
     * @throws DoesNotExistException    When the type does not exist
     * @throws InvalidArgumentException When the type is system-scoped or the user is not the owner
     */
    public function updateType(string $id, string $label, string $userId): SecretType
    {
        $type = $this->typeMapper->findById(id: $id);

        if ($type->getScope() === 'system') {
            throw new InvalidArgumentException('System secret types cannot be modified');
        }

        if ($type->getScope() === 'user' && $type->getOwnerId() !== $userId) {
            throw new InvalidArgumentException('You do not own this secret type');
        }

        $type->setLabel($label);

        $this->typeMapper->update($type);

        $this->logger->info("Doriath: SecretType {$id} updated by {$userId}");

        return $type;
    }//end updateType()

    /**
     * Delete a secret type.
     *
     * System types cannot be deleted. Before deleting, all secrets that reference
     * this type are re-pointed to the system 'login' type to avoid orphaned references.
     *
     * @param string $id     The secret type ID
     * @param string $userId The Nextcloud user ID performing the deletion
     *
     * @return void
     *
     * @throws DoesNotExistException    When the type does not exist
     * @throws InvalidArgumentException When the type is system-scoped or the user is not the owner
     */
    public function deleteType(string $id, string $userId): void
    {
        $type = $this->typeMapper->findById(id: $id);

        if ($type->getScope() === 'system') {
            throw new InvalidArgumentException('System secret types cannot be deleted');
        }

        if ($type->getScope() === 'user' && $type->getOwnerId() !== $userId) {
            throw new InvalidArgumentException('You do not own this secret type');
        }

        // Re-point all secrets using this type to the system login type.
        $loginType   = $this->getSystemLoginType();
        $loginTypeId = $loginType->getId();

        $secrets = $this->secretMapper->findByTypeId(typeId: $id);
        foreach ($secrets as $secret) {
            $secret->setTypeId($loginTypeId);
            $this->secretMapper->update($secret);
        }//end foreach

        $this->typeMapper->delete($type);

        $this->logger->info("Doriath: SecretType {$id} deleted by {$userId}");
    }//end deleteType()
}//end class
