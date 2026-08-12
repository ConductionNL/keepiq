<?php

/**
 * Doriath Audit Event
 *
 * The single typed event every audited secret operation dispatches via
 * OCP\EventDispatcher (add-secret-audit-trail §2.1, design D2). One concrete
 * event class carries the audited fact — actor, event type, object reference,
 * non-sensitive object name, and the (pre-validation) metadata payload — and a
 * single registered AuditListener maps it to an append-only row. Services
 * never touch the audit table directly: they dispatch this event and continue,
 * so an audit-write failure can never roll back the audited operation.
 *
 * Named constructors (forUser / forApplication / forSystem / forLinkVisitor)
 * keep call sites at dispatch points terse and make the actor type explicit.
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

use OCP\EventDispatcher\Event;

/**
 * A single server-observable secret operation to be recorded in the audit log.
 */
class AuditEvent extends Event {
	public const ACTOR_USER = 'user';
	public const ACTOR_APPLICATION = 'application';
	public const ACTOR_SYSTEM = 'system';
	public const ACTOR_LINK_VISITOR = 'link_visitor';

	/**
	 * Constructor for AuditEvent.
	 *
	 * @param string $actorType The actor type (one of the ACTOR_* constants)
	 * @param string|null $actorId The actor's user/application id (null for system/link_visitor)
	 * @param string $eventType The dot-namespaced event type (AuditEventTypes constant)
	 * @param string $objectType The object type (secret|folder|share|...)
	 * @param string|null $objectId The object reference id
	 * @param string|null $objectName The denormalized non-sensitive object name
	 * @param array<string,mixed> $metadata The pre-validation metadata payload
	 *
	 * @return void
	 */
	public function __construct(
		private string $actorType,
		private ?string $actorId,
		private string $eventType,
		private string $objectType,
		private ?string $objectId = null,
		private ?string $objectName = null,
		private array $metadata = [],
	) {
		parent::__construct();
	}//end __construct()

	/**
	 * Build an event actored by a Nextcloud user.
	 *
	 * @param string $actorId The user id
	 * @param string $eventType The event type
	 * @param string $objectType The object type
	 * @param string|null $objectId The object id
	 * @param string|null $objectName The object name
	 * @param array<string,mixed> $metadata The metadata
	 *
	 * @return self
	 */
	public static function forUser(
		string $actorId,
		string $eventType,
		string $objectType,
		?string $objectId = null,
		?string $objectName = null,
		array $metadata = [],
	): self {
		return new self(self::ACTOR_USER, $actorId, $eventType, $objectType, $objectId, $objectName, $metadata);
	}//end forUser()

	/**
	 * Build an event actored by an application.
	 *
	 * @param string $actorId The application id
	 * @param string $eventType The event type
	 * @param string $objectType The object type
	 * @param string|null $objectId The object id
	 * @param string|null $objectName The object name
	 * @param array<string,mixed> $metadata The metadata
	 *
	 * @return self
	 */
	public static function forApplication(
		string $actorId,
		string $eventType,
		string $objectType,
		?string $objectId = null,
		?string $objectName = null,
		array $metadata = [],
	): self {
		return new self(self::ACTOR_APPLICATION, $actorId, $eventType, $objectType, $objectId, $objectName, $metadata);
	}//end forApplication()

	/**
	 * Build an event with no human/application actor (background/system).
	 *
	 * @param string $eventType The event type
	 * @param string $objectType The object type
	 * @param string|null $objectId The object id
	 * @param string|null $objectName The object name
	 * @param array<string,mixed> $metadata The metadata
	 *
	 * @return self
	 */
	public static function forSystem(
		string $eventType,
		string $objectType,
		?string $objectId = null,
		?string $objectName = null,
		array $metadata = [],
	): self {
		return new self(self::ACTOR_SYSTEM, null, $eventType, $objectType, $objectId, $objectName, $metadata);
	}//end forSystem()

	/**
	 * Build an event for an anonymous link-share visitor (no actor id).
	 *
	 * @param string $eventType The event type
	 * @param string $objectType The object type
	 * @param string|null $objectId The object id
	 * @param string|null $objectName The object name
	 * @param array<string,mixed> $metadata The metadata
	 *
	 * @return self
	 */
	public static function forLinkVisitor(
		string $eventType,
		string $objectType,
		?string $objectId = null,
		?string $objectName = null,
		array $metadata = [],
	): self {
		return new self(self::ACTOR_LINK_VISITOR, null, $eventType, $objectType, $objectId, $objectName, $metadata);
	}//end forLinkVisitor()

	/**
	 * Get the actor type.
	 *
	 * @return string
	 */
	public function getActorType(): string {
		return $this->actorType;
	}//end getActorType()

	/**
	 * Get the actor id.
	 *
	 * @return string|null
	 */
	public function getActorId(): ?string {
		return $this->actorId;
	}//end getActorId()

	/**
	 * Get the event type.
	 *
	 * @return string
	 */
	public function getEventType(): string {
		return $this->eventType;
	}//end getEventType()

	/**
	 * Get the object type.
	 *
	 * @return string
	 */
	public function getObjectType(): string {
		return $this->objectType;
	}//end getObjectType()

	/**
	 * Get the object id.
	 *
	 * @return string|null
	 */
	public function getObjectId(): ?string {
		return $this->objectId;
	}//end getObjectId()

	/**
	 * Get the object name.
	 *
	 * @return string|null
	 */
	public function getObjectName(): ?string {
		return $this->objectName;
	}//end getObjectName()

	/**
	 * Get the pre-validation metadata payload.
	 *
	 * @return array<string,mixed>
	 */
	public function getMetadata(): array {
		return $this->metadata;
	}//end getMetadata()
}//end class
