<?php

/**
 * Keepiq Fields Not Re-encrypted Exception
 *
 * Thrown when a migration commit leaves out a value field the stored record holds.
 * Accepting it would re-point the record to the new suite while that field stayed sealed under the old, revoked key.
 * Controllers map this to an HTTP 409 response carrying the missing field names.
 *
 * @category Exception
 * @package  OCA\Keepiq\Exception
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

namespace OCA\Keepiq\Exception;

use RuntimeException;

/**
 * Thrown when a migration commit omits a stored value field.
 *
 * @spec openspec/changes/restore-suite-migration-loop/specs/encryption-suites/spec.md#requirement-migration-covers-every-suite-bound-store
 */
class FieldsNotReencryptedException extends RuntimeException {
	/**
	 * The API names of the stored fields the commit left out.
	 *
	 * @var string[]
	 */
	private array $fields = [];

	/**
	 * Record the missing field names to surface to the caller.
	 *
	 * @param string[] $fields The API names of the missing fields
	 *
	 * @return self
	 *
	 * @spec openspec/changes/restore-suite-migration-loop/specs/encryption-suites/spec.md#requirement-migration-covers-every-suite-bound-store
	 */
	public function withFields(array $fields): self {
		$this->fields = $fields;
		return $this;
	}//end withFields()

	/**
	 * The API names of the stored fields the commit left out.
	 *
	 * @return string[]
	 *
	 * @spec openspec/changes/restore-suite-migration-loop/specs/encryption-suites/spec.md#requirement-migration-covers-every-suite-bound-store
	 */
	public function getFields(): array {
		return $this->fields;
	}//end getFields()
}//end class
