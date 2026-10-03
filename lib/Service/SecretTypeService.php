<?php

/**
 * Keepiq Secret Type Service
 *
 * Business logic for SecretType lifecycle: listing available types,
 * resolving the default login type, creating user and global custom types,
 * renaming, and deleting with fallback-to-login reassignment.
 *
 * @category Service
 * @package  OCA\Keepiq\Service
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

namespace OCA\Keepiq\Service;

use DateTime;
use InvalidArgumentException;
use OCA\Keepiq\Db\SecretMapper;
use OCA\Keepiq\Db\SecretType;
use OCA\Keepiq\Db\SecretTypeMapper;
use OCA\Keepiq\Exception\ConflictException;
use OCA\Keepiq\Exception\ForbiddenException;
use OCP\AppFramework\Db\DoesNotExistException;
use Psr\Log\LoggerInterface;
use Ramsey\Uuid\Uuid;

/**
 * Business logic for SecretType lifecycle.
 */
class SecretTypeService {

	/**
	 * The kinds a field of a type can have (admin-18).
	 *
	 * @var list<string>
	 */
	public const FIELD_KINDS = ['text', 'hidden', 'url', 'email'];

	/**
	 * The most fields one type may carry.
	 *
	 * @var int
	 */
	public const MAX_FIELDS = 30;

	/**
	 * Labels a field may not take: its value is stored under the label in the
	 * additional-fields blob, and these names route to built-in columns.
	 *
	 * @var list<string>
	 */
	private const RESERVED_LABELS = ['key', 'login', 'url'];
	/**
	 * Constructor for SecretTypeService.
	 *
	 * @param SecretTypeMapper $mapper The secret type mapper
	 * @param SecretMapper $secretMapper The secret mapper (for reassignment)
	 * @param LoggerInterface $logger The logger interface
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
	 * Get every SecretType available to a user (system + global + own user types).
	 *
	 * @param string $userId The Nextcloud user ID
	 *
	 * @return SecretType[]
	 */
	public function getAvailableTypes(string $userId): array {
		return $this->mapper->findAvailableForUser($userId);
	}//end getAvailableTypes()

	/**
	 * Get the system login type, used as the default and fallback type.
	 *
	 * @return SecretType
	 *
	 * @throws DoesNotExistException When the system types have not been seeded
	 */
	public function getSystemLoginType(): SecretType {
		return $this->mapper->findByName('login');
	}//end getSystemLoginType()

	/**
	 * Resolve and validate a type for assignment to a secret. Returns the
	 * default login type when no type ID is given.
	 *
	 * @param string|null $typeId The requested type ID (null = default)
	 * @param string $userId The requesting Nextcloud user ID
	 *
	 * @return string The resolved, validated type ID
	 *
	 * @throws InvalidArgumentException When the type does not exist or is not available
	 *
	 * @spec openspec/specs/secrets/spec.md#requirement-secret-types
	 */
	public function resolveTypeForSecret(?string $typeId, string $userId): string {
		if ($typeId === null || $typeId === '') {
			return $this->getSystemLoginType()->getId();
		}

		try {
			$type = $this->mapper->findById($typeId);
		} catch (DoesNotExistException) {
			throw new InvalidArgumentException('Unknown secret type');
		}

		if ($type->getScope() === 'user' && $type->getOwnerId() !== $userId) {
			throw new InvalidArgumentException('Secret type is not available to this user');
		}

		return $type->getId();
	}//end resolveTypeForSecret()

