<?php

/**
 * Doriath Notifier
 *
 * Renders Doriath sharing notifications into localized, human-readable
 * messages with a deep link to the affected secret.
 *
 * @category Notification
 * @package  OCA\Doriath\Notification
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

namespace OCA\Doriath\Notification;

use InvalidArgumentException;
use OCA\Doriath\AppInfo\Application;
use OCP\IURLGenerator;
use OCP\L10N\IFactory;
use OCP\Notification\INotification;
use OCP\Notification\INotifier;
use OCP\Notification\UnknownNotificationException;

/**
 * INotifier implementation rendering Doriath sharing notifications.
 */
class DoriathNotifier implements INotifier
{
    /**
     * Constructor for DoriathNotifier.
     *
     * @param IFactory      $l10nFactory  The localization factory
     * @param IURLGenerator $urlGenerator The URL generator for deep links
     *
     * @return void
     */
    public function __construct(
        private IFactory $l10nFactory,
        private IURLGenerator $urlGenerator,
    ) {
    }//end __construct()

    /**
     * Identifier of the notifier.
     *
     * @return string
     */
    public function getID(): string
    {
        return Application::APP_ID;
    }//end getID()

    /**
     * Human-readable name of the notifier.
     *
     * @return string
     */
    public function getName(): string
    {
        return $this->l10nFactory->get(Application::APP_ID)->t('Doriath');
    }//end getName()

    /**
     * Prepare a notification for display.
     *
     * @param INotification $notification The notification to prepare
     * @param string        $languageCode The user's language code
     *
     * @return INotification
     *
     * @throws UnknownNotificationException When the notification is not ours
     * @throws InvalidArgumentException     When the subject is unknown
     */
    public function prepare(INotification $notification, string $languageCode): INotification
    {
        if ($notification->getApp() !== Application::APP_ID) {
            throw new UnknownNotificationException();
        }

        $l      = $this->l10nFactory->get(Application::APP_ID, $languageCode);
        $params = $notification->getSubjectParameters();
        $secret = (string) ($params['secretName'] ?? $l->t('a secret'));
        $actor  = (string) ($params['actorName'] ?? ($params['actorId'] ?? $l->t('Someone')));

        switch ($notification->getSubject()) {
            case 'secret_shared':
                $notification->setParsedSubject(
                    $l->t('%1$s shared "%2$s" with you', [$actor, $secret])
                );
                break;

            case 'share_request':
                $target = (string) ($params['targetName'] ?? ($params['targetUserId'] ?? ''));
                $notification->setParsedSubject(
                    $l->t('%1$s requests you share "%2$s" with %3$s', [$actor, $secret, $target])
                );
                break;

            case 'share_request_result':
                $approved = ($params['approved'] ?? false) === true;
                $message  = $l->t('Your request to share "%1$s" was denied', [$secret]);
                if ($approved === true) {
                    $message = $l->t('Your request to share "%1$s" was approved', [$secret]);
                }

                $notification->setParsedSubject($message);
                break;

            case 'group_member_added':
                $group = (string) ($params['groupName'] ?? ($params['groupId'] ?? ''));
                $count = (int) ($params['secretCount'] ?? 1);
                $notification->setParsedSubject(
                    $l->n(
                        '%1$s joined group "%2$s" — %3$d secret needs your approval',
                        '%1$s joined group "%2$s" — %3$d secrets need your approval',
                        $count,
                        [$actor, $group, $count]
                    )
                );
                break;

            case 'secret_compromised':
                $notification->setParsedSubject(
                    $l->t('"%1$s" may be compromised — replace its value', [$secret])
                );
                break;

            default:
                throw new InvalidArgumentException('Unknown Doriath notification subject');
        }//end switch

        $notification->setIcon(
            $this->urlGenerator->getAbsoluteURL(
                $this->urlGenerator->imagePath(Application::APP_ID, 'app-dark.svg')
            )
        );
        $notification->setLink(
            $this->urlGenerator->linkToRouteAbsolute('doriath.dashboard.page')
        );

        return $notification;
    }//end prepare()
}//end class
