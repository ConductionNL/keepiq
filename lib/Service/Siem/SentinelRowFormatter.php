<?php

/**
 * Keepiq SIEM formatter: Microsoft Sentinel row
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

/**
 * One row for the KeepiqAudit_CL table through the Logs Ingestion API. The
 * column list is the one the template integrations/siem/sentinel/keepiq-dcr.json
 * declares; a test keeps the two equal.
 *
 * @spec openspec/specs/siem-vendor-connectors/spec.md#requirement-microsoft-sentinel-delivery-through-the-logs-ingestion-api
 */
final class SentinelRowFormatter {

	/**
	 * The row columns, in order.
	 *
	 * @var string[]
	 */
	public const COLUMNS = ['TimeGenerated', 'EventType', 'Category', 'ActorType', 'ActorId', 'ObjectType', 'ObjectId', 'Metadata'];

	/**
	 * Build the row.
	 *
	 * @param array<string,mixed> $payload The buildPayload() array
	 *
	 * @return array<string,mixed>
	 *
	 * @spec openspec/specs/siem-vendor-connectors/spec.md#requirement-microsoft-sentinel-delivery-through-the-logs-ingestion-api
	 */
	public function format(array $payload): array {
		$view = new PayloadView(payload: $payload);
		return [
			'TimeGenerated' => gmdate('Y-m-d\TH:i:s\Z', (int)$view->epoch()),
			'EventType' => $view->get(field: 'eventType'),
			'Category' => $view->get(field: 'category'),
			'ActorType' => $view->get(field: 'actorType'),
			'ActorId' => $view->get(field: 'actorId'),
			'ObjectType' => $view->get(field: 'objectType'),
			'ObjectId' => $view->get(field: 'objectId'),
			'Metadata' => (object)$view->metadata(),
		];
	}//end format()
}//end class