	/**
	 * Create a custom SecretType.
	 *
	 * @param string $name The unique type name
	 * @param string $label The human-readable label
	 * @param string $scope The scope (user or global)
	 * @param string $userId The requesting Nextcloud user ID
	 * @param bool $isAdmin Whether the requester is an administrator
	 * @param array<mixed>|null $fields The fields an item of this type carries (admin-18)
	 *
	 * @return SecretType
	 *
	 * @throws InvalidArgumentException When validation fails
	 * @throws ForbiddenException When a non-admin requests a global type
	 * @throws ConflictException When the name already exists
	 *
	 * @spec openspec/specs/secrets/spec.md#requirement-secret-types
	 * @spec openspec/specs/admin-secret-types/spec.md#requirement-item-type-definitions
	 */
	public function createType(
		string $name,
		string $label,
		string $scope,
		string $userId,
		bool $isAdmin,
		?array $fields=null,
	): SecretType {
		$name = trim($name);
		if ($name === '' || $label === '') {
			throw new InvalidArgumentException('Type name and label are required');
		}

		if (in_array($scope, ['user', 'global'], true) === false) {
			throw new InvalidArgumentException('Scope must be user or global');
		}

		if ($scope === 'global' && $isAdmin === false) {
			throw new ForbiddenException(message: 'Only administrators can create global types');
		}

		if ($this->mapper->countByName($name) > 0) {
			throw new ConflictException(message: 'A secret type with this name already exists');
		}

		$ownerScopeId = null;
		if ($scope === 'user') {
			$ownerScopeId = $userId;
		}

		$type = new SecretType();
		$type->setId(Uuid::uuid4()->toString());
		$type->setName($name);
		$type->setLabel($label);
		$type->setScope($scope);
		$type->setOwnerId($ownerScopeId);
		$type->setFieldList($this->normaliseFields(fields: $fields ?? []));
		$type->setCreatedAt(new DateTime());

		$this->mapper->insert($type);
		$this->logger->info("Keepiq: secret type '{$name}' ({$scope}) created by {$userId}");

		return $type;
	}//end createType()

	/**
	 * Rename (relabel) a custom SecretType the requester is allowed to manage.
	 *
	 * @param string $id The type ID
	 * @param string $label The new label
	 * @param string $userId The requesting Nextcloud user ID
	 * @param bool $isAdmin Whether the requester is an administrator
	 * @param array<mixed>|null $fields The new field list, or null to keep it (admin-18)
	 *
	 * @return SecretType
	 *
	 * @throws ForbiddenException When the type is a system type or not owned
	 * @throws InvalidArgumentException When the label or the field list is invalid
	 *
	 * @spec openspec/specs/secrets/spec.md#requirement-secret-types
	 * @spec openspec/specs/admin-secret-types/spec.md#requirement-item-type-definitions
	 */
	public function updateType(
		string $id,
		string $label,
		string $userId,
		bool $isAdmin,
		?array $fields=null,
	): SecretType {
		$label = trim($label);
		if ($label === '') {
			throw new InvalidArgumentException('Label is required');
		}

		$type = $this->loadManageable(id: $id, userId: $userId, isAdmin: $isAdmin);

		$type->setLabel($label);
		if ($fields !== null) {
			$type->setFieldList($this->normaliseFields(fields: $fields));
		}

		$this->mapper->update($type);
		$this->logger->info("Keepiq: secret type {$id} relabelled by {$userId}");

		return $type;
	}//end updateType()

	/**
	 * Validate a field list and bring it into its stored shape.
	 *
	 * Keys are unique, labels are unique (values are stored under the label)
	 * and not a built-in member name, kinds are known, at most MAX_FIELDS.
	 *
	 * @param array<mixed> $fields The submitted fields
	 *
	 * @return list<array{key: string, label: string, kind: string, required: bool}>
	 *
	 * @throws InvalidArgumentException When the list is invalid
	 *
	 * @spec openspec/specs/admin-secret-types/spec.md#requirement-item-type-definitions
	 */
	private function normaliseFields(array $fields): array {
		if (count($fields) > self::MAX_FIELDS) {
			throw new InvalidArgumentException('A type can have at most '.self::MAX_FIELDS.' fields');
		}

		$out    = [];
		$seen   = [];
		foreach ($fields as $field) {
			$normalised = $this->normaliseField(field: $field);
			$keyId      = 'k:'.$normalised['key'];
			$labelId    = 'l:'.mb_strtolower($normalised['label']);
			if (isset($seen[$keyId]) === true || isset($seen[$labelId]) === true) {
				throw new InvalidArgumentException('Field keys and labels must be unique');
			}

			$seen[$keyId]   = true;
			$seen[$labelId] = true;
			$out[]          = $normalised;
		}

		return $out;
	}//end normaliseFields()

