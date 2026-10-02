<?php

/**
 * Keepiq admin area: audit and compliance
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
 * The audit and compliance area: the audit log, compliance reports, SIEM sinks and honey alerts.
 *
 * @psalm-suppress UnusedClass Loaded by Nextcloud from appinfo/info.xml <settings>.
 */
class AuditAdminSettings extends AdminAreaSettings {
	/**
	 * The area key.
	 *
	 * @return string
	 *
	 * @spec openspec/changes/admin-scoped-roles/tasks.md#1.1
	 */
	public function getArea(): string {
		return 'audit';
	}//end getArea()

	/**
	 * The area name on Nextcloud's "Administration privileges" page.
	 *
	 * @return string
	 *
	 * @spec openspec/changes/admin-scoped-roles/tasks.md#1.1
	 */
	public function getName(): string {
		return $this->l10n->t('Audit and compliance');
	}//end getName()

	/**
	 * Position in the section, after the areas listed before it.
	 *
	 * @return int
	 *
	 * @spec openspec/changes/admin-scoped-roles/tasks.md#1.1
	 */
	public function getPriority(): int {
		return 14;
	}//end getPriority()
}//end class
