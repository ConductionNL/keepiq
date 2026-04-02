<template>
	<div class="lock-screen">
		<div class="lock-screen__card">
			<div class="lock-screen__icon">
				<LockIcon :size="48" />
			</div>
			<h1 class="lock-screen__title">
				{{ isFirstSetup ? t('doriath', 'Set up your master password') : t('doriath', 'Unlock Doriath') }}
			</h1>

			<!-- Migration paused banner -->
			<NcNoteCard v-if="hasPausedMigration" type="warning">
				{{ t('doriath', 'Key migration paused — enter your master password to resume') }}
			</NcNoteCard>

			<!-- First-time setup mode -->
			<template v-if="isFirstSetup">
				<NcPasswordField
					v-model="masterPassword"
					:label="t('doriath', 'Master password')"
					:disabled="loading"
					@keyup.enter="handleSetup" />
				<PasswordStrengthMeter
					v-if="masterPassword"
					:password="masterPassword"
					@strength-change="onStrengthChange" />
				<NcPasswordField
					v-model="confirmPassword"
					:label="t('doriath', 'Confirm master password')"
					:disabled="loading"
					@keyup.enter="handleSetup" />
				<NcButton
					type="primary"
					:disabled="!canSubmitSetup || loading"
					:wide="true"
					@click="handleSetup">
					{{ loading ? t('doriath', 'Setting up...') : t('doriath', 'Set up vault') }}
				</NcButton>
			</template>

			<!-- Normal unlock mode -->
			<template v-else>
				<NcPasswordField
					v-model="masterPassword"
					:label="t('doriath', 'Master password')"
					:disabled="loading"
					@keyup.enter="handleUnlock" />
				<NcButton
					type="primary"
					:disabled="!masterPassword || loading"
					:wide="true"
					@click="handleUnlock">
					{{ loading ? t('doriath', 'Unlocking...') : t('doriath', 'Unlock') }}
				</NcButton>
			</template>

			<NcNoteCard v-if="error" type="error">
				{{ error }}
			</NcNoteCard>
		</div>
	</div>
</template>

<script>
import { NcButton, NcNoteCard, NcPasswordField } from '@nextcloud/vue'
import LockIcon from 'vue-material-design-icons/Lock.vue'
import PasswordStrengthMeter from '../components/PasswordStrengthMeter.vue'
import { useSessionStore } from '../store/modules/session.js'
import { useEncryptionSuiteStore } from '../store/modules/encryptionSuite.js'

export default {
	name: 'LockScreen',
	components: {
		NcButton,
		NcNoteCard,
		NcPasswordField,
		LockIcon,
		PasswordStrengthMeter,
	},

	data() {
		return {
			masterPassword: '',
			confirmPassword: '',
			loading: false,
			error: null,
			strengthValid: false,
		}
	},

	computed: {
		sessionStore() {
			return useSessionStore()
		},
		suiteStore() {
			return useEncryptionSuiteStore()
		},
		isFirstSetup() {
			return !this.suiteStore.currentSuite
		},
		hasPausedMigration() {
			return this.suiteStore.migrationStatus?.status === 'in_progress'
		},
		canSubmitSetup() {
			return this.masterPassword
				&& this.confirmPassword
				&& this.masterPassword === this.confirmPassword
				&& this.strengthValid
		},
	},

	async created() {
		await this.suiteStore.fetchSuite()
		await this.suiteStore.fetchMigrationStatus()
	},

	methods: {
		async handleUnlock() {
			this.loading = true
			this.error = null

			try {
				await this.sessionStore.unlock(this.masterPassword)
				const returnUrl = this.$route.query.returnUrl || '/'
				this.$router.push(returnUrl)
			} catch (e) {
				this.error = t('doriath', 'Wrong master password or decryption failed')
			} finally {
				this.loading = false
				this.masterPassword = ''
			}
		},

		async handleSetup() {
			if (!this.canSubmitSetup) return

			this.loading = true
			this.error = null

			try {
				await this.suiteStore.createSuite(this.masterPassword)
				this.$router.push('/')
			} catch (e) {
				this.error = e.message || t('doriath', 'Setup failed')
			} finally {
				this.loading = false
				this.masterPassword = ''
				this.confirmPassword = ''
			}
		},

		onStrengthChange({ isValid }) {
			this.strengthValid = isValid
		},
	},
}
</script>

<style scoped>
.lock-screen {
	display: flex;
	justify-content: center;
	align-items: center;
	height: 100vh;
	background: var(--color-background-dark);
}

.lock-screen__card {
	background: var(--color-main-background);
	border-radius: var(--border-radius-large);
	padding: 2rem;
	max-width: 400px;
	width: 100%;
	box-shadow: 0 2px 8px rgba(0, 0, 0, 0.1);
	display: flex;
	flex-direction: column;
	gap: 1rem;
}

.lock-screen__icon {
	text-align: center;
}

.lock-screen__title {
	text-align: center;
	font-size: 1.25rem;
	margin: 0;
}
</style>
