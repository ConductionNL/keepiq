<?php

/**
 * Doriath SIEM Service
 *
 * Sink CRUD, forwarding-queue backpressure, and delivery (siem-audit-
 * export §2/§3). Forwarded payloads are rebuilt through the audit
 * whitelist so they are strict subsets of sanitized audit entries; the
 * webhook HMAC secret is ICrypto-encrypted at rest, decrypted in memory
 * only, write-only over the API.
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
use InvalidArgumentException;
use OCA\Doriath\Db\SiemQueueItem;
use OCA\Doriath\Db\SiemQueueItemMapper;
use OCA\Doriath\Db\SiemSink;
use OCA\Doriath\Db\SiemSinkMapper;
use OCA\Doriath\Event\Audit\AuditEvent;
use OCA\Doriath\Event\Audit\AuditEventTypes;
use OCP\AppFramework\Db\DoesNotExistException;
use OCP\Http\Client\IClientService;
use OCP\IGroupManager;
use OCP\Security\ICrypto;
use Psr\Log\LoggerInterface;
use Ramsey\Uuid\Uuid;
use RuntimeException;
use Throwable;

/**
 * Business logic for SIEM audit export.
 *
 * @SuppressWarnings(PHPMD.CouplingBetweenObjects) Sink CRUD + queue +
 *   two transports deliberately live in one seam.
 * @SuppressWarnings(PHPMD.TooManyPublicMethods)   One method per API op.
 */
class SiemService
{
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
     * Per-request delivery timeout in seconds.
     *
     * @var int
     */
    private const DELIVERY_TIMEOUT = 10;

