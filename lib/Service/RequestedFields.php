<?php

/**
 * Keepiq Requested Fields
 *
 * The fields a secret request asks for, with the schemas of its custom fields.
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
 * The names of the fields a request asks for, and the schema of each requested
 * custom field by name. A name without a schema is plain text.
 *
 * @spec openspec/changes/vault-custom-field-kinds-and-ssh-key/specs/vault-custom-fields/spec.md#requirement-a-request-carries-the-schemas-of-its-fields
 */
final class RequestedFields {
	/**
	 * Constructor for RequestedFields.
	 *
	 * @param array<string> $names The field names to be filled in
	 * @param array<mixed> $schemas The schemas of requested custom fields, by name
	 *
	 * @return void
	 */
	public function __construct(
		public readonly array $names,
		public readonly array $schemas = [],
	) {
		$this->refuseSchemaMember();
	}//end __construct()

	/**
	 * Refuse the member that holds the field schemas as a requested field: it
	 * is not a field, and applications are told to skip it. Every path that
	 * makes a request, a user's or an application's, builds this object.
	 *
	 * @return void
	 *
	 * @throws InvalidArgumentException With code 400 when `@schema` is among them
	 *
	 * @spec openspec/changes/vault-custom-field-kinds-and-ssh-key/specs/vault-custom-fields/spec.md#requirement-custom-fields-carry-a-schema
	 */
	private function refuseSchemaMember(): void {
		if (in_array(FieldSchemaShape::SCHEMA_MEMBER, $this->names, true) === true) {
			throw new InvalidArgumentException(
				message: FieldSchemaShape::SCHEMA_MEMBER . ' holds the field schemas and cannot be requested',
				code: 400
			);
		}
	}//end refuseSchemaMember()
}//end class
