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
		if (in_array($type, ['syslog', 'webhook'], true) === false) {
			throw new InvalidArgumentException('type must be syslog or webhook');
		}

		$endpoint = (string)($params['endpoint'] ?? '');
		if ($endpoint === '') {
			throw new InvalidArgumentException('endpoint is required');
		}

		if ($type === 'webhook' && str_starts_with($endpoint, 'https://') === false) {
			throw new InvalidArgumentException('webhook endpoints must be https://');
		}

		$sink = new SiemSink();
		$sink->setId(Uuid::uuid4()->toString());
		$sink->setName((string)($params['name'] ?? $type));
		$sink->setType($type);
		$sink->setEnabled((bool)($params['enabled'] ?? true));
		$sink->setEndpoint($endpoint);
		$sink->setTls((bool)($params['tls'] ?? true));
		$sink->setQueueCap(max(10, (int)($params['queueCap'] ?? 1000)));
		$sink->setCreatedBy($adminUid);
		$sink->setCreatedAt(new DateTime());
		$this->applySecretAndFilter(sink: $sink, params: $params);
		$sink = $this->sinkMapper->insert($sink);

		$this->auditTrail->recordSinkCreated(actorId: $adminUid, sinkId: $sink->getId(), type: $type);

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
			$sink->setEndpoint((string)$params['endpoint']);
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

		$this->auditTrail->recordSinkUpdated(actorId: $adminUid, sinkId: $sinkId);

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
			$error = $exception->getMessage();
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

		if (array_key_exists('categoryFilter', $params) === true) {
			$filter = $params['categoryFilter'];
			$encoded = null;
			if (is_array($filter) === true && $filter !== []) {
				$encoded = (string)json_encode(array_map('strval', $filter));
			}

			$sink->setCategoryFilter($encoded);
		}
	}//end applySecretAndFilter()
}//end class
