<!--
  SPDX-License-Identifier: EUPL-1.2
  SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>

  ApplicationQueueSection — admin settings section listing pending
  application registrations with approve / reject actions.

  The Application entity, ApplicationMapper, and ApplicationController
  ship with the implement-secret-requests leaf change, which is not yet
  built. Until it lands this section renders the empty-state contract
  from the spec ("No pending applications"); the approve / reject wiring
  and the pending-list fetch attach to the clearly-marked slots below
  once the ApplicationController endpoints exist.

  @spec openspec/changes/implement-dashboard-settings/specs/admin-settings/spec.md
-->
<template>
	<CnSettingsSection
		:name="t('doriath', 'Applications')"
		:description="t('doriath', 'Approve or reject application registration requests')">
		<NcEmptyContent
			v-if="pendingApplications.length === 0"
			:name="t('doriath', 'No pending applications')">
			<template #icon>
				<AppsIcon :size="48" />
			</template>
		</NcEmptyContent>

		<ul v-else class="application-queue">
			<li
				v-for="app in pendingApplications"
				:key="app.id"
				class="application-queue__item">
				<div class="application-queue__info">
					<strong>{{ app.name }}</strong>
					<p>{{ app.description }}</p>
					<span class="application-queue__date">{{ app.created_at }}</span>
				</div>
				<div class="application-queue__actions">
					<NcButton type="primary" @click="approve(app)">
						{{ t('doriath', 'Approve') }}
					</NcButton>
					<NcButton type="secondary" @click="reject(app)">
						{{ t('doriath', 'Reject') }}
					</NcButton>
				</div>
			</li>
		</ul>
	</CnSettingsSection>
</template>

<script>
import { NcButton, NcEmptyContent } from '@nextcloud/vue'
import { CnSettingsSection } from '@conduction/nextcloud-vue'
import AppsIcon from 'vue-material-design-icons/Apps.vue'

export default {
	name: 'ApplicationQueueSection',

	components: { NcButton, NcEmptyContent, CnSettingsSection, AppsIcon },

	data() {
		return {
			// Populated from the ApplicationController list endpoint once
			// implement-secret-requests ships; empty until then.
			pendingApplications: [],
		}
	},

	methods: {
		/**
		 * Approve a pending application. Wires to the ApplicationController
		 * approve endpoint once the secret-requests leaf change ships.
		 *
		 * @param {object} app The application to approve.
		 * @spec openspec/changes/implement-dashboard-settings/specs/admin-settings/spec.md
		 */
		approve(app) {
			this.pendingApplications = this.pendingApplications.filter((a) => a.id !== app.id)
		},

		/**
		 * Reject a pending application. Wires to the ApplicationController
		 * reject endpoint once the secret-requests leaf change ships.
		 *
		 * @param {object} app The application to reject.
		 * @spec openspec/changes/implement-dashboard-settings/specs/admin-settings/spec.md
		 */
		reject(app) {
			this.pendingApplications = this.pendingApplications.filter((a) => a.id !== app.id)
		},
	},
}
</script>

<style scoped>
.application-queue {
	list-style: none;
	padding: 0;
	margin: 0;
}

.application-queue__item {
	display: flex;
	justify-content: space-between;
	align-items: center;
	gap: 1rem;
	padding: 0.75rem 0;
	border-bottom: 1px solid var(--color-border);
}

.application-queue__date {
	color: var(--color-text-lighter);
	font-size: 0.85rem;
}

.application-queue__actions {
	display: flex;
	gap: 0.5rem;
}
</style>
