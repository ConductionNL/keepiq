<?php

/**
 * Keepiq SIEM formatter: the sanitized payload, read field by field
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

use OCA\Keepiq\Event\Audit\AuditEventTypes;

/**
 * Reads the fields of a SiemService::buildPayload() array for the
 * formatters. It is the only way a formatter sees the payload: it returns
 * the eight known fields and nothing else, and it drops every forbidden
 * metadata key again, so no formatter can forward secret material even if a
 * payload carried it.
 *
 * @spec openspec/specs/siem-vendor-connectors/spec.md#requirement-connector-output-carries-no-secret-material
 */
final class PayloadView {

	/**
	 * Wrap one payload.
	 *
	 * @param array<string,mixed> $payload The buildPayload() array
	 *
	 * @return void
	 *
	 * @spec openspec/specs/siem-vendor-connectors/spec.md#requirement-connector-output-carries-no-secret-material
	 */
	public function __construct(private array $payload) {
	}//end __construct()

	/**
	 * A top-level string field, '' when absent.
	 *
	 * @param string $field One of eventType, category, actorType, actorId, objectType, objectId, occurredAt
	 *
	 * @return string
	 *
	 * @spec openspec/specs/siem-vendor-connectors/spec.md#requirement-connector-output-carries-no-secret-material
	 */
	public function get(string $field): string {
		$value = $this->payload[$field] ?? '';
		if (is_scalar($value) === false) {
			return '';
		}

		return (string)$value;
	}//end get()

	/**
	 * The whitelisted metadata minus every forbidden key.
	 *
	 * @return array<string,mixed>
	 *
	 * @spec openspec/specs/siem-vendor-connectors/spec.md#requirement-connector-output-carries-no-secret-material
	 */
	public function metadata(): array {
		$metadata = $this->payload['metadata'] ?? [];
		if (is_array($metadata) === false) {
			return [];
		}

		foreach (AuditEventTypes::FORBIDDEN_KEYS as $forbidden) {
			unset($metadata[$forbidden]);
		}

		return $metadata;
	}//end metadata()

	/**
	 * The event time as a Unix timestamp with milliseconds, now when absent.
	 *
	 * @return float
	 *
	 * @spec openspec/specs/siem-vendor-connectors/spec.md#requirement-connector-output-carries-no-secret-material
	 */
	public function epoch(): float {
		$time = strtotime($this->get(field: 'occurredAt'));
		if ($time === false) {
			return (float)time();
		}

		return (float)$time;
	}//end epoch()

	/**
	 * The payload rebuilt from the known fields only.
	 *
	 * @return array<string,mixed>
	 *
	 * @spec openspec/specs/siem-vendor-connectors/spec.md#requirement-connector-output-carries-no-secret-material
	 */
	public function sanitized(): array {
		return [
			'eventType' => $this->get(field: 'eventType'),
			'category' => $this->get(field: 'category'),
			'actorType' => $this->get(field: 'actorType'),
			'actorId' => $this->get(field: 'actorId'),
			'objectType' => $this->get(field: 'objectType'),
			'objectId' => $this->get(field: 'objectId'),
			'occurredAt' => $this->get(field: 'occurredAt'),
			'metadata' => $this->metadata(),
		];
	}//end sanitized()
}//end class
