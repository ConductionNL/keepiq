/**
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 *
 * The five Keepiq admin areas and the sections each one renders
 * (admin-scoped-roles D1, D3). The server registers one settings class per
 * area and renders the admin template once per area the viewer holds, with
 * a mount element `#keepiq-settings-<key>` and an initial-state flag
 * `area-<key>`. One flag per area, because Nextcloud keeps only the last
 * value of a repeated initial-state key.
 */

/**
 * Area keys in page order, each with the section components it renders.
 * Version and trash retention are Policies (decision of 2 Oct).
 *
 * @type {Array<{key: string, sections: string[]}>}
 */
export const ADMIN_AREAS = [
	{
		key: 'general',
		sections: [
			'AdminAreasSection',
			'CaHealthSection',
			'AttachmentLimitsSection',
			'OfflineCacheSection',
			'BreachCheckSection',
			'DeviceApprovalSection',
			'ItemTypesSection',
			'VaultBackupSection',
			'FederationPartnersSection',
		],
	},
	{
		key: 'policies',
		sections: [
			'PasswordPolicySection',
			'OrgPasswordPolicySection',
			'VaultPolicySection',
			'TeamFolderAutoConfirmSection',
			'RotationPolicySection',
			'RetentionPolicySection',
			'ExtensionSection',
		],
	},
	{
		key: 'applications',
		sections: ['ApplicationQueueSection', 'MachineLeaseSection'],
	},
	{
		key: 'people',
		sections: [
			'MemberOverviewSection',
			'OffboardingSection',
			'AdminSuiteSection',
			'AccountRecoverySection',
		],
	},
	{
		key: 'audit',
		sections: [
			'AdminAuditSection',
			'ComplianceSection',
			'SiemSection',
			'HoneySection',
		],
	},
]

/**
 * The section component names of one area; none for an unknown area.
 *
 * @param {string} key The area key
 * @return {string[]} The section names
 *
 * @spec openspec/changes/archive/2026-10-04-admin-scoped-roles/tasks.md#3.1
 */
export function sectionsOf(key) {
	return ADMIN_AREAS.find((area) => area.key === key)?.sections ?? []
}

/**
 * Mount the admin bundle once for every area the server rendered.
 *
 * @param {object} deps Injected so the decision is testable
 * @param {function(string, string, boolean): boolean} deps.loadState `@nextcloud/initial-state` loadState
 * @param {function(string, string): void} deps.mount Mounts one area at a selector
 * @return {string[]} The area keys that were mounted
 *
 * @spec openspec/changes/archive/2026-10-04-admin-scoped-roles/tasks.md#3.1
 */
export function mountAdminAreas({ loadState, mount }) {
	const mounted = []
	for (const { key } of ADMIN_AREAS) {
		if (loadState('keepiq', 'area-' + key, false) !== true) {
			continue
		}
		mount(key, '#keepiq-settings-' + key)
		mounted.push(key)
	}
	return mounted
}

/**
 * The settings API path of one area. People owns no settings keys.
 *
 * @param {'general'|'policies'|'applications'|'audit'} key The area key
 * @return {string} The path below /apps/keepiq
 *
 * @spec openspec/changes/archive/2026-10-04-admin-scoped-roles/tasks.md#2.1
 */
export function areaSettingsPath(key) {
	return '/apps/keepiq/api/settings/admin/' + key
}
