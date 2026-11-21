<?php

/**
 * Keepiq Notification Service
 *
 * Central dispatch helper for the notification subjects raised by
 * sharing, secret-request and application-management flows. Subjects
 * are gated by a per-user preference key resolved via IConfig — when
 * the user has opted out, the notification is silently dropped.
 *
 * @category Service
 * @package  OCA\Keepiq\Service
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

namespace OCA\Keepiq\Service;

use DateTime;
use OCA\Keepiq\Kernel\AppIdentity;
use OCA\Keepiq\Kernel\Contracts\NotificationServiceInterface;
use OCP\IConfig;
use OCP\Notification\IManager;
use Psr\Log\LoggerInterface;

/**
 * Dispatches Keepiq notifications through OCP\Notification\IManager.
 *
 * The subject -> user-setting map mirrors the spec for
 * implement-user-sharing (§7), implement-secret-requests (§5),
 * and implement-application-mgmt (§7). A null setting key means the
 * notification is always delivered (admin-only "app_pending"). Subject
 * rendering lives in KeepiqNotifier.
 */
class NotificationService implements NotificationServiceInterface {

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
	 *
	 * @spec openspec/specs/user-settings/spec.md#requirement-notification-toggles-mvp
	 * @spec openspec/specs/user-sharing/spec.md#requirement-notification-on-share-received
	 */
	public function notify(
		string $subject,
		string $recipientId,
		array $params = [],
		?string $objectType = null,
		?string $objectId = null,
	): bool {
		if ($this->isKnownSubject(subject: $subject) === false
			|| $recipientId === ''
			|| $this->optedOutOf(subject: $subject, recipientId: $recipientId) === true
		) {
			return false;
		}

		// Nextcloud refuses an empty object id (InvalidValueException), so a
		// notification about no particular object names its subject instead
		// (found live on Nextcloud 35, keepiq#788).
		if ($objectId === null || $objectId === '') {
			$objectId = $subject;
		}

		$notification = $this->notificationManager->createNotification();
		$notification->setApp(AppIdentity::APP_ID)
			->setUser($recipientId)
			->setDateTime(new DateTime())
			->setObject($objectType ?? $subject, $objectId)
			->setSubject($subject, $params);

		$this->notificationManager->notify($notification);

		return true;
	}//end notify()

	/**
	 * Whether a subject is one this app sends; an unknown one is logged and dropped.
	 *
	 * @param string $subject The subject key
	 *
	 * @return bool
	 */
	private function isKnownSubject(string $subject): bool {
		if (array_key_exists($subject, self::SUBJECT_SETTING_MAP) === true) {
			return true;
		}

		$this->logger->warning(
			'Keepiq notify(): unknown subject "' . $subject . '" — dropping',
			['app' => AppIdentity::APP_ID]
		);
		return false;
	}//end isKnownSubject()

	/**
	 * Whether the recipient switched off this subject's category; a drop is
	 * logged at debug level. A subject without a category is always sent.
	 *
	 * @param string $subject     The subject key, one of SUBJECT_SETTING_MAP
	 * @param string $recipientId The recipient Nextcloud user ID
	 *
	 * @return bool
	 */
	private function optedOutOf(string $subject, string $recipientId): bool {
		$settingKey = self::SUBJECT_SETTING_MAP[$subject];
		if ($settingKey === null || $this->isOptedOut(userId: $recipientId, settingKey: $settingKey) === false) {
			return false;
		}

		$this->logger->debug(
			'Keepiq notify(): user ' . $recipientId . ' opted out of "' . $subject . '"',
			['app' => AppIdentity::APP_ID]
		);
		return true;
	}//end optedOutOf()

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
		$value = $this->config->getUserValue($userId, AppIdentity::APP_ID, $settingKey, '1');

		return $value === '0' || $value === 'false' || $value === '';
	}//end isOptedOut()
}//end class
