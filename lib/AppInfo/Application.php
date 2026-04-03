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

use OCA\Doriath\Listener\DeepLinkRegistrationListener;
use OCA\Doriath\Listener\UserAddedToGroupListener;
use OCA\Doriath\Listener\UserRemovedFromGroupListener;
use OCA\OpenRegister\Event\DeepLinkRegistrationEvent;
use OCP\AppFramework\App;
use OCP\AppFramework\Bootstrap\IBootContext;
use OCP\AppFramework\Bootstrap\IBootstrap;
use OCP\AppFramework\Bootstrap\IRegistrationContext;
use OCP\Group\Events\UserAddedEvent;
use OCP\Group\Events\UserRemovedEvent;

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

        // Group membership listeners — keep group shares in sync.
        $context->registerEventListener(
            event: UserAddedEvent::class,
            listener: UserAddedToGroupListener::class
        );
        $context->registerEventListener(
            event: UserRemovedEvent::class,
            listener: UserRemovedFromGroupListener::class
        );

        // Repair steps (BootstrapCertificateAuthority, InitializeSettings,
        // SeedDevelopmentData) are registered via info.xml <repair-steps>.
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
