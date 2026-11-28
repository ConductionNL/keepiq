<?php

/**
 * Keepiq PrivilegedCopyGate
 *
 * @category Service
 * @package  OCA\Keepiq\Service\PrivilegedAccess
 *
 * @author    Conduction Development Team <dev@conductio.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git-id>
 *
 * @link https://conduction.nl
 */

declare(strict_types=1);

namespace OCA\Keepiq\Service\PrivilegedAccess;

use DateTimeImmutable;
use OCA\Keepiq\Db\AccessRequest;
use OCA\Keepiq\Db\AccessRequestMapper;
use OCA\Keepiq\Db\PrivilegedLoginMapper;
use OCA\Keepiq\Db\Secret;
use OCA\Keepiq\Event\Audit\AuditEventFactory;
use OCA\Keepiq\Event\Audit\AuditEventTypes;
use OCA\Keepiq\Exception\PrivilegedAccessRequiredException;
use OCA\Keepiq\Service\AuditService;
use OCA\Keepiq\Service\ShareRowJudge;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\IRequest;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * Decides, on every path that serves a recipient's copy, whether that copy
 * is a privileged login used outside an approved window or address range.
 * Such a copy is served without its ciphertext (or refused outright for a
 * single fetch), and the refusal is audited. The owner's own secret is never
 * a copy, so the owner always keeps full access.
 *
 * @SuppressWarnings(PHPMD.CouplingBetweenObjects) One decision over marks,
 *   requests, share rows, the client address and the audit trail.
 */
class PrivilegedCopyGate {

	/**
	 * The reason a withheld row carries.
	 *
	 * @var string
	 */
	public const REASON = 'Privileged login: an approved access window is required';

	/**
	 * Per user: the privileged source of each of their copies, keyed by copy id.
	 *
	 * @var array<string, array<string,string>>
	 */
	private array $restricted = [];

	/**
	 * Builds the audit events this gate records.
	 *
	 * @var AuditEventFactory
	 */
	private AuditEventFactory $auditEvents;

	/**
	 * Constructor.
	 *
	 * @param PrivilegedLoginMapper $flags    The privileged marks
	 * @param AccessRequestMapper   $requests The access requests
	 * @param ShareRowJudge         $rows     The share rows a user holds
	 * @param AuditService          $audit    The audit trail
	 * @param IRequest              $request  The client address
	 * @param ITimeFactory          $time     The clock
	 * @param LoggerInterface       $logger   The logger
	 *
	 * @return void
	 */
	public function __construct(
		private PrivilegedLoginMapper $flags,
		private AccessRequestMapper $requests,
		private ShareRowJudge $rows,
		private AuditService $audit,
		private IRequest $request,
		private ITimeFactory $time,
		private LoggerInterface $logger,
	) {
		$this->auditEvents = new AuditEventFactory();
	}//end __construct()

	/**
	 * Refuse a single fetch of a privileged copy outside its window, and
	 * audit the refusal or the use.
	 *
	 * @param Secret $secret  The row about to be served
	 * @param string $userId  The user it is served to
	 * @param string $channel Which client path serves it (web, extension, offline)
	 *
	 * @return void
	 *
	 * @throws PrivilegedAccessRequiredException When no approved window allows it now, from here
	 *
	 * @spec openspec/specs/user-sharing/spec.md#requirement-use-of-a-privileged-login-needs-an-approved-time-window
	 */
	public function assertServable(Secret $secret, string $userId, string $channel): void {
		if ($this->allows(secret: $secret, userId: $userId, channel: $channel, audit: true) === false) {
			throw new PrivilegedAccessRequiredException(message: self::REASON);
		}
	}//end assertServable()

	/**
	 * A serialised row as it may be served: unchanged, or without any
	 * ciphertext when it is a privileged copy outside its window.
	 *
	 * @param Secret              $secret  The row
	 * @param array<string,mixed> $data    Its full serialisation
	 * @param string              $userId  The user it is served to
	 * @param string              $channel Which client path serves it
	 * @param bool                $audit   Whether to audit a use or refusal (a value fetch), or not (a listing)
	 *
	 * @return array<string,mixed>
	 *
	 * @SuppressWarnings(PHPMD.BooleanArgumentFlag) A listing withholds as a fetch does, it only does not audit.
	 *
	 * @spec openspec/specs/user-sharing/spec.md#requirement-use-of-a-privileged-login-needs-an-approved-time-window
	 */
	public function servable(Secret $secret, array $data, string $userId, string $channel, bool $audit = false): array {
		if ($this->allows(secret: $secret, userId: $userId, channel: $channel, audit: $audit) === true) {
			return $data;
		}

		return [
			...$secret->jsonSerializeBlocked(self::REASON),
			'privilegedAccess' => PrivilegedAccessRequiredException::CODE,
		];
	}//end servable()

