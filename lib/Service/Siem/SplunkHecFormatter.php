<?php

/**
 * Keepiq SIEM formatter: Splunk HTTP Event Collector
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
 * The Splunk HEC event envelope: time, host, source, sourcetype, an optional
 * index, and the sanitized payload as the event.
 *
 * @spec openspec/specs/siem-vendor-connectors/spec.md#requirement-splunk-http-event-collector-delivery
 */
final class SplunkHecFormatter {

	/**
	 * The default sourcetype, matching integrations/siem/splunk/props.conf.
	 *
	 * @var string
	 */
	public const SOURCETYPE = 'keepiq:audit';

	/**
	 * Build the envelope.
	 *
	 * @param array<string,mixed> $payload The buildPayload() array
	 * @param string $host The Nextcloud host name
	 * @param array<string,string> $options The sink's connector options (index, sourcetype)
	 *
	 * @return array<string,mixed>
	 *
	 * @spec openspec/specs/siem-vendor-connectors/spec.md#requirement-splunk-http-event-collector-delivery
	 */
	public function format(array $payload, string $host, array $options = []): array {
		$view = new PayloadView(payload: $payload);
		$envelope = [
			'time' => $view->epoch(),
			'host' => $host,
			'source' => 'keepiq',
			'sourcetype' => self::SOURCETYPE,
		];
		if (($options['sourcetype'] ?? '') !== '') {
			$envelope['sourcetype'] = $options['sourcetype'];
		}

		if (($options['index'] ?? '') !== '') {
			$envelope['index'] = $options['index'];
		}

		$event = $view->sanitized();
		$event['metadata'] = (object)$event['metadata'];
		$envelope['event'] = $event;
		return $envelope;
	}//end format()
}//end class
