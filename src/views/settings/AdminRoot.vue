<template>
	<!-- The version card and re-import action belong to the General area
	     only (admin-scoped-roles D3); every other area renders its sections. -->
	<CnAdminSettingsShell
		v-if="area === 'general'"
		appId="keepiq"
		appName="Keepiq"
		@reimported="onReimported">
		<Settings v-if="storesReady" :area="area" />
	</CnAdminSettingsShell>
	<Settings v-else-if="storesReady" :area="area" />
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

	props: {
		/**
		 * The admin area this mount renders: general, policies,
		 * applications, people or audit.
		 */
		area: {
			type: String,
			default: 'general',
		},
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
		 *
		 * @spec exclude Event handler: re-runs store initialisation after the shell re-imports configuration.
		 */
		onReimported() {
			initializeStores()
		},
	},
}
</script>
