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
-->
<template>
	<div class="keepiq-settings">
		<PasswordPolicySection />
		<OrgPasswordPolicySection />
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
		<OffboardingSection />
		<AdminAuditSection />
	</div>
</template>

<script>
import AdminAuditSection from '../../components/settings/AdminAuditSection.vue'
import ApplicationQueueSection from '../../components/settings/ApplicationQueueSection.vue'
import AttachmentLimitsSection from '../../components/settings/AttachmentLimitsSection.vue'
import BreachCheckSection from '../../components/settings/BreachCheckSection.vue'
import CaHealthSection from '../../components/settings/CaHealthSection.vue'
import ComplianceSection from '../../components/settings/ComplianceSection.vue'
import HoneySection from '../../components/settings/HoneySection.vue'
import MachineLeaseSection from '../../components/settings/MachineLeaseSection.vue'
import OffboardingSection from '../../components/settings/OffboardingSection.vue'
import OfflineCacheSection from '../../components/settings/OfflineCacheSection.vue'
import OrgPasswordPolicySection from '../../components/settings/OrgPasswordPolicySection.vue'
import PasswordPolicySection from '../../components/settings/PasswordPolicySection.vue'
import RotationPolicySection from '../../components/settings/RotationPolicySection.vue'
import SiemSection from '../../components/settings/SiemSection.vue'

export default {
	name: 'Settings',
	components: {
		PasswordPolicySection,
		OrgPasswordPolicySection,
		BreachCheckSection,
		CaHealthSection,
		ApplicationQueueSection,
		AttachmentLimitsSection,
		RotationPolicySection,
		MachineLeaseSection,
		ComplianceSection,
		SiemSection,
		HoneySection,
		OfflineCacheSection,
		OffboardingSection,
		AdminAuditSection,
	},
}
</script>
