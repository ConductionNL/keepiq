<!--
  SPDX-License-Identifier: EUPL-1.2
  SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>

  Keepiq admin-settings root. Renders the three canonical admin-settings
  sections in order — PasswordPolicy → CaHealth → ApplicationQueue —
  per implement-dashboard-settings #4.4. The register form referenced by
  the legacy AdminSettings template has been removed (legacy admin-IA);
  the application-queue surface here replaces it.

  @spec openspec/changes/implement-dashboard-settings/tasks.md#4.4
  @spec openspec/changes/implement-dashboard-settings/tasks.md#4.5
  @spec openspec/changes/admin-vault-policies/tasks.md#1.3
  @spec openspec/changes/admin-scheduled-vault-backups/tasks.md#4.1
  @spec openspec/changes/admin-auto-confirm-members/tasks.md#1.2
-->
<template>
	<div class="keepiq-settings">
		<PasswordPolicySection />
		<OrgPasswordPolicySection />
		<VaultPolicySection />
		<TeamFolderAutoConfirmSection />
		<BreachCheckSection />
		<CaHealthSection />
		<ApplicationQueueSection />
		<AttachmentLimitsSection />
		<RotationPolicySection />
		<MachineLeaseSection />
		<ComplianceSection />
		<SiemSection />
		<HoneySection />
		<OfflineCacheSection />
		<DeviceApprovalSection />
		<AccountRecoverySection />
		<ExtensionSection />
		<ItemTypesSection />
		<OffboardingSection />
		<AdminSuiteSection />
		<AdminAuditSection />
		<VaultBackupSection />
	</div>
</template>

<script>
import AccountRecoverySection from '../../components/settings/AccountRecoverySection.vue'
import AdminAuditSection from '../../components/settings/AdminAuditSection.vue'
import AdminSuiteSection from '../../components/settings/AdminSuiteSection.vue'
import ApplicationQueueSection from '../../components/settings/ApplicationQueueSection.vue'
import AttachmentLimitsSection from '../../components/settings/AttachmentLimitsSection.vue'
import BreachCheckSection from '../../components/settings/BreachCheckSection.vue'
import CaHealthSection from '../../components/settings/CaHealthSection.vue'
import ComplianceSection from '../../components/settings/ComplianceSection.vue'
import DeviceApprovalSection from '../../components/settings/DeviceApprovalSection.vue'
import ExtensionSection from '../../components/settings/ExtensionSection.vue'
import HoneySection from '../../components/settings/HoneySection.vue'
import ItemTypesSection from '../../components/settings/ItemTypesSection.vue'
import MachineLeaseSection from '../../components/settings/MachineLeaseSection.vue'
import OffboardingSection from '../../components/settings/OffboardingSection.vue'
import OfflineCacheSection from '../../components/settings/OfflineCacheSection.vue'
import OrgPasswordPolicySection from '../../components/settings/OrgPasswordPolicySection.vue'
import PasswordPolicySection from '../../components/settings/PasswordPolicySection.vue'
import RotationPolicySection from '../../components/settings/RotationPolicySection.vue'
import SiemSection from '../../components/settings/SiemSection.vue'
import TeamFolderAutoConfirmSection from '../../components/settings/TeamFolderAutoConfirmSection.vue'
import VaultBackupSection from '../../components/settings/VaultBackupSection.vue'
import VaultPolicySection from '../../components/settings/VaultPolicySection.vue'

export default {
	name: 'Settings',
	components: {
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
		OffboardingSection,
		AdminSuiteSection,
		AdminAuditSection,
		VaultPolicySection,
		VaultBackupSection,
	},
}
</script>
