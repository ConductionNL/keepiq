<?php

/**
 * Keepiq Settings Section
 *
 * The "Keepiq" section of Administration settings, which Nextcloud
 * instantiates by the class name in info.xml `<settings><admin-section>`.
 * Section id `keepiq`, name `Keepiq`, icon `app-dark.svg`, priority 75.
 *
 * @category Sections
 * @package  OCA\Keepiq\Sections
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

namespace OCA\Keepiq\Sections;

use OCA\Keepiq\AppInfo\Application;
use OCP\IURLGenerator;
use OCP\Settings\IIconSection;

/**
 * Keepiq's admin-settings section: the "Keepiq" entry in Administration
 * settings that the five admin areas render under.
 *
 * A native Nextcloud section (ADR-006). The id, name, icon and priority are
 * the values the former AppHost section used, so the section keeps its place
 * and its admin-area delegations keep resolving.
 *
 * @psalm-suppress UnusedClass Loaded by Nextcloud from appinfo/info.xml <settings>.
 *
 * @spec openspec/specs/app-shell/spec.md#requirement-native-admin-section-and-version-card
 */
class SettingsSection implements IIconSection {
	/**
	 * Constructor for SettingsSection.
	 *
	 * @param IURLGenerator $urlGenerator The URL generator (icon path)
	 *
	 * @return void
	 */
	public function __construct(private readonly IURLGenerator $urlGenerator) {
	}//end __construct()

	/**
	 * The section id the admin areas declare as their section.
	 *
	 * @return string The section id
	 *
	 * @spec openspec/specs/app-shell/spec.md#requirement-native-admin-section-and-version-card
	 */
	public function getID(): string {
		return Application::APP_ID;
	}//end getID()

	/**
	 * The section name, the product name, so it is not translated.
	 *
	 * @return string The section name
	 *
	 * @spec openspec/specs/app-shell/spec.md#requirement-native-admin-section-and-version-card
	 */
	public function getName(): string {
		return 'Keepiq';
	}//end getName()

	/**
	 * The section's position in the admin navigation.
	 *
	 * @return int The priority
	 *
	 * @spec openspec/specs/app-shell/spec.md#requirement-native-admin-section-and-version-card
	 */
	public function getPriority(): int {
		return 75;
	}//end getPriority()

	/**
	 * The section icon.
	 *
	 * @return string The icon URL
	 *
	 * @spec openspec/specs/app-shell/spec.md#requirement-native-admin-section-and-version-card
	 */
	public function getIcon(): string {
		return $this->urlGenerator->imagePath(appName: Application::APP_ID, file: 'app-dark.svg');
	}//end getIcon()
}//end class
