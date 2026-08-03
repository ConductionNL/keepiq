<?php

/**
 * Doriath Audit Event Factory
 *
 * The injectable counterpart of AuditEvent's named constructors. Services
 * take this factory through constructor DI and call instance methods on it,
 * so a dispatch site can be substituted in a test and so the audit surface is
 * a collaborator a class declares rather than a hard-wired static call
 * (rulesets/cleancode.xml/StaticAccess).
 *
 * The factory is stateless and holds no dependencies, so every consumer
 * defaults it to a fresh instance: wiring it explicitly is possible (and what
 * the Nextcloud container does) but never required.
 *
 * AuditEvent keeps its static named constructors: they are the value object's
 * own public API and are exercised directly by tests. This class delegates to
 * them rather than duplicating the ACTOR_* mapping.
 *
 * @category Event
 * @package  OCA\Doriath\Event\Audit
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

namespace OCA\Doriath\Event\Audit;

/**
 * Builds AuditEvent instances through instance methods.
 *
 * @SuppressWarnings(PHPMD.StaticAccess) The four methods below are the ONE
 * place in the app that reaches AuditEvent's static named constructors; every
 * other call site goes through this factory. Collapsing the constructors into
 * this class instead would duplicate the ACTOR_* mapping and break the tests
 * that construct AuditEvent directly.
 */
class AuditEventFactory
{
    /**
     * Build an event actored by a Nextcloud user.
     *
     * @param string              $actorId    The user id
     * @param string              $eventType  The event type
     * @param string              $objectType The object type
     * @param string|null         $objectId   The object id
     * @param string|null         $objectName The object name
     * @param array<string,mixed> $metadata   The metadata
     *
     * @return AuditEvent
     */
    public function forUser(
        string $actorId,
        string $eventType,
        string $objectType,
        ?string $objectId=null,
        ?string $objectName=null,
        array $metadata=[],
    ): AuditEvent {
        return AuditEvent::forUser($actorId, $eventType, $objectType, $objectId, $objectName, $metadata);
    }//end forUser()

    /**
     * Build an event actored by an application.
     *
     * @param string              $actorId    The application id
     * @param string              $eventType  The event type
     * @param string              $objectType The object type
     * @param string|null         $objectId   The object id
     * @param string|null         $objectName The object name
     * @param array<string,mixed> $metadata   The metadata
     *
     * @return AuditEvent
     */
    public function forApplication(
        string $actorId,
        string $eventType,
        string $objectType,
        ?string $objectId=null,
        ?string $objectName=null,
        array $metadata=[],
    ): AuditEvent {
        return AuditEvent::forApplication($actorId, $eventType, $objectType, $objectId, $objectName, $metadata);
    }//end forApplication()

    /**
     * Build an event with no human/application actor (background/system).
     *
     * @param string              $eventType  The event type
     * @param string              $objectType The object type
     * @param string|null         $objectId   The object id
     * @param string|null         $objectName The object name
     * @param array<string,mixed> $metadata   The metadata
     *
     * @return AuditEvent
     */
    public function forSystem(
        string $eventType,
        string $objectType,
        ?string $objectId=null,
        ?string $objectName=null,
        array $metadata=[],
    ): AuditEvent {
        return AuditEvent::forSystem($eventType, $objectType, $objectId, $objectName, $metadata);
    }//end forSystem()

    /**
     * Build an event for an anonymous link-share visitor (no actor id).
     *
     * @param string              $eventType  The event type
     * @param string              $objectType The object type
     * @param string|null         $objectId   The object id
     * @param string|null         $objectName The object name
     * @param array<string,mixed> $metadata   The metadata
     *
     * @return AuditEvent
     */
    public function forLinkVisitor(
        string $eventType,
        string $objectType,
        ?string $objectId=null,
        ?string $objectName=null,
        array $metadata=[],
    ): AuditEvent {
        return AuditEvent::forLinkVisitor($eventType, $objectType, $objectId, $objectName, $metadata);
    }//end forLinkVisitor()
}//end class
