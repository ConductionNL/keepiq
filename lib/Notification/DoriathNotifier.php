<?php

/**
 * Doriath Notifier
 *
 * INotifier implementation that renders Doriath notification subjects
 * into a localised parsed payload (icon + subject + message + link).
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
use OCA\Doriath\Service\NotificationService;
use OCP\IURLGenerator;
use OCP\L10N\IFactory;
use OCP\Notification\INotification;
use OCP\Notification\INotifier;
use OCP\Notification\UnknownNotificationException;

/**
 * Renders Doriath notifications.
 *
 * The subject IDs here must match NotificationService::SUBJECT_SETTING_MAP.
 * Each branch builds a short subject line, a longer message line and a
 * deep-link the user clicks to land on the affected secret / queue.
 */
class DoriathNotifier implements INotifier
{
    /**
     * Constructor for DoriathNotifier.
     *
     * @param IFactory      $l10nFactory The L10N factory
     * @param IURLGenerator $url         The URL generator
     *
     * @return void
     */
    public function __construct(
        private IFactory $l10nFactory,
        private IURLGenerator $url,
    ) {
    }//end __construct()

    /**
     * Identifier for the manager registration.
     *
     * @return string
     */
    public function getID(): string
    {
        return Application::APP_ID;
    }//end getID()

    /**
     * Human-readable name used by the Nextcloud notification settings.
     *
     * @return string
     */
    public function getName(): string
    {
        return 'Doriath';
    }//end getName()

    /**
     * Prepare a notification for display.
     *
     * @param INotification $notification The notification instance
     * @param string        $languageCode The user's language code
     *
     * @return INotification
     *
     * @throws UnknownNotificationException
     */
    public function prepare(INotification $notification, string $languageCode): INotification
    {
        if ($notification->getApp() !== Application::APP_ID) {
            throw new UnknownNotificationException();
        }

        $l    = $this->l10nFactory->get(app: Application::APP_ID, lang: $languageCode);
        $subj = $notification->getSubject();
        $p    = $notification->getSubjectParameters();

        if (array_key_exists($subj, NotificationService::SUBJECT_SETTING_MAP) === false) {
            throw new UnknownNotificationException();
        }

        $notification->setIcon($this->url->getAbsoluteURL($this->url->imagePath(Application::APP_ID, 'app.svg')));

        switch ($subj) {
            case 'secret_shared':
                $secretName = (string) ($p['secret_name'] ?? $l->t('a secret'));
                $sharedBy   = (string) ($p['shared_by'] ?? $l->t('a user'));
                $notification->setParsedSubject((string) $l->t('Secret shared with you'));
                $notification->setParsedMessage(
                    (string) $l->t('%1$s shared the secret "%2$s" with you.', [$sharedBy, $secretName])
                );
                $this->withSecretLink(notification: $notification, params: $p);
                break;

            case 'share_request':
                $requester  = (string) ($p['requester'] ?? $l->t('a user'));
                $secretName = (string) ($p['secret_name'] ?? $l->t('a secret'));
                $notification->setParsedSubject((string) $l->t('Share request'));
                $notification->setParsedMessage(
                    (string) $l->t('%1$s requested access to the secret "%2$s".', [$requester, $secretName])
                );
                $this->withSecretLink(notification: $notification, params: $p);
                break;

            case 'share_request_result':
                $secretName = (string) ($p['secret_name'] ?? $l->t('a secret'));
                $result     = (string) ($p['result'] ?? 'denied');
                $notification->setParsedSubject((string) $l->t('Share request result'));
                if ($result === 'approved') {
                    $resultMessage = (string) $l->t('Your share request for "%s" was approved.', [$secretName]);
                } else {
                    $resultMessage = (string) $l->t('Your share request for "%s" was denied.', [$secretName]);
                }

                $notification->setParsedMessage($resultMessage);
                $this->withSecretLink(notification: $notification, params: $p);
                break;

            case 'group_member_added':
                $groupId    = (string) ($p['group_id'] ?? '');
                $secretName = (string) ($p['secret_name'] ?? $l->t('a secret'));
                $notification->setParsedSubject((string) $l->t('Group member added'));
                $notification->setParsedMessage(
                    (string) $l->t('A new member joined the group "%1$s" — approve to share "%2$s".', [$groupId, $secretName])
                );
                $this->withSecretLink(notification: $notification, params: $p);
                break;

            case 'secret_compromised':
                $secretName = (string) ($p['secret_name'] ?? $l->t('a secret'));
                $notification->setParsedSubject((string) $l->t('Secret may be compromised'));
                $notification->setParsedMessage(
                    (string) $l->t('Your secret "%s" may be compromised and requires migration.', [$secretName])
                );
                $this->withSecretLink(notification: $notification, params: $p);
                break;

            case 'request_fulfilled':
                $secretName = (string) ($p['secret_name'] ?? $l->t('a secret'));
                $notification->setParsedSubject((string) $l->t('Secret request fulfilled'));
                $notification->setParsedMessage(
                    (string) $l->t('Your request for "%s" has been filled in.', [$secretName])
                );
                $this->withSecretLink(notification: $notification, params: $p);
                break;

            case 'app_pending':
                $appName      = (string) ($p['app_name'] ?? $l->t('an application'));
                $registeredBy = (string) ($p['registered_by'] ?? $l->t('an external party'));
                $notification->setParsedSubject((string) $l->t('New application pending approval'));
                $notification->setParsedMessage(
                    (string) $l->t('Application "%1$s" was registered by %2$s and is awaiting approval.', [$appName, $registeredBy])
                );
                $notification->setLink(
                    $this->url->getAbsoluteURL(
                        $this->url->linkToRoute('settings.AdminSettings.index', ['section' => Application::APP_ID])
                    )
                );
                break;

            case 'emergency_access_requested':
                $granteeName = (string) ($p['grantee_name'] ?? $p['granteeUserId'] ?? $l->t('a trusted contact'));
                $waitDays    = (int) ($p['waitPeriodDays'] ?? 7);
                $notification->setParsedSubject((string) $l->t('Emergency access requested'));
                $notification->setParsedMessage(
                    (string) $l->t(
                        '%1$s requested emergency access to your vault. It will be granted in %2$d day(s) unless you decline.',
                        [$granteeName, $waitDays]
                    )
                );
                break;

            case 'emergency_access_accessed':
                $granteeName = (string) ($p['grantee_name'] ?? $p['granteeUserId'] ?? $l->t('a trusted contact'));
                $notification->setParsedSubject((string) $l->t('Emergency access used'));
                $notification->setParsedMessage(
                    (string) $l->t('%s accessed your vault through emergency access.', [$granteeName])
                );
                break;

            default:
                throw new UnknownNotificationException();
        }//end switch

        return $notification;
    }//end prepare()

    /**
     * Attach a deep-link to the affected secret, when the params include one.
     *
     * @param INotification       $notification The notification to mutate
     * @param array<string,mixed> $params       The subject parameters
     *
     * @return void
     */
    private function withSecretLink(INotification $notification, array $params): void
    {
        $secretId = (string) ($params['secret_id'] ?? '');
        if ($secretId === '') {
            return;
        }

        try {
            $route = $this->url->linkToRoute('doriath.dashboard.page').'#/secrets/'.$secretId;
            $notification->setLink($this->url->getAbsoluteURL($route));
        } catch (InvalidArgumentException) {
            // Fall back silently — the link is optional.
        }
    }//end withSecretLink()
}//end class
