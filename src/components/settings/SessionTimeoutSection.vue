<template>
	<CnSettingsSection
		:name="t('keepiq', 'Session')"
		:description="t('keepiq', 'Vault session timeout for this account')">
		<div class="session-timeout">
			<label for="session-timeout-select">{{
				t('keepiq', 'Lock vault after')
			}}</label>
			<select
				id="session-timeout-select"
				v-model="sessionTimeout"
				@change="save">
				<option value="session">
					{{ t('keepiq', 'Nextcloud session') }}
				</option>
				<option value="10min">
					{{ t('keepiq', '10 minutes') }}
				</option>
				<option value="30min">
					{{ t('keepiq', '30 minutes') }}
				</option>
			</select>
		</div>
	</CnSettingsSection>
</template>

<script>
import { CnSettingsSection } from '@conduction/nextcloud-vue'
import axios from '@nextcloud/axios'
import { generateUrl } from '@nextcloud/router'

export default {
	name: 'SessionTimeoutSection',
	components: { CnSettingsSection },

	data() {
		return {
			sessionTimeout: 'session',
		}
	},

	/**
	 * Load the current user's session_timeout preference.
	 *
	 * @spec openspec/changes/implement-dashboard-settings/tasks.md#task-5.1
	 */
	async created() {
		try {
			const response = await axios.get(
				generateUrl('/apps/keepiq/api/settings/user'),
			)
			this.sessionTimeout = response.data.session_timeout || 'session'
		} catch (e) {
			console.warn('Keepiq: failed to load user settings', e)
		}
	},

	methods: {
		/**
		 * Persist the session_timeout preference.
		 *
		 * @spec openspec/changes/implement-dashboard-settings/tasks.md#task-5.1
		 */
		async save() {
			await axios.put(generateUrl('/apps/keepiq/api/settings/user'), {
				session_timeout: this.sessionTimeout,
			})
		},
	},
}
</script>

<style scoped>
.session-timeout label {
	display: block;
	font-weight: 600;
	margin-bottom: 4px;
}
</style>
