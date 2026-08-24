<?php

/**
 * Keepiq Admin Settings
 *
 * AppHost stub floor: Nextcloud instantiates the admin-settings panel by the
 * class name in info.xml `<settings><admin>`, and
 * `#[AuthorizedAdminSetting(AdminSettings::class)]` on the SettingsController
 * mutating methods targets this exact class — so it must physically exist in
 * the Keepiq namespace. All behaviour lives in the engine-owned
 * {@see \OCA\OpenRegister\AppHost\Settings\GenericAdminSettings}, which
 * Bootstrap::register() binds to this class via a factory closure. This subclass
 * carries no logic.
 *
 * @category Settings
 * @package  OCA\Keepiq\Settings
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

namespace OCA\Keepiq\Settings;

use OCA\OpenRegister\AppHost\Settings\GenericAdminSettings;

/**
 * Keepiq admin-settings panel — engine-backed stub (AppHost, ADR-040).
 *
 * @psalm-suppress UnusedClass
 */
class AdminSettings extends GenericAdminSettings {
}//end class
