<?php

/**
 * Keepiq Partner Root Renewal Notifier
 *
 * Tells every member of the Nextcloud `admin` group about a partner's offered root renewal, or reminds them of it
 * (partner-root-renewal-announcement D5, D6), as CheckRootCertificateExpiry tells them about this instance's root.
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

use OCA\Keepiq\Db\FederationPartner;
use OCA\Keepiq\Kernel\Contracts\NotificationServiceInterface;
use OCP\IGroupManager;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * Notifies the administrators of a partner's root renewal.
 *
 * @spec openspec/changes/partner-root-renewal-announcement/specs/federated-sharing/spec.md#requirement-an-announced-renewal-is-offered-to-administrators-after-its-signature-checks-out
 */
class PartnerRootRenewalNotifier {
	/**
	 * Constructor for PartnerRootRenewalNotifier.
	 *
	 * @param NotificationServiceInterface $notifications Sends the Nextcloud notifications
	 * @param IGroupManager $groupManager The `admin` group
	 * @param LoggerInterface $logger Logs a notification that failed
	 *
	 * @return void
	 *
	 * @spec exclude Constructor wiring only; no behaviour.
	 */
	public function __construct(
		private NotificationServiceInterface $notifications,
		private IGroupManager $groupManager,
		private LoggerInterface $logger,
	) {
	}//end __construct()

	/**
	 * Notify every member of the `admin` group about the partner's renewal. A failed notification is logged and the
	 * others still go out.
	 *
	 * @param string $subject PartnerRootRenewalService::SUBJECT_OFFERED or SUBJECT_REMINDER
	 * @param FederationPartner $partner The partner
	 * @param int $daysLeft Days until the offer ends
	 *
	 * @return void
	 *
	 * @spec openspec/changes/partner-root-renewal-announcement/specs/federated-sharing/spec.md#requirement-an-announced-renewal-is-offered-to-administrators-after-its-signature-checks-out
	 */
	public function notifyAdmins(string $subject, FederationPartner $partner, int $daysLeft): void {
		$admins = $this->groupManager->get('admin');
		if ($admins === null) {
			return;
		}

		foreach ($admins->getUsers() as $admin) {
			try {
				$this->notifications->notify(
					subject: $subject,
					recipientId: $admin->getUID(),
					params: ['partner' => $partner->getBaseUrl(), 'partner_id' => $partner->getId(), 'days_left' => $daysLeft],
					objectType: 'federation_partner',
					objectId: $partner->getId(),
				);
			} catch (Throwable $exception) {
				$this->logger->warning('Keepiq: partner root renewal notification failed: ' . $exception::class);
			}
		}
	}//end notifyAdmins()
}//end class
