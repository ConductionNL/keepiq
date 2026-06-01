<!--
  SPDX-License-Identifier: EUPL-1.2
  SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>

  SessionTimeoutSection — user-settings dialog section for the
  per-user session-timeout preference (Nextcloud session / 10 min /
  30 min). Persists via the settings store (PUT /api/settings/user) and
  applies the chosen interval to the live session store so the in-memory
  CryptoKey clears on the new cadence.

  @spec openspec/changes/implement-dashboard-settings/specs/user-settings/spec.md
-->
<template>
	<NcAppSettingsSection
		id="session"
		:name="t('doriath', 'Session')">
		<template #icon>
			<TimerIcon :size="20" />
		</template>
		<div class="user-settings__field">
			<NcSelect
				v-model="sessionTimeout"
				:options="timeoutOptions"
				:input-label="t('doriath', 'Session timeout')"
				label="label"
				:reduce="opt => opt.value"
				:clearable="false"
				@input="save" />
		</div>
	</NcAppSettingsSection>
</template>

<script>
import { NcAppSettingsSection, NcSelect } from '@nextcloud/vue'
import { translate as ncT } from '@nextcloud/l10n'
import TimerIcon from 'vue-material-design-icons/Timer.vue'
import { useSettingsStore } from '../../store/modules/settings.js'
import { useSessionStore } from '../../store/modules/session.js'

export default {
	name: 'SessionTimeoutSection',

	components: { NcAppSettingsSection, NcSelect, TimerIcon },

	data() {
		return {
			sessionTimeout: 'session',
			timeoutOptions: [
				{ value: 'session', label: ncT('doriath', 'Nextcloud session') },
				{ value: '10min', label: ncT('doriath', '10 minutes') },
				{ value: '30min', label: ncT('doriath', '30 minutes') },
			],
		}
	},

	/**
	 * Load the persisted session-timeout preference from the user
	 * settings API and reflect it in the dropdown.
	 *
	 * @spec openspec/changes/implement-dashboard-settings/specs/user-settings/spec.md
	 */
	async created() {
		const store = useSettingsStore()
		await store.fetchUserPreferences()
		this.sessionTimeout = store.sessionTimeout
		this.applyToSession()
	},

	methods: {
		/**
		 * Persist the chosen timeout and apply it to the live session.
		 *
		 * @spec openspec/changes/implement-dashboard-settings/specs/user-settings/spec.md
		 */
		async save() {
			await useSettingsStore().saveUserPreferences({ session_timeout: this.sessionTimeout })
			this.applyToSession()
		},

		/**
		 * Map the timeout enum to a millisecond interval on the session
		 * store so the inactivity-clear cadence updates immediately.
		 *
		 * @spec openspec/changes/implement-dashboard-settings/specs/user-settings/spec.md
		 */
		applyToSession() {
			const timeouts = { session: 0, '10min': 600000, '30min': 1800000 }
			useSessionStore().timeout = timeouts[this.sessionTimeout] ?? 0
		},
	},
}
</script>

<style scoped>
.user-settings__field {
	margin-bottom: 1rem;
}
</style>
