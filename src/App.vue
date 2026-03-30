<template>
	<NcContent app-name="doriath">
		<template v-if="storesReady">
			<MainMenu />
			<NcAppContent>
				<router-view />
			</NcAppContent>
		</template>
		<NcAppContent v-else>
			<div style="display: flex; justify-content: center; align-items: center; height: 100%;">
				<NcLoadingIcon :size="64" />
			</div>
		</NcAppContent>
	</NcContent>
</template>

<script>
import { NcContent, NcAppContent, NcLoadingIcon } from '@nextcloud/vue'
import { initializeStores } from './store/store.js'
import MainMenu from './navigation/MainMenu.vue'

export default {
	name: 'App',
	components: {
		NcContent,
		NcAppContent,
		NcLoadingIcon,
		MainMenu,
	},

	data() {
		return {
			storesReady: false,
		}
	},

	async created() {
		await initializeStores()
		this.storesReady = true
	},
}
</script>
