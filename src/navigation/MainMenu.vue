<template>
	<NcAppNavigation>
		<template #list>
			<NcAppNavigationItem
				:name="t('doriath', 'Dashboard')"
				:to="{ name: 'Dashboard' }"
				:exact="true">
				<template #icon>
					<HomeIcon :size="20" />
				</template>
			</NcAppNavigationItem>
			<NcAppNavigationItem
				:name="t('doriath', 'Documentation')"
				@click="openLink('https://conduction.nl', '_blank')">
				<template #icon>
					<BookOpenVariantOutline :size="20" />
				</template>
			</NcAppNavigationItem>
		</template>
		<template #footer>
			<NcAppNavigationItem
				:name="t('doriath', 'Lock vault')"
				@click="lockVault">
				<template #icon>
					<LockIcon :size="20" />
				</template>
			</NcAppNavigationItem>
			<NcAppNavigationItem
				:name="t('doriath', 'Settings')"
				@click="showUserSettings = true">
				<template #icon>
					<CogIcon :size="20" />
				</template>
			</NcAppNavigationItem>
		</template>
		<UserSettings :open.sync="showUserSettings" />
	</NcAppNavigation>
</template>

<script>
import { NcAppNavigation, NcAppNavigationItem } from '@nextcloud/vue'
import BookOpenVariantOutline from 'vue-material-design-icons/BookOpenVariantOutline.vue'
import CogIcon from 'vue-material-design-icons/Cog.vue'
import HomeIcon from 'vue-material-design-icons/Home.vue'
import LockIcon from 'vue-material-design-icons/Lock.vue'
import UserSettings from '../views/settings/UserSettings.vue'
import { useSessionStore } from '../store/modules/session.js'

export default {
	name: 'MainMenu',
	components: {
		NcAppNavigation,
		NcAppNavigationItem,
		BookOpenVariantOutline,
		CogIcon,
		HomeIcon,
		LockIcon,
		UserSettings,
	},
	data() {
		return {
			showUserSettings: false,
		}
	},
	methods: {
		lockVault() {
			const session = useSessionStore()
			session.lock()
			this.$router.push({ name: 'Lock' })
		},
		openLink(url, target = '_blank') {
			window.open(url, target)
		},
	},
}
</script>
