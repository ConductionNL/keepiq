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
	 * The numeric limits a schema may carry.
	 *
	 * @var string[]
	 */
	public const LIMITS = ['minLength', 'maxLength', 'minimum', 'maximum', 'multipleOf'];

	/**
	 * The longest pattern a schema may carry.
	 *
	 * @var integer
	 */
	public const MAX_PATTERN_LENGTH = 500;

	/**
	 * A quantified group with a quantifier inside, such as `(a+)+`: the shape
	 * that backtracks for ever on a near match. The pattern runs on every
	 * keystroke in the recipient's browser, which has no time limit.
	 *
	 * @var string
	 */
	private const NESTED_QUANTIFIER = '/\((?:\\\\.|[^()\\\\])*[*+}](?:\\\\.|[^()\\\\])*\)(?:[*+]|\{\d*,\d*\})/';

	/**
	 * Fields whose values live in their own columns and never carry a schema.
	 *
	 * @var string[]
	 */
	private const COLUMN_FIELDS = ['key', 'login', 'url'];

	/**
	 * Check the schemas against the requested fields and return them as stored.
	 *
	 * Each schema must belong to a requested custom field and have a known type, a known format, numeric limits, a
	 * pattern that compiles, is of reasonable length and has no quantified group with a quantifier inside, and a
	 * boolean `hidden`. Unknown keys are refused rather than dropped, so a
	 * client learns its schema was not stored as sent.
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
			if (in_array($name, $requestedFields, true) === false || in_array($name, self::COLUMN_FIELDS, true) === true) {
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
	 * @spec openspec/changes/vault-custom-field-kinds-and-ssh-key/specs/vault-custom-fields/spec.md#requirement-a-request-carries-the-schemas-of-its-fields
	 */
	public function encode(array $schemas): ?string {
		if ($schemas === []) {
			return null;
		}

		return (string)json_encode($schemas);
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

		foreach ($schema as $key => $value) {
			if ($this->allows(key: (string)$key, value: $value) === false) {
				throw new InvalidArgumentException(message: 'The schema of ' . $name . ' has an invalid ' . $key);
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
		if (in_array($key, self::LIMITS, true) === true) {
			return is_int($value) === true || is_float($value) === true;
		}

		return match ($key) {
			'type' => true,
			'format' => in_array($value, self::FORMATS, true),
			'pattern' => is_string($value) === true && $this->isUsablePattern(pattern: $value),
			'hidden' => is_bool($value),
			default => false,
		};
	}//end allows()

	/**
	 * Whether a pattern can be used on the fill page: not too long, without a
	 * nested quantifier, and one that compiles. It is only compiled here, never
	 * run on a value.
	 *
	 * @param string $pattern The pattern
	 *
	 * @return bool
	 *
	 * @spec openspec/changes/vault-custom-field-kinds-and-ssh-key/specs/vault-custom-fields/spec.md#requirement-a-request-carries-the-schemas-of-its-fields
	 */
	private function isUsablePattern(string $pattern): bool {
		if ($pattern === '' || strlen($pattern) > self::MAX_PATTERN_LENGTH || str_contains($pattern, "\x01") === true) {
			return false;
		}

		if (preg_match(self::NESTED_QUANTIFIER, $pattern) === 1) {
			return false;
		}

		// A pattern that does not compile raises a warning; it only means no.
		set_error_handler(static fn (): bool => true);
		try {
			return preg_match("\x01" . $pattern . "\x01u", '') !== false;
		} finally {
			restore_error_handler();
		}
	}//end isUsablePattern()
}//end class
