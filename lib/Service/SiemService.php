<?php

/**
 * Doriath SIEM Service
 *
 * Payload building, forwarding-queue backpressure, and delivery drainage
 * (siem-audit-export §2/§4/§5). Forwarded payloads are rebuilt through
 * the audit whitelist so they are strict subsets of sanitized audit
 * entries.
 *
 * Sink administration lives in SiemSinkService, the wire in
 * SiemTransport, and the sink audit vocabulary in SiemAuditTrail; this
 * class keeps the queue.
 *
 * @category Service
 * @package  OCA\Doriath\Service
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

namespace OCA\Doriath\Service;

use DateInterval;
use DateTime;
use OCA\Doriath\Db\SiemQueueItem;
use OCA\Doriath\Db\SiemQueueItemMapper;
use OCA\Doriath\Db\SiemSink;
use OCA\Doriath\Db\SiemSinkMapper;
use OCA\Doriath\Event\Audit\AuditEvent;
use OCA\Doriath\Event\Audit\AuditEventTypes;
use OCP\IGroupManager;
use Psr\Log\LoggerInterface;
use Ramsey\Uuid\Uuid;
use Throwable;

/**
 * Business logic for the SIEM forwarding queue and its drainage.
 *
 * @SuppressWarnings(PHPMD.CouplingBetweenObjects) Pre-existing suppression,
 *   narrowed but not yet retired. Sink administration (SiemSinkService), the
 *   wire (SiemTransport) and the sink audit vocabulary (SiemAuditTrail) have
 *   been split out, which took the value from 22 to 17. What remains is the
 *   queue itself: two mappers plus their two entities, the audit event it
 *   reshapes, the transport it drains through, and the admin dead-letter
 *   notification — plus the five sink methods this class still re-exports for
 *   SiemSinkController. Retiring the tag needs that controller repointed at
 *   SiemSinkService, which is outside this change.
 */
class SiemService {
	/**
	 * Retry ceiling before a row dead-letters.
	 *
	 * @var int
	 */
	public const MAX_ATTEMPTS = 8;

	/**
	 * Base backoff in seconds (doubles per attempt).
	 *
	 * @var int
	 */
	private const BACKOFF_BASE_SECONDS = 60;

	/**
	 * Constructor for SiemService.
	 *
	 * @param SiemSinkMapper $sinkMapper The sink mapper
	 * @param SiemQueueItemMapper $queueMapper The queue mapper
	 * @param SiemTransport $transport The sink transport
	 * @param SiemSinkService $sinkService The sink administration service
	 * @param IGroupManager $groupManager The group manager (admin notifications)
	 * @param NotificationService|null $notificationService The notification dispatcher
	 * @param LoggerInterface $logger The logger
	 *
	 * @return void
	 */
	public function __construct(
		private SiemSinkMapper $sinkMapper,
		private SiemQueueItemMapper $queueMapper,
		private SiemTransport $transport,
		private SiemSinkService $sinkService,
		private IGroupManager $groupManager,
		private ?NotificationService $notificationService,
		private LoggerInterface $logger,
	) {
	}//end __construct()

	/**
	 * Build the forwarding payload for an audit event by rebuilding the
	 * metadata through the whitelist — a strict subset of the sanitized
	 * audit entry (§2.1). Returns null for unknown event types.
	 *
	 * @param AuditEvent $event The dispatched audit event
	 *
	 * @return array<string,mixed>|null
	 */
	public function buildPayload(AuditEvent $event): ?array {
		$eventType = $event->getEventType();
		$whitelist = AuditEventTypes::WHITELIST[$eventType] ?? null;
		if ($whitelist === null) {
			return null;
		}

		$metadata = [];
		foreach ($event->getMetadata() as $metaKey => $metaValue) {
			// Both predicates are evaluated up front rather than short-circuited.
			// The forbidden-key check is defence in depth: today no whitelist row
			// lists a forbidden key, so a && chain lets a static analyser narrow
			// $metaKey to the whitelist literals and declare the second check
			// dead. It is NOT dead — it is what stops secret material reaching a
			// SIEM sink the day someone widens a whitelist row.
			$isWhitelisted = in_array($metaKey, $whitelist, true);
			$isForbidden = in_array($metaKey, AuditEventTypes::FORBIDDEN_KEYS, true);
			if ($isWhitelisted === true && $isForbidden === false) {
				$metadata[$metaKey] = $metaValue;
			}
		}

		return [
			'eventType' => $eventType,
			'category' => explode('.', $eventType)[0],
			'actorType' => $event->getActorType(),
			'actorId' => $event->getActorId(),
			'objectType' => $event->getObjectType(),
			'objectId' => $event->getObjectId(),
			'occurredAt' => (new DateTime())->format('c'),
			'metadata' => $metadata,
		];
	}//end buildPayload()

