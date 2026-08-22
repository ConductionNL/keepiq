<template>
	<CnAdminSettingsShell
		appId="keepiq"
		appName="Keepiq"
		@reimported="onReimported">
		<Settings v-if="storesReady" />
	</CnAdminSettingsShell>
</template>

<script>
import { CnAdminSettingsShell } from '@conduction/nextcloud-vue'
import Settings from './Settings.vue'
import { initializeStores } from '../../store/store.js'

export default {
	name: 'AdminRoot',
	components: {
		CnAdminSettingsShell,
		Settings,
	},

	data() {
		return {
			storesReady: false,
		}
	},

	/**
	 * Initialise the Pinia stores backing the admin-settings sections
	 * before rendering them.
	 *
	 * @spec openspec/changes/retrofit-2026-05-25-doriath-coverage/tasks.md#task-8
	 */
	async created() {
		await initializeStores()
		this.storesReady = true
	},

	methods: {
		/**
		 * Re-initialise the stores after the shell's Re-import action
		 * reloads the app configuration.
		 */
		onReimported() {
			initializeStores()
		},
	},
}
</script>
