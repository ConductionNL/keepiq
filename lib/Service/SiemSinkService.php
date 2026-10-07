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
use OCA\Keepiq\Db\SiemQueueItemMapper;
use OCA\Keepiq\Db\SiemSink;
use OCA\Keepiq\Db\SiemSinkMapper;
use OCA\Keepiq\Service\Connection\ConnectionReporter;
use OCA\Keepiq\Service\Siem\SinkConnectorSettings;
use OCP\AppFramework\Db\DoesNotExistException;
use OCP\Security\ICrypto;
use Ramsey\Uuid\Uuid;
use Throwable;

/**
 * CRUD and test-fire for SIEM sinks.
 */
class SiemSinkService {


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
	 * Invalid parameters raise SinkConnectorSettings's InvalidArgumentException.
	 *
	 * @spec openspec/specs/siem-audit-export/spec.md#requirement-admin-configured-syslog-and-webhook-sinks
	 */
	public function createSink(string $adminUid, array $params): SiemSink {
		$connector = (new SinkConnectorSettings())->forCreate(params: $params);
		$type = $connector['type'];

		$sink = new SiemSink();
		$sink->setId(Uuid::uuid4()->toString());
		$sink->setName((string)($params['name'] ?? $type));
		$sink->setType($type);
		$sink->setEnabled((bool)($params['enabled'] ?? true));
		$sink->setEndpoint($connector['endpoint']);
		$sink->setTls((bool)($params['tls'] ?? true));
		$sink->setQueueCap(max(10, (int)($params['queueCap'] ?? 1000)));
		$sink->setFormat($connector['format']);
		$sink->setConnectorOptions($connector['connectorOptions']);
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
	 * An invalid format, endpoint or connector option raises SinkConnectorSettings's InvalidArgumentException.
	 *
	 * @spec openspec/specs/siem-audit-export/spec.md#requirement-admin-configured-syslog-and-webhook-sinks
	 */
	public function updateSink(string $adminUid, string $sinkId, array $params): SiemSink {
		$sink = $this->sinkMapper->findById($sinkId);
		$this->applyGeneralChanges(sink: $sink, params: $params);
		(new SinkConnectorSettings())->applyUpdate(sink: $sink, params: $params);

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
	 * Apply the generic fields an update supplies: name, enabled, tls and the
	 * queue cap. Absent fields stay as they are.
	 *
	 * @param SiemSink $sink The sink to mutate
	 * @param array<string,mixed> $params The request params
	 *
	 * @return void
	 */
	private function applyGeneralChanges(SiemSink $sink, array $params): void {
		if (isset($params['name']) === true) {
			$sink->setName((string)$params['name']);
		}

		if (isset($params['enabled']) === true) {
			$sink->setEnabled((bool)$params['enabled']);
		}

		if (isset($params['tls']) === true) {
			$sink->setTls((bool)$params['tls']);
		}

		if (isset($params['queueCap']) === true) {
			$sink->setQueueCap(max(10, (int)$params['queueCap']));
		}
	}//end applyGeneralChanges()

	/**
	 * Ask integriq to resolve SIEM export again after a sink change.
	 *
	 * The reporter counts the enabled sinks only when integriq is installed,
	 * and never throws (adopt-connection-registry).
	 *
	 * @return void
	 *
	 * @spec openspec/specs/admin-integrations/spec.md#requirement-req-keepiq-conn-002-a-save-asks-integriq-to-look-again-and-a-lookup-or-a-drain-reports-what-it-met
	 */
	private function reportSinksChanged(): void {
		$this->connectionReporter?->siemSinksChanged(
			enabledSinkCount: fn (): int => count($this->sinkMapper->findEnabled()),
			sinkCount: fn (): int => count($this->sinkMapper->findAll())
		);
	}//end reportSinksChanged()
}//end class
