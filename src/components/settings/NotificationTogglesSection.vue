<!--
  SPDX-License-Identifier: EUPL-1.2
  SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>

  NotificationTogglesSection — user-settings dialog section for the
  notification-category toggles. MVP ships the share + request toggles;
  the group-share and security toggles (V1) land with the
  implement-user-sharing NotificationService SUBJECT_SETTING_MAP and are
  intentionally not rendered yet. Each toggle persists immediately via
  the settings store (PUT /api/settings/user).

  @spec openspec/changes/implement-dashboard-settings/specs/user-settings/spec.md
-->
<template>
	<NcAppSettingsSection
		id="notifications"
		:name="t('doriath', 'Notifications')">
		<template #icon>
			<BellIcon :size="20" />
		</template>
		<NcCheckboxRadioSwitch
			:checked.sync="notifyShares"
			type="switch"
			@update:checked="save('notify_shares', $event)">
			{{ t('doriath', 'Share notifications') }}
		</NcCheckboxRadioSwitch>
		<NcCheckboxRadioSwitch
			:checked.sync="notifyRequests"
			type="switch"
			@update:checked="save('notify_requests', $event)">
			{{ t('doriath', 'Request notifications') }}
		</NcCheckboxRadioSwitch>
	</NcAppSettingsSection>
</template>

<script>
import { NcAppSettingsSection, NcCheckboxRadioSwitch } from '@nextcloud/vue'
import BellIcon from 'vue-material-design-icons/Bell.vue'
import { useSettingsStore } from '../../store/modules/settings.js'

export default {
	name: 'NotificationTogglesSection',

	components: { NcAppSettingsSection, NcCheckboxRadioSwitch, BellIcon },

	data() {
		return {
			notifyShares: true,
			notifyRequests: true,
		}
	},

	/**
	 * Load the persisted notification toggles from the user settings API.
	 *
	 * @spec openspec/changes/implement-dashboard-settings/specs/user-settings/spec.md
	 */
	async created() {
		const store = useSettingsStore()
		await store.fetchUserPreferences()
		const toggles = store.notificationToggles
		this.notifyShares = toggles.notify_shares
		this.notifyRequests = toggles.notify_requests
	},

	methods: {
		/**
		 * Persist a single toggle change to the user settings API.
		 *
		 * @param {string} key   The preference key (notify_shares|notify_requests).
		 * @param {boolean} value The new toggle value.
		 * @spec openspec/changes/implement-dashboard-settings/specs/user-settings/spec.md
		 */
		async save(key, value) {
			await useSettingsStore().saveUserPreferences({ [key]: value })
		},
	},
}
</script>
