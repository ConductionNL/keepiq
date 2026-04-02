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
				<p><strong>{{ t('doriath', 'Suite ID') }}:</strong> {{ suiteStore.currentSuite.id }}</p>

				<template v-if="suiteStore.currentSuite.status === 'active'">
					<NcNoteCard v-if="revokeConfirm" type="warning">
						{{ t('doriath', 'Revoking your encryption suite will make all your secrets inaccessible until an administrator reinstates it. This cannot be undone by you.') }}
						<div style="margin-top: 0.5rem;">
							<NcTextField
								v-model="revokeReason"
								:label="t('doriath', 'Reason for revocation')"
								:placeholder="t('doriath', 'e.g. Device lost, key compromised')" />
						</div>
						<div style="display: flex; gap: 0.5rem; margin-top: 0.5rem;">
							<NcButton
								type="error"
								:disabled="!revokeReason || revoking"
								@click="handleRevoke">
								{{ revoking ? t('doriath', 'Revoking...') : t('doriath', 'Confirm revocation') }}
							</NcButton>
							<NcButton type="secondary" @click="revokeConfirm = false">
								{{ t('doriath', 'Cancel') }}
							</NcButton>
						</div>
					</NcNoteCard>
					<NcButton
						v-else
						type="warning"
						@click="revokeConfirm = true">
						{{ t('doriath', 'Revoke encryption suite') }}
					</NcButton>
				</template>

				<NcNoteCard v-if="revokeSuccess" type="success">
					{{ t('doriath', 'Encryption suite revoked. Contact an administrator to reinstate it.') }}
				</NcNoteCard>
				<NcNoteCard v-if="revokeError" type="error">
					{{ revokeError }}
				</NcNoteCard>
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
import { NcAppSettingsDialog, NcAppSettingsSection, NcButton, NcEmptyContent, NcNoteCard, NcSelect, NcTextField } from '@nextcloud/vue'
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
		NcNoteCard,
		NcSelect,
		NcTextField,
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
			revokeConfirm: false,
			revokeReason: '',
			revoking: false,
			revokeSuccess: false,
			revokeError: null,
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
		async handleRevoke() {
			this.revoking = true
			this.revokeError = null
			this.revokeSuccess = false

			try {
				await this.suiteStore.revokeSuite(this.revokeReason)
				this.revokeSuccess = true
				this.revokeConfirm = false
				this.revokeReason = ''
			} catch (e) {
				this.revokeError = e.response?.data?.message || e.message || t('doriath', 'Failed to revoke suite')
			} finally {
				this.revoking = false
			}
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
