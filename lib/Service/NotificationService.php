<?php

/**
 * Doriath Notification Service
 *
 * Sends user notifications for Doriath events, respecting per-user notification preferences.
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

use OCP\IConfig;
use OCP\Notification\IManager;
use Psr\Log\LoggerInterface;

/**
 * Sends user notifications for Doriath events.
 */
class NotificationService
{

    /**
     * Map from notification subject to the user-preference setting key.
     * A null value means the notification is always sent (no user preference controls it).
     *
     * @var array<string,string|null>
     */
    private const SUBJECT_SETTING_MAP = [
        'secret_shared'        => 'notify_shares',
        'share_request'        => 'notify_shares',
        'share_request_result' => 'notify_shares',
        'group_member_added'   => 'notify_group_shares',
        'secret_compromised'   => 'notify_security',
        'app_pending'          => null,
        'request_fulfilled'    => 'notify_requests',
    ];

    /**
     * Constructor for NotificationService.
     *
     * @param IManager        $notificationManager The Nextcloud notification manager
     * @param IConfig         $config              The Nextcloud config service
     * @param LoggerInterface $logger              The logger interface
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
     * Send a notification to a user.
     *
     * Checks the SUBJECT_SETTING_MAP for the matching preference key. If the key
     * is not null, the user's saved preference is fetched (default: 'true'); when
     * the preference is 'false' the notification is suppressed. Otherwise the
     * notification is created and dispatched via the IManager.
     *
     * Expected $params keys:
     *  - objectType (string)  — the notification object type
     *  - objectId   (string)  — the notification object ID
     *  - Additional keys are passed as subject parameters.
     *
     * @param string              $subject     The notification subject key
     * @param string              $recipientId The Nextcloud user ID of the recipient
     * @param array<string,mixed> $params      Subject parameters and object type/id
     *
     * @return void
     */
    public function notify(string $subject, string $recipientId, array $params): void
    {
        $settingKey = self::SUBJECT_SETTING_MAP[$subject] ?? false;

        // If the subject maps to a user preference, check whether it is enabled.
        if ($settingKey !== null && $settingKey !== false) {
            $enabled = $this->config->getUserValue(
                userId: $recipientId,
                appName: 'doriath',
                key: $settingKey,
                default: 'true'
            );

            if ($enabled === 'false') {
                $this->logger->debug(
                    "Doriath: Notification '{$subject}' suppressed for {$recipientId} (preference disabled)"
                );
                return;
            }
        }

        $objectType = $params['objectType'] ?? 'doriath';
        $objectId   = $params['objectId'] ?? 'general';

        $notification = $this->notificationManager->createNotification();
        $notification->setApp(app: 'doriath')
            ->setUser(user: $recipientId)
            ->setDateTime(dateTime: new \DateTime())
            ->setObject(type: $objectType, id: (string) $objectId)
            ->setSubject(subject: $subject, parameters: $params);

        $this->notificationManager->notify($notification);

        $this->logger->debug(
            "Doriath: Notification '{$subject}' sent to {$recipientId}"
        );
    }//end notify()
}//end class
