<?php

/**
 * Doriath Admin Settings
 *
 * Provides the admin settings form for the Doriath application.
 *
 * @category Settings
 * @package  OCA\Doriath\Settings
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

namespace OCA\Doriath\Settings;

use OCA\Doriath\AppInfo\Application;
use OCP\App\IAppManager;
use OCP\AppFramework\Http\TemplateResponse;
use OCP\AppFramework\Services\IInitialState;
use OCP\Settings\IDelegatedSettings;

/**
 * Provides the admin settings form for the Doriath application.
 *
 * Implements IDelegatedSettings (extends ISettings) so that the
 * #[AuthorizedAdminSetting(AdminSettings::class)] attribute on controller methods
 * satisfies PHPStan's class-string<IDelegatedSettings> constraint.
 */
class AdminSettings implements IDelegatedSettings
{
    /**
     * Constructor.
     *
     * @param IAppManager   $appManager   The app manager.
     * @param IInitialState $initialState The initial state service.
     */
    public function __construct(
        private IAppManager $appManager,
        private IInitialState $initialState,
    ) {
    }//end __construct()

    /**
     * Get the settings form template.
     *
     * @return TemplateResponse
     */
    public function getForm(): TemplateResponse
    {
        $version = $this->appManager->getAppVersion(appId: Application::APP_ID);
        $this->initialState->provideInitialState('version', $version);

        return new TemplateResponse(
            Application::APP_ID,
            'settings/admin'
        );
    }//end getForm()

    /**
     * Get the section ID this settings page belongs to.
     *
     * @return string
     */
    public function getSection(): string
    {
        return 'doriath';
    }//end getSection()

    /**
     * Get the priority for ordering within the section.
     *
     * @return int
     */
    public function getPriority(): int
    {
        return 10;
    }//end getPriority()

    /**
     * Get the human-readable name of this settings panel, or null to show only
     * the section name.
     *
     * Required by IDelegatedSettings (since NC 23).
     *
     * @return string|null
     */
    public function getName(): ?string
    {
        return null;
    }//end getName()

    /**
     * Get a list of authorized app config keys this settings panel may modify.
     *
     * Returns all Doriath app config keys as authorised for delegated settings
     * access. Required by IDelegatedSettings (since NC 23).
     *
     * @return array<string, list<string>>
     */
    public function getAuthorizedAppConfig(): array
    {
        return [
            Application::APP_ID => ['/.*/'],
        ];
    }//end getAuthorizedAppConfig()
}//end class
