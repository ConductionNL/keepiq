<?php

/**
 * Keepiq admin area settings base
 *
 * One delegable area of the Keepiq admin settings (admin-scoped-roles D1).
 * Nextcloud's "Administration privileges" page lists every registered
 * `IDelegatedSettings` class by its `getName()`, and a delegation stores the
 * concrete class name. `#[AuthorizedAdminSetting(<Area>::class)]` and
 * `IManager::getAllowedAdminSettings()` compare that stored name with
 * `get_class()` of the registered instance, so each area MUST be a concrete
 * Keepiq class registered under its own name (see DomainOverrideRegistrar).
 *
 * @category Settings
 * @package  OCA\Keepiq\Settings
 *
 * @author    Conduction Development Team <dev@conductio.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git-id>
 *
 * @link https://conduction.nl
 */

declare(strict_types=1);

namespace OCA\Keepiq\Settings;

use OCA\Keepiq\AppInfo\Application;
use OCP\AppFramework\Http\TemplateResponse;
use OCP\AppFramework\Services\IInitialState;
use OCP\IL10N;
use OCP\Settings\IDelegatedSettings;

/**
 * Shared shape of the five Keepiq admin areas.
 */
abstract class AdminAreaSettings implements IDelegatedSettings {
	/**
	 * The Keepiq admin section every area belongs to.
	 *
	 * @var string
	 */
	public const SECTION = Application::APP_ID;

	/**
	 * Constructor.
	 *
	 * @param IL10N $l10n Translates the area name
	 * @param IInitialState $initialState Tells the admin bundle which area mounts here
	 *
	 * @return void
	 *
	 * @spec exclude Constructor wiring only.
	 */
	public function __construct(
		protected readonly IL10N $l10n,
		protected readonly IInitialState $initialState,
	) {
	}//end __construct()

	/**
	 * The area key: `general`, `policies`, `applications`, `people` or `audit`.
	 *
	 * @return string
	 */
	abstract public function getArea(): string;

	/**
	 * The admin form: one mount element and one initial-state key per area,
	 * so five areas on one page never overwrite each other's state
	 * (admin-scoped-roles D3).
	 *
	 * @return TemplateResponse
	 *
	 * @spec openspec/changes/admin-scoped-roles/tasks.md#1.1
	 */
	public function getForm(): TemplateResponse {
		$this->initialState->provideInitialState('area-' . $this->getArea(), true);
		$this->provideAreaState();

		return new TemplateResponse(Application::APP_ID, 'settings/admin', ['area' => $this->getArea()]);
	}//end getForm()

	/**
	 * Extra initial state an area needs; none by default.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/admin-scoped-roles/tasks.md#1.1
	 */
	protected function provideAreaState(): void {
	}//end provideAreaState()

	/**
	 * The Keepiq admin section.
	 *
	 * @return string
	 *
	 * @spec openspec/changes/admin-scoped-roles/tasks.md#1.1
	 */
	public function getSection(): string {
		return self::SECTION;
	}//end getSection()

	/**
	 * Delegated admins manage no app config through Nextcloud's own API;
	 * Keepiq's area routes do the writing.
	 *
	 * @return array<string, string[]>
	 *
	 * @spec openspec/changes/admin-scoped-roles/tasks.md#1.1
	 */
	public function getAuthorizedAppConfig(): array {
		return [];
	}//end getAuthorizedAppConfig()
}//end class
