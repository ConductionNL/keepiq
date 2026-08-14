<template>
	<div class="lock-screen">
		<div class="lock-screen__card">
			<div class="lock-screen__icon">
				<LockIcon :size="48" />
			</div>
			<h1 class="lock-screen__title">
				{{
					isFirstSetup
						? t('doriath', 'Set up your master password')
						: t('doriath', 'Unlock Doriath')
				}}
			</h1>

			<!-- Insecure context warning -->
			<template v-if="!isSecureContext">
				<NcNoteCard type="error">
					{{
						t(
							'doriath',
							'Doriath requires a secure connection (HTTPS) to function. Please access this instance over HTTPS.',
						)
					}}
				</NcNoteCard>
			</template>

			<template v-else>
				<!-- Migration paused banner -->
				<NcNoteCard v-if="hasPausedMigration" type="warning">
					{{
						t(
							'doriath',
							'Key migration paused — enter your master password to resume',
						)
					}}
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
						@strengthChange="onStrengthChange" />
					<NcPasswordField
						v-model="confirmPassword"
						:label="t('doriath', 'Confirm master password')"
						:disabled="loading"
						@keyup.enter="handleSetup" />
					<NcButton
						variant="primary"
						:disabled="!canSubmitSetup || loading"
						:wide="true"
						@click="handleSetup">
						{{
							loading
								? t('doriath', 'Setting up...')
								: t('doriath', 'Set up vault')
						}}
					</NcButton>
				</template>

				<!-- Normal unlock mode -->
				<template v-else>
					<NcButton
						v-if="passkeyOffered"
						variant="primary"
						:disabled="loading"
						:wide="true"
						data-testid="unlock-with-passkey"
						@click="handlePasskeyUnlock">
						<template #icon>
							<KeyIcon :size="20" />
						</template>
						{{
							loading
								? t('doriath', 'Unlocking...')
								: t('doriath', 'Unlock with passkey')
						}}
					</NcButton>

					<NcPasswordField
						v-model="masterPassword"
						:label="t('doriath', 'Master password')"
						:disabled="loading"
						@keyup.enter="handleUnlock" />
					<NcButton
						:variant="passkeyOffered ? 'secondary' : 'primary'"
						:disabled="!masterPassword || loading"
						:wide="true"
						@click="handleUnlock">
						{{
							loading
								? t('doriath', 'Unlocking...')
								: t('doriath', 'Unlock')
						}}
					</NcButton>
				</template>

				<NcNoteCard v-if="error" type="error">
					{{ error }}
				</NcNoteCard>
			</template>
		</div>
	</div>
</template>

<script>
import { NcButton, NcNoteCard, NcPasswordField } from '@nextcloud/vue'
import KeyIcon from 'vue-material-design-icons/Key.vue'
import LockIcon from 'vue-material-design-icons/Lock.vue'
import PasswordStrengthMeter from '../components/PasswordStrengthMeter.vue'
import { useEncryptionSuiteStore } from '../store/modules/encryptionSuite.js'
import { useOfflineStore } from '../store/modules/offline.js'
import { usePasskeyStore } from '../store/modules/passkey.js'
import { useSessionStore } from '../store/modules/session.js'

