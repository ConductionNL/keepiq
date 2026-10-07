<template>
	<!-- The version card belongs to the General area only (admin-scoped-roles
	     D3); every other area renders its sections. There is no re-import:
	     Keepiq imports no configuration from another app (ADR-006). -->
	<CnAdminSettingsShell
		v-if="area === 'general'"
		appId="keepiq"
		appName="Keepiq"
		:showReimport="false">
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
}
</script>
