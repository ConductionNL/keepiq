<!--
  SPDX-License-Identifier: EUPL-1.2
  SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>

  Doriath admin-settings root. Renders the three canonical admin-settings
  sections in order — PasswordPolicy → CaHealth → ApplicationQueue —
  per implement-dashboard-settings #4.4. The register form referenced by
  the legacy AdminSettings template has been removed (legacy admin-IA);
  the application-queue surface here replaces it.

  @spec openspec/changes/implement-dashboard-settings/tasks.md#4.4
  @spec openspec/changes/implement-dashboard-settings/tasks.md#4.5
-->
<template>
	<div class="doriath-settings">
		<PasswordPolicySection />
		<BreachCheckSection />
		<CaHealthSection />
		<ApplicationQueueSection />
		<AdminAuditSection />
	</div>
</template>

<script>
import PasswordPolicySection from '../../components/settings/PasswordPolicySection.vue'
import BreachCheckSection from '../../components/settings/BreachCheckSection.vue'
import CaHealthSection from '../../components/settings/CaHealthSection.vue'
import ApplicationQueueSection from '../../components/settings/ApplicationQueueSection.vue'
import AdminAuditSection from '../../components/settings/AdminAuditSection.vue'

export default {
	name: 'Settings',
	components: {
		PasswordPolicySection,
		BreachCheckSection,
		CaHealthSection,
		ApplicationQueueSection,
		AdminAuditSection,
	},
}
</script>
