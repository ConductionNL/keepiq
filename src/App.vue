<template>
	<NcContent app-name="doriath">
		<template v-if="storesReady">
			<MainMenu v-if="!isLocked" />
			<NcAppContent>
				<router-view />
			</NcAppContent>
			<SecretSidebar v-if="!isLocked" />
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
import { useSessionStore } from './store/modules/session.js'
import MainMenu from './navigation/MainMenu.vue'
import SecretSidebar from './components/SecretSidebar.vue'

export default {
	name: 'App',
	components: {
		NcContent,
		NcAppContent,
		NcLoadingIcon,
		MainMenu,
		SecretSidebar,
	},

	data() {
		return {
			storesReady: false,
			timeoutInterval: null,
		}
	},

	computed: {
		isLocked() {
			const session = useSessionStore()
			return session.isLocked
		},
	},

	async created() {
		await initializeStores()
		this.storesReady = true

		// Now that Pinia is ready, check if we need the lock screen.
		// The router guard may have let us through on the initial navigation
		// because Pinia wasn't active yet.
		const session = useSessionStore()
		if (session.isLocked && this.$route.name !== 'Lock') {
			this.$router.replace({
				name: 'Lock',
				query: { returnUrl: this.$route.fullPath },
			})
		}

		// Session timeout check every 10 seconds.
		this.timeoutInterval = setInterval(() => {
			const session = useSessionStore()
			const wasLocked = session.isLocked
			session.checkTimeout()
			if (!wasLocked && session.isLocked && this.$route.name !== 'Lock') {
				this.$router.replace({
					name: 'Lock',
					query: { returnUrl: this.$route.fullPath },
				})
			}
		}, 10000)

		// Check timeout when tab becomes visible.
		document.addEventListener('visibilitychange', this.handleVisibilityChange)

		// Best-effort key clear on tab close.
		window.addEventListener('beforeunload', this.handleBeforeUnload)
	},

	beforeDestroy() {
		if (this.timeoutInterval) {
			clearInterval(this.timeoutInterval)
		}
		document.removeEventListener('visibilitychange', this.handleVisibilityChange)
		window.removeEventListener('beforeunload', this.handleBeforeUnload)
	},

	methods: {
		handleVisibilityChange() {
			if (document.visibilityState === 'visible') {
				const session = useSessionStore()
				const wasLocked = session.isLocked
				session.checkTimeout()
				if (!wasLocked && session.isLocked && this.$route.name !== 'Lock') {
					this.$router.replace({
						name: 'Lock',
						query: { returnUrl: this.$route.fullPath },
					})
				}
			}
		},

		handleBeforeUnload() {
			const session = useSessionStore()
			session.lock()
		},
	},
}
</script>
