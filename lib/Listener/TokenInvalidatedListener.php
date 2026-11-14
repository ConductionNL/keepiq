<?php

/**
 * Keepiq Token Invalidated Listener
 *
 * @category Listener
 * @package  OCA\Keepiq\Listener
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

namespace OCA\Keepiq\Listener;

use OCA\Keepiq\Db\PasskeyMapper;
use OCA\Keepiq\Event\Audit\AuditEventFactory;
use OCA\Keepiq\Event\Audit\AuditEventTypes;
use OCA\Keepiq\Kernel\Contracts\ExtensionChallengeStoreInterface;
use OCP\Authentication\Events\TokenInvalidatedEvent;
use OCP\EventDispatcher\Event;
use OCP\EventDispatcher\IEventDispatcher;
use OCP\EventDispatcher\IEventListener;

/**
 * When Nextcloud invalidates an app password (revoked in Devices &
 * sessions, sessions revoked by an admin, remote wipe), delete the
 * extension passkeys and pending challenges bound to it, and audit each
 * owner's loss.
 *
 * @template-implements IEventListener<TokenInvalidatedEvent>
 *
 * @spec openspec/changes/extension-passkey-enrolment/specs/extension-passkey-trust/spec.md#requirement-extension-credentials-follow-their-app-password
 */
class TokenInvalidatedListener implements IEventListener {
	/**
	 * Constructor for TokenInvalidatedListener.
	 *
	 * @param PasskeyMapper $passkeys The passkey credentials
	 * @param ExtensionChallengeStoreInterface $challenges The extension challenges
	 * @param IEventDispatcher $dispatcher The audit dispatcher
	 * @param AuditEventFactory $auditEvents The audit-event factory
	 *
	 * @return void
	 */
	public function __construct(
		private PasskeyMapper $passkeys,
		private ExtensionChallengeStoreInterface $challenges,
		private IEventDispatcher $dispatcher,
		private AuditEventFactory $auditEvents = new AuditEventFactory(),
	) {
	}//end __construct()

	/**
	 * Delete what was bound to the invalidated app password.
	 *
	 * @param Event $event The event
	 *
	 * @return void
	 *
	 * @spec openspec/changes/extension-passkey-enrolment/specs/extension-passkey-trust/spec.md#requirement-extension-credentials-follow-their-app-password
	 */
	public function handle(Event $event): void {
		if (($event instanceof TokenInvalidatedEvent) === false) {
			return;
		}

		$tokenId = $event->getToken()->getId();
		$this->challenges->forgetToken(tokenId: $tokenId);
		foreach ($this->passkeys->deleteByToken(tokenId: $tokenId) as $ownerId => $count) {
			$this->dispatcher->dispatchTyped(
				$this->auditEvents->forUser(
					actorId: (string)$ownerId,
					eventType: AuditEventTypes::PASSKEY_REVOKED_WITH_APP_PASSWORD,
					objectType: 'passkey',
					metadata: ['credentialCount' => $count],
				)
			);
		}
	}//end handle()
}//end class
