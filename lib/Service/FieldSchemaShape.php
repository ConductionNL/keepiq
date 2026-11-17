<?php

/**
 * Keepiq Field Schema Shape
 *
 * Checks the shape of the custom-field schemas a secret request carries, so the
 * request record only ever holds schemas the fill page can read. The values the
 * schemas describe stay encrypted; this class never sees them.
 *
 * @category Service
 * @package  OCA\Keepiq\Service
 *
 * @author    Conduction Development Team <dev@conductio.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git-id>
 *
 * @link https://conduction.nl
 */

declare(strict_types=1);

namespace OCA\Keepiq\Service;

use InvalidArgumentException;
use JsonException;

/**
 * The shape rules for a request's field schemas.
 *
 * @spec openspec/changes/vault-custom-field-kinds-and-ssh-key/specs/vault-custom-fields/spec.md#requirement-a-request-carries-the-schemas-of-its-fields
 */
class FieldSchemaShape {

	/**
	 * The member of the additional-fields blob that holds the schemas; it is not a field.
	 *
	 * @var string
	 */
	public const SCHEMA_MEMBER = '@schema';

	/**
	 * The types a schema may have.
	 *
	 * @var string[]
	 */
	public const TYPES = ['string', 'integer', 'number', 'boolean', 'object'];

	/**
	 * The formats a string schema may have.
	 *
	 * @var string[]
	 */
	public const FORMATS = [
		'text',
		'password',
		'uri',
		'email',
		'hostname',
		'ipv4',
		'ipv6',
		'date',
		'date-time',
		'time',
		'uuid',
		'color-hex',
	];

	/**
	 * The keys a schema of each type may carry, besides `type` and `hidden`.
	 *
	 * @var array<string,string[]>
	 */
	public const KEYS_BY_TYPE = [
		'string'  => ['format', 'minLength', 'maxLength', 'pattern'],
		'integer' => ['minimum', 'maximum'],
		'number'  => ['minimum', 'maximum', 'multipleOf'],
		'boolean' => [],
		'object'  => [],
	];

	/**
	 * The pairs of limits whose first may not exceed its second.
	 *
	 * @var array<int,array{0:string,1:string}>
	 */
	private const RANGES = [['minLength', 'maxLength'], ['minimum', 'maximum']];

	/**
	 * Fields whose values live in their own columns and never carry a schema.
	 *
	 * @var string[]
	 */
	private const COLUMN_FIELDS = ['key', 'login', 'url'];

	/**
	 * Check the schemas against the requested fields and return them as stored.
	 *
	 * Each schema must belong to a requested custom field and have a known type, and only the keys that type allows:
	 * a known format, non-negative whole lengths, finite numeric limits, a `multipleOf` above zero, a minimum no
	 * higher than its maximum, a pattern SafePattern accepts, and a boolean `hidden`. Anything else is refused rather
	 * than dropped, so a client learns its schema was not stored as sent.
	 *
	 * @param array<mixed>  $schemas         The schemas by field name, as the client sent them
	 * @param array<string> $requestedFields The requested field names
	 *
	 * @return array<string,array<string,mixed>> The schemas to store
	 *
	 * @throws InvalidArgumentException With a message naming the first problem.
	 *
	 * @spec openspec/changes/vault-custom-field-kinds-and-ssh-key/specs/vault-custom-fields/spec.md#requirement-a-request-carries-the-schemas-of-its-fields
	 */
	public function normalize(array $schemas, array $requestedFields): array {
		$checked = [];
		foreach ($schemas as $field => $schema) {
			$name = (string)$field;
			if (in_array($name, $requestedFields, true) === false
				|| in_array($name, [...self::COLUMN_FIELDS, self::SCHEMA_MEMBER], true) === true
			) {
				throw new InvalidArgumentException(message: 'A field schema must belong to a requested custom field: ' . $name);
			}

			if (is_array($schema) === false) {
				throw new InvalidArgumentException(message: 'The schema of ' . $name . ' must be an object');
			}

			$checked[$name] = $this->checkSchema(name: $name, schema: $schema);
		}

		return $checked;
	}//end normalize()

	/**
	 * The checked schemas as the request stores them: JSON, or null when there are none.
	 *
	 * @param array<string,array<string,mixed>> $schemas The checked schemas
	 *
	 * @return string|null
	 *
	 * @throws InvalidArgumentException When a schema cannot be written as JSON.
	 *
	 * @spec openspec/changes/vault-custom-field-kinds-and-ssh-key/specs/vault-custom-fields/spec.md#requirement-a-request-carries-the-schemas-of-its-fields
	 */
	public function encode(array $schemas): ?string {
		if ($schemas === []) {
			return null;
		}

		try {
			return json_encode($schemas, JSON_THROW_ON_ERROR);
		} catch (JsonException $e) {
			throw new InvalidArgumentException(message: 'The field schemas cannot be stored: ' . $e->getMessage());
		}
	}//end encode()

	/**
	 * Check one schema.
	 *
	 * @param string       $name   The field name, for the message
	 * @param array<mixed> $schema The schema
	 *
	 * @return array<string,mixed> The schema
	 *
	 * @throws InvalidArgumentException When a key or value is not allowed.
	 *
	 * @spec openspec/changes/vault-custom-field-kinds-and-ssh-key/specs/vault-custom-fields/spec.md#requirement-a-request-carries-the-schemas-of-its-fields
	 */
	private function checkSchema(string $name, array $schema): array {
		if (in_array($schema['type'] ?? null, self::TYPES, true) === false) {
			throw new InvalidArgumentException(message: 'The schema of ' . $name . ' needs a known type');
		}

		$keys = ['type', 'hidden', ...self::KEYS_BY_TYPE[$schema['type']]];
		foreach ($schema as $key => $value) {
			$key = (string)$key;
			if (in_array($key, $keys, true) === false || $this->allows(key: $key, value: $value) === false) {
				throw new InvalidArgumentException(message: 'The schema of ' . $name . ' has an invalid ' . $key);
			}
		}

		foreach (self::RANGES as [$low, $high]) {
			if (isset($schema[$low], $schema[$high]) === true && $schema[$low] > $schema[$high]) {
				throw new InvalidArgumentException(message: 'The schema of ' . $name . ' has a ' . $low . ' above its ' . $high);
			}
		}

		return $schema;
	}//end checkSchema()

	/**
	 * Whether a schema may carry this key with this value.
	 *
	 * @param string $key   The key
	 * @param mixed  $value Its value
	 *
	 * @return bool
	 *
	 * @spec openspec/changes/vault-custom-field-kinds-and-ssh-key/specs/vault-custom-fields/spec.md#requirement-a-request-carries-the-schemas-of-its-fields
	 */
	private function allows(string $key, mixed $value): bool {
		$finite = (is_int($value) === true || is_float($value) === true) && is_finite((float)$value) === true;

		return match ($key) {
			'type' => true,
			'format' => in_array($value, self::FORMATS, true),
			'pattern' => is_string($value) === true && (new SafePattern())->isSafe(pattern: $value),
			'hidden' => is_bool($value),
			'minLength', 'maxLength' => is_int($value) === true && $value >= 0,
			'minimum', 'maximum' => $finite,
			'multipleOf' => $finite === true && $value > 0,
			default => false,
		};
	}//end allows()
}//end class
