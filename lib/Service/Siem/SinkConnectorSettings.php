<?php

/**
 * Keepiq SIEM: connector settings of a sink
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

use InvalidArgumentException;
use OCA\Keepiq\Db\SiemSink;

/**
 * Validates what a sink type may carry: the type itself, an https endpoint
 * for every HTTP connector, json or (on syslog only) cef, the connector's own
 * options with the Sentinel ones required and defaulted, and a credential for
 * Splunk and Sentinel. Pure: no storage, no crypto.
 *
 * @spec openspec/specs/siem-vendor-connectors/spec.md#requirement-named-siem-connectors-on-a-sink
 */
final class SinkConnectorSettings {

	/**
	 * Sink types: the generic syslog and webhook transports and the named
	 * Splunk HEC and Microsoft Sentinel connectors.
	 *
	 * @var string[]
	 */
	public const TYPES = ['syslog', 'webhook', 'splunk_hec', 'sentinel'];

	/**
	 * The non-secret options each connector accepts.
	 *
	 * @var array<string,string[]>
	 */
	public const CONNECTOR_OPTIONS = [
		'splunk_hec' => ['index', 'sourcetype'],
		'sentinel' => ['tenantId', 'clientId', 'dataCollectionEndpoint', 'dcrImmutableId', 'streamName', 'authorityHost'],
	];

	/**
	 * Connectors that need a credential (HEC token, client secret).
	 *
	 * @var string[]
	 */
	private const NEED_CREDENTIAL = ['splunk_hec', 'sentinel'];

	/**
	 * Validate the connector fields of a create and return them normalised.
	 *
	 * @param array<string,mixed> $params The create params
	 *
	 * @return array{type: string, endpoint: string, format: string, connectorOptions: string|null}
	 *
	 * @throws InvalidArgumentException On any invalid field
	 *
	 * @spec openspec/specs/siem-vendor-connectors/spec.md#requirement-named-siem-connectors-on-a-sink
	 */
	public function forCreate(array $params): array {
		$type = (string)($params['type'] ?? '');
		if (in_array($type, self::TYPES, true) === false) {
			throw new InvalidArgumentException('type must be one of: ' . implode(', ', self::TYPES));
		}

		$endpoint = (string)($params['endpoint'] ?? '');
		if ($endpoint === '') {
			throw new InvalidArgumentException('endpoint is required');
		}

		$this->assertEndpoint(type: $type, endpoint: $endpoint);
		$credential = ($params['credential'] ?? '');
		if (in_array($type, self::NEED_CREDENTIAL, true) === true && (is_string($credential) === false || $credential === '')) {
			throw new InvalidArgumentException($type . ' needs a credential (the HEC token or the client secret)');
		}

		return [
			'type' => $type,
			'endpoint' => $endpoint,
			'format' => $this->format(type: $type, format: (string)($params['format'] ?? 'json')),
			'connectorOptions' => $this->encode(options: $this->options(type: $type, options: ($params['connectorOptions'] ?? []))),
		];
	}//end forCreate()

	/**
	 * Apply an update's endpoint, format and connector options to a sink,
	 * validated against its type. Absent or blank fields stay as they are.
	 *
	 * @param SiemSink $sink The sink
	 * @param array<string,mixed> $params The update params
	 *
	 * @return void
	 *
	 * @throws InvalidArgumentException On any invalid field
	 *
	 * @spec openspec/specs/siem-vendor-connectors/spec.md#requirement-named-siem-connectors-on-a-sink
	 */
	public function applyUpdate(SiemSink $sink, array $params): void {
		$type = $sink->getType();
		$endpoint = (string)($params['endpoint'] ?? '');
		if ($endpoint !== '') {
			$this->assertEndpoint(type: $type, endpoint: $endpoint);
			$sink->setEndpoint($endpoint);
		}

		$format = (string)($params['format'] ?? '');
		if ($format !== '') {
			$sink->setFormat($this->format(type: $type, format: $format));
		}

		if (($params['connectorOptions'] ?? null) !== null) {
			$sink->setConnectorOptions($this->encode(options: $this->options(type: $type, options: $params['connectorOptions'])));
		}
	}//end applyUpdate()

	/**
	 * Every HTTP transport (webhook, Splunk HEC, Sentinel) must be https://.
	 *
	 * @param string $type The sink type
	 * @param string $endpoint The endpoint
	 *
	 * @return void
	 *
	 * @throws InvalidArgumentException On a non-https HTTP endpoint
	 */
	private function assertEndpoint(string $type, string $endpoint): void {
		if ($type !== 'syslog' && str_starts_with($endpoint, 'https://') === false) {
			throw new InvalidArgumentException($type . ' endpoints must be https://');
		}
	}//end assertEndpoint()

	/**
	 * json everywhere, cef on syslog only.
	 *
	 * @param string $type The sink type
	 * @param string $format The requested format
	 *
	 * @return string
	 *
	 * @throws InvalidArgumentException On an unknown format or cef off syslog
	 */
	private function format(string $type, string $format): string {
		if (in_array($format, ['json', 'cef'], true) === false) {
			throw new InvalidArgumentException('format must be json or cef');
		}

		if ($format === 'cef' && $type !== 'syslog') {
			throw new InvalidArgumentException('format cef is only for syslog sinks');
		}

		return $format;
	}//end format()

	/**
	 * Only the keys the connector knows, blanks dropped; for Sentinel the
	 * required settings and the defaults too.
	 *
	 * @param string $type The sink type
	 * @param mixed $options The requested options
	 *
	 * @return array<string,string>
	 *
	 * @throws InvalidArgumentException On unknown, missing or invalid options
	 */
	private function options(string $type, mixed $options): array {
		if (is_array($options) === false) {
			throw new InvalidArgumentException('connectorOptions must be an object');
		}

		$allowed = (self::CONNECTOR_OPTIONS[$type] ?? []);
		$clean = [];
		foreach ($options as $key => $value) {
			if (in_array((string)$key, $allowed, true) === false) {
				throw new InvalidArgumentException('connectorOptions.' . $key . ' is not an option of ' . $type);
			}

			if ((string)$value !== '') {
				$clean[(string)$key] = (string)$value;
			}
		}

		if ($type === 'sentinel') {
			return $this->sentinel(options: $clean);
		}

		return $clean;
	}//end options()

	/**
	 * The Sentinel settings: tenant, client, endpoint and rule required; the
	 * stream and authority host defaulted; both URLs https.
	 *
	 * @param array<string,string> $options The cleaned options
	 *
	 * @return array<string,string>
	 *
	 * @throws InvalidArgumentException On a missing setting or a non-https URL
	 */
	private function sentinel(array $options): array {
		foreach (['tenantId', 'clientId', 'dataCollectionEndpoint', 'dcrImmutableId'] as $required) {
			if (isset($options[$required]) === false) {
				throw new InvalidArgumentException('sentinel needs connectorOptions.' . $required);
			}
		}

		$options += ['streamName' => 'Custom-KeepiqAudit', 'authorityHost' => 'https://login.microsoftonline.com'];
		foreach (['dataCollectionEndpoint', 'authorityHost'] as $url) {
			if (str_starts_with($options[$url], 'https://') === false) {
				throw new InvalidArgumentException('connectorOptions.' . $url . ' must be https://');
			}
		}

		return $options;
	}//end sentinel()

	/**
	 * Options as stored: JSON, or null when there are none.
	 *
	 * @param array<string,string> $options The options
	 *
	 * @return string|null
	 */
	private function encode(array $options): ?string {
		if ($options === []) {
			return null;
		}

		return (string)json_encode($options);
	}//end encode()
}//end class