	/**
	 * Enqueue a payload for every enabled sink whose category filter
	 * matches, with drop-oldest backpressure at the cap (§2.3).
	 *
	 * @param array<string,mixed> $payload The forwarding payload
	 *
	 * @return int Rows enqueued
	 */
	public function enqueue(array $payload): int {
		$enqueued = 0;
		foreach ($this->sinkMapper->findEnabled() as $sink) {
			$filter = $sink->categoryFilterArray();
			if ($filter !== null && in_array((string)$payload['category'], $filter, true) === false) {
				continue;
			}

			// Drop-oldest backpressure: the queue never exceeds the cap.
			while ($this->queueMapper->countPending($sink->getId()) >= $sink->getQueueCap()) {
				$oldest = $this->queueMapper->oldestPending($sink->getId());
				if ($oldest === null) {
					break;
				}

				$this->queueMapper->delete($oldest);
				$sink->setDroppedCount($sink->getDroppedCount() + 1);
				$this->sinkMapper->update($sink);
			}

			$item = new SiemQueueItem();
			$item->setId(Uuid::uuid4()->toString());
			$item->setSinkId($sink->getId());
			$item->setPayload((string)json_encode($payload));
			$item->setEnqueuedAt(new DateTime());
			$item->setStatus('pending');
			$this->queueMapper->insert($item);
			++$enqueued;
		}//end foreach

		return $enqueued;
	}//end enqueue()

	/**
	 * Drain due rows for every enabled sink in bounded batches (§4.1).
	 *
	 * @return int Rows delivered
	 */
	public function deliverDue(): int {
		$delivered = 0;
		foreach ($this->sinkMapper->findEnabled() as $sink) {
			$hadDeadBefore = $this->queueMapper->countDead($sink->getId()) > 0;
			foreach ($this->queueMapper->findDue(sinkId: $sink->getId(), now: new DateTime()) as $item) {
				if ($this->deliverOne(sink: $sink, item: $item) === true) {
					++$delivered;
				}
			}

			// Raise the admin dead-letter notification once per
			// escalation: only when this drain produced the FIRST dead
			// rows (§5.1).
			if ($hadDeadBefore === false && $this->queueMapper->countDead($sink->getId()) > 0) {
				$this->notifyDeadLetter(sink: $sink);
			}
		}

		return $delivered;
	}//end deliverDue()

	/**
	 * Attempt one delivery; applies backoff / dead-letter transitions.
	 *
	 * @param SiemSink $sink The target sink
	 * @param SiemQueueItem $item The queued payload
	 *
	 * @return bool Whether the delivery succeeded
	 *
	 * @spec openspec/specs/siem-audit-export/spec.md#requirement-reliable-background-delivery
	 */
	public function deliverOne(SiemSink $sink, SiemQueueItem $item): bool {
		$sink->setLastAttemptAt(new DateTime());
		try {
			$this->transport->deliver(sink: $sink, payloadJson: $item->getPayload());

			$item->setStatus('delivered');
			$this->queueMapper->update($item);

			$sink->setLastDeliveryStatus('ok');
			$sink->setLastSuccessAt(new DateTime());
			$sink->setLastError(null);
			$sink->setConsecutiveFailures(0);
			$this->sinkMapper->update($sink);

			return true;
		} catch (Throwable $exception) {
			$item->setAttempts($item->getAttempts() + 1);
			$item->setLastError(substr($exception->getMessage(), 0, 500));
			if ($item->getAttempts() >= self::MAX_ATTEMPTS) {
				$item->setStatus('dead');
			}

			if ($item->getAttempts() < self::MAX_ATTEMPTS) {
				$backoff = self::BACKOFF_BASE_SECONDS * (2 ** ($item->getAttempts() - 1));
				$item->setNextAttemptAt((new DateTime())->add(new DateInterval('PT' . $backoff . 'S')));
			}

			$this->queueMapper->update($item);

			$sink->setLastDeliveryStatus('failing');
			if ($item->getStatus() === 'dead') {
				$sink->setLastDeliveryStatus('dead');
			}

			$sink->setLastError(substr($exception->getMessage(), 0, 500));
			$sink->setConsecutiveFailures($sink->getConsecutiveFailures() + 1);
			$this->sinkMapper->update($sink);

			return false;
		}//end try
	}//end deliverOne()

