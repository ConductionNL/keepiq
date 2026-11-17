<?php

/**
 * Keepiq CredentialExpiryRequestedListener
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

use DateTimeInterface;
use DateTimeZone;
use OCA\Keepiq\Kernel\AppIdentity;
use OCA\Keepiq\Db\BrokerMintMapper;
use OCA\Keepiq\Db\Secret;
use OCA\Keepiq\Db\SecretMapper;
use OCA\Keepiq\Event\CredentialExpiryRequestedEvent;
use OCP\EventDispatcher\Event;
use OCP\EventDispatcher\IEventListener;
use OCP\IURLGenerator;
use Throwable;

/**
 * Answers integriq's expiry question for its own connection certificate, and
 * nothing else: only an application-owned secret the broker minted for the
 * consumer `integriq` and for the organisation integriq acts for, with an
 * expiry. Every other request, a personal entry, another organisation's
 * credential, an unknown or removed secret, gets no answer at all.
 *
 * @template-implements IEventListener<Event>
 *
 * @spec openspec/changes/connection-certificate-expiry-for-integriq/specs/integration-boundary/spec.md#requirement-integriq-may-learn-when-its-own-connection-certificate-expires
 */
class CredentialExpiryRequestedListener implements IEventListener {
	/**
	 * The one app keepiq answers.
	 *
	 * @var string
	 */
	public const RECEIVER = 'integriq';

	/**
	 * Constructor.
	 *
	 * @param BrokerMintMapper $mints   The broker mint records
	 * @param SecretMapper     $secrets The vault entries
	 * @param IURLGenerator    $url     Builds the secret's page link
	 */
	public function __construct(
		private BrokerMintMapper $mints,
		private SecretMapper $secrets,
		private IURLGenerator $url,
	) {
	}//end __construct()

	/**
	 * Answer the request when every condition holds, else leave it unanswered.
	 *
	 * @param Event $event The event
	 *
	 * @return void
	 *
	 * @spec openspec/changes/connection-certificate-expiry-for-integriq/specs/integration-boundary/spec.md#requirement-integriq-may-learn-when-its-own-connection-certificate-expires
	 */
	public function handle(Event $event): void {
		if (($event instanceof CredentialExpiryRequestedEvent) === false) {
			return;
		}

		$secret = $this->answerableSecret(event: $event);
		$expiresAt = $secret?->getExpiresAt();
		if ($secret === null || $expiresAt === null) {
			return;
		}

		$notAfter = (clone $expiresAt)->setTimezone(new DateTimeZone('UTC'))->format(DateTimeInterface::ATOM);
		$event->answer(notAfter: $notAfter, renewUrl: $this->renewUrl(secretId: $secret->getId()));
	}//end handle()

	/**
	 * The secret when it is integriq's own organisation credential, else null.
	 *
	 * @param CredentialExpiryRequestedEvent $event The request
	 *
	 * @return Secret|null
	 */
	private function answerableSecret(CredentialExpiryRequestedEvent $event): ?Secret {
		$organisation = trim($event->getActingOrganisationId());
		if ($organisation === '' || $event->getCredentialId() === '') {
			return null;
		}

		$mint = $this->mints->findBySecret(secretId: $event->getCredentialId());
		if ($mint === null || $mint->getConsumer() !== self::RECEIVER || $mint->getOrganisationId() !== $organisation) {
			return null;
		}

		try {
			$secret = $this->secrets->findById($event->getCredentialId());
		} catch (Throwable) {
			return null;
		}

		if ($secret->getOwnerType() !== 'application' || $secret->getTrashedAt() !== null || $secret->getTombstonedAt() !== null) {
			return null;
		}

		return $secret;
	}//end answerableSecret()

	/**
	 * The absolute link to the secret's page, where the guided renewal is.
	 *
	 * @param string $secretId The secret
	 *
	 * @return string
	 */
	private function renewUrl(string $secretId): string {
		$root = rtrim($this->url->linkToRoute(AppIdentity::APP_ID . '.dashboard.page'), '/');

		return $this->url->getAbsoluteURL($root . '/secrets/' . rawurlencode($secretId));
	}//end renewUrl()
}//end class
