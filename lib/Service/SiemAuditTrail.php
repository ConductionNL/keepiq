<?php

/**
 * Doriath SIEM Audit Trail
 *
 * The one place that knows which audit event each SIEM sink
 * administration action emits. Extracted from SiemService so sink CRUD no
 * longer carries the audit vocabulary (AuditEventFactory, AuditEventTypes
 * and the dispatcher) alongside its own collaborators.
 *
 * Every event carries identifiers only — never endpoint credentials and
 * never the HMAC secret.
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

use OCA\Doriath\Event\Audit\AuditEventFactory;
use OCA\Doriath\Event\Audit\AuditEventTypes;
use OCP\EventDispatcher\IEventDispatcher;

/**
 * Records the audit trail of SIEM sink administration.
 */
class SiemAuditTrail
{
    /**
     * Constructor for SiemAuditTrail.
     *
     * @param IEventDispatcher|null $eventDispatcher The audit dispatcher
     * @param AuditEventFactory     $auditEvents     The audit-event factory
     *
     * @return void
     *
     * @spec exclude Constructor wiring only; the recorded actions carry the spec anchors.
     */
    public function __construct(
        private ?IEventDispatcher $eventDispatcher=null,
        private AuditEventFactory $auditEvents=new AuditEventFactory(),
    ) {
    }//end __construct()

    /**
     * Record the creation of a sink.
     *
     * @param string $actorId The admin actor
     * @param string $sinkId  The sink id
     * @param string $type    The sink transport type
     *
     * @return void
     *
     * @spec openspec/specs/siem-audit-export/spec.md#requirement-admin-configured-syslog-and-webhook-sinks
     */
    public function recordSinkCreated(string $actorId, string $sinkId, string $type): void
    {
        $this->dispatch(
            actorId: $actorId,
            eventType: AuditEventTypes::SIEM_SINK_CREATED,
            sinkId: $sinkId,
            extra: ['type' => $type],
        );
    }//end recordSinkCreated()

    /**
     * Record an update of a sink.
     *
     * @param string $actorId The admin actor
     * @param string $sinkId  The sink id
     *
     * @return void
     *
     * @spec openspec/specs/siem-audit-export/spec.md#requirement-admin-configured-syslog-and-webhook-sinks
     */
    public function recordSinkUpdated(string $actorId, string $sinkId): void
    {
        $this->dispatch(actorId: $actorId, eventType: AuditEventTypes::SIEM_SINK_UPDATED, sinkId: $sinkId);
    }//end recordSinkUpdated()

    /**
     * Record the deletion of a sink.
     *
     * @param string $actorId The admin actor
     * @param string $sinkId  The sink id
     *
     * @return void
     *
     * @spec openspec/specs/siem-audit-export/spec.md#requirement-admin-configured-syslog-and-webhook-sinks
     */
    public function recordSinkDeleted(string $actorId, string $sinkId): void
    {
        $this->dispatch(actorId: $actorId, eventType: AuditEventTypes::SIEM_SINK_DELETED, sinkId: $sinkId);
    }//end recordSinkDeleted()

    /**
     * Record an admin test-fire of a sink and its outcome.
     *
     * @param string $actorId The admin actor
     * @param string $sinkId  The sink id
     * @param string $outcome The delivery outcome (ok|failed)
     *
     * @return void
     *
     * @spec openspec/specs/siem-audit-export/spec.md#requirement-backpressure-and-observability
     */
    public function recordSinkTested(string $actorId, string $sinkId, string $outcome): void
    {
        $this->dispatch(
            actorId: $actorId,
            eventType: AuditEventTypes::SIEM_SINK_TESTED,
            sinkId: $sinkId,
            extra: ['outcome' => $outcome],
        );
    }//end recordSinkTested()

    /**
     * Dispatch a SIEM audit event (identifiers only), fail-soft.
     *
     * @param string              $actorId   The admin actor
     * @param string              $eventType The event type
     * @param string              $sinkId    The sink id
     * @param array<string,mixed> $extra     Additional whitelisted metadata
     *
     * @return void
     */
    private function dispatch(string $actorId, string $eventType, string $sinkId, array $extra=[]): void
    {
        $this->eventDispatcher?->dispatchTyped(
            $this->auditEvents->forUser(
                actorId: $actorId,
                eventType: $eventType,
                objectType: 'siem_sink',
                objectId: $sinkId,
                objectName: '',
                metadata: array_merge(['sinkId' => $sinkId], $extra),
            )
        );
    }//end dispatch()
}//end class
