<!--
  SPDX-License-Identifier: EUPL-1.2
  SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>

  Keepiq admin-settings sections of ONE admin area (admin-scoped-roles D3).
  Nextcloud mounts the bundle once per area the viewer holds, so a
  delegated admin sees only the sections of their areas. The area-to-section
  table lives in adminAreas.js.

  @spec openspec/changes/implement-dashboard-settings/tasks.md#4.4
  @spec openspec/changes/implement-dashboard-settings/tasks.md#4.5
  @spec openspec/specs/vault-policies/spec.md#requirement-administrator-configures-vault-policies-per-group
  @spec openspec/specs/team-folder-auto-confirm/spec.md#requirement-administrator-switches-automatic-member-confirmation-on
  @spec openspec/specs/vault-backups/spec.md#requirement-administrator-schedules-vault-backups
  @spec openspec/changes/archive/2026-10-04-admin-scoped-roles/tasks.md#3.1
-->
<template>
	<div class="keepiq-settings" :class="['keepiq-settings--' + area]">
		<component :is="section" v-for="section in sections" :key="section" />
	</div>
</template>

<script>
import AccountRecoverySection from '../../components/settings/AccountRecoverySection.vue'
import AdminAreasSection from '../../components/settings/AdminAreasSection.vue'
import AdminAuditSection from '../../components/settings/AdminAuditSection.vue'
import AdminSuiteSection from '../../components/settings/AdminSuiteSection.vue'
import ApplicationQueueSection from '../../components/settings/ApplicationQueueSection.vue'
import AttachmentLimitsSection from '../../components/settings/AttachmentLimitsSection.vue'
import BreachCheckSection from '../../components/settings/BreachCheckSection.vue'
import CaHealthSection from '../../components/settings/CaHealthSection.vue'
import ComplianceSection from '../../components/settings/ComplianceSection.vue'
import DeviceApprovalSection from '../../components/settings/DeviceApprovalSection.vue'
import ExtensionSection from '../../components/settings/ExtensionSection.vue'
import FederationPartnersSection from '../../components/settings/FederationPartnersSection.vue'
import HoneySection from '../../components/settings/HoneySection.vue'
import ItemTypesSection from '../../components/settings/ItemTypesSection.vue'
import MachineLeaseSection from '../../components/settings/MachineLeaseSection.vue'
import MemberOverviewSection from '../../components/settings/MemberOverviewSection.vue'
import OffboardingSection from '../../components/settings/OffboardingSection.vue'
import OfflineCacheSection from '../../components/settings/OfflineCacheSection.vue'
import OrgPasswordPolicySection from '../../components/settings/OrgPasswordPolicySection.vue'
import PasswordPolicySection from '../../components/settings/PasswordPolicySection.vue'
import RetentionPolicySection from '../../components/settings/RetentionPolicySection.vue'
import RotationPolicySection from '../../components/settings/RotationPolicySection.vue'
import SiemSection from '../../components/settings/SiemSection.vue'
import TeamFolderAutoConfirmSection from '../../components/settings/TeamFolderAutoConfirmSection.vue'
import VaultBackupSection from '../../components/settings/VaultBackupSection.vue'
import VaultPolicySection from '../../components/settings/VaultPolicySection.vue'
import { sectionsOf } from './adminAreas.js'

export default {
	name: 'Settings',
	components: {
		AdminAreasSection,
		RetentionPolicySection,
		PasswordPolicySection,
		OrgPasswordPolicySection,
		TeamFolderAutoConfirmSection,
		BreachCheckSection,
		CaHealthSection,
		ApplicationQueueSection,
		AttachmentLimitsSection,
		RotationPolicySection,
		MachineLeaseSection,
		ComplianceSection,
		SiemSection,
		HoneySection,
		ItemTypesSection,
		OfflineCacheSection,
		DeviceApprovalSection,
		AccountRecoverySection,
		ExtensionSection,
		FederationPartnersSection,
		MemberOverviewSection,
		OffboardingSection,
		AdminSuiteSection,
		AdminAuditSection,
		VaultPolicySection,
		VaultBackupSection,
	},

	props: {
		/**
		 * The admin area whose sections to render.
		 */
		area: {
			type: String,
			default: 'general',
		},
	},

	computed: {
		/**
		 * The section components of this area, in page order.
		 *
		 * @return {string[]} Component names
		 * @spec openspec/changes/archive/2026-10-04-admin-scoped-roles/tasks.md#3.1
		 */
		sections() {
			return sectionsOf(this.area)
		},
	},
}
</script>
