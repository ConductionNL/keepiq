<?php

/**
 * Doriath Notification Service
 *
 * Centralised dispatch of Nextcloud notifications for all sharing events,
 * gated by per-user notification preferences.
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

/**
 * Centralised notification dispatch for sharing events.
 */
class NotificationService
{
    /**
     * Map of notification subjects to the per-user setting key that gates them.
     * A subject with no mapped setting is always dispatched.
     *
     * @var array<string,string>
     */
    public const SUBJECT_SETTING_MAP = [
        'secret_shared'        => 'notify_shares',
        'share_request'        => 'notify_shares',
        'share_request_result' => 'notify_shares',
        'group_member_added'   => 'notify_group_shares',
        'secret_compromised'   => 'notify_security',
    ];

    /**
     * Constructor for NotificationService.
     *
     * @param IManager $manager The Nextcloud notification manager
     * @param IConfig  $config  The config interface (per-user preferences)
     *
     * @return void
     */
    public function __construct(
        private IManager $manager,
        private IConfig $config,
    ) {
    }//end __construct()

    /**
     * Check whether a recipient wants notifications for a given subject.
     *
     * Preferences default to opt-in ('true'). A subject not present in
     * SUBJECT_SETTING_MAP is always enabled.
     *
     * @param string $subject     The notification subject
     * @param string $recipientId The recipient user ID
     *
     * @return bool
     */
    public function isEnabled(string $subject, string $recipientId): bool
    {
        $settingKey = (self::SUBJECT_SETTING_MAP[$subject] ?? null);
        if ($settingKey === null) {
            return true;
        }

        $value = $this->config->getUserValue($recipientId, Application::APP_ID, $settingKey, 'true');

        return $value === 'true';
    }//end isEnabled()

    /**
     * Create and dispatch a notification for a sharing event.
     *
     * Respects the recipient's per-subject preference. Parameters are stored
     * as the notification's subject parameters and rendered by DoriathNotifier.
     *
     * @param string              $subject     The notification subject
     * @param string              $recipientId The recipient user ID
     * @param array<string,mixed> $params      Subject parameters for rendering
     * @param string|null         $objectId    The affected object ID (secret ID)
     *
     * @return void
     */
    public function notify(string $subject, string $recipientId, array $params, ?string $objectId=null): void
    {
        if ($this->isEnabled(subject: $subject, recipientId: $recipientId) === false) {
            return;
        }

        $notification = $this->manager->createNotification();
        $notification->setApp(Application::APP_ID)
            ->setUser($recipientId)
            ->setDateTime(new DateTime())
            ->setObject('secret', ($objectId ?? $subject))
            ->setSubject($subject, $params);

        $this->manager->notify($notification);
    }//end notify()

    /**
     * Mark prior notifications for an object/subject as resolved (dismissed).
     *
     * Used when a share request is approved/denied so the actionable
     * notification disappears from the owner's notification list.
     *
     * @param string $subject     The notification subject
     * @param string $recipientId The recipient user ID
     * @param string $objectId    The affected object ID
     *
     * @return void
     */
    public function markResolved(string $subject, string $recipientId, string $objectId): void
    {
        $notification = $this->manager->createNotification();
        $notification->setApp(Application::APP_ID)
            ->setUser($recipientId)
            ->setObject('secret', $objectId)
            ->setSubject($subject);

        $this->manager->markProcessed($notification);
    }//end markResolved()
}//end class
