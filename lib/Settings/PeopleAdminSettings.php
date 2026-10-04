<?php

/**
 * Keepiq admin area: people and offboarding
 *
 * One of the five delegable Keepiq admin areas (admin-scoped-roles D1). A
 * Nextcloud group delegated this class on the "Administration privileges"
 * page holds the area; every endpoint of the area names this class in its
 * `#[AuthorizedAdminSetting]` guard or in `AdminAreaAuthorizer::holds()`.
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

/**
 * The people and offboarding area: members, team offboarding, encryption suites and admin handover.
 *
 * @psalm-suppress UnusedClass Loaded by Nextcloud from appinfo/info.xml <settings>.
 */
class PeopleAdminSettings extends AdminAreaSettings {
	/**
	 * The area key.
	 *
	 * @return string
	 *
	 * @spec openspec/changes/archive/2026-10-04-admin-scoped-roles/tasks.md#1.1
	 */
	public function getArea(): string {
		return 'people';
	}//end getArea()

	/**
	 * The area name on Nextcloud's "Administration privileges" page.
	 *
	 * @return string
	 *
	 * @spec openspec/changes/archive/2026-10-04-admin-scoped-roles/tasks.md#1.1
	 */
	public function getName(): string {
		return $this->l10n->t('People and offboarding');
	}//end getName()

	/**
	 * Position in the section, after the areas listed before it.
	 *
	 * @return int
	 *
	 * @spec openspec/changes/archive/2026-10-04-admin-scoped-roles/tasks.md#1.1
	 */
	public function getPriority(): int {
		return 13;
	}//end getPriority()
}//end class
