<?php

/**
 * Keepiq Partner Root Renewal Notifier
 *
 * Tells everyone who may accept a partner's offered root renewal about it, or reminds them of it
 * (partner-root-renewal-announcement D5, D6), as CheckRootCertificateExpiry tells them about this instance's root, and
 * removes those notifications once the offer is gone.
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

use OCA\Keepiq\Db\DelegatedSettingsGroups;
use OCA\Keepiq\Db\FederationPartner;
use OCA\Keepiq\Kernel\AppIdentity;
use OCA\Keepiq\Kernel\Contracts\NotificationServiceInterface;
use OCA\Keepiq\Settings\AdminSettings;
use OCP\IGroupManager;
use OCP\Notification\IManager;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * Notifies the administrators of a partner's root renewal.
 *
 * @spec openspec/changes/partner-root-renewal-announcement/specs/federated-sharing/spec.md#requirement-an-announced-renewal-is-offered-to-administrators-after-its-signature-checks-out
 */
class PartnerRootRenewalNotifier {
	/**
	 * The notification object a renewal offer is about, with the partner id as object id.
	 *
	 * @var string
	 */
	public const OBJECT_TYPE = 'federation_partner';

	/**
	 * Constructor for PartnerRootRenewalNotifier.
	 *
	 * @param NotificationServiceInterface $notifications Sends the Nextcloud notifications
	 * @param IGroupManager $groupManager The `admin` group and the delegated groups
	 * @param LoggerInterface $logger Logs a notification that failed
	 * @param IManager $notificationManager Removes the notifications of an offer that is gone
	 * @param DelegatedSettingsGroups $delegates The groups delegated Keepiq's general admin settings
	 *
	 * @return void
	 *
	 * @spec exclude Constructor wiring only; no behaviour.
	 */
	public function __construct(
		private NotificationServiceInterface $notifications,
		private IGroupManager $groupManager,
		private LoggerInterface $logger,
		private IManager $notificationManager,
		private DelegatedSettingsGroups $delegates,
	) {
	}//end __construct()

	/**
	 * Notify everyone who may accept the partner's renewal, each once. A failed notification is logged and the others
	 * still go out.
	 *
	 * @param string $subject PartnerRootRenewalOffers::SUBJECT_OFFERED or SUBJECT_REMINDER
	 * @param FederationPartner $partner The partner
	 * @param int $daysLeft Days until the offer ends
	 *
	 * @return void
	 *
	 * @spec openspec/changes/partner-root-renewal-announcement/specs/federated-sharing/spec.md#requirement-an-announced-renewal-is-offered-to-administrators-after-its-signature-checks-out
	 */
	public function notifyAdmins(string $subject, FederationPartner $partner, int $daysLeft): void {
		foreach ($this->recipients() as $userId) {
			try {
				$this->notifications->notify(
					subject: $subject,
					recipientId: $userId,
					params: ['partner' => $partner->getBaseUrl(), 'partner_id' => $partner->getId(), 'days_left' => $daysLeft],
					objectType: self::OBJECT_TYPE,
					objectId: $partner->getId(),
				);
			} catch (Throwable $exception) {
				$this->logger->warning('Keepiq: partner root renewal notification failed: ' . $exception::class);
			}
		}
	}//end notifyAdmins()

	/**
	 * Remove every recipient's notifications about the partner's renewal offer, once it is accepted, withdrawn,
	 * replaced or expired. A failure is logged: the offer itself is already gone.
	 *
	 * @param string $partnerId The partner UUID
	 *
	 * @return void
	 *
	 * @spec openspec/changes/partner-root-renewal-announcement/specs/federated-sharing/spec.md#scenario-the-notifications-go-when-the-offer-goes
	 */
	public function dismiss(string $partnerId): void {
		try {
			$notification = $this->notificationManager->createNotification();
			$notification->setApp(AppIdentity::APP_ID)->setObject(self::OBJECT_TYPE, $partnerId);
			$this->notificationManager->markProcessed($notification);
		} catch (Throwable $exception) {
			$this->logger->warning('Keepiq: removing the partner root renewal notifications failed: ' . $exception::class);
		}
	}//end dismiss()

	/**
	 * Who may accept a renewal, as who may re-pin a partner: the members of the `admin` group and of every group
	 * delegated Keepiq's general admin settings. A failing delegation lookup leaves only the `admin` group.
	 *
	 * @return string[] User ids, each once
	 */
	private function recipients(): array {
		$groupIds = ['admin'];
		try {
			$groupIds = array_merge($groupIds, $this->delegates->groupIdsFor(settingsClass: AdminSettings::class));
		} catch (Throwable $exception) {
			$this->logger->warning('Keepiq: reading the delegated admin groups failed: ' . $exception::class);
		}

		$userIds = [];
		foreach (array_unique($groupIds) as $groupId) {
			$group = $this->groupManager->get($groupId);
			if ($group === null) {
				continue;
			}

			foreach ($group->getUsers() as $user) {
				$userIds[$user->getUID()] = (string)$user->getUID();
			}
		}

		return array_values($userIds);
	}//end recipients()
}//end class
