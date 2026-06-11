<?php

/**
 * Doriath Application API Controller (Base)
 *
 * Marker base class for controllers that accept JWT-Bearer authentication
 * from a registered application instead of a Nextcloud session. The
 * JwtAuthMiddleware checks `instanceof ApplicationApiController` to know
 * whether to require + validate the Authorization header.
 *
 * @category Controller
 * @package  OCA\Doriath\Controller
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

namespace OCA\Doriath\Controller;

use OCA\Doriath\Db\Application;
use OCP\AppFramework\Controller;
use OCP\IRequest;

/**
 * Base class for Bearer-authenticated controllers.
 *
 * The Application entity is injected by JwtAuthMiddleware after the
 * Authorization header is validated. Controllers extending this class
 * should consult `$this->getApplication()` for the calling application
 * — never trust headers directly.
 */
abstract class ApplicationApiController extends Controller
{
    /**
     * The Application entity resolved from the Bearer token. Populated
     * by JwtAuthMiddleware::beforeController before the controller
     * method runs.
     *
     * @var Application|null
     */
    private ?Application $application = null;


    /**
     * Constructor for ApplicationApiController.
     *
     * @param string   $appName The Nextcloud app name
     * @param IRequest $request The HTTP request
     *
     * @return void
     */
    public function __construct(string $appName, IRequest $request)
    {
        parent::__construct(appName: $appName, request: $request);
    }//end __construct()


    /**
     * Inject the Application entity (called by the middleware after the
     * Bearer token is validated).
     *
     * @param Application $application The authenticated application
     *
     * @return void
     */
    public function setApplication(Application $application): void
    {
        $this->application = $application;
    }//end setApplication()


    /**
     * Get the Application entity for the current Bearer-authenticated
     * call.
     *
     * @return Application|null
     */
    public function getApplication(): ?Application
    {
        return $this->application;
    }//end getApplication()
}//end class
