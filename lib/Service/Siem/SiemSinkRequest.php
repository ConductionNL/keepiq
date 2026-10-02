<?php

/**
 * Keepiq SIEM: the sink fields of one request
 *
 * @category Service
 * @package  OCA\Keepiq\Service\Siem
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

namespace OCA\Keepiq\Service\Siem;

use OCP\IRequest;

/**
 * Reads the sink fields of a create or update request into the params array
 * SiemSinkService takes. One value object instead of eleven router-bound
 * arguments: the HTTP contract (field names and defaults) is unchanged.
 *
 * @spec openspec/specs/siem-vendor-connectors/spec.md#requirement-named-siem-connectors-on-a-sink
 */
final class SiemSinkRequest {

	/**
	 * Constructor.
	 *
	 * @param IRequest $request The request
	 *
	 * @return void
	 */
	public function __construct(private IRequest $request) {
	}//end __construct()

	/**
	 * The params of a create, with the create defaults.
	 *
	 * @return array<string,mixed>
	 *
	 * @spec openspec/specs/siem-vendor-connectors/spec.md#requirement-named-siem-connectors-on-a-sink
	 */
	public function forCreate(): array {
		$format = $this->string(name: 'format');
		if ($format === '') {
			$format = 'json';
		}

		return [
			'name' => $this->string(name: 'name'),
			'type' => $this->string(name: 'type'),
			'endpoint' => $this->string(name: 'endpoint'),
			'tls' => ($this->bool(name: 'tls') ?? true),
			'hmacSecret' => $this->string(name: 'hmacSecret'),
			'categoryFilter' => ($this->list(name: 'categoryFilter') ?? []),
			'queueCap' => ($this->int(name: 'queueCap') ?? 1000),
			'enabled' => ($this->bool(name: 'enabled') ?? true),
			'format' => $format,
			'credential' => $this->string(name: 'credential'),
			'connectorOptions' => ($this->list(name: 'connectorOptions') ?? []),
		];
	}//end forCreate()

	/**
	 * The fields an update actually supplies. A blank string or an absent
	 * field means "leave unchanged"; the two write-only secrets are always
	 * passed, because the service reads '' as "keep the stored one".
	 *
	 * @return array<string,mixed>
	 *
	 * @spec openspec/specs/siem-vendor-connectors/spec.md#requirement-connector-credentials-are-write-only-and-encrypted-at-rest
	 */
	public function forUpdate(): array {
		$params = [
			'hmacSecret' => $this->string(name: 'hmacSecret'),
			'credential' => $this->string(name: 'credential'),
		];
		$candidates = [
			'name' => $this->blankToNull(value: $this->string(name: 'name')),
			'endpoint' => $this->blankToNull(value: $this->string(name: 'endpoint')),
			'format' => $this->blankToNull(value: $this->string(name: 'format')),
			'tls' => $this->bool(name: 'tls'),
			'enabled' => $this->bool(name: 'enabled'),
			'queueCap' => $this->int(name: 'queueCap'),
			'categoryFilter' => $this->list(name: 'categoryFilter'),
			'connectorOptions' => $this->list(name: 'connectorOptions'),
		];

		return $params + array_filter($candidates, static fn ($value): bool => $value !== null);
	}//end forUpdate()

	/**
	 * A string field, '' when absent or not scalar.
	 *
	 * @param string $name The field
	 *
	 * @return string
	 */
	private function string(string $name): string {
		$value = $this->request->getParam($name);
		if (is_scalar($value) === false) {
			return '';
		}

		return (string)$value;
	}//end string()

	/**
	 * A boolean field, null when absent.
	 *
	 * @param string $name The field
	 *
	 * @return bool|null
	 */
	private function bool(string $name): ?bool {
		$value = $this->request->getParam($name);
		if ($value === null || $value === '') {
			return null;
		}

		return filter_var($value, FILTER_VALIDATE_BOOLEAN);
	}//end bool()

	/**
	 * An integer field, null when absent or not numeric.
	 *
	 * @param string $name The field
	 *
	 * @return int|null
	 */
	private function int(string $name): ?int {
		$value = $this->request->getParam($name);
		if (is_numeric($value) === false) {
			return null;
		}

		return (int)$value;
	}//end int()

	/**
	 * An array field (list or object), null when absent or not an array.
	 *
	 * @param string $name The field
	 *
	 * @return array<array-key,mixed>|null
	 */
	private function list(string $name): ?array {
		$value = $this->request->getParam($name);
		if (is_array($value) === false) {
			return null;
		}

		return $value;
	}//end list()

	/**
	 * Null for an empty string.
	 *
	 * @param string $value The value
	 *
	 * @return string|null
	 */
	private function blankToNull(string $value): ?string {
		if ($value === '') {
			return null;
		}

		return $value;
	}//end blankToNull()
}//end class
