<template>
	<NcAppSettingsDialog
		:open="open"
		:show-navigation="true"
		:name="t('doriath', 'Doriath settings')"
		@update:open="$emit('update:open', $event)">
		<NcAppSettingsSection
			id="session"
			:name="t('doriath', 'Session')">
			<template #icon>
				<TimerIcon :size="20" />
			</template>
			<div class="user-settings__field">
				<label for="session-timeout">{{ t('doriath', 'Session timeout') }}</label>
				<NcSelect
					v-model="sessionTimeout"
					:options="timeoutOptions"
					label="label"
					:reduce="opt => opt.value"
					@input="saveTimeout" />
			</div>
		</NcAppSettingsSection>

		<NcAppSettingsSection
			id="security"
			:name="t('doriath', 'Security')">
			<template #icon>
				<ShieldIcon :size="20" />
			</template>
			<div class="user-settings__field">
				<MasterPasswordForm />
			</div>
			<div class="user-settings__field">
				<NcButton type="error" @click="showRecovery = !showRecovery">
					{{ t('doriath', 'My master password was compromised') }}
				</NcButton>
				<CompromiseRecoveryForm v-if="showRecovery" @recovery-started="$emit('update:open', false)" />
			</div>
		</NcAppSettingsSection>

		<NcAppSettingsSection
			id="encryption"
			:name="t('doriath', 'Encryption')">
			<template #icon>
				<KeyIcon :size="20" />
			</template>
			<div v-if="suiteStore.currentSuite" class="user-settings__suite-info">
				<p><strong>{{ t('doriath', 'Status') }}:</strong> {{ suiteStore.currentSuite.status }}</p>
				<p><strong>{{ t('doriath', 'Created') }}:</strong> {{ suiteStore.currentSuite.createdAt }}</p>
			</div>
			<NcEmptyContent
				v-else
				:name="t('doriath', 'No encryption suite')"
				:description="t('doriath', 'Unlock the vault to set up encryption')">
				<template #icon>
					<KeyIcon :size="64" />
				</template>
			</NcEmptyContent>
		</NcAppSettingsSection>
	</NcAppSettingsDialog>
</template>

<script>
import { NcAppSettingsDialog, NcAppSettingsSection, NcButton, NcEmptyContent, NcSelect } from '@nextcloud/vue'
import TimerIcon from 'vue-material-design-icons/Timer.vue'
import ShieldIcon from 'vue-material-design-icons/Shield.vue'
import KeyIcon from 'vue-material-design-icons/Key.vue'
import MasterPasswordForm from '../../components/MasterPasswordForm.vue'
import CompromiseRecoveryForm from '../../components/CompromiseRecoveryForm.vue'
import { useSessionStore } from '../../store/modules/session.js'
import { useEncryptionSuiteStore } from '../../store/modules/encryptionSuite.js'

export default {
	name: 'UserSettings',
	components: {
		NcAppSettingsDialog,
		NcAppSettingsSection,
		NcButton,
		NcEmptyContent,
		NcSelect,
		TimerIcon,
		ShieldIcon,
		KeyIcon,
		MasterPasswordForm,
		CompromiseRecoveryForm,
	},

	props: {
		open: {
			type: Boolean,
			default: false,
		},
	},

	data() {
		return {
			sessionTimeout: 'session',
			showRecovery: false,
			timeoutOptions: [
				{ value: 'session', label: t('doriath', 'Nextcloud session') },
				{ value: '10min', label: t('doriath', '10 minutes') },
				{ value: '30min', label: t('doriath', '30 minutes') },
			],
		}
	},

	computed: {
		suiteStore() {
			return useEncryptionSuiteStore()
		},
	},

	methods: {
		saveTimeout() {
			const session = useSessionStore()
			const timeouts = { session: 0, '10min': 600000, '30min': 1800000 }
			session.timeout = timeouts[this.sessionTimeout] || 600000
		},
	},
}
</script>

<style scoped>
.user-settings__field {
	margin-bottom: 1rem;
}
.user-settings__suite-info p {
	margin: 0.25rem 0;
}
</style>
