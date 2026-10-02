<?php

/**
 * Keepiq SIEM Sink Service
 *
 * Sink administration for SIEM audit export (siem-audit-export §6.1):
 * create, update, delete, list and test-fire. Extracted from SiemService,
 * which now owns only payload building, the forwarding queue and its
 * drainage — two lifecycles that share nothing but the sink row.
 *
 * The webhook HMAC secret is write-only over the API: a blank value on an
 * update preserves the stored (ICrypto-encrypted) secret, and no read path
 * ever returns it.
 *
 * @category Service
 * @package  OCA\Keepiq\Service
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

namespace OCA\Keepiq\Service;

use DateTime;
use InvalidArgumentException;
use OCA\Keepiq\Db\SiemQueueItemMapper;
use OCA\Keepiq\Db\SiemSink;
use OCA\Keepiq\Db\SiemSinkMapper;
use OCA\Keepiq\Service\Connection\ConnectionReporter;
use OCP\AppFramework\Db\DoesNotExistException;
use OCP\Security\ICrypto;
use Ramsey\Uuid\Uuid;
use Throwable;

/**
 * CRUD and test-fire for SIEM sinks.
 */
class SiemSinkService {
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
	 * The sink audit trail.
	 *
	 * @var SiemAuditTrail
	 */
	private SiemAuditTrail $auditTrail;

	/**
	 * Constructor for SiemSinkService.
	 *
	 * @param SiemSinkMapper $sinkMapper The sink mapper
	 * @param SiemQueueItemMapper $queueMapper The queue mapper (delete cascade)
	 * @param ICrypto $crypto NC crypto (HMAC secret at rest)
	 * @param SiemTransport $transport The sink transport (test-fire)
	 * @param SiemAuditTrail|null $auditTrail The sink audit trail
	 * @param ConnectionReporter|null $connectionReporter Asks integriq to look again after a sink change, or nothing when absent
	 *
	 * @return void
	 *
	 * @spec exclude Constructor wiring only; the sink operations carry the spec anchors.
	 */
	public function __construct(
		private SiemSinkMapper $sinkMapper,
		private SiemQueueItemMapper $queueMapper,
		private ICrypto $crypto,
		private SiemTransport $transport,
		?SiemAuditTrail $auditTrail = null,
		private ?ConnectionReporter $connectionReporter = null,
	) {
		$this->auditTrail = ($auditTrail ?? new SiemAuditTrail());
	}//end __construct()

	/**
	 * Create a sink (§6.1).
	 *
	 * @param string $adminUid The creating admin
	 * @param array<string,mixed> $params {name, type, endpoint, tls?, hmacSecret?, categoryFilter?, queueCap?}
	 *
	 * @return SiemSink
	 *
	 * @throws InvalidArgumentException On invalid parameters
	 *
	 * @spec openspec/specs/siem-audit-export/spec.md#requirement-admin-configured-syslog-and-webhook-sinks
	 */
	public function createSink(string $adminUid, array $params): SiemSink {
		$type = (string)($params['type'] ?? '');
		if (in_array($type, self::TYPES, true) === false) {
			throw new InvalidArgumentException('type must be one of: ' . implode(', ', self::TYPES));
		}

		$endpoint = (string)($params['endpoint'] ?? '');
		if ($endpoint === '') {
			throw new InvalidArgumentException('endpoint is required');
		}

		$this->assertEndpoint(type: $type, endpoint: $endpoint);
		$format = $this->checkedFormat(type: $type, format: (string)($params['format'] ?? 'json'));
		$options = $this->checkedOptions(type: $type, options: $params['connectorOptions'] ?? []);
		$credential = $params['credential'] ?? '';
		if (in_array($type, ['splunk_hec', 'sentinel'], true) === true && (is_string($credential) === false || $credential === '')) {
			throw new InvalidArgumentException($type . ' needs a credential (the HEC token or the client secret)');
		}

		$sink = new SiemSink();
		$sink->setId(Uuid::uuid4()->toString());
		$sink->setName((string)($params['name'] ?? $type));
		$sink->setType($type);
		$sink->setEnabled((bool)($params['enabled'] ?? true));
		$sink->setEndpoint($endpoint);
		$sink->setTls((bool)($params['tls'] ?? true));
		$sink->setQueueCap(max(10, (int)($params['queueCap'] ?? 1000)));
		$sink->setFormat($format);
		$sink->setConnectorOptions($this->encodeOptions(options: $options));
		$sink->setCreatedBy($adminUid);
		$sink->setCreatedAt(new DateTime());
		$this->applySecretAndFilter(sink: $sink, params: $params);
		$sink = $this->sinkMapper->insert($sink);

		$this->auditTrail->recordSinkCreated(actorId: $adminUid, sinkId: $sink->getId(), type: $type);
		$this->reportSinksChanged();

		return $sink;
	}//end createSink()

