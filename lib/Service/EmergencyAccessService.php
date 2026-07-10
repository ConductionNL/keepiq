<?php

/**
 * Doriath Emergency Access Service
 *
 * Business logic for the emergency-access lifecycle: an owner designates a
 * trusted contact, confirms the grant (storing the owner's vault key material
 * re-wrapped under the contact's suite public key — the server never sees
 * plaintext), and the contact can later request access. After a configurable
 * wait period elapses without owner rejection, a background job auto-grants.
 *
 * This service owns the state-machine and authorization logic only. The
 * client-side re-wrap (WebCrypto), the notification dispatch wiring, the
 * controllers/routes, the background-job registration, and the Vue UI are
 * layered on top per the emergency-access change's remaining tasks.
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

use DateTime;
use InvalidArgumentException;
use OCA\Doriath\Db\EmergencyContact;
use OCA\Doriath\Db\EmergencyContactMapper;
use OCA\Doriath\Db\EmergencyRequest;
use OCA\Doriath\Db\EmergencyRequestMapper;
use OCA\Doriath\Event\EmergencyAccessGrantedEvent;
use OCA\Doriath\Event\EmergencyAccessRejectedEvent;
use OCA\Doriath\Event\EmergencyAccessRequestedEvent;
use OCP\AppFramework\Db\DoesNotExistException;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\EventDispatcher\IEventDispatcher;
use Psr\Log\LoggerInterface;
use Ramsey\Uuid\Uuid;

/**
 * State-machine + authorization logic for emergency access.
 */
class EmergencyAccessService
{

    /**
     * The allowed access levels.
     *
     * @var string[]
     */
    private const ALLOWED_LEVELS = [
        EmergencyContact::LEVEL_VIEW,
        EmergencyContact::LEVEL_TAKEOVER,
    ];

    /**
     * Constructor for EmergencyAccessService.
     *
     * @param EmergencyContactMapper $contactMapper   The contact designation mapper
     * @param EmergencyRequestMapper $requestMapper   The access-request mapper
     * @param ITimeFactory           $timeFactory     The time factory (testable clock)
     * @param LoggerInterface        $logger          The logger
     * @param IEventDispatcher|null  $eventDispatcher Optional typed-event dispatcher
     *
     * @return void
     */
    public function __construct(
        private EmergencyContactMapper $contactMapper,
        private EmergencyRequestMapper $requestMapper,
        private ITimeFactory $timeFactory,
        private LoggerInterface $logger,
        private ?IEventDispatcher $eventDispatcher=null,
    ) {
    }//end __construct()

    /**
     * Designate an emergency contact for an owner. Creates a
     * `pending-confirmation` row; no key material exists until the owner
     * confirms. Rejects self-designation and unknown access levels.
     *
     * @param string $ownerId         The owner's Nextcloud user ID
     * @param string $contactId       The contact's Nextcloud user ID
     * @param int    $waitPeriodHours The wait period before auto-grant
     * @param string $level           The access level (view|takeover)
     *
     * @return EmergencyContact
     *
     * @throws InvalidArgumentException On self-designation, bad level, or bad wait period.
     */
    public function registerContact(
        string $ownerId,
        string $contactId,
        int $waitPeriodHours,
        string $level=EmergencyContact::LEVEL_VIEW,
    ): EmergencyContact {
        if ($ownerId === $contactId) {
            throw new InvalidArgumentException(message: 'An owner cannot designate themselves as their own emergency contact');
        }

        if (in_array($level, self::ALLOWED_LEVELS, true) === false) {
            throw new InvalidArgumentException(message: 'Unknown emergency access level: '.$level);
        }

        if ($waitPeriodHours < 1) {
            throw new InvalidArgumentException(message: 'The wait period must be at least one hour');
        }

        $contact = new EmergencyContact();
        $contact->setId(Uuid::uuid4()->toString());
        $contact->setOwnerId($ownerId);
        $contact->setContactId($contactId);
        $contact->setWaitPeriodHours($waitPeriodHours);
        $contact->setLevel($level);
        $contact->setStatus(EmergencyContact::STATUS_PENDING_CONFIRMATION);
        $contact->setCreatedAt($this->timeFactory->getDateTime());

        return $this->contactMapper->insert($contact);
    }//end registerContact()

