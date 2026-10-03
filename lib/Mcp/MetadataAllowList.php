<?php

/**
 * Keepiq MCP: the metadata allow-list
 *
 * @category Mcp
 * @package  OCA\Keepiq\Mcp
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

namespace OCA\Keepiq\Mcp;

use InvalidArgumentException;

/**
 * The only keys a Keepiq MCP tool result may carry, per result type.
 *
 * An allow-list fails closed: a column added to Secret later does not reach
 * an agent until it is named here, in a change to the mcp-metadata-surface
 * capability. Secret values, ciphertext (key, login, additionalFields) and
 * encryptionSuiteId are on no list.
 *
 * @spec openspec/changes/hermiq-ai-tooling/specs/mcp-metadata-surface/spec.md#requirement-no-tool-ever-returns-secret-material
 */
final class MetadataAllowList {

	/**
	 * Allowed keys per result type.
	 *
	 * @var array<string,list<string>>
	 */
	public const KEYS = [
		'entry' => ['id', 'name', 'url', 'typeId', 'folderId', 'expiresAt', 'keyUpdatedAt', 'possiblyCompromisedAt', 'tombstonedAt'],
		'certificate' => ['id', 'name', 'subject', 'issuer', 'serial', 'notAfter', 'daysRemaining', 'expired', 'fingerprintSha256'],
		'expiringSecret' => ['id', 'name', 'expiresAt', 'daysRemaining', 'expired'],
		'rotationFlag' => ['id', 'name', 'reason', 'status', 'flaggedAt', 'keyUpdatedAtAtFlag'],
	];

	/**
	 * Keep only the allow-listed keys of one row, in allow-list order.
	 * Values that are not scalars or null are dropped too, so a nested
	 * structure cannot carry anything past the list.
	 *
	 * @param array<string,mixed> $row The source row
	 * @param string $type One of the KEYS result types
	 *
	 * @return array<string,scalar|null>
	 *
	 * @throws InvalidArgumentException On an unknown result type
	 *
	 * @spec openspec/changes/hermiq-ai-tooling/specs/mcp-metadata-surface/spec.md#requirement-no-tool-ever-returns-secret-material
	 */
	public function project(array $row, string $type): array {
		if (isset(self::KEYS[$type]) === false) {
			throw new InvalidArgumentException('Unknown MCP result type: ' . $type);
		}

		$out = [];
		foreach (self::KEYS[$type] as $key) {
			if (array_key_exists($key, $row) === false) {
				continue;
			}

			$value = $row[$key];
			if ($value === null || is_scalar($value) === true) {
				$out[$key] = $value;
			}
		}

		return $out;
	}//end project()
}//end class
