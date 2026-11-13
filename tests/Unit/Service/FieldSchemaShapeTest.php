<?php

/**
 * Keepiq - FieldSchemaShape tests
 *
 * The shape check a request's field schemas pass before they are stored: only
 * requested custom fields, a known type and format, numeric limits, a string
 * pattern and a boolean hidden switch.
 *
 * @category Test
 * @package  OCA\Keepiq\Tests\Unit\Service
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

namespace OCA\Keepiq\Tests\Unit\Service;

use InvalidArgumentException;
use OCA\Keepiq\Service\FieldSchemaShape;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * Tests for the field schema shape check.
 *
 * @spec openspec/changes/vault-custom-field-kinds-and-ssh-key/specs/vault-custom-fields/spec.md#requirement-a-request-carries-the-schemas-of-its-fields
 */
class FieldSchemaShapeTest extends TestCase {

	/**
	 * A well-formed schema for a requested custom field is kept as sent.
	 *
	 * @return void
	 */
	public function testKeepsAWellFormedSchema(): void {
		$schemas = [
			'Port' => ['type' => 'integer', 'minimum' => 1, 'maximum' => 65535],
			'PIN'  => ['type' => 'string', 'pattern' => '^[0-9]{4,6}$', 'hidden' => true],
			'Hook' => ['type' => 'string', 'format' => 'uri', 'maxLength' => 200],
		];

		$this->assertSame(
			expected: $schemas,
			actual: (new FieldSchemaShape())->normalize(schemas: $schemas, requestedFields: ['key', 'Port', 'PIN', 'Hook'])
		);
	}//end testKeepsAWellFormedSchema()

	/**
	 * Malformed schemas are refused.
	 *
	 * @return array<string,array{0: array<mixed>}>
	 */
	public static function malformed(): array {
		return [
			'a field not requested'     => [['Other' => ['type' => 'string']]],
			'a column field'            => [['key' => ['type' => 'string']]],
			'no type'                   => [['PIN' => ['hidden' => true]]],
			'an unknown type'           => [['PIN' => ['type' => 'secret']]],
			'an unknown format'         => [['PIN' => ['type' => 'string', 'format' => 'phone']]],
			'a limit that is not a number' => [['PIN' => ['type' => 'string', 'maxLength' => '6']]],
			'a hidden that is not a boolean' => [['PIN' => ['type' => 'string', 'hidden' => 'yes']]],
			'an unknown key'            => [['PIN' => ['type' => 'string', 'enum' => ['1']]]],
			'a schema that is no object' => [['PIN' => 'string']],
		];
	}//end malformed()

	/**
	 * A malformed schema is refused with an InvalidArgumentException.
	 *
	 * @param array<mixed> $schemas The schemas.
	 *
	 * @return void
	 */
	#[DataProvider('malformed')]
	public function testRefusesAMalformedSchema(array $schemas): void {
		$this->expectException(exception: InvalidArgumentException::class);

		(new FieldSchemaShape())->normalize(schemas: $schemas, requestedFields: ['key', 'PIN']);
	}//end testRefusesAMalformedSchema()
}//end class
