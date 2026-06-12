<?php

/**
 * Doriath Application
 *
 * Main application class for the Doriath Nextcloud app.
 *
 * @category AppInfo
 * @package  OCA\Doriath\AppInfo
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

namespace OCA\Doriath\AppInfo;

use OCA\Doriath\Event\EncryptionSuiteRevokedEvent;
use OCA\Doriath\Event\SuiteMigrationCompletedEvent;
use OCA\Doriath\Event\SuiteMigrationStartedEvent;
use OCA\Doriath\Listener\DeepLinkRegistrationListener;
use OCA\Doriath\Listener\EncryptionSuiteRevokedListener;
use OCA\Doriath\Listener\SuiteCompromiseListener;
use OCA\Doriath\Listener\SuiteMigrationCompletedListener;
use OCA\Doriath\Listener\SuiteMigrationStartedListener;
use OCA\Doriath\Listener\UserAddedToGroupListener;
use OCA\Doriath\Listener\UserRemovedFromGroupListener;
use OCP\Group\Events\UserAddedEvent;
use OCP\Group\Events\UserRemovedEvent;
use OCA\Doriath\Middleware\JwtAuthMiddleware;
use OCA\Doriath\Notification\DoriathNotifier;
use OCA\Doriath\Search\SecretSearchProvider;
use OCA\OpenRegister\Event\DeepLinkRegistrationEvent;
use OCP\AppFramework\App;
use OCP\AppFramework\Bootstrap\IBootContext;
use OCP\AppFramework\Bootstrap\IBootstrap;
use OCP\AppFramework\Bootstrap\IRegistrationContext;

/**
 * Main application class for the Doriath Nextcloud app.
 */
class Application extends App implements IBootstrap
{
    public const APP_ID = 'doriath';

    /**
     * Constructor for the Application class.
     *
     * @return void
     */
    public function __construct()
    {
        parent::__construct(appName: self::APP_ID);
    }//end __construct()

    /**
     * Register event listeners and services.
     *
     * @param IRegistrationContext $context The registration context
     *
     * @return void
     *
     * @SuppressWarnings(PHPMD.UnusedFormalParameter)
     */
    public function register(IRegistrationContext $context): void
    {
        include_once __DIR__.'/../../vendor/autoload.php';

        // Register deep link patterns with OpenRegister's unified search provider.
        // Only fires when OpenRegister is installed and dispatches the event.
        $context->registerEventListener(
            event: DeepLinkRegistrationEvent::class,
            listener: DeepLinkRegistrationListener::class
        );

        // Compromise-recovery: lock SecretRequests when migration starts and
        // unlock + re-suite them when it completes
        // (implement-secret-requests §6.1-6.3).
        $context->registerEventListener(
            event: SuiteMigrationStartedEvent::class,
            listener: SuiteMigrationStartedListener::class
        );
        $context->registerEventListener(
            event: SuiteMigrationCompletedEvent::class,
            listener: SuiteMigrationCompletedListener::class
        );

        // implement-user-sharing §8 — sharing-graph reactions to
        // group membership churn, suite revocation, and
        // post-migration possibly-compromised flagging.
        $context->registerEventListener(
            event: UserAddedEvent::class,
            listener: UserAddedToGroupListener::class
        );
        $context->registerEventListener(
            event: UserRemovedEvent::class,
            listener: UserRemovedFromGroupListener::class
        );
        $context->registerEventListener(
            event: EncryptionSuiteRevokedEvent::class,
            listener: EncryptionSuiteRevokedListener::class
        );
        $context->registerEventListener(
            event: SuiteMigrationCompletedEvent::class,
            listener: SuiteCompromiseListener::class
        );

        // Register the Nextcloud unified search provider for secrets. It
        // queries unencrypted name/url metadata only and needs no vault
        // session (ADR-003).
        $context->registerSearchProvider(SecretSearchProvider::class);

        // Register the notifier responsible for rendering sharing,
        // secret-request and application-management notification subjects.
        $context->registerNotifierService(DoriathNotifier::class);

        // Register the JWT-Bearer middleware for application-authenticated
        // routes. Fires only on ApplicationApiController subclasses; session
        // controllers pass through untouched.
        $context->registerMiddleware(JwtAuthMiddleware::class);

        // Repair steps (BootstrapCertificateAuthority, InitializeSettings,
        // SeedDevelopmentData, SeedSecretTypes, SeedDevelopmentSecrets) are
        // registered via info.xml <repair-steps>.
    }//end register()

    /**
     * Boot the application.
     *
     * @param IBootContext $context The boot context
     *
     * @return void
     *
     * @SuppressWarnings(PHPMD.UnusedFormalParameter)
     */
    public function boot(IBootContext $context): void
    {
    }//end boot()
}//end class
