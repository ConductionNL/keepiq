<template>
	<div class="lock-screen">
		<div class="lock-screen__card">
			<div class="lock-screen__icon">
				<LockIcon :size="48" />
			</div>
			<h1 class="lock-screen__title">
				{{ isFirstSetup ? t('doriath', 'Set up your master password') : t('doriath', 'Unlock Doriath') }}
			</h1>

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

			<!-- Migration resume mode -->
			<template v-else-if="hasPausedMigration">
				<NcNoteCard type="warning">
					{{ t('doriath', 'A key migration is in progress. Enter both your old and new master passwords to resume migrating your secrets.') }}
				</NcNoteCard>
				<NcPasswordField
					v-model="masterPassword"
					:label="t('doriath', 'New master password')"
					:disabled="loading" />
				<NcPasswordField
					v-model="oldPassword"
					:label="t('doriath', 'Old (compromised) master password')"
					:disabled="loading" />
				<p v-if="migrationProgress" class="lock-screen__progress">
					{{ migrationProgress }}
				</p>
				<NcButton
					type="primary"
					:disabled="!masterPassword || !oldPassword || loading"
					:wide="true"
					@click="handleResumeMigration">
					{{ loading ? migrationProgress || t('doriath', 'Migrating...') : t('doriath', 'Resume migration') }}
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
import axios from '@nextcloud/axios'
import { generateUrl } from '@nextcloud/router'
import { NcButton, NcNoteCard, NcPasswordField } from '@nextcloud/vue'
import LockIcon from 'vue-material-design-icons/Lock.vue'
import PasswordStrengthMeter from '../components/PasswordStrengthMeter.vue'
import { decryptPrivateKey, encryptPrivateKey, importPrivateKey, importPrivateKeyForSigning, importPublicKey, rsaEncrypt, rsaDecrypt, rsaSign } from '../crypto/index.js'
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
			oldPassword: '',
			loading: false,
			error: null,
			strengthValid: false,
			migrationProgress: null,
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

		async handleResumeMigration() {
			this.loading = true
			this.error = null
			this.migrationProgress = t('doriath', 'Checking migration status...')

			try {
				// Step 1: Fetch fresh migration status (before unlock, since unlock
				// may fail if the new suite has no private key yet).
				await this.suiteStore.fetchMigrationStatus()
				const migrationData = this.suiteStore.migrationStatus
				const oldEncryptedPk = migrationData.oldEncryptedPrivateKey
				let newPublicKeyPem = migrationData.newPublicKeyPem

				if (!oldEncryptedPk) {
					throw new Error('Migration data incomplete — old private key missing')
				}

				// Step 2: If the new suite needs repair (Phase 2 was interrupted),
				// regenerate its key pair and set up the session manually.
				if (migrationData.newSuiteNeedsRepair) {
					this.migrationProgress = t('doriath', 'Repairing interrupted key generation...')

					// Step A: Server generates new key pair + nonce.
					const repairResp = await axios.post(
						generateUrl(`/apps/doriath/api/v1/suites/${migrationData.newSuiteId}/repair`),
					)
					const repair = repairResp.data

					// Step B: Decrypt old private key, sign the nonce to prove identity.
					this.migrationProgress = t('doriath', 'Verifying identity...')
					const oldPrivateKeyPemForSign = await decryptPrivateKey(oldEncryptedPk, this.oldPassword)
					const signingKey = await importPrivateKeyForSigning(oldPrivateKeyPemForSign)
					const signature = await rsaSign(repair.nonce, signingKey)

					// Step C: Decrypt new private key, re-encrypt with master password.
					const newPrivateKeyPem = await decryptPrivateKey(repair.encryptedPrivateKey, repair.passphrase)
					const newCryptoKey = await importPrivateKey(newPrivateKeyPem)
					const masterEncryptedPk = await encryptPrivateKey(newPrivateKeyPem, this.masterPassword)

					// Step D: Confirm repair with signature + encrypted PK.
					await axios.post(
						generateUrl(`/apps/doriath/api/v1/suites/${migrationData.newSuiteId}/repair/confirm`),
						{
							oldSuiteId: migrationData.oldSuiteId,
							nonce: repair.nonce,
							signature,
							encryptedPrivateKey: masterEncryptedPk,
						},
					)

					// Set up the session manually (can't use sessionStore.unlock
					// because the suite didn't have a private key before repair).
					const session = this.sessionStore
					session.cryptoKey = newCryptoKey
					session.encryptedPrivateKey = masterEncryptedPk
					session.certificate = repair.publicKeyPem
					session.suiteId = migrationData.newSuiteId
					session.lastActivity = Date.now()
					newPublicKeyPem = repair.publicKeyPem
				} else {
					// New suite is intact — normal unlock with the new master password.
					this.migrationProgress = t('doriath', 'Unlocking vault...')
					await this.sessionStore.unlock(this.masterPassword)
				}

				if (!newPublicKeyPem) {
					throw new Error('Migration data incomplete — new public key missing')
				}

				// Step 3: Decrypt old private key with old master password.
				this.migrationProgress = t('doriath', 'Decrypting old key...')
				const oldPrivateKeyPem = await decryptPrivateKey(oldEncryptedPk, this.oldPassword)
				const oldCryptoKey = await importPrivateKey(oldPrivateKeyPem)
				const newPublicKey = await importPublicKey(newPublicKeyPem)

				// Step 4: Fetch all secrets and migrate them.
				this.migrationProgress = t('doriath', 'Fetching secrets...')
				const secretsResponse = await axios.get(
					generateUrl('/apps/doriath/api/v1/secrets'),
					{ params: { limit: 9999 } },
				)
				const secrets = secretsResponse.data.results ?? []
				let hasErrors = false
				let migrated = 0

				for (const secret of secrets) {
					migrated++
					this.migrationProgress = t(
						'doriath',
						'Migrating secret {current} of {total}...',
						{ current: migrated, total: secrets.length },
					)

					try {
						const detailResp = await axios.get(
							generateUrl(`/apps/doriath/api/v1/secrets/${secret.id}/for-migration`),
						)
						const detail = detailResp.data
						const migratedData = { newSuiteId: migrationData.newSuiteId }

						if (detail.key) {
							const plain = await rsaDecrypt(detail.key, oldCryptoKey)
							migratedData.key = await rsaEncrypt(plain, newPublicKey)
						}
						if (detail.login) {
							const plain = await rsaDecrypt(detail.login, oldCryptoKey)
							migratedData.login = await rsaEncrypt(plain, newPublicKey)
						}
						if (detail.additionalFields) {
							const plain = await rsaDecrypt(detail.additionalFields, oldCryptoKey)
							migratedData.additionalFields = await rsaEncrypt(plain, newPublicKey)
						}

						await axios.put(
							generateUrl(`/apps/doriath/api/v1/secrets/${secret.id}/migrate`),
							migratedData,
						)
					} catch (e) {
						console.error(`Doriath: Failed to migrate secret ${secret.id}`, e)
						hasErrors = true
					}
				}

				// Step 5: Complete the migration.
				this.migrationProgress = t('doriath', 'Completing migration...')
				await axios.post(
					generateUrl(`/apps/doriath/api/v1/migrations/${migrationData.id}/complete`),
					{ hasErrors },
				)
				await this.suiteStore.fetchMigrationStatus()

				this.migrationProgress = null
				const returnUrl = this.$route.query.returnUrl || '/'
				this.$router.push(returnUrl)
			} catch (e) {
				this.error = e.message || t('doriath', 'Migration failed')
				this.migrationProgress = null
			} finally {
				this.loading = false
				this.oldPassword = ''
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

.lock-screen__progress {
	text-align: center;
	color: var(--color-text-maxcontrast);
	font-size: 0.9rem;
	margin: 0;
}

.lock-screen__title {
	text-align: center;
	font-size: 1.25rem;
	margin: 0;
}
</style>
