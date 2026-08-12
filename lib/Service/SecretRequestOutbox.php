<?php

/**
 * Doriath Secret Request Outbox
 *
 * Everything the secret-request lifecycle emits OUTWARD: the typed audit
 * trail of each transition and the request_fulfilled notification to the
 * original requester. Both are fail-soft by construction — a missing
 * dispatcher or notification bind silently noops, because a request must
 * never fail to be created or filled just because nobody could be told
 * about it.
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

use OCA\Doriath\Db\SecretRequest;
use OCA\Doriath\Event\Audit\AuditEventFactory;
use OCA\Doriath\Event\Audit\AuditEventTypes;
use OCP\EventDispatcher\IEventDispatcher;

/**
 * Outbound signalling for the SecretRequest lifecycle.
 */
class SecretRequestOutbox {
	/**
	 * The object type used in audit entries for secret requests.
	 *
	 * @var string
	 */
	private const OBJECT_TYPE = 'secret_request';

	/**
	 * Constructor for SecretRequestOutbox.
	 *
	 * @param NotificationService|null $notificationService Optional notification dispatcher
	 * @param IEventDispatcher|null $eventDispatcher The event dispatcher
	 * @param AuditEventFactory $auditEvents The audit-event factory
	 *
	 * @return void
	 *
	 * @spec exclude Constructor wiring only — no domain logic.
	 */
	public function __construct(
		private ?NotificationService $notificationService = null,
		private ?IEventDispatcher $eventDispatcher = null,
		private AuditEventFactory $auditEvents = new AuditEventFactory(),
	) {
	}//end __construct()

	/**
	 * Notify the original requester that their request was filled in, and
	 * record the fulfilment on the audit trail with the link visitor as
	 * actor.
	 *
	 * @param SecretRequest $request The fulfilled request
	 *
	 * @return void
	 *
	 * @spec openspec/changes/add-secret-audit-trail/tasks.md#task-3.5
	 */
	public function announceFulfilled(SecretRequest $request): void {
		// Notify the requester — silently noop when the dependency was
		// not wired (legacy call sites still using the 2-arg constructor).
		if ($this->notificationService !== null && $request->getCreatedBy() !== '') {
			$this->notificationService->notify(
				subject: 'request_fulfilled',
				recipientId: $request->getCreatedBy(),
				params: ['secret_id' => $request->getSecretId()],
				objectType: 'secret',
				objectId: $request->getSecretId(),
			);
		}

		$this->eventDispatcher?->dispatchTyped(
			$this->auditEvents->forLinkVisitor(
				eventType: AuditEventTypes::REQUEST_FULFILLED,
				objectType: self::OBJECT_TYPE,
				objectId: $request->getId(),
			)
		);
	}//end announceFulfilled()

	/**
	 * Record the creation of a plain (non re-request) secret request.
	 *
	 * @param string $userId The Nextcloud user who created it
	 * @param string $requestId The new request ID
	 *
	 * @return void
	 *
	 * @spec openspec/changes/add-secret-audit-trail/tasks.md#task-3.5
	 */
	public function recordCreated(string $userId, string $requestId): void {
		$this->eventDispatcher?->dispatchTyped(
			$this->auditEvents->forUser(
				actorId: $userId,
				eventType: AuditEventTypes::REQUEST_CREATED,
				objectType: self::OBJECT_TYPE,
				objectId: $requestId,
			)
		);
	}//end recordCreated()

	/**
	 * Record the creation of a re-request.
	 *
	 * @param string $userId The Nextcloud user who created it
	 * @param string $requestId The new request ID
	 *
	 * @return void
	 *
	 * @spec openspec/changes/implement-secret-requests/tasks.md#task-3.4
	 */
	public function recordReRequested(string $userId, string $requestId): void {
		$this->eventDispatcher?->dispatchTyped(
			$this->auditEvents->forUser(
				actorId: $userId,
				eventType: AuditEventTypes::REQUEST_RE_REQUESTED,
				objectType: self::OBJECT_TYPE,
				objectId: $requestId,
			)
		);
	}//end recordReRequested()

	/**
	 * Record that a request was declined (revoked) by its creator.
	 *
	 * @param string $userId The Nextcloud user who declined it
	 * @param string $requestId The request ID
	 *
	 * @return void
	 *
	 * @spec openspec/changes/add-secret-audit-trail/tasks.md#task-3.5
	 */
	public function recordRevoked(string $userId, string $requestId): void {
		$this->eventDispatcher?->dispatchTyped(
			$this->auditEvents->forUser(
				actorId: $userId,
				eventType: AuditEventTypes::REQUEST_REVOKED,
				objectType: self::OBJECT_TYPE,
				objectId: $requestId,
			)
		);
	}//end recordRevoked()
}//end class