    /**
     * Confirm a designation by storing the owner-supplied wrapped key
     * material (re-wrapped client-side under the contact's suite public
     * key). Owner-only. Idempotent: confirming an already-active designation
     * simply refreshes the wrapped material and fingerprint.
     *
     * @param string      $id                      The designation ID
     * @param string      $ownerId                 The owner's Nextcloud user ID (authorization)
     * @param string      $wrappedKeyMaterial      The re-wrapped key ciphertext (base64)
     * @param string|null $contactSuiteFingerprint The contact's active-suite fingerprint
     *
     * @return EmergencyContact
     *
     * @throws InvalidArgumentException When the designation is unknown (404) or not owned (403).
     */
    public function confirmContact(
        string $id,
        string $ownerId,
        string $wrappedKeyMaterial,
        ?string $contactSuiteFingerprint=null,
    ): EmergencyContact {
        $contact = $this->loadOwnedContact(id: $id, ownerId: $ownerId);

        if ($wrappedKeyMaterial === '') {
            throw new InvalidArgumentException(message: 'Wrapped key material is required to confirm an emergency contact');
        }

        $contact->setWrappedKeyMaterial($wrappedKeyMaterial);
        $contact->setContactSuiteFingerprint($contactSuiteFingerprint);
        $contact->setStatus(EmergencyContact::STATUS_ACTIVE);
        $contact->setConfirmedAt($this->timeFactory->getDateTime());

        return $this->contactMapper->update($contact);
    }//end confirmContact()

    /**
     * Revoke a designation (owner-only). Idempotent.
     *
     * @param string $id      The designation ID
     * @param string $ownerId The owner's Nextcloud user ID (authorization)
     *
     * @return EmergencyContact
     *
     * @throws InvalidArgumentException When the designation is unknown (404) or not owned (403).
     */
    public function revokeContact(string $id, string $ownerId): EmergencyContact
    {
        $contact = $this->loadOwnedContact(id: $id, ownerId: $ownerId);
        $contact->setStatus(EmergencyContact::STATUS_REVOKED);

        return $this->contactMapper->update($contact);
    }//end revokeContact()

    /**
     * Raise an emergency-access request as a contact. Validates that an
     * ACTIVE designation exists for the owner/contact pair before creating
     * the `requested` row and dispatching the requested event.
     *
     * @param string $contactId The requesting contact's Nextcloud user ID
     * @param string $ownerId   The vault owner's Nextcloud user ID
     *
     * @return EmergencyRequest
     *
     * @throws InvalidArgumentException When no active designation exists (403).
     */
    public function requestAccess(string $contactId, string $ownerId): EmergencyRequest
    {
        try {
            $contact = $this->contactMapper->findActiveForPair(ownerId: $ownerId, contactId: $contactId);
        } catch (DoesNotExistException) {
            throw new InvalidArgumentException(
                message: 'No active emergency-access designation exists for this owner/contact pair',
                code: 403
            );
        }

        $request = new EmergencyRequest();
        $request->setId(Uuid::uuid4()->toString());
        $request->setEmergencyContactId($contact->getId());
        $request->setContactId($contactId);
        $request->setOwnerId($ownerId);
        $request->setStatus(EmergencyRequest::STATUS_REQUESTED);
        $request->setRequestedAt($this->timeFactory->getDateTime());

        $saved = $this->requestMapper->insert($request);

        $this->dispatch(
            event: new EmergencyAccessRequestedEvent(
                requestId: $saved->getId(),
                ownerId: $ownerId,
                contactId: $contactId
            )
        );

        return $saved;
    }//end requestAccess()

    /**
     * Reject a pending request (owner-only) during the wait period.
     *
     * @param string $id      The request ID
     * @param string $ownerId The owner's Nextcloud user ID (authorization)
     *
     * @return EmergencyRequest
     *
     * @throws InvalidArgumentException When the request is unknown (404), not owned (403),
     *                                  or no longer pending (409).
     */
    public function rejectRequest(string $id, string $ownerId): EmergencyRequest
    {
        $request = $this->loadPendingRequest(id: $id);

        if ($request->getOwnerId() !== $ownerId) {
            throw new InvalidArgumentException(message: 'Only the vault owner may reject an emergency-access request', code: 403);
        }

        $request->setStatus(EmergencyRequest::STATUS_REJECTED);
        $request->setResolvedAt($this->timeFactory->getDateTime());
        $saved = $this->requestMapper->update($request);

        $this->dispatch(
            event: new EmergencyAccessRejectedEvent(
                requestId: $saved->getId(),
                ownerId: $ownerId,
                contactId: $saved->getContactId()
            )
        );

        return $saved;
    }//end rejectRequest()

    /**
     * Cancel a pending request (contact-initiated self-cancel).
     *
     * @param string $id        The request ID
     * @param string $contactId The requesting contact's Nextcloud user ID (authorization)
     *
     * @return EmergencyRequest
     *
     * @throws InvalidArgumentException When the request is unknown (404), not the caller's (403),
     *                                  or no longer pending (409).
     */
    public function cancelRequest(string $id, string $contactId): EmergencyRequest
    {
        $request = $this->loadPendingRequest(id: $id);

        if ($request->getContactId() !== $contactId) {
            throw new InvalidArgumentException(message: 'Only the requesting contact may cancel their own request', code: 403);
        }

        $request->setStatus(EmergencyRequest::STATUS_CANCELLED);
        $request->setResolvedAt($this->timeFactory->getDateTime());

        return $this->requestMapper->update($request);
    }//end cancelRequest()

