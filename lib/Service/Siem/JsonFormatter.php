<?php

/**
 * Keepiq SIEM formatter: JSON
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
 * The generic JSON body syslog and webhook sinks have always carried.
 *
 * @spec openspec/specs/siem-vendor-connectors/spec.md#requirement-named-siem-connectors-on-a-sink
 */
final class JsonFormatter {

	/**
	 * Encode the sanitized payload.
	 *
	 * @param array<string,mixed> $payload The buildPayload() array
	 *
	 * @return string
	 *
	 * @spec openspec/specs/siem-vendor-connectors/spec.md#requirement-named-siem-connectors-on-a-sink
	 */
	public function format(array $payload): string {
		return (string)json_encode((new PayloadView(payload: $payload))->sanitized());
	}//end format()
}//end class