	/**
	 * Update a sink; a blank HMAC secret preserves the stored one (§3.2).
	 *
	 * @param string $adminUid The updating admin
	 * @param string $sinkId The sink UUID
	 * @param array<string,mixed> $params Updatable fields
	 *
	 * @return SiemSink
	 *
	 * @throws DoesNotExistException When the sink is missing
	 *
	 * @spec openspec/specs/siem-audit-export/spec.md#requirement-admin-configured-syslog-and-webhook-sinks
	 */
	public function updateSink(string $adminUid, string $sinkId, array $params): SiemSink {
		$sink = $this->sinkMapper->findById($sinkId);
		if (isset($params['name']) === true) {
			$sink->setName((string)$params['name']);
		}

		if (isset($params['enabled']) === true) {
			$sink->setEnabled((bool)$params['enabled']);
		}

		if (isset($params['endpoint']) === true && (string)$params['endpoint'] !== '') {
			$this->assertEndpoint(type: $sink->getType(), endpoint: (string)$params['endpoint']);
			$sink->setEndpoint((string)$params['endpoint']);
		}

		if (isset($params['format']) === true && (string)$params['format'] !== '') {
			$sink->setFormat($this->checkedFormat(type: $sink->getType(), format: (string)$params['format']));
		}

		if (array_key_exists('connectorOptions', $params) === true && $params['connectorOptions'] !== null) {
			$options = $this->checkedOptions(type: $sink->getType(), options: $params['connectorOptions']);
			$sink->setConnectorOptions($this->encodeOptions(options: $options));
		}

		if (isset($params['tls']) === true) {
			$sink->setTls((bool)$params['tls']);
		}

		if (isset($params['queueCap']) === true) {
			$sink->setQueueCap(max(10, (int)$params['queueCap']));
		}

		$this->applySecretAndFilter(sink: $sink, params: $params);
		$sink->setUpdatedAt(new DateTime());
		$sink = $this->sinkMapper->update($sink);

		$this->auditTrail->recordSinkUpdated(actorId: $adminUid, sinkId: $sinkId, type: $sink->getType());
		$this->reportSinksChanged();

		return $sink;
	}//end updateSink()

	/**
	 * Delete a sink and its queue rows.
	 *
	 * @param string $adminUid The deleting admin
	 * @param string $sinkId The sink UUID
	 *
	 * @return void
	 *
	 * @throws DoesNotExistException When the sink is missing
	 *
	 * @spec openspec/specs/siem-audit-export/spec.md#requirement-admin-configured-syslog-and-webhook-sinks
	 */
	public function deleteSink(string $adminUid, string $sinkId): void {
		$sink = $this->sinkMapper->findById($sinkId);
		$this->queueMapper->deleteBySink($sinkId);
		$this->sinkMapper->delete($sink);

		$this->auditTrail->recordSinkDeleted(actorId: $adminUid, sinkId: $sinkId);
		$this->reportSinksChanged();
	}//end deleteSink()

	/**
	 * Test-fire a sink with a synthetic payload (§6.1).
	 *
	 * @param string $adminUid The testing admin
	 * @param string $sinkId The sink UUID
	 *
	 * @return array{ok:bool, error:string|null}
	 *
	 * @throws DoesNotExistException When the sink is missing
	 *
	 * @spec openspec/specs/siem-audit-export/spec.md#requirement-backpressure-and-observability
	 */
	public function testSink(string $adminUid, string $sinkId): array {
		$sink = $this->sinkMapper->findById($sinkId);
		$payload = (string)json_encode(
			[
				'eventType' => 'siem.sink_tested',
				'category' => 'siem',
				'actorType' => 'user',
				'actorId' => $adminUid,
				'objectType' => 'siem_sink',
				'objectId' => $sinkId,
				'occurredAt' => (new DateTime())->format('c'),
				'metadata' => ['test' => true],
			]
		);

		$outcome = 'ok';
		$error = null;
		try {
			$this->transport->deliver(sink: $sink, payloadJson: $payload);
		} catch (Throwable $exception) {
			$outcome = 'failed';
			// The admin sees class, status and host, never the message, which
			// names the full sink URL with any token in it (keepiq#728).
			$error = $this->transport->describeFailure(exception: $exception, sink: $sink);
		}

		$this->auditTrail->recordSinkTested(actorId: $adminUid, sinkId: $sinkId, outcome: $outcome);

		return [
			'ok' => ($outcome === 'ok'),
			'error' => $error,
		];
	}//end testSink()

	/**
	 * All sinks (secrets never included in serialization).
	 *
	 * @return SiemSink[]
	 *
	 * @spec openspec/specs/siem-audit-export/spec.md#requirement-admin-configured-syslog-and-webhook-sinks
	 */
	public function listSinks(): array {
		return $this->sinkMapper->findAll();
	}//end listSinks()

