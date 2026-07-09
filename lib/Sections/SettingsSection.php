<?php

/**
 * Doriath Settings Section
 *
 * AppHost stub floor: Nextcloud instantiates the admin-settings section by the
 * class name in info.xml `<settings><admin-section>`, so it must physically
 * exist in the Doriath namespace. All behaviour lives in the engine-owned
 * {@see \OCA\OpenRegister\AppHost\Settings\GenericSettingsSection}, which
 * Bootstrap::register() binds to this class via a factory closure (section id
 * `doriath`, name `Doriath`, icon `app-dark.svg`, priority 75 — unchanged from
 * the bespoke section). This subclass carries no logic.
 *
 * @category Sections
 * @package  OCA\Doriath\Sections
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

namespace OCA\Doriath\Sections;

use OCA\OpenRegister\AppHost\Settings\GenericSettingsSection;

/**
 * Doriath admin-settings section — engine-backed stub (AppHost, ADR-040).
 *
 * @psalm-suppress UnusedClass
 */
class SettingsSection extends GenericSettingsSection
{
}//end class
