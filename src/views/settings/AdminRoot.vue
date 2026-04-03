<template>
	<div class="doriath-admin">
		<CnVersionInfoCard
			:app-name="'Doriath'"
			:app-version="appVersion"
			:is-up-to-date="true"
			:show-update-button="true"
			:title="t('doriath', 'Version Information')"
			:description="t('doriath', 'Information about the current Doriath installation')">
			<template #footer>
				<div class="cn-support-info">
					<h4>{{ t('doriath', 'Support') }}</h4>
					<p>{{ t('doriath', 'For support, contact us at') }} <a href="mailto:support@conduction.nl">support@conduction.nl</a></p>
				</div>
			</template>
		</CnVersionInfoCard>

		<Settings v-if="storesReady" />
	</div>
</template>

<script>
import { CnVersionInfoCard } from '@conduction/nextcloud-vue'
import Settings from './Settings.vue'
import { loadState } from '@nextcloud/initial-state'
import { initializeStores } from '../../store/store.js'

export default {
	name: 'AdminRoot',
	components: {
		CnVersionInfoCard,
		Settings,
	},
	data() {
		return {
			storesReady: false,
			appVersion: loadState('doriath', 'version', 'Unknown'),
		}
	},
	async created() {
		await initializeStores()
		this.storesReady = true
	},
}
</script>

<style scoped>
.doriath-admin {
	max-width: 900px;
}
</style>
