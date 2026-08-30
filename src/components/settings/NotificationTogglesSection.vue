<template>
	<CnSettingsSection
		:name="t('keepiq', 'Notifications')"
		:description="
			t('keepiq', 'Choose which Keepiq events you receive notifications for')
		">
		<div class="notification-toggles">
			<div
				v-for="toggle in toggles"
				:key="toggle.key"
				class="notification-toggles__row">
				<label :for="`notify-${toggle.key}`">
					<input
						:id="`notify-${toggle.key}`"
						v-model="prefs[toggle.key]"
						type="checkbox"
						@change="save(toggle.key)" />
					{{ toggle.label }}
				</label>
			</div>
		</div>
	</CnSettingsSection>
</template>

<script>
import { CnSettingsSection } from '@conduction/nextcloud-vue'
import axios from '@nextcloud/axios'
import { generateUrl } from '@nextcloud/router'

export default {
	name: 'NotificationTogglesSection',
	components: { CnSettingsSection },

	data() {
		return {
			prefs: {
				notify_shares: true,
				notify_requests: true,
				notify_group_shares: true,
				notify_security: true,
			},
		}
	},

	computed: {
		/**
		 * The four notification categories the user can opt out of, paired
		 * with their translated labels.
		 *
		 * @return {Array<{key: string, label: string}>}
		 * @spec openspec/specs/user-settings/spec.md#requirement-notification-toggles-mvp
		 */
		toggles() {
			return [
				{
					key: 'notify_shares',
					label: t('keepiq', 'A secret is shared with me'),
				},
				{
					key: 'notify_requests',
					label: t('keepiq', 'A secret request is fulfilled or expires'),
				},
				{
					key: 'notify_group_shares',
					label: t('keepiq', 'A group share affects me'),
				},
				{
					key: 'notify_security',
					label: t('keepiq', 'Security event (compromise, revocation)'),
				},
			]
		},
	},

	/**
	 * Load the current user's notification preferences.
	 *
	 * @spec openspec/changes/implement-dashboard-settings/tasks.md#task-5.2
	 */
	async created() {
		try {
			const response = await axios.get(
				generateUrl('/apps/keepiq/api/settings/user'),
			)
			for (const key of Object.keys(this.prefs)) {
				if (response.data[key] !== undefined) {
					this.prefs[key] =
						response.data[key] === '1' || response.data[key] === true
				}
			}
		} catch (e) {
			console.warn('Keepiq: failed to load notification prefs', e)
		}
	},

	methods: {
		/**
		 * Persist a single notification preference.
		 *
		 * @param {string} key Preference key
		 *
		 * @spec openspec/changes/implement-dashboard-settings/tasks.md#task-5.2
		 */
		async save(key) {
			await axios.put(generateUrl('/apps/keepiq/api/settings/user'), {
				[key]: this.prefs[key] ? '1' : '0',
			})
		},
	},
}
</script>

<style scoped>
.notification-toggles__row {
	margin: 0.5rem 0;
}
</style>
