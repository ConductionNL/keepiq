<?php

/**
 * Doriath Application API Controller (base)
 *
 * Base controller for endpoints authenticated via the RFC 7523 JWT Bearer
 * flow. JwtAuthMiddleware validates the Bearer access token and injects the
 * authenticated Application before the controller method runs.
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
use OCP\AppFramework\OCSController;

/**
 * Base controller for JWT-Bearer-authenticated application API endpoints.
 *
 * @SuppressWarnings(PHPMD.LongVariable) The authenticated-application property name is descriptive by intent.
 */
abstract class ApplicationApiController extends OCSController
{

    /**
     * The authenticated application, injected by JwtAuthMiddleware.
     *
     * @var Application|null
     */
    private ?Application $authenticatedApplication = null;

    /**
     * Set the authenticated application (called by JwtAuthMiddleware).
     *
     * @param Application $application The authenticated application
     *
     * @return void
     */
    public function setAuthenticatedApplication(Application $application): void
    {
        $this->authenticatedApplication = $application;
    }//end setAuthenticatedApplication()

    /**
     * Get the authenticated application.
     *
     * @return Application|null
     */
    public function getAuthenticatedApplication(): ?Application
    {
        return $this->authenticatedApplication;
    }//end getAuthenticatedApplication()
}//end class