	/**
	 * Apply the write-only HMAC secret (blank preserves) and the
	 * category filter from request params.
	 *
	 * @param SiemSink $sink The sink to mutate
	 * @param array<string,mixed> $params The request params
	 *
	 * @return void
	 */
	private function applySecretAndFilter(SiemSink $sink, array $params): void {
		$secret = $params['hmacSecret'] ?? null;
		if (is_string($secret) === true && $secret !== '') {
			$sink->setHmacSecretEnc($this->crypto->encrypt($secret));
		}

		// The connector credential follows the HMAC secret's rule: write-only,
		// encrypted at rest, and blank keeps the stored one.
		$credential = $params['credential'] ?? null;
		if (is_string($credential) === true && $credential !== '') {
			$sink->setCredentialEnc($this->crypto->encrypt($credential));
		}

		if (array_key_exists('categoryFilter', $params) === true) {
			$filter = $params['categoryFilter'];
			$encoded = null;
			if (is_array($filter) === true && $filter !== []) {
				$encoded = (string)json_encode(array_map('strval', $filter));
			}

			$sink->setCategoryFilter($encoded);
		}
	}//end applySecretAndFilter()

	/**
	 * Refuse an endpoint the sink type cannot use: every HTTP transport
	 * (webhook, Splunk HEC, Sentinel) must be https://.
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
	 * The format a sink may use: json everywhere, cef on syslog only.
	 *
	 * @param string $type The sink type
	 * @param string $format The requested format
	 *
	 * @return string
	 *
	 * @throws InvalidArgumentException On an unknown format or cef off syslog
	 *
	 * @spec openspec/specs/siem-vendor-connectors/spec.md
	 */
	private function checkedFormat(string $type, string $format): string {
		if (in_array($format, ['json', 'cef'], true) === false) {
			throw new InvalidArgumentException('format must be json or cef');
		}

		if ($format === 'cef' && $type !== 'syslog') {
			throw new InvalidArgumentException('format cef is only for syslog sinks');
		}

		return $format;
	}//end checkedFormat()

	/**
	 * Validate connector options against the sink type: only the keys the
	 * connector knows, the required Sentinel settings present, https for the
	 * Sentinel authority host, and the defaults filled in.
	 *
	 * @param string $type The sink type
	 * @param mixed $options The requested options
	 *
	 * @return array<string,string>
	 *
	 * @throws InvalidArgumentException On unknown, missing or invalid options
	 *
	 * @spec openspec/specs/siem-vendor-connectors/spec.md
	 */
	private function checkedOptions(string $type, mixed $options): array {
		if (is_array($options) === false) {
			throw new InvalidArgumentException('connectorOptions must be an object');
		}

		$allowed = self::CONNECTOR_OPTIONS[$type] ?? [];
		$clean = [];
		foreach ($options as $key => $value) {
			if (in_array((string)$key, $allowed, true) === false) {
				throw new InvalidArgumentException('connectorOptions.' . $key . ' is not an option of ' . $type);
			}

			if ((string)$value !== '') {
				$clean[(string)$key] = (string)$value;
			}
		}

		if ($type !== 'sentinel') {
			return $clean;
		}

		foreach (['tenantId', 'clientId', 'dataCollectionEndpoint', 'dcrImmutableId'] as $required) {
			if (isset($clean[$required]) === false) {
				throw new InvalidArgumentException('sentinel needs connectorOptions.' . $required);
			}
		}

		$clean['streamName'] = ($clean['streamName'] ?? 'Custom-KeepiqAudit');
		$clean['authorityHost'] = ($clean['authorityHost'] ?? 'https://login.microsoftonline.com');
		foreach (['dataCollectionEndpoint', 'authorityHost'] as $url) {
			if (str_starts_with($clean[$url], 'https://') === false) {
				throw new InvalidArgumentException('connectorOptions.' . $url . ' must be https://');
			}
		}

		return $clean;
	}//end checkedOptions()

	/**
	 * Encode options for storage, or null when there are none.
	 *
	 * @param array<string,string> $options The options
	 *
	 * @return string|null
	 */
	private function encodeOptions(array $options): ?string {
		if ($options === []) {
			return null;
		}

		return (string)json_encode($options);
	}//end encodeOptions()

	/**
	 * Ask integriq to resolve SIEM export again after a sink change.
	 *
	 * The reporter counts the enabled sinks only when integriq is installed,
	 * and never throws (adopt-connection-registry).
	 *
	 * @return void
	 *
	 * @spec openspec/changes/adopt-connection-registry/specs/admin-integrations/spec.md#requirement-req-keepiq-conn-002-a-save-asks-integriq-to-look-again-and-a-lookup-or-a-drain-reports-what-it-met
	 */
	private function reportSinksChanged(): void {
		$this->connectionReporter?->siemSinksChanged(
			enabledSinkCount: fn (): int => count($this->sinkMapper->findEnabled()),
			sinkCount: fn (): int => count($this->sinkMapper->findAll())
		);
	}//end reportSinksChanged()
}//end class
