<?php

/**
 * Static-analysis stub for OpenRegister's AppHost Settings engine (ADR-040).
 *
 * Analysis-only — referenced from phpstan.neon `scanFiles` and psalm.xml
 * `<stubs>`, and NEVER loaded at runtime. Doriath's Sections\SettingsSection
 * and Settings\AdminSettings are engine-backed stubs that extend these two
 * classes; the real implementations live in the openregister sibling app
 * (openregister/lib/AppHost/Settings/), which is absent from the CI analysis
 * path. Without this file both leaf classes read as "extends unknown class"
 * and every #[AuthorizedAdminSetting(AdminSettings::class)] attribute fails
 * its class-string<IDelegatedSettings> check.
 *
 * The signatures below mirror openregister/lib/AppHost/Settings/
 * GenericSettingsSection.php and GenericAdminSettings.php verbatim.
 *
 * @category Test
 * @package  OCA\OpenRegister\AppHost\Settings
 *
 * @author    Conduction Development Team <dev@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://conduction.nl
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

namespace OCA\OpenRegister\AppHost\Settings;

use OCP\App\IAppManager;
use OCP\AppFramework\Http\TemplateResponse;
use OCP\AppFramework\Services\IInitialState;
use OCP\IAppConfig;
use OCP\IURLGenerator;
use OCP\Settings\IDelegatedSettings;
use OCP\Settings\IIconSection;

/**
 * Analysis-only stub for the AppHost admin-settings section base class.
 *
 * The real class lives in the openregister sibling app (ADR-040).
 */
class GenericSettingsSection implements IIconSection {

	/**
	 * Construct a settings section.
	 *
	 * @param string $sectionId The section id.
	 * @param string $name The display name.
	 * @param string $appId The app id.
	 * @param string $iconFile The icon file name.
	 * @param int $priority The display priority.
	 * @param IURLGenerator $urlGenerator The URL generator.
	 */
	public function __construct(
		protected string $sectionId,
		protected string $name,
		protected string $appId,
		protected string $iconFile,
		protected int $priority,
		protected IURLGenerator $urlGenerator,
	) {

	}//end __construct()

	/**
	 * Return the section id.
	 *
	 * @return string
	 */
	public function getID(): string {
		return $this->sectionId;
	}//end getID()

	/**
	 * Return the display name.
	 *
	 * @return string
	 */
	public function getName(): string {
		return $this->name;
	}//end getName()

	/**
	 * Return the display priority.
	 *
	 * @return int
	 */
	public function getPriority(): int {
		return $this->priority;
	}//end getPriority()

	/**
	 * Return the absolute URL to the section icon.
	 *
	 * @return string
	 */
	public function getIcon(): string {
		return $this->urlGenerator->imagePath($this->appId, $this->iconFile);
	}//end getIcon()

}//end class

/**
 * Analysis-only stub for the AppHost admin-settings panel base class.
 *
 * The real class lives in the openregister sibling app (ADR-040).
 */
class GenericAdminSettings implements IDelegatedSettings {

	/**
	 * Construct an admin settings panel.
	 *
	 * @param string $appId The app id.
	 * @param string $sectionId The section id.
	 * @param int $priority The display priority.
	 * @param IAppManager $appManager The app manager.
	 * @param IInitialState $initialState The initial state service.
	 * @param IAppConfig $appConfig The app config service.
	 */
	public function __construct(
		protected string $appId,
		protected string $sectionId,
		protected int $priority,
		protected IAppManager $appManager,
		protected IInitialState $initialState,
		protected IAppConfig $appConfig,
	) {

	}//end __construct()

	/**
	 * Return the settings form template response.
	 *
	 * @return TemplateResponse
	 */
	public function getForm(): TemplateResponse {
		return new TemplateResponse($this->appId, 'settings-admin');
	}//end getForm()

	/**
	 * Return the section id.
	 *
	 * @return string
	 */
	public function getSection(): string {
		return $this->sectionId;
	}//end getSection()

	/**
	 * Return the display priority.
	 *
	 * @return int
	 */
	public function getPriority(): int {
		return $this->priority;
	}//end getPriority()

	/**
	 * Return the delegated-settings display name.
	 *
	 * @return string|null
	 */
	public function getName(): ?string {
		return null;
	}//end getName()

	/**
	 * Return the app-config keys this panel is authorised to write.
	 *
	 * @return array<string, list<string>>
	 */
	public function getAuthorizedAppConfig(): array {
		return [];
	}//end getAuthorizedAppConfig()

}//end class