	/**
	 * A row serialised for the user it is returned to, after a write or in a
	 * conflict body: without any ciphertext when it is a privileged copy
	 * outside its window. Not audited, as a listing.
	 *
	 * @param Secret $secret  The row
	 * @param string $userId  The user it is returned to
	 * @param string $channel Which client path returns it
	 *
	 * @return array<string,mixed>
	 *
	 * @spec openspec/specs/user-sharing/spec.md#requirement-use-of-a-privileged-login-needs-an-approved-time-window
	 */
	public function serialisedFor(Secret $secret, string $userId, string $channel = 'web'): array {
		return $this->servable(secret: $secret, data: $secret->jsonSerialize(), userId: $userId, channel: $channel);
	}//end serialisedFor()

	/**
	 * Whether the row may be served with its ciphertext now, to this address.
	 *
	 * @param Secret $secret  The row
	 * @param string $userId  The user it is served to
	 * @param string $channel Which client path serves it
	 * @param bool   $audit   Whether to audit the use or the refusal
	 *
	 * @return bool
	 *
	 * @SuppressWarnings(PHPMD.BooleanArgumentFlag) Auditing is the caller's choice per path.
	 *
	 * @spec openspec/specs/user-sharing/spec.md#requirement-use-of-a-privileged-login-needs-an-approved-time-window
	 */
	public function allows(Secret $secret, string $userId, string $channel, bool $audit = false): bool {
		$sourceId = ($this->restrictedCopies(userId: $userId)[$secret->getId()] ?? null);
		if ($sourceId === null) {
			return true;
		}

		$address = $this->request->getRemoteAddress();
		$now     = (new DateTimeImmutable())->setTimestamp($this->time->getTime());
		$granted = $this->grantingRequest(sourceId: $sourceId, userId: $userId, now: $now, address: $address);
		if ($audit === true) {
			$eventType = AuditEventTypes::PRIVILEGED_ACCESS_USED;
			if ($granted === null) {
				$eventType = AuditEventTypes::PRIVILEGED_ACCESS_REFUSED;
			}

			$this->record(
				secret: $secret,
				userId: $userId,
				eventType: $eventType,
				metadata: ['requestId' => $granted?->getId(), 'address' => $address, 'channel' => $channel]
			);
		}

		return $granted !== null;
	}//end allows()

	/**
	 * The approved request whose window allows a use now from the address.
	 *
	 * @param string            $sourceId The owner's source secret
	 * @param string            $userId   The recipient
	 * @param DateTimeImmutable $now      The moment of use
	 * @param string            $address  The client address
	 *
	 * @return AccessRequest|null
	 */
	private function grantingRequest(string $sourceId, string $userId, DateTimeImmutable $now, string $address): ?AccessRequest {
		foreach ($this->requests->findApproved(secretId: $sourceId, requesterId: $userId) as $approved) {
			try {
				$window = $approved->approvedWindow();
			} catch (Throwable $exception) {
				// A stored window that no longer parses grants nothing.
				$this->logger->warning(
					'Keepiq: an approved access window could not be read: ' . $exception->getMessage(),
					['app' => 'keepiq', 'requestId' => $approved->getId()]
				);
				continue;
			}

			if ($window !== null && $window->allows(now: $now, address: $address) === true) {
				return $approved;
			}
		}

		return null;
	}//end grantingRequest()

	/**
	 * The user's copies whose source is privileged, keyed by copy id. Every
	 * live share row counts, judged or not: a restriction fails closed.
	 *
	 * @param string $userId The holder
	 *
	 * @return array<string,string>
	 */
	private function restrictedCopies(string $userId): array {
		if (isset($this->restricted[$userId]) === true) {
			return $this->restricted[$userId];
		}

		$sourceOf = [];
		foreach ($this->rows->rowsOfTarget(targetUserId: $userId)->all() as $row) {
			$sourceOf[$row->getSecretId()] = $row->getSourceSecretId();
		}

		$privileged = array_flip($this->flags->privilegedAmong(secretIds: array_values($sourceOf)));

		$this->restricted[$userId] = array_filter(
			$sourceOf,
			static fn (string $sourceId): bool => isset($privileged[$sourceId])
		);

		return $this->restricted[$userId];
	}//end restrictedCopies()

	/**
	 * Audit a use or a refusal. A failing audit write never opens the gate
	 * and never breaks the response.
	 *
	 * @param Secret              $secret    The recipient's copy
	 * @param string              $userId    The recipient
	 * @param string              $eventType The event
	 * @param array<string,mixed> $metadata  The request, address and channel
	 *
	 * @return void
	 */
	private function record(Secret $secret, string $userId, string $eventType, array $metadata): void {
		try {
			$this->audit->record(
				$this->auditEvents->forUser(
					actorId: $userId,
					eventType: $eventType,
					objectType: 'secret',
					objectId: $secret->getId(),
					objectName: $secret->getName(),
					metadata: array_filter($metadata, static fn ($value): bool => $value !== null)
				)
			);
		} catch (Throwable $exception) {
			$this->logger->warning(
				'Keepiq: privileged access could not be audited: ' . $exception->getMessage(),
				['app' => 'keepiq']
			);
		}
	}//end record()
}//end class