    /**
     * Auto-grant every `requested` row whose wait period has elapsed. Called
     * by the EmergencyAccessExpiryJob background job. Rows still inside their
     * wait period are left untouched.
     *
     * @return int The number of requests transitioned to `granted`.
     */
    public function processExpiredRequests(): int
    {
        $now     = $this->timeFactory->getDateTime();
        $granted = 0;

        foreach ($this->requestMapper->findAllRequested() as $request) {
            $waitHours   = $this->resolveWaitPeriodHours(request: $request);
            $requestedAt = $request->getRequestedAt();
            if ($requestedAt === null) {
                continue;
            }

            $expiresAt = (clone $requestedAt)->modify('+'.$waitHours.' hours');
            if ($now < $expiresAt) {
                // Still inside the wait period — the owner may yet reject.
                continue;
            }

            $request->setStatus(EmergencyRequest::STATUS_GRANTED);
            $request->setResolvedAt($now);
            $this->requestMapper->update($request);

            $this->dispatch(
                event: new EmergencyAccessGrantedEvent(
                    requestId: $request->getId(),
                    ownerId: $request->getOwnerId(),
                    contactId: $request->getContactId(),
                    level: $this->resolveLevel(request: $request)
                )
            );

            $granted++;
        }//end foreach

        return $granted;
    }//end processExpiredRequests()

    /**
     * Whether a contact currently holds granted emergency access to an
     * owner's vault. Used to extend the SecretService read authorization
     * (emergency-access §2.6) once that wiring lands.
     *
     * @param string $contactId The would-be reader's Nextcloud user ID
     * @param string $ownerId   The vault owner's Nextcloud user ID
     *
     * @return bool
     */
    public function hasGrantedAccess(string $contactId, string $ownerId): bool
    {
        foreach ($this->requestMapper->findByOwner($ownerId) as $request) {
            if ($request->getContactId() === $contactId
                && $request->getStatus() === EmergencyRequest::STATUS_GRANTED
            ) {
                return true;
            }
        }

        return false;
    }//end hasGrantedAccess()

    /**
     * Load a designation and assert the caller owns it.
     *
     * @param string $id      The designation ID
     * @param string $ownerId The owner's Nextcloud user ID
     *
     * @return EmergencyContact
     *
     * @throws InvalidArgumentException When unknown (404) or not owned (403).
     */
    private function loadOwnedContact(string $id, string $ownerId): EmergencyContact
    {
        try {
            $contact = $this->contactMapper->findById($id);
        } catch (DoesNotExistException) {
            throw new InvalidArgumentException(message: 'Emergency contact designation not found', code: 404);
        }

        if ($contact->getOwnerId() !== $ownerId) {
            throw new InvalidArgumentException(message: 'Only the vault owner may manage this designation', code: 403);
        }

        return $contact;
    }//end loadOwnedContact()

    /**
     * Load a request and assert it is still pending (`requested`).
     *
     * @param string $id The request ID
     *
     * @return EmergencyRequest
     *
     * @throws InvalidArgumentException When unknown (404) or not pending (409).
     */
    private function loadPendingRequest(string $id): EmergencyRequest
    {
        try {
            $request = $this->requestMapper->findById($id);
        } catch (DoesNotExistException) {
            throw new InvalidArgumentException(message: 'Emergency-access request not found', code: 404);
        }

        if ($request->getStatus() !== EmergencyRequest::STATUS_REQUESTED) {
            throw new InvalidArgumentException(message: 'This emergency-access request is no longer pending', code: 409);
        }

        return $request;
    }//end loadPendingRequest()

    /**
     * Resolve the wait period for a request from its bound designation,
     * falling back to a 24h default if the designation is gone.
     *
     * @param EmergencyRequest $request The request
     *
     * @return int
     */
    private function resolveWaitPeriodHours(EmergencyRequest $request): int
    {
        try {
            return $this->contactMapper->findById($request->getEmergencyContactId())->getWaitPeriodHours();
        } catch (DoesNotExistException) {
            return 24;
        }
    }//end resolveWaitPeriodHours()

    /**
     * Resolve the access level for a request from its bound designation,
     * falling back to `view` if the designation is gone.
     *
     * @param EmergencyRequest $request The request
     *
     * @return string
     */
    private function resolveLevel(EmergencyRequest $request): string
    {
        try {
            return $this->contactMapper->findById($request->getEmergencyContactId())->getLevel();
        } catch (DoesNotExistException) {
            return EmergencyContact::LEVEL_VIEW;
        }
    }//end resolveLevel()

    /**
     * Dispatch a typed event when a dispatcher is wired.
     *
     * @param object $event The event to dispatch
     *
     * @return void
     */
    private function dispatch(object $event): void
    {
        if ($this->eventDispatcher !== null) {
            $this->eventDispatcher->dispatchTyped($event);
        }
    }//end dispatch()
}//end class
