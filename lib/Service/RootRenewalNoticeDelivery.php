<?php

/**
 * Keepiq Root Renewal Notice Delivery
 *
 * The renewing side's notification (partner-root-renewal-announcement D2): after this instance renews its root, every
 * partner with inbound sharing on gets a signed OCM notification of its own type, tied to no share. It says only that
 * this server announced a renewal; the partner reads the announcement from the discovery document. The renewal only
 * queues the notices on the partner rows; RetryRootRenewalNoticesJob sends them, and retries a failed one on the
 * schedule of FederatedNotificationDelivery.
 *
 * @category Service
 * @package  OCA\Keepiq\Service
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

namespace OCA\Keepiq\Service;

use DateTime;
use OCA\Keepiq\Db\FederationPartner;
use OCA\Keepiq\Db\FederationPartnerMapper;
use OCA\Keepiq\Kernel\AppIdentity;
use Psr\Log\LoggerInterface;

/**
 * Renewal notices to partners, with retries.
 *
 * @spec openspec/changes/partner-root-renewal-announcement/specs/federated-sharing/spec.md#requirement-a-renewed-root-is-announced-to-partners
 */
class RootRenewalNoticeDelivery {
	/**
	 * The notification type of a renewal notice.
	 *
	 * @var string
	 */
	public const ROOT_RENEWED = 'ROOT_RENEWED';

	/**
	 * What a renewal notice presents as its shared secret and provider id. OCM requires both, and the notice belongs
	 * to no share, so it is a fixed value that matches no share's secret.
	 *
	 * @var string
	 */
	public const NOTICE_ID = 'keepiq-root-renewal';

	/**
	 * Constructor for RootRenewalNoticeDelivery.
	 *
	 * @param FederationPartnerMapper $partnerMapper The partners and the notice state on their rows
	 * @param RootRenewalNoticeSender $sender Sends one notice
	 * @param FederationRootService $root The version gate
	 * @param LoggerInterface $logger Logs a notice given up
	 *
	 * @return void
	 *
	 * @spec exclude Constructor wiring only; no behaviour.
	 */
	public function __construct(
		private FederationPartnerMapper $partnerMapper,
		private RootRenewalNoticeSender $sender,
		private FederationRootService $root,
		private LoggerInterface $logger,
	) {
	}//end __construct()

	/**
	 * Queue a notice for every partner with inbound sharing on, due now. Nothing is sent here: the retry job sends
	 * them, so a slow partner holds up no request and no other partner.
	 *
	 * @param DateTime $now The current time
	 *
	 * @return int How many partners a notice was queued for
	 *
	 * @spec openspec/changes/partner-root-renewal-announcement/specs/federated-sharing/spec.md#scenario-the-partner-renews-its-root
	 */
	public function announce(DateTime $now): int {
		if ($this->root->isSupported() === false) {
			return 0;
		}

		$queued = 0;
		foreach ($this->partnerMapper->findAllPartners() as $partner) {
			if ($partner->getAllowInbound() !== true) {
				continue;
			}

			$partner->setRenewalNoticeAttempts(0);
			$partner->setRenewalNoticeNextAt(clone $now);
			$this->partnerMapper->update(entity: $partner);
			$queued++;
		}

		return $queued;
	}//end announce()

	/**
	 * Send every renewal notice that is due, first ones and retries. A partner whose inbound sharing was turned off
	 * since gets none.
	 *
	 * @param DateTime $now The current time
	 *
	 * @return int How many were delivered
	 *
	 * @spec openspec/changes/partner-root-renewal-announcement/specs/federated-sharing/spec.md#requirement-a-renewed-root-is-announced-to-partners
	 */
	public function retryDue(DateTime $now): int {
		$delivered = 0;
		foreach ($this->partnerMapper->findDueRenewalNotices(now: $now) as $partner) {
			if ($partner->getAllowInbound() !== true) {
				$this->clear(partner: $partner);
				continue;
			}

			if ($this->attempt(partner: $partner, now: $now) === true) {
				$delivered++;
			}
		}

		return $delivered;
	}//end retryDue()

	/**
	 * One attempt at the partner's notice; on failure the next one waits twice as long, and after
	 * FederatedNotificationDelivery::MAX_ATTEMPTS the notice is given up. The partner still finds the announcement in
	 * its next certificate lookup.
	 *
	 * @param FederationPartner $partner The partner
	 * @param DateTime $now The current time
	 *
	 * @return bool Whether it was delivered
	 */
	private function attempt(FederationPartner $partner, DateTime $now): bool {
		if ($this->sender->send(baseUrl: $partner->getBaseUrl()) === true) {
			$this->clear(partner: $partner);
			return true;
		}

		$attempts = (int)$partner->getRenewalNoticeAttempts() + 1;
		$partner->setRenewalNoticeAttempts($attempts);
		$partner->setRenewalNoticeNextAt($this->retryAt(now: $now, attempts: $attempts));
		if ($attempts >= FederatedNotificationDelivery::MAX_ATTEMPTS) {
			$partner->setRenewalNoticeNextAt(null);
			$this->logger->warning(
				'Keepiq: partner {partner} did not take the root renewal notice; it finds the renewal in its next certificate lookup',
				['partner' => $partner->getBaseUrl(), 'app' => AppIdentity::APP_ID]
			);
		}

		$this->partnerMapper->update(entity: $partner);

		return false;
	}//end attempt()

	/**
	 * When the next attempt is due after `$attempts` failed ones: one wait after the first, then each wait doubles.
	 *
	 * @param DateTime $now The current time
	 * @param int $attempts Failed attempts so far
	 *
	 * @return DateTime
	 */
	private function retryAt(DateTime $now, int $attempts): DateTime {
		$wait = FederatedNotificationDelivery::FIRST_WAIT * (int)(2 ** max(0, $attempts - 1));

		return (clone $now)->modify('+' . $wait . ' seconds');
	}//end retryAt()

	/**
	 * Remove the notice from the partner row.
	 *
	 * @param FederationPartner $partner The partner
	 *
	 * @return void
	 */
	private function clear(FederationPartner $partner): void {
		$partner->setRenewalNoticeAttempts(null);
		$partner->setRenewalNoticeNextAt(null);
		$this->partnerMapper->update(entity: $partner);
	}//end clear()
}//end class