export default {
	name: 'LockScreen',
	components: {
		NcButton,
		NcNoteCard,
		NcPasswordField,
		LockIcon,
		KeyIcon,
		PasswordStrengthMeter,
	},

	data() {
		return {
			masterPassword: '',
			confirmPassword: '',
			loading: false,
			error: null,
			strengthValid: false,
			passkeyOffered: false,
		}
	},

	computed: {
		/**
		 * @spec exclude Store-ref passthrough — returns the Pinia offline store with no domain logic.
		 */
		offlineStore() {
			return useOfflineStore()
		},

		/**
		 * @spec exclude Store-ref passthrough — returns the Pinia session store with no domain logic.
		 */
		sessionStore() {
			return useSessionStore()
		},

		/**
		 * @spec exclude Store-ref passthrough — returns the Pinia encryption-suite store with no domain logic.
		 */
		suiteStore() {
			return useEncryptionSuiteStore()
		},

		isFirstSetup() {
			return !this.suiteStore.currentSuite
		},

		hasPausedMigration() {
			return this.suiteStore.migrationStatus?.status === 'in_progress'
		},

		isSecureContext() {
			return window.isSecureContext
		},

		/**
		 * Gate the first-time setup submit on matching, strength-valid passwords.
		 *
		 * @return {boolean} True when setup may be submitted.
		 * @spec openspec/changes/retrofit-2026-05-25-doriath-coverage/tasks.md#task-7
		 */
		canSubmitSetup() {
			return (
				this.masterPassword
				&& this.confirmPassword
				&& this.masterPassword === this.confirmPassword
				&& this.strengthValid
			)
		},
	},

	/**
	 * Load the user's suite and migration status to decide between the
	 * unlock, first-setup, and paused-migration screens.
	 *
	 * @spec openspec/changes/retrofit-2026-05-25-doriath-coverage/tasks.md#task-7
	 */
	async created() {
		await this.suiteStore.fetchSuite()
		await this.suiteStore.fetchMigrationStatus()
		// Offer passkey unlock only when WebAuthn is present AND the caller has
		// an active enrolled passkey (feature-detected, never assumed).
		if (this.offlineStore.online) {
			this.passkeyOffered = await usePasskeyStore().isUnlockOffered()
		}
	},

	methods: {
		/**
		 * Unlock the vault with a passkey (passkey-vault-login §4.1). On any
		 * failure, fall back to the master-password field without leaving the
		 * lock screen — the master password always works.
		 *
		 * @return {Promise<void>}
		 */
		async handlePasskeyUnlock() {
			this.loading = true
			this.error = null
			try {
				await usePasskeyStore().unlockWithPasskey()
				this.$router.push(this.$route.query.returnUrl || '/')
			} catch (e) {
				this.error =
					e?.message
					|| t(
						'doriath',
						'Passkey unlock failed — use your master password',
					)
			} finally {
				this.loading = false
			}
		},

		/**
		 * Derive the AES key from the master password, unlock the vault,
		 * and redirect to the return URL (or root).
		 *
		 * @spec openspec/changes/retrofit-2026-05-25-doriath-coverage/tasks.md#task-7
		 */
		async handleUnlock() {
			this.loading = true
			this.error = null

			try {
				if (this.offlineStore.online) {
					await this.sessionStore.unlock(this.masterPassword)
				} else {
					// Offline unlock from the cached snapshot — no server request;
					// the master password never leaves the browser (offline §4.1).
					await this.offlineStore.unlockOffline(this.masterPassword)
				}
				const returnUrl = this.$route.query.returnUrl || '/'
				this.$router.push(returnUrl)
			} catch (e) {
				// When an online unlock fails on a network error, fall back to the
				// offline snapshot (covers "online but server unreachable").
				if (this.offlineStore.online && this.isNetworkError(e)) {
					try {
						await this.offlineStore.unlockOffline(this.masterPassword)
						this.$router.push(this.$route.query.returnUrl || '/')
						return
					} catch (offlineError) {
						// fall through to the generic error below
					}
				}
				this.error = t(
					'doriath',
					'Wrong master password or decryption failed',
				)
			} finally {
				this.loading = false
				this.masterPassword = ''
			}
		},

		/**
		 * Whether an unlock error is a network failure (server unreachable)
		 * rather than a wrong password — used to trigger the offline fallback.
		 *
		 * @param {Error} e The unlock error.
		 * @return {boolean}
		 */
		isNetworkError(e) {
			return (
				!!e
				&& (e.message === 'Network Error'
					|| e.code === 'ERR_NETWORK'
					|| (e.request && !e.response))
			)
		},

		/**
		 * First-time suite setup: create the encryption suite from the
		 * new master password and navigate into the vault.
		 *
		 * @spec openspec/changes/retrofit-2026-05-25-doriath-coverage/tasks.md#task-7
		 */
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

		/**
		 * Track password-strength validity emitted by the strength meter.
		 *
		 * @param {object} root0 Strength event.
		 * @param {boolean} root0.isValid Whether the password meets the floor.
		 * @spec openspec/changes/retrofit-2026-05-25-doriath-coverage/tasks.md#task-7
		 */
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
