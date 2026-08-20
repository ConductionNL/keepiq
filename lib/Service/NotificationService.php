<?php

/**
 * Doriath Notification Service
 *
 * Central dispatch helper for the notification subjects raised by
 * sharing, secret-request and application-management flows. Subjects
 * are gated by a per-user preference key resolved via IConfig — when
 * the user has opted out, the notification is silently dropped.
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
use OCA\Doriath\AppInfo\Application;
use OCP\IConfig;
use OCP\Notification\IManager;
use Psr\Log\LoggerInterface;

/**
 * Dispatches Doriath notifications through OCP\Notification\IManager.
 *
 * The subject -> user-setting map mirrors the spec for
 * implement-user-sharing (§7), implement-secret-requests (§5),
 * and implement-application-mgmt (§7). A null setting key means the
 * notification is always delivered (admin-only "app_pending"). Subject
 * rendering lives in DoriathNotifier.
 */
class NotificationService {
	/**
	 * Subject -> user-setting key map. A null value means the
	 * notification is unconditionally delivered (admin queue).
	 *
	 * @var array<string,string|null>
	 */
	public const SUBJECT_SETTING_MAP = [
		'secret_shared' => 'notify_shares',
		'share_request' => 'notify_shares',
		'share_request_result' => 'notify_shares',
		'group_member_added' => 'notify_group_shares',
		'secret_compromised' => 'notify_security',
		'request_fulfilled' => 'notify_requests',
		'app_pending' => null,
		// Emergency access — grantor is notified on a break-glass request (so
		// the veto window is actionable) and on actual access. Gated on the
		// existing security-notification category (add-emergency-access §4.2).
		'emergency_access_requested' => 'notify_security',
		'emergency_access_accessed' => 'notify_security',
		// Team folder sharing (team-folder-sharing §4.2): fan-out share
		// to a recipient; group-join approval request to the owner.
		'team_folder_shared' => 'notify_shares',
		'team_folder_join_request' => 'notify_group_shares',
		// Rotation & expiry (rotation-expiry-policies §4): approaching
		// expiry reminders and the overdue/rotation-due flag.
		'secret_expiring' => 'notify_security',
		'secret_rotation_due' => 'notify_security',
		// SIEM audit export (siem-audit-export §5.1): an operational
		// admin alert like app_pending — never user-suppressible.
		'siem_dead_letter' => null,
		// Certificate lifecycle (certificate-lifecycle §3.2): suite
		// certificate approaching notAfter.
		'certificate_expiring' => 'notify_security',
		// Honey credentials (honey-credentials §D3): a muted tripwire
		// is worthless — always pages, like app_pending.
		'honey_access' => null,
	];

	/**
	 * Constructor for NotificationService.
	 *
	 * @param IManager $notificationManager The notification manager
	 * @param IConfig $config The Nextcloud config
	 * @param LoggerInterface $logger The logger
	 *
	 * @return void
	 */
	public function __construct(
		private IManager $notificationManager,
		private IConfig $config,
		private LoggerInterface $logger,
	) {
	}//end __construct()

	/**
	 * Dispatch a notification to a single Nextcloud user.
	 *
	 * @param string $subject The subject key (must be in SUBJECT_SETTING_MAP)
	 * @param string $recipientId The recipient Nextcloud user ID
	 * @param array<string,mixed> $params Subject-specific parameters (passed to the notifier)
	 * @param string|null $objectType Optional object type (e.g. 'secret', 'application')
	 * @param string|null $objectId Optional object ID
	 *
	 * @return bool True when the notification was queued, false when suppressed.
	 */
	public function notify(
		string $subject,
		string $recipientId,
		array $params = [],
		?string $objectType = null,
		?string $objectId = null,
	): bool {
		if (array_key_exists($subject, self::SUBJECT_SETTING_MAP) === false) {
			$this->logger->warning(
				'Doriath notify(): unknown subject "' . $subject . '" — dropping',
				['app' => Application::APP_ID]
			);
			return false;
		}

		if ($recipientId === '') {
			return false;
		}

		$settingKey = self::SUBJECT_SETTING_MAP[$subject];
		if ($settingKey !== null && $this->isOptedOut(userId: $recipientId, settingKey: $settingKey) === true) {
			$this->logger->debug(
				'Doriath notify(): user ' . $recipientId . ' opted out of "' . $subject . '"',
				['app' => Application::APP_ID]
			);
			return false;
		}

		$notification = $this->notificationManager->createNotification();
		$notification->setApp(Application::APP_ID)
			->setUser($recipientId)
			->setDateTime(new DateTime())
			->setObject($objectType ?? $subject, $objectId ?? '')
			->setSubject($subject, $params);

		$this->notificationManager->notify($notification);

		return true;
	}//end notify()

	/**
	 * Check whether a user has opted out of a notification category.
	 *
	 * Defaults are "on" — the absence of any value is treated as opt-in,
	 * matching the InitializeSettings repair step.
	 *
	 * @param string $userId The Nextcloud user ID
	 * @param string $settingKey The user-preference key (e.g. 'notify_shares')
	 *
	 * @return bool True when the user has explicitly disabled the category.
	 */
	private function isOptedOut(string $userId, string $settingKey): bool {
		$value = $this->config->getUserValue($userId, Application::APP_ID, $settingKey, '1');

		return $value === '0' || $value === 'false' || $value === '';
	}//end isOptedOut()
}//end class
