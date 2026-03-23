<?php

/**
 * AppTemplate Application
 *
 * Main application class for the AppTemplate Nextcloud app.
 *
 * @category AppInfo
 * @package  OCA\AppTemplate\AppInfo
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

namespace OCA\AppTemplate\AppInfo;

use OCP\AppFramework\App;
use OCP\AppFramework\Bootstrap\IBootContext;
use OCP\AppFramework\Bootstrap\IBootstrap;
use OCP\AppFramework\Bootstrap\IRegistrationContext;

/**
 * Main application class for the AppTemplate Nextcloud app.
 */
class Application extends App implements IBootstrap
{
    public const APP_ID = 'app-template';

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
     */
    public function register(IRegistrationContext $context): void
    {
        // Register your event listeners and services here.
        // Example:
        // $context->registerEventListener(
        //     event: SomeEvent::class,
        //     listener: SomeListener::class
        // );
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
