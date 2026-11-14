<!-- SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl> -->
<!-- SPDX-License-Identifier: EUPL-1.2 -->
<!--
  - AdminFlowTriggersSection: the Flows section of the audit administration
  - (board KqBeheerAudit). One switch per trigger group decides which vault
  - events may start an OpenRegister flow; every group starts off. Shown only
  - when OpenRegister is enabled, because without it nothing can be started.
  -
  - @spec openspec/changes/clients-vault-event-flow-triggers/specs/vault-event-flow-triggers/spec.md#requirement-administrators-choose-which-event-groups-start-flows
  -->
<template>
	<CnSettingsSection
		v-if="openRegisterEnabled"
		:name="t('keepiq', 'Flows')"
		:description="t('keepiq', 'Flows receive who did what and when, never a secret.')"
		data-testid="flow-triggers">
		<p class="flow-triggers__intro">
			{{ t('keepiq', 'Choose which vault events may start a flow. Every group starts switched off.') }}
		</p>
		<ul class="flow-triggers__groups">
			<li v-for="group in groups" :key="group.key">
				<NcCheckboxRadioSwitch
					type="switch"
					:modelValue="enabled.includes(group.key)"
					:disabled="saving"
					:data-testid="'flow-group-' + group.key"
					@update:modelValue="toggle(group.key, $event)">
					{{ group.label }}
				</NcCheckboxRadioSwitch>
			</li>
		</ul>
		<p v-if="error" class="flow-triggers__error" role="alert" data-testid="flow-triggers-error">
			{{ error }}
		</p>
	</CnSettingsSection>
</template>

<script>
import { CnSettingsSection } from '@conduction/nextcloud-vue'
import axios from '@nextcloud/axios'
import { generateUrl } from '@nextcloud/router'
import { NcCheckboxRadioSwitch } from '@nextcloud/vue'
import { currentAppsWebRoots, isAppEnabled } from '../../utils/navEntries.js'

/** The trigger groups, in the order the server stores them. */
export const FLOW_TRIGGER_GROUPS = [
	'items',
	'item_reads',
	'sharing',
	'links',
	'requests',
	'emergency_access',
	'applications',
	'keys',
]

const AUDIT_SETTINGS = '/apps/keepiq/api/settings/admin/audit'

export default {
	name: 'AdminFlowTriggersSection',

	components: { CnSettingsSection, NcCheckboxRadioSwitch },

	data() {
		return {
			enabled: [],
			saving: false,
			error: '',
			openRegisterEnabled: isAppEnabled('openregister', currentAppsWebRoots()),
		}
	},

	computed: {
		/**
		 * The groups with their labels.
		 *
		 * @return {Array<{key: string, label: string}>}
		 */
		groups() {
			const labels = {
				items: t('keepiq', 'Items'),
				item_reads: t('keepiq', 'Item reads'),
				sharing: t('keepiq', 'Sharing'),
				links: t('keepiq', 'Links'),
				requests: t('keepiq', 'Requests'),
				emergency_access: t('keepiq', 'Emergency access'),
				applications: t('keepiq', 'Applications'),
				keys: t('keepiq', 'Keys'),
			}
			return FLOW_TRIGGER_GROUPS.map((key) => ({ key, label: labels[key] }))
		},
	},

	async created() {
		if (this.openRegisterEnabled) {
			await this.load()
		}
	},

	methods: {
		/**
		 * Load the enabled groups from the audit area settings.
		 *
		 * @return {Promise<void>}
		 * @spec openspec/changes/clients-vault-event-flow-triggers/tasks.md#task-2.2
		 */
		async load() {
			try {
				const response = await axios.get(generateUrl(AUDIT_SETTINGS))
				const stored = response.data?.flow_trigger_groups
				this.enabled = Array.isArray(stored) ? stored : []
			} catch {
				this.enabled = []
			}
		},

		/**
		 * Switch one group on or off and save the whole list.
		 *
		 * @param {string} key The group
		 * @param {boolean} on Whether it is switched on
		 * @return {Promise<void>}
		 * @spec openspec/changes/clients-vault-event-flow-triggers/tasks.md#task-2.2
		 */
		async toggle(key, on) {
			const wanted = new Set(this.enabled)
			if (on) {
				wanted.add(key)
			} else {
				wanted.delete(key)
			}
			const next = FLOW_TRIGGER_GROUPS.filter((g) => wanted.has(g))
			this.saving = true
			this.error = ''
			try {
				const response = await axios.put(generateUrl(AUDIT_SETTINGS), { flow_trigger_groups: next })
				const saved = response.data?.flow_trigger_groups
				this.enabled = Array.isArray(saved) ? saved : next
			} catch (e) {
				this.error = e?.response?.data?.message || t('keepiq', 'The flow settings could not be saved.')
				await this.load()
			} finally {
				this.saving = false
			}
		},
	},
}
</script>

<style scoped>
.flow-triggers__intro {
	margin-bottom: 8px;
	color: var(--color-text-maxcontrast);
}

.flow-triggers__groups {
	display: flex;
	flex-direction: column;
	gap: 4px;
}

.flow-triggers__error {
	color: var(--color-error-text, var(--color-error));
}
</style>
