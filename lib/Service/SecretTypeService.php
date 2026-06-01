<?php

/**
 * Doriath Secret Type Service
 *
 * Business logic for SecretType CRUD: system seeding helpers, custom
 * user/global types, deletion with fallback-to-login reassignment.
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
 * Business logic for SecretType lifecycle.
 */
class SecretTypeService
{
    /**
     * The 6 immutable system types: name => label.
     *
     * @var array<string,string>
     */
    public const SYSTEM_TYPES = [
        'login'       => 'Login',
        'api_key'     => 'API Key',
        'ssh_key'     => 'SSH Key',
        'certificate' => 'Certificate',
        'note'        => 'Secure Note',
        'database'    => 'Database',
    ];

    /**
     * The UUID v5 namespace for deterministic system-type IDs.
     *
     * @var string
     */
    private const TYPE_NAMESPACE = '6ba7b810-9dad-11d1-80b4-00c04fd430c8';

    /**
     * Constructor for SecretTypeService.
     *
     * @param SecretTypeMapper $mapper       The secret type mapper
     * @param SecretMapper     $secretMapper The secret mapper (for fallback reassignment)
     * @param LoggerInterface  $logger       The logger
     *
     * @return void
     */
    public function __construct(
        private SecretTypeMapper $mapper,
        private SecretMapper $secretMapper,
        private LoggerInterface $logger,
    ) {
    }//end __construct()

    /**
     * Compute the deterministic UUID v5 for a system type name.
     *
     * @param string $name The system type name
     *
     * @return string
     */
    public static function systemTypeId(string $name): string
    {
        return Uuid::uuid5(self::TYPE_NAMESPACE, 'doriath:secret-type:'.$name)->toString();
    }//end systemTypeId()

    /**
     * Get the types available to a user (system + global + own user types).
     *
     * @param string $userId The user UID
     *
     * @return SecretType[]
     */
    public function getAvailableTypes(string $userId): array
    {
        return $this->mapper->findAvailableForUser($userId);
    }//end getAvailableTypes()

    /**
     * Resolve the `login` system type ID (the global default).
     *
     * @return string
     */
    public function getSystemLoginTypeId(): string
    {
        return self::systemTypeId(name: 'login');
    }//end getSystemLoginTypeId()

    /**
     * Validate that a type ID exists and is usable by the user; fall back to
     * the login system type when none is supplied.
     *
     * @param string|null $typeId The requested type ID (nullable)
     * @param string      $userId The user UID
     *
     * @return string The resolved type ID
     *
     * @throws InvalidArgumentException When the type is not available to the user
     */
    public function resolveTypeForUser(?string $typeId, string $userId): string
    {
        if ($typeId === null || $typeId === '') {
            return $this->getSystemLoginTypeId();
        }

        foreach ($this->getAvailableTypes(userId: $userId) as $type) {
            if ($type->getId() === $typeId) {
                return $typeId;
            }
        }

        throw new InvalidArgumentException('Secret type is not available to this user');
    }//end resolveTypeForUser()

    /**
     * Create a custom SecretType.
     *
     * @param string      $name    The unique type name
     * @param string      $label   The human-readable label
     * @param string      $scope   The scope (user or global)
     * @param string|null $ownerId The owner UID (for user scope) or null
     *
     * @return SecretType
     *
     * @throws InvalidArgumentException On duplicate name or invalid scope
     */
    public function createType(string $name, string $label, string $scope, ?string $ownerId): SecretType
    {
        if (in_array($scope, ['user', 'global'], true) === false) {
            throw new InvalidArgumentException('Invalid scope: only user or global custom types may be created');
        }

        if ($this->mapper->nameExists($name) === true) {
            throw new InvalidArgumentException('A secret type with this name already exists');
        }

        $type = new SecretType();
        $type->setId(Uuid::uuid4()->toString());
        $type->setName($name);
        $type->setLabel($label);
        $type->setScope($scope);

        $resolvedOwner = null;
        if ($scope === 'user') {
            $resolvedOwner = $ownerId;
        }

        $type->setOwnerId($resolvedOwner);
        $type->setCreatedAt(new DateTime());

        $this->mapper->insert($type);
        $this->logger->info("Doriath: SecretType '{$name}' created (scope {$scope})");

        return $type;
    }//end createType()

    /**
     * Update the label of a custom type owned/managed by the caller.
     *
     * @param string $id      The type ID
     * @param string $label   The new label
     * @param string $userId  The acting user UID
     * @param bool   $isAdmin Whether the acting user is an admin
     *
     * @return SecretType
     *
     * @throws DoesNotExistException
     * @throws InvalidArgumentException On a permission or immutability violation
     */
    public function updateType(string $id, string $label, string $userId, bool $isAdmin): SecretType
    {
        $type = $this->mapper->findById($id);
        $this->assertManageable(type: $type, userId: $userId, isAdmin: $isAdmin);

        $type->setLabel($label);
        $this->mapper->update($type);

        return $type;
    }//end updateType()

    /**
     * Delete a custom type, reassigning its secrets to the login type.
     *
     * @param string $id      The type ID
     * @param string $userId  The acting user UID
     * @param bool   $isAdmin Whether the acting user is an admin
     *
     * @return void
     *
     * @throws DoesNotExistException
     * @throws InvalidArgumentException On a permission or immutability violation
     */
    public function deleteType(string $id, string $userId, bool $isAdmin): void
    {
        $type = $this->mapper->findById($id);
        $this->assertManageable(type: $type, userId: $userId, isAdmin: $isAdmin);

        // Reassign all secrets of this type to the login system type before deletion.
        $this->secretMapper->reassignType($id, $this->getSystemLoginTypeId());
        $this->mapper->delete($type);

        $this->logger->info("Doriath: SecretType '{$type->getName()}' deleted, secrets reassigned to login");
    }//end deleteType()

    /**
     * Assert the caller may manage (rename/delete) the given type.
     *
     * @param SecretType $type    The type
     * @param string     $userId  The acting user UID
     * @param bool       $isAdmin Whether the acting user is an admin
     *
     * @return void
     *
     * @throws InvalidArgumentException On immutability or permission violation
     */
    private function assertManageable(SecretType $type, string $userId, bool $isAdmin): void
    {
        if ($type->getScope() === 'system') {
            throw new InvalidArgumentException('System types cannot be modified or deleted');
        }

        if ($type->getScope() === 'global' && $isAdmin === false) {
            throw new InvalidArgumentException('Only administrators can manage global types');
        }

        if ($type->getScope() === 'user' && $type->getOwnerId() !== $userId) {
            throw new InvalidArgumentException('You can only manage your own custom types');
        }
    }//end assertManageable()
}//end class
