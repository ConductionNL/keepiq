<?php

/**
 * Doriath Honey Tripwire Listener
 *
 * The single central wiring point of the honey tripwire
 * (honey-credentials §3.1 / D1): subscribes to the typed AuditEvent
 * bus and checks every server-observable secret access — UI reveal,
 * machine-API retrieval, anonymous link access, and share-recipient
 * copy reads — against the honey flags. Fail-soft by contract: a
 * tripwire failure never blocks or delays the observed access.
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

use OCA\Doriath\Db\LinkShareMapper;
use OCA\Doriath\Db\ShareTargetMapper;
use OCA\Doriath\Event\Audit\AuditEvent;
use OCA\Doriath\Event\Audit\AuditEventTypes;
use OCA\Doriath\Service\HoneyTripwireService;
use OCP\AppFramework\Db\DoesNotExistException;
use OCP\EventDispatcher\Event;
use OCP\EventDispatcher\IEventListener;
use OCP\IRequest;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * Central honey tripwire on the typed audit stream.
 *
 * @implements IEventListener<AuditEvent>
 */
class HoneyTripwireListener implements IEventListener {
	/**
	 * Constructor for HoneyTripwireListener.
	 *
	 * @param HoneyTripwireService $honeyService The honey tripwire service
	 * @param LinkShareMapper $linkShareMapper Link → secret resolution
	 * @param ShareTargetMapper $shareTargetMapper Copy → source resolution
	 * @param IRequest $request IP/user-agent source
	 * @param LoggerInterface $logger The logger
	 *
	 * @return void
	 */
	public function __construct(
		private HoneyTripwireService $honeyService,
		private LinkShareMapper $linkShareMapper,
		private ShareTargetMapper $shareTargetMapper,
		private IRequest $request,
		private LoggerInterface $logger,
	) {
	}//end __construct()

	/**
	 * Inspect a dispatched audit event for honey hits (fail-soft).
	 *
	 * Channel derivation (D2): secret.read → ui (a share-recipient
	 * copy read pivots to the flagged SOURCE and reports share);
	 * application.secret_retrieved → machine_api;
	 * link_share.accessed → link (resolved through the link row).
	 *
	 * @param Event $event The dispatched event
	 *
	 * @return void
	 *
	 * @spec openspec/specs/honey-credentials/spec.md#requirement-any-access-to-a-honey-secret-raises-a-high-severity-alert
	 */
	public function handle(Event $event): void {
		if ($event instanceof AuditEvent === false) {
			return;
		}

		try {
			$this->trip(
				event: $event,
				remoteIp: $this->resolveRemoteIp(),
				userAgent: $this->resolveUserAgent()
			);
		} catch (Throwable $exception) {
			// Fail-soft: the tripwire never breaks the observed access.
			$this->logger->error(
				'Doriath: honey tripwire failed: ' . $exception->getMessage(),
				['app' => 'doriath']
			);
		}//end try
	}//end handle()

	/**
	 * Remote address of the observed access, or null when it is unavailable.
	 *
	 * @return string|null
	 */
	private function resolveRemoteIp(): ?string {
		$remoteAddress = $this->request->getRemoteAddress();
		if ($remoteAddress === '') {
			return null;
		}

		return $remoteAddress;
	}//end resolveRemoteIp()

	/**
	 * Truncated user agent of the observed access, or null when it is unavailable.
	 *
	 * @return string|null
	 */
	private function resolveUserAgent(): ?string {
		$userAgent = $this->request->getHeader('User-Agent');
		if ($userAgent === '') {
			return null;
		}

		return substr($userAgent, 0, 512);
	}//end resolveUserAgent()

	/**
	 * Route one audit event onto the honey channel it belongs to.
	 *
	 * @param AuditEvent $event The dispatched audit event
	 * @param string|null $remoteIp Remote address of the access
	 * @param string|null $userAgent User agent of the access
	 *
	 * @return void
	 */
	private function trip(AuditEvent $event, ?string $remoteIp, ?string $userAgent): void {
		$eventType = $event->getEventType();

		if ($eventType === AuditEventTypes::SECRET_READ && $event->getObjectType() === 'secret') {
			$secretId = (string)$event->getObjectId();
			$hit = $this->honeyService->raiseAlert(
				secretId: $secretId,
				accessorType: $event->getActorType(),
				accessorId: $event->getActorId(),
				channel: 'ui',
				remoteIp: $remoteIp,
				userAgent: $userAgent,
			);
			if ($hit === false) {
				// Not flagged directly — a share-recipient copy read
				// trips the wire of its flagged SOURCE secret.
				$this->tripSourceOfCopy(event: $event, copySecretId: $secretId, remoteIp: $remoteIp, userAgent: $userAgent);
			}

			return;
		}

		if ($eventType === AuditEventTypes::APPLICATION_SECRET_RETRIEVED && $event->getObjectType() === 'secret') {
			$this->honeyService->raiseAlert(
				secretId: (string)$event->getObjectId(),
				accessorType: $event->getActorType(),
				accessorId: $event->getActorId(),
				channel: 'machine_api',
				remoteIp: $remoteIp,
				userAgent: $userAgent,
			);

			return;
		}

		if ($eventType === AuditEventTypes::LINK_SHARE_ACCESSED && $event->getObjectType() === 'link_share') {
			$linkShare = $this->linkShareMapper->findById((string)$event->getObjectId());
			$this->honeyService->raiseAlert(
				secretId: $linkShare->getSecretId(),
				accessorType: $event->getActorType(),
				accessorId: $event->getActorId(),
				channel: 'link',
				remoteIp: $remoteIp,
				userAgent: $userAgent,
			);
		}
	}//end trip()

	/**
	 * A read of a share-recipient COPY trips the wire of its flagged
	 * source secret (channel: share).
	 *
	 * @param AuditEvent $event The read event
	 * @param string $copySecretId The read (possibly copy) secret id
	 * @param string|null $remoteIp Remote address
	 * @param string|null $userAgent User agent
	 *
	 * @return void
	 */
	private function tripSourceOfCopy(AuditEvent $event, string $copySecretId, ?string $remoteIp, ?string $userAgent): void {
		try {
			$shareTarget = $this->shareTargetMapper->findByRecipientSecret($copySecretId);
		} catch (DoesNotExistException) {
			return;
		}

		$this->honeyService->raiseAlert(
			secretId: $shareTarget->getSourceSecretId(),
			accessorType: $event->getActorType(),
			accessorId: $event->getActorId(),
			channel: 'share',
			remoteIp: $remoteIp,
			userAgent: $userAgent,
		);
	}//end tripSourceOfCopy()
}//end class
