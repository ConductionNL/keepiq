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
 * @spec openspec/changes/audit-siem-vendor-connectors/specs/siem-vendor-connectors/spec.md#requirement-microsoft-sentinel-delivery-through-the-logs-ingestion-api
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
	 */
	public function format(array $payload): array {
		$view = new PayloadView(payload: $payload);
		return [
			'TimeGenerated' => gmdate('Y-m-d\TH:i:s\Z', (int)$view->epoch()),
			'EventType' => $view->get('eventType'),
			'Category' => $view->get('category'),
			'ActorType' => $view->get('actorType'),
			'ActorId' => $view->get('actorId'),
			'ObjectType' => $view->get('objectType'),
			'ObjectId' => $view->get('objectId'),
			'Metadata' => (object)$view->metadata(),
		];
	}//end format()
}//end class
