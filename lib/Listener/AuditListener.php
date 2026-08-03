<?php

/**
 * Doriath Audit Listener
 *
 * The single listener that turns audit events into append-only rows
 * (add-secret-audit-trail §2.4). It handles the internal AuditEvent dispatched
 * by every audited operation and — when the secret-export capability is
 * present — the three secret-export-gdpr events (SecretExportedEvent,
 * GdprExportPerformedEvent, AccountDataDeletedEvent), mapping their existing
 * payloads into audit rows as-is and, on account deletion, anonymizing the
 * departed user out of the trail.
 *
 * Every record() call is wrapped in try/catch with error-level logging so an
 * audit-write failure NEVER propagates into the audited business operation
 * (the "Audit failure does not block the operation" requirement / design D2).
 *
 * @category Listener
 * @package  OCA\Doriath\Listener
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

namespace OCA\Doriath\Listener;

use OCA\Doriath\Event\Audit\AuditEvent;
use OCA\Doriath\Event\Audit\AuditEventTypes;
use OCA\Doriath\Service\AuditService;
use OCP\EventDispatcher\Event;
use OCP\EventDispatcher\IEventListener;
use Psr\Log\LoggerInterface;
use ReflectionClass;
use Throwable;

/**
 * Persists audit events to the append-only log, fail-soft.
 *
 * @template-implements IEventListener<Event>
 */
class AuditListener implements IEventListener
{
    /**
     * Constructor for AuditListener.
     *
     * @param AuditService    $auditService The audit service
     * @param LoggerInterface $logger       The logger
     *
     * @return void
     */
    public function __construct(
        private AuditService $auditService,
        private LoggerInterface $logger,
    ) {
    }//end __construct()

    /**
     * Handle an audit-relevant event.
     *
     * @param Event $event The dispatched event
     *
     * @return void
     *
     * @spec openspec/changes/add-secret-audit-trail/tasks.md#task-2.4
     */
    public function handle(Event $event): void
    {
        try {
            if ($event instanceof AuditEvent) {
                $this->auditService->record($event);
                return;
            }

            $this->handleExportGdprEvent(event: $event);
        } catch (Throwable $e) {
            // Fail-soft: never let an audit-write failure roll back or fail the
            // audited operation. Log at error level so dropped entries surface.
            $this->logger->error(
                'Doriath: audit entry could not be recorded: '.$e->getMessage(),
                ['exception' => $e]
            );
        }
    }//end handle()

    /**
     * Map a secret-export-gdpr event to an audit row, if that capability ships.
     *
     * The three event classes belong to the secret-export-gdpr change, which is
     * its scoped producer. They are matched by short class name so this listener
     * compiles and runs whether or not that change has landed yet (no hard
     * dependency on classes that may not exist). Payloads are read via the
     * conventional getter/accessor shape and mapped as-is.
     *
     * @param Event $event The dispatched event
     *
     * @return void
     */
    private function handleExportGdprEvent(Event $event): void
    {
        $shortName = (new ReflectionClass($event))->getShortName();

        switch ($shortName) {
            case 'SecretExportedEvent':
                $this->recordVaultEvent(event: $event, eventType: AuditEventTypes::VAULT_EXPORTED);
                break;
            case 'GdprExportPerformedEvent':
                $this->recordVaultEvent(event: $event, eventType: AuditEventTypes::VAULT_GDPR_EXPORTED);
                break;
            case 'AccountDataDeletedEvent':
                $this->recordVaultEvent(event: $event, eventType: AuditEventTypes::VAULT_ACCOUNT_DELETED);
                $userId = $this->readActorId(event: $event);
                if ($userId !== null && $userId !== '') {
                    $this->auditService->anonymizeUser($userId);
                }
                break;
            default:
                // Not an audit-relevant event family — ignore.
                break;
        }
    }//end handleExportGdprEvent()

    /**
     * Record a vault.* entry from an export/deletion event, mapping its payload.
     *
     * @param Event  $event     The export/deletion event
     * @param string $eventType The target audit event type
     *
     * @return void
     */
    private function recordVaultEvent(Event $event, string $eventType): void
    {
        $actorId  = $this->readActorId(event: $event);
        $metadata = $this->readPayload(event: $event);

        $auditEvent = AuditEvent::forUser(
            actorId: ($actorId ?? AuditEventTypes::VAULT_ACCOUNT_DELETED),
            eventType: $eventType,
            objectType: 'vault',
            objectId: $actorId,
            objectName: null,
            metadata: $metadata,
        );

        $this->auditService->record($auditEvent);
    }//end recordVaultEvent()

    /**
     * Best-effort read of the acting user id from an export/deletion event.
     *
     * @param Event $event The event
     *
     * @return string|null
     */
    private function readActorId(Event $event): ?string
    {
        foreach (['getUserId', 'getUid', 'getActorId'] as $method) {
            if (method_exists($event, $method) === true) {
                $value = $event->$method();
                if (is_string($value) === true) {
                    return $value;
                }
            }
        }

        return null;
    }//end readActorId()

    /**
     * Best-effort read of the event payload as an associative array.
     *
     * @param Event $event The event
     *
     * @return array<string,mixed>
     */
    private function readPayload(Event $event): array
    {
        foreach (['getMetadata', 'getPayload', 'jsonSerialize', 'toArray'] as $method) {
            if (method_exists($event, $method) === true) {
                $value = $event->$method();
                if (is_array($value) === true) {
                    return $value;
                }
            }
        }

        return [];
    }//end readPayload()
}//end class
