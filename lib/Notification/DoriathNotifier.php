<?php

/**
 * Doriath Notifier
 *
 * Prepares human-readable notification messages for the Nextcloud notification system.
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
use OCP\IURLGenerator;
use OCP\L10N\IFactory;
use OCP\Notification\INotification;
use OCP\Notification\INotifier;

/**
 * Prepares human-readable notification messages for Doriath notifications.
 *
 * @implements INotifier
 */
class DoriathNotifier implements INotifier
{
    /**
     * Constructor for DoriathNotifier.
     *
     * @param IFactory      $l10nFactory  The L10N factory for translations
     * @param IURLGenerator $urlGenerator The URL generator
     *
     * @return void
     */
    public function __construct(
        private IFactory $l10nFactory,
        private IURLGenerator $urlGenerator,
    ) {
    }//end __construct()

    /**
     * Return the unique identifier of this notifier.
     *
     * @return string
     */
    public function getID(): string
    {
        return 'doriath';
    }//end getID()

    /**
     * Return the human-readable name of this notifier.
     *
     * @return string
     */
    public function getName(): string
    {
        return 'Doriath';
    }//end getName()

    /**
     * Prepare the notification for display.
     *
     * Resolves the subject key to a human-readable string and sets the parsed
     * subject and message on the notification. Unknown subjects throw an
     * InvalidArgumentException so the notification framework skips them.
     *
     * @param INotification $notification The notification to prepare
     * @param string        $languageCode The language code for the recipient
     *
     * @return INotification
     *
     * @throws InvalidArgumentException When the notification app is not 'doriath'
     * @throws InvalidArgumentException When the notification subject is unknown
     */
    public function prepare(INotification $notification, string $languageCode): INotification
    {
        if ($notification->getApp() !== 'doriath') {
            throw new InvalidArgumentException('Notification is not for the doriath app');
        }

        $l = $this->l10nFactory->get(app: 'doriath', lang: $languageCode);

        $params = $notification->getSubjectParameters();

        switch ($notification->getSubject()) {
            case 'secret_shared':
                $notification->setParsedSubject(
                    (string) $l->t('A secret has been shared with you')
                );
                $notification->setParsedMessage(
                    (string) $l->t('"%s" was shared with you.', [$params['secretName'] ?? ''])
                );
                break;

            case 'share_request':
                $notification->setParsedSubject(
                    (string) $l->t('Secret share request')
                );
                $notification->setParsedMessage(
                    (string) $l->t('%s requested access to one of your secrets.', [$params['requesterId'] ?? ''])
                );
                break;

            case 'share_request_result':
                $notification->setParsedSubject(
                    (string) $l->t('Your share request has been handled')
                );
                $notification->setParsedMessage(
                    (string) $l->t('Your share request was %s.', [$params['result'] ?? 'processed'])
                );
                break;

            case 'group_member_added':
                $notification->setParsedSubject(
                    (string) $l->t('New group member added')
                );
                $notification->setParsedMessage(
                    (string) $l->t(
                        'User %s was added to a group with shared secrets.',
                        [$params['userId'] ?? '']
                    )
                );
                break;

            case 'secret_compromised':
                $notification->setParsedSubject(
                    (string) $l->t('Secret possibly compromised')
                );
                $notification->setParsedMessage(
                    (string) $l->t('One of your secrets may have been compromised. Please rotate your credentials.')
                );
                break;

            case 'app_pending':
                $notification->setParsedSubject(
                    (string) $l->t('Application pending approval')
                );
                $notification->setParsedMessage(
                    (string) $l->t('An application is waiting for admin approval in Doriath.')
                );
                break;

            case 'request_fulfilled':
                $notification->setParsedSubject(
                    (string) $l->t('Secret request fulfilled')
                );
                $notification->setParsedMessage(
                    (string) $l->t('Your secret request has been fulfilled.')
                );
                break;

            default:
                throw new InvalidArgumentException(
                    "Unknown Doriath notification subject: {$notification->getSubject()}"
                );
        }//end switch

        return $notification;
    }//end prepare()
}//end class