    /**
     * Constructor for SiemService.
     *
     * @param SiemSinkMapper           $sinkMapper          The sink mapper
     * @param SiemQueueItemMapper      $queueMapper         The queue mapper
     * @param ICrypto                  $crypto              NC crypto (HMAC secret at rest)
     * @param IClientService           $clientService       The HTTP client factory (webhooks)
     * @param IGroupManager            $groupManager        The group manager (admin notifications)
     * @param NotificationService|null $notificationService The notification dispatcher
     * @param LoggerInterface          $logger              The logger
     * @param AuditTrail|null          $auditTrail          The audit trail
     *
     * @return void
     */
    public function __construct(
        private SiemSinkMapper $sinkMapper,
        private SiemQueueItemMapper $queueMapper,
        private ICrypto $crypto,
        private IClientService $clientService,
        private IGroupManager $groupManager,
        private ?NotificationService $notificationService,
        private LoggerInterface $logger,
        private ?AuditTrail $auditTrail=null,
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
    public function buildPayload(AuditEvent $event): ?array
    {
        $eventType = $event->getEventType();
        $whitelist = AuditEventTypes::METADATA_WHITELIST[$eventType] ?? null;
        if ($whitelist === null) {
            return null;
        }

        $metadata = [];
        foreach ($event->getMetadata() as $metaKey => $metaValue) {
            if (in_array($metaKey, $whitelist, true) === true
                && in_array($metaKey, AuditEventTypes::FORBIDDEN_KEYS, true) === false
            ) {
                $metadata[$metaKey] = $metaValue;
            }
        }

        return [
            'eventType'  => $eventType,
            'category'   => explode('.', $eventType)[0],
            'actorType'  => $event->getActorType(),
            'actorId'    => $event->getActorId(),
            'objectType' => $event->getObjectType(),
            'objectId'   => $event->getObjectId(),
            'occurredAt' => (new DateTime())->format('c'),
            'metadata'   => $metadata,
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
    public function enqueue(array $payload): int
    {
        $enqueued = 0;
        foreach ($this->sinkMapper->findEnabled() as $sink) {
            $filter = $sink->categoryFilterArray();
            if ($filter !== null && in_array((string) $payload['category'], $filter, true) === false) {
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
            $item->setPayload((string) json_encode($payload));
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
    public function deliverDue(): int
    {
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
     * @param SiemSink      $sink The target sink
     * @param SiemQueueItem $item The queued payload
     *
     * @return bool Whether the delivery succeeded
     */
    public function deliverOne(SiemSink $sink, SiemQueueItem $item): bool
    {
        $sink->setLastAttemptAt(new DateTime());
        try {
            if ($sink->getType() === 'syslog') {
                $this->deliverSyslog(sink: $sink, payloadJson: $item->getPayload());
            } else {
                $this->deliverWebhook(sink: $sink, payloadJson: $item->getPayload());
            }

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
            } else {
                $backoff = self::BACKOFF_BASE_SECONDS * (2 ** ($item->getAttempts() - 1));
                $item->setNextAttemptAt((new DateTime())->add(new DateInterval('PT'.$backoff.'S')));
            }

            $this->queueMapper->update($item);

            if ($item->getStatus() === 'dead') {
                $sink->setLastDeliveryStatus('dead');
            } else {
                $sink->setLastDeliveryStatus('failing');
            }

            $sink->setLastError(substr($exception->getMessage(), 0, 500));
            $sink->setConsecutiveFailures($sink->getConsecutiveFailures() + 1);
            $this->sinkMapper->update($sink);

            return false;
        }//end try
    }//end deliverOne()

    /**
     * RFC 5424 syslog delivery over TCP (TLS when configured, §3.1).
     *
     * @param SiemSink $sink        The sink (endpoint host:port)
     * @param string   $payloadJson The JSON payload
     *
     * @return void
     *
     * @throws \RuntimeException On transport failure
     */
    private function deliverSyslog(SiemSink $sink, string $payloadJson): void
    {
        $endpoint = $sink->getEndpoint();
        $scheme   = 'tcp://';
        if ($sink->getTls() === true) {
            $scheme = 'tls://';
        }

        $errno  = 0;
        $errstr = '';
        $socket = @stream_socket_client(
            $scheme.$endpoint,
            $errno,
            $errstr,
            self::DELIVERY_TIMEOUT
        );
        if ($socket === false) {
            throw new RuntimeException('syslog connect failed: '.$errstr.' ('.$errno.')');
        }

        try {
            // RFC 5424: <PRI>VERSION TIMESTAMP HOSTNAME APP-NAME PROCID MSGID SD MSG
            // PRI 134 = facility 16 (local0), severity 6 (informational).
            $message = '<134>1 '.(new DateTime())->format('c').' nextcloud doriath - - - '.$payloadJson;
            // RFC 6587 octet-counted framing for TCP transport.
            $frame   = strlen($message).' '.$message;
            $written = fwrite($socket, $frame);
            if ($written === false || $written < strlen($frame)) {
                throw new RuntimeException('syslog write failed');
            }
        } finally {
            fclose($socket);
        }
    }//end deliverSyslog()

    /**
     * HTTPS webhook delivery with an HMAC-SHA256 signature header
     * (§3.2). The secret is decrypted in memory only.
     *
     * @param SiemSink $sink        The sink (HTTPS endpoint)
     * @param string   $payloadJson The JSON payload
     *
     * @return void
     *
     * @throws \RuntimeException On transport failure / non-2xx
     */
    private function deliverWebhook(SiemSink $sink, string $payloadJson): void
    {
        $headers = ['Content-Type' => 'application/json'];
        $enc     = $sink->getHmacSecretEnc();
        if ($enc !== null && $enc !== '') {
            $secret = $this->crypto->decrypt($enc);
            $headers['X-Doriath-Signature'] = 'sha256='.hash_hmac('sha256', $payloadJson, $secret);
        }

        $client   = $this->clientService->newClient();
        $response = $client->post(
            $sink->getEndpoint(),
            [
                'body'    => $payloadJson,
                'headers' => $headers,
                'timeout' => self::DELIVERY_TIMEOUT,
            ]
        );
        $status   = $response->getStatusCode();
        if ($status < 200 || $status > 299) {
            throw new RuntimeException('webhook responded '.$status);
        }
    }//end deliverWebhook()

    /**
     * Create a sink (§6.1).
     *
     * @param string              $adminUid The creating admin
     * @param array<string,mixed> $params   {name, type, endpoint, tls?, hmacSecret?, categoryFilter?, queueCap?}
     *
     * @return SiemSink
     *
     * @throws InvalidArgumentException On invalid parameters
     */
    public function createSink(string $adminUid, array $params): SiemSink
    {
        $type = (string) ($params['type'] ?? '');
        if (in_array($type, ['syslog', 'webhook'], true) === false) {
            throw new InvalidArgumentException('type must be syslog or webhook');
        }

        $endpoint = (string) ($params['endpoint'] ?? '');
        if ($endpoint === '') {
            throw new InvalidArgumentException('endpoint is required');
        }

        if ($type === 'webhook' && str_starts_with($endpoint, 'https://') === false) {
            throw new InvalidArgumentException('webhook endpoints must be https://');
        }

        $sink = new SiemSink();
        $sink->setId(Uuid::uuid4()->toString());
        $sink->setName((string) ($params['name'] ?? $type));
        $sink->setType($type);
        $sink->setEnabled((bool) ($params['enabled'] ?? true));
        $sink->setEndpoint($endpoint);
        $sink->setTls((bool) ($params['tls'] ?? true));
        $sink->setQueueCap(max(10, (int) ($params['queueCap'] ?? 1000)));
        $sink->setCreatedBy($adminUid);
        $sink->setCreatedAt(new DateTime());
        $this->applySecretAndFilter(sink: $sink, params: $params);
        $sink = $this->sinkMapper->insert($sink);

        $this->dispatchAudit(
            actorId: $adminUid,
            eventType: AuditEventTypes::SIEM_SINK_CREATED,
            sinkId: $sink->getId(),
            extra: ['type' => $type],
        );

        return $sink;
    }//end createSink()

    /**
     * Update a sink; a blank HMAC secret preserves the stored one (§3.2).
     *
     * @param string              $adminUid The updating admin
     * @param string              $sinkId   The sink UUID
     * @param array<string,mixed> $params   Updatable fields
     *
     * @return SiemSink
     *
     * @throws DoesNotExistException When the sink is missing
     */
    public function updateSink(string $adminUid, string $sinkId, array $params): SiemSink
    {
        $sink = $this->sinkMapper->findById($sinkId);
        if (isset($params['name']) === true) {
            $sink->setName((string) $params['name']);
        }

        if (isset($params['enabled']) === true) {
            $sink->setEnabled((bool) $params['enabled']);
        }

        if (isset($params['endpoint']) === true && (string) $params['endpoint'] !== '') {
            $sink->setEndpoint((string) $params['endpoint']);
        }

        if (isset($params['tls']) === true) {
            $sink->setTls((bool) $params['tls']);
        }

        if (isset($params['queueCap']) === true) {
            $sink->setQueueCap(max(10, (int) $params['queueCap']));
        }

        $this->applySecretAndFilter(sink: $sink, params: $params);
        $sink->setUpdatedAt(new DateTime());
        $sink = $this->sinkMapper->update($sink);

        $this->dispatchAudit(actorId: $adminUid, eventType: AuditEventTypes::SIEM_SINK_UPDATED, sinkId: $sinkId);

        return $sink;
    }//end updateSink()

    /**
     * Delete a sink and its queue rows.
     *
     * @param string $adminUid The deleting admin
     * @param string $sinkId   The sink UUID
     *
     * @return void
     *
     * @throws DoesNotExistException When the sink is missing
     */
    public function deleteSink(string $adminUid, string $sinkId): void
    {
        $sink = $this->sinkMapper->findById($sinkId);
        $this->queueMapper->deleteBySink($sinkId);
        $this->sinkMapper->delete($sink);

        $this->dispatchAudit(actorId: $adminUid, eventType: AuditEventTypes::SIEM_SINK_DELETED, sinkId: $sinkId);
    }//end deleteSink()

    /**
     * Test-fire a sink with a synthetic payload (§6.1).
     *
     * @param string $adminUid The testing admin
     * @param string $sinkId   The sink UUID
     *
     * @return array{ok:bool, error:string|null}
     *
     * @throws DoesNotExistException When the sink is missing
     */
    public function testSink(string $adminUid, string $sinkId): array
    {
        $sink    = $this->sinkMapper->findById($sinkId);
        $payload = (string) json_encode(
            [
                'eventType'  => 'siem.sink_tested',
                'category'   => 'siem',
                'actorType'  => 'user',
                'actorId'    => $adminUid,
                'objectType' => 'siem_sink',
                'objectId'   => $sinkId,
                'occurredAt' => (new DateTime())->format('c'),
                'metadata'   => ['test' => true],
            ]
        );

        $outcome = 'ok';
        $error   = null;
        try {
            if ($sink->getType() === 'syslog') {
                $this->deliverSyslog(sink: $sink, payloadJson: $payload);
            } else {
                $this->deliverWebhook(sink: $sink, payloadJson: $payload);
            }
        } catch (Throwable $exception) {
            $outcome = 'failed';
            $error   = $exception->getMessage();
        }

        $this->dispatchAudit(
            actorId: $adminUid,
            eventType: AuditEventTypes::SIEM_SINK_TESTED,
            sinkId: $sinkId,
            extra: ['outcome' => $outcome],
        );

        return [
            'ok'    => ($outcome === 'ok'),
            'error' => $error,
        ];
    }//end testSink()

    /**
     * All sinks (secrets never included in serialization).
     *
     * @return SiemSink[]
     */
    public function listSinks(): array
    {
        return $this->sinkMapper->findAll();
    }//end listSinks()

    /**
     * Apply the write-only HMAC secret (blank preserves) and the
     * category filter from request params.
     *
     * @param SiemSink            $sink   The sink to mutate
     * @param array<string,mixed> $params The request params
     *
     * @return void
     */
    private function applySecretAndFilter(SiemSink $sink, array $params): void
    {
        $secret = $params['hmacSecret'] ?? null;
        if (is_string($secret) === true && $secret !== '') {
            $sink->setHmacSecretEnc($this->crypto->encrypt($secret));
        }

        if (array_key_exists('categoryFilter', $params) === true) {
            $filter = $params['categoryFilter'];
            if (is_array($filter) === true && $filter !== []) {
                $sink->setCategoryFilter((string) json_encode(array_map('strval', $filter)));
            } else {
                $sink->setCategoryFilter(null);
            }
        }
    }//end applySecretAndFilter()

    /**
     * Notify every admin once when a sink first accrues dead letters
     * (§5.1).
     *
     * @param SiemSink $sink The failing sink
     *
     * @return void
     */
    private function notifyDeadLetter(SiemSink $sink): void
    {
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
                    'Doriath: SIEM dead-letter notification failed: '.$exception->getMessage(),
                    ['app' => 'doriath']
                );
            }
        }
    }//end notifyDeadLetter()

    /**
     * Dispatch a SIEM audit event (identifiers only).
     *
     * @param string              $actorId   The admin actor
     * @param string              $eventType The event type
     * @param string              $sinkId    The sink id
     * @param array<string,mixed> $extra     Additional whitelisted metadata
     *
     * @return void
     */
    private function dispatchAudit(string $actorId, string $eventType, string $sinkId, array $extra=[]): void
    {
        $this->auditTrail?->forUser(
            actorId: $actorId,
            eventType: $eventType,
            objectType: 'siem_sink',
            objectId: $sinkId,
            objectName: '',
            metadata: array_merge(['sinkId' => $sinkId], $extra),
        );
    }//end dispatchAudit()
}//end class
