<?php

/**
 * Doriath Audit Trail
 *
 * The injectable seam every audited operation dispatches through
 * (add-secret-audit-trail §2.1, design D2). It owns both halves of the
 * dispatch — building the typed AuditEvent for the right actor kind and
 * handing it to the event dispatcher — so callers state the audited fact
 * and nothing else.
 *
 * This collaborator replaces the AuditEvent::forUser() / forApplication()
 * / forSystem() / forLinkVisitor() static named constructors that used to
 * be called directly from ~20 services. Those static calls made the audit
 * seam unsubstitutable: a test could not observe or stub the construction
 * of the event, only the dispatcher underneath it. Injecting AuditTrail
 * makes the whole audit path a single mockable dependency and lets each
 * service drop its own private dispatchAudit() duplicate.
 *
 * The dispatcher stays nullable so pre-audit call sites (and the many unit
 * tests that construct services without one) keep working: with no
 * dispatcher the trail is a silent no-op, exactly as the per-service
 * `$this->eventDispatcher?->dispatchTyped()` helpers were.
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

use OCA\Doriath\Event\Audit\AuditEvent;
use OCP\EventDispatcher\IEventDispatcher;

/**
 * Injectable dispatch seam for the typed audit event.
 */
class AuditTrail
{
    /**
     * Constructor for AuditTrail.
     *
     * @param IEventDispatcher|null $eventDispatcher The event dispatcher (null = no-op trail)
     *
     * @return void
     */
    public function __construct(
        private ?IEventDispatcher $eventDispatcher=null,
    ) {
    }//end __construct()

    /**
     * Record an operation actored by a Nextcloud user.
     *
     * @param string              $actorId    The user id
     * @param string              $eventType  The event type
     * @param string              $objectType The object type
     * @param string|null         $objectId   The object id
     * @param string|null         $objectName The object name
     * @param array<string,mixed> $metadata   The metadata
     *
     * @return void
     */
    public function forUser(
        string $actorId,
        string $eventType,
        string $objectType,
        ?string $objectId=null,
        ?string $objectName=null,
        array $metadata=[],
    ): void {
        $this->dispatch(
            event: new AuditEvent(
                actorType: AuditEvent::ACTOR_USER,
                actorId: $actorId,
                eventType: $eventType,
                objectType: $objectType,
                objectId: $objectId,
                objectName: $objectName,
                metadata: $metadata,
            )
        );
    }//end forUser()

    /**
     * Record an operation actored by an application.
     *
     * @param string              $actorId    The application id
     * @param string              $eventType  The event type
     * @param string              $objectType The object type
     * @param string|null         $objectId   The object id
     * @param string|null         $objectName The object name
     * @param array<string,mixed> $metadata   The metadata
     *
     * @return void
     */
    public function forApplication(
        string $actorId,
        string $eventType,
        string $objectType,
        ?string $objectId=null,
        ?string $objectName=null,
        array $metadata=[],
    ): void {
        $this->dispatch(
            event: new AuditEvent(
                actorType: AuditEvent::ACTOR_APPLICATION,
                actorId: $actorId,
                eventType: $eventType,
                objectType: $objectType,
                objectId: $objectId,
                objectName: $objectName,
                metadata: $metadata,
            )
        );
    }//end forApplication()

    /**
     * Record an operation with no human/application actor (background/system).
     *
     * @param string              $eventType  The event type
     * @param string              $objectType The object type
     * @param string|null         $objectId   The object id
     * @param string|null         $objectName The object name
     * @param array<string,mixed> $metadata   The metadata
     *
     * @return void
     */
    public function forSystem(
        string $eventType,
        string $objectType,
        ?string $objectId=null,
        ?string $objectName=null,
        array $metadata=[],
    ): void {
        $this->dispatch(
            event: new AuditEvent(
                actorType: AuditEvent::ACTOR_SYSTEM,
                actorId: null,
                eventType: $eventType,
                objectType: $objectType,
                objectId: $objectId,
                objectName: $objectName,
                metadata: $metadata,
            )
        );
    }//end forSystem()

    /**
     * Record an operation by an anonymous link-share visitor.
     *
     * @param string              $eventType  The event type
     * @param string              $objectType The object type
     * @param string|null         $objectId   The object id
     * @param string|null         $objectName The object name
     * @param array<string,mixed> $metadata   The metadata
     *
     * @return void
     */
    public function forLinkVisitor(
        string $eventType,
        string $objectType,
        ?string $objectId=null,
        ?string $objectName=null,
        array $metadata=[],
    ): void {
        $this->dispatch(
            event: new AuditEvent(
                actorType: AuditEvent::ACTOR_LINK_VISITOR,
                actorId: null,
                eventType: $eventType,
                objectType: $objectType,
                objectId: $objectId,
                objectName: $objectName,
                metadata: $metadata,
            )
        );
    }//end forLinkVisitor()

    /**
     * Hand a built event to the dispatcher, if one is wired.
     *
     * @param AuditEvent $event The audit event
     *
     * @return void
     */
    private function dispatch(AuditEvent $event): void
    {
        $this->eventDispatcher?->dispatchTyped($event);
    }//end dispatch()
}//end class