	/**
	 * Create a sink (§6.1).
	 *
	 * @param string $adminUid The creating admin
	 * @param array<string,mixed> $params {name, type, endpoint, tls?, hmacSecret?, categoryFilter?, queueCap?}
	 *
	 * @return SiemSink
	 *
	 * @throws \InvalidArgumentException On invalid parameters
	 *
	 * @spec openspec/specs/siem-audit-export/spec.md#requirement-admin-configured-syslog-and-webhook-sinks
	 */
	public function createSink(string $adminUid, array $params): SiemSink {
		return $this->sinkService->createSink(adminUid: $adminUid, params: $params);
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
	 * @throws \OCP\AppFramework\Db\DoesNotExistException When the sink is missing
	 *
	 * @spec openspec/specs/siem-audit-export/spec.md#requirement-admin-configured-syslog-and-webhook-sinks
	 */
	public function updateSink(string $adminUid, string $sinkId, array $params): SiemSink {
		return $this->sinkService->updateSink(adminUid: $adminUid, sinkId: $sinkId, params: $params);
	}//end updateSink()

	/**
	 * Delete a sink and its queue rows.
	 *
	 * @param string $adminUid The deleting admin
	 * @param string $sinkId The sink UUID
	 *
	 * @return void
	 *
	 * @throws \OCP\AppFramework\Db\DoesNotExistException When the sink is missing
	 *
	 * @spec openspec/specs/siem-audit-export/spec.md#requirement-admin-configured-syslog-and-webhook-sinks
	 */
	public function deleteSink(string $adminUid, string $sinkId): void {
		$this->sinkService->deleteSink(adminUid: $adminUid, sinkId: $sinkId);
	}//end deleteSink()

	/**
	 * Test-fire a sink with a synthetic payload (§6.1).
	 *
	 * @param string $adminUid The testing admin
	 * @param string $sinkId The sink UUID
	 *
	 * @return array{ok:bool, error:string|null}
	 *
	 * @throws \OCP\AppFramework\Db\DoesNotExistException When the sink is missing
	 *
	 * @spec openspec/specs/siem-audit-export/spec.md#requirement-backpressure-and-observability
	 */
	public function testSink(string $adminUid, string $sinkId): array {
		return $this->sinkService->testSink(adminUid: $adminUid, sinkId: $sinkId);
	}//end testSink()

	/**
	 * All sinks (secrets never included in serialization).
	 *
	 * @return SiemSink[]
	 *
	 * @spec openspec/specs/siem-audit-export/spec.md#requirement-admin-configured-syslog-and-webhook-sinks
	 */
	public function listSinks(): array {
		return $this->sinkService->listSinks();
	}//end listSinks()

	/**
	 * Notify every admin once when a sink first accrues dead letters
	 * (§5.1).
	 *
	 * @param SiemSink $sink The failing sink
	 *
	 * @return void
	 */
	private function notifyDeadLetter(SiemSink $sink): void {
		if ($this->notificationService === null) {
			return;
		}

		$adminGroup = $this->groupManager->get('admin');
		if ($adminGroup === null) {
			return;
		}

		foreach ($adminGroup->getUsers() as $admin) {
			try {
				$this->notificationService->notify(
					subject: 'siem_dead_letter',
					recipientId: $admin->getUID(),
					params: ['sink_name' => $sink->getName()],
					objectType: 'siem_sink',
					objectId: $sink->getId(),
				);
			} catch (Throwable $exception) {
				$this->logger->warning(
					'Doriath: SIEM dead-letter notification failed: ' . $exception->getMessage(),
					['app' => 'doriath']
				);
			}
		}
	}//end notifyDeadLetter()
}//end class
