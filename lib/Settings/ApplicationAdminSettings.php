<?php

/**
 * Keepiq admin area: applications and machine access
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
 * The applications and machine access area: the application queue, application requests and machine leases.
 *
 * @psalm-suppress UnusedClass Loaded by Nextcloud from appinfo/info.xml <settings>.
 */
class ApplicationAdminSettings extends AdminAreaSettings {
	/**
	 * The area key.
	 *
	 * @return string
	 *
	 * @spec openspec/changes/archive/2026-10-04-admin-scoped-roles/tasks.md#1.1
	 */
	public function getArea(): string {
		return 'applications';
	}//end getArea()

	/**
	 * The area name on Nextcloud's "Administration privileges" page.
	 *
	 * @return string
	 *
	 * @spec openspec/changes/archive/2026-10-04-admin-scoped-roles/tasks.md#1.1
	 */
	public function getName(): string {
		return $this->l10n->t('Applications and machine access');
	}//end getName()

	/**
	 * Position in the section, after the areas listed before it.
	 *
	 * @return int
	 *
	 * @spec openspec/changes/archive/2026-10-04-admin-scoped-roles/tasks.md#1.1
	 */
	public function getPriority(): int {
		return 12;
	}//end getPriority()
}//end class