	/**
	 * Validate one field definition and bring it into its stored shape.
	 *
	 * @param mixed $field The submitted field
	 *
	 * @return array{key: string, label: string, kind: string, required: bool}
	 *
	 * @throws InvalidArgumentException When the field is invalid
	 *
	 * @spec openspec/specs/admin-secret-types/spec.md#requirement-item-type-definitions
	 */
	private function normaliseField(mixed $field): array {
		if (is_array($field) === false) {
			throw new InvalidArgumentException('Each field must be an object');
		}

		$key   = trim((string) ($field['key'] ?? ''));
		$label = trim((string) ($field['label'] ?? ''));
		$kind  = (string) ($field['kind'] ?? '');
		if (preg_match('/^[a-z0-9][a-z0-9_-]{0,63}$/', $key) !== 1) {
			throw new InvalidArgumentException('A field key must be lowercase letters, digits, dashes or underscores');
		}

		if ($label === '' || mb_strlen($label) > 128) {
			throw new InvalidArgumentException('A field label is required and at most 128 characters');
		}

		if (in_array(mb_strtolower($label), self::RESERVED_LABELS, true) === true) {
			throw new InvalidArgumentException("A field cannot be called '{$label}'");
		}

		if (in_array($kind, self::FIELD_KINDS, true) === false) {
			throw new InvalidArgumentException("Unknown field kind '{$kind}'");
		}

		return [
			'key'      => $key,
			'label'    => $label,
			'kind'     => $kind,
			'required' => ($field['required'] ?? false) === true,
		];
	}//end normaliseField()

	/**
	 * Delete a custom SecretType, reassigning its secrets to the login type.
	 *
	 * @param string $id The type ID
	 * @param string $userId The requesting Nextcloud user ID
	 * @param bool $isAdmin Whether the requester is an administrator
	 *
	 * @return void
	 *
	 * @throws ForbiddenException When the type is a system type or not owned
	 *
	 * @spec openspec/specs/secrets/spec.md#requirement-secret-types
	 */
	public function deleteType(string $id, string $userId, bool $isAdmin): void {
		$type = $this->loadManageable(id: $id, userId: $userId, isAdmin: $isAdmin);

		$loginTypeId = $this->getSystemLoginType()->getId();
		$this->secretMapper->reassignType($id, $loginTypeId);

		$this->mapper->delete($type);
		$this->logger->info("Keepiq: secret type {$id} deleted by {$userId} (secrets reassigned to login)");
	}//end deleteType()

	/**
	 * Load a type and verify the requester is allowed to manage it. System
	 * types are never manageable; user types require ownership; global types
	 * require admin.
	 *
	 * @param string $id The type ID
	 * @param string $userId The requesting Nextcloud user ID
	 * @param bool $isAdmin Whether the requester is an administrator
	 *
	 * @return SecretType
	 *
	 * @throws ForbiddenException When the requester may not manage the type
	 */
	private function loadManageable(string $id, string $userId, bool $isAdmin): SecretType {
		try {
			$type = $this->mapper->findById($id);
		} catch (DoesNotExistException) {
			throw new ForbiddenException(message: 'Secret type not found');
		}

		if ($type->getScope() === 'system') {
			throw new ForbiddenException(message: 'System secret types cannot be modified or deleted');
		}

		if ($type->getScope() === 'user' && $type->getOwnerId() !== $userId) {
			throw new ForbiddenException(message: 'This secret type belongs to another user');
		}

		if ($type->getScope() === 'global' && $isAdmin === false) {
			throw new ForbiddenException(message: 'Only administrators can manage global types');
		}

		return $type;
	}//end loadManageable()
}//end class
