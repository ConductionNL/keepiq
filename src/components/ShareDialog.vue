<template>
	<NcDialog
		:name="t('doriath', 'Share with user')"
		:open.sync="dialogOpen"
		@close="onClose">
		<div class="share-dialog">
			<NcNoteCard v-if="error" type="error">
				{{ error }}
			</NcNoteCard>

			<p class="share-dialog__hint">
				{{ t('doriath', 'Enter the user ID of the person you want to share this secret with.') }}
			</p>

			<NcInputField
				v-model="targetUserId"
				:label="t('doriath', 'User ID')"
				:disabled="sharing"
				:placeholder="t('doriath', 'e.g. john.doe')" />

			<div class="share-dialog__actions">
				<NcButton :disabled="sharing" @click="onClose">
					{{ t('doriath', 'Cancel') }}
				</NcButton>
				<NcButton
					type="primary"
					:disabled="!targetUserId.trim() || sharing"
					@click="submit">
					{{ sharing ? t('doriath', 'Sharing…') : t('doriath', 'Share') }}
				</NcButton>
			</div>
		</div>
	</NcDialog>
</template>

<script>
import { NcButton, NcDialog, NcInputField, NcNoteCard } from '@nextcloud/vue'
import { useShareStore } from '../store/modules/share.js'
import { useSecretStore } from '../store/modules/secret.js'
import { useSessionStore } from '../store/modules/session.js'
import { importPublicKey, rsaEncrypt } from '../crypto/index.js'

export default {
	name: 'ShareDialog',

	components: {
		NcButton,
		NcDialog,
		NcInputField,
		NcNoteCard,
	},

	props: {
		open: {
			type: Boolean,
			default: false,
		},
		secretId: {
			type: String,
			required: true,
		},
	},

	emits: ['update:open', 'shared'],

	data() {
		return {
			targetUserId: '',
			sharing: false,
			error: null,
		}
	},

	computed: {
		dialogOpen: {
			get() {
				return this.open
			},
			set(value) {
				this.$emit('update:open', value)
			},
		},
		shareStore() {
			return useShareStore()
		},
		secretStore() {
			return useSecretStore()
		},
		sessionStore() {
			return useSessionStore()
		},
	},

	methods: {
		onClose() {
			this.targetUserId = ''
			this.error = null
			this.$emit('update:open', false)
		},

		async submit() {
			const userId = this.targetUserId.trim()
			if (!userId) return

			this.sharing = true
			this.error = null

			try {
				console.debug('Doriath share: starting share for user:', userId)
				// Fetch the target user's public key.
				const targetPublicKeyPem = await this.shareStore.fetchUserPublicKey(userId)
				console.debug('Doriath share: got public key, length:', targetPublicKeyPem?.length)
				console.debug('Doriath share: target public key PEM (first 80 chars):', targetPublicKeyPem?.substring(0, 80))
				console.debug('Doriath share: own certificate (first 80 chars):', this.sessionStore.certificate?.substring(0, 80))
				console.debug('Doriath share: keys are same?', targetPublicKeyPem === this.sessionStore.certificate)
				const targetPublicKey = await importPublicKey(targetPublicKeyPem)

				// Get the current (decrypted) secret from the store.
				const secret = this.secretStore.currentSecret
				if (!secret) {
					throw new Error(t('doriath', 'No secret selected'))
				}

				// Ensure the vault is unlocked — we need plaintext values.
				if (!this.sessionStore.cryptoKey) {
					throw new Error(t('doriath', 'Vault is locked — unlock it first'))
				}

				// The secret in the store is already decrypted (by fetchSecret).
				// Re-encrypt each field with the target user's public key.
				const encryptedData = {}

				if (secret.key) {
					encryptedData.key = await rsaEncrypt(secret.key, targetPublicKey)
				}
				if (secret.login) {
					encryptedData.login = await rsaEncrypt(secret.login, targetPublicKey)
				}
				if (secret.additionalFields) {
					encryptedData.additionalFields = await rsaEncrypt(JSON.stringify(secret.additionalFields), targetPublicKey)
				}

				// Include secret metadata for the recipient's copy.
				encryptedData.name = secret.name || ''
				encryptedData.url = secret.url || null
				encryptedData.typeId = secret.typeId || ''

				await this.shareStore.createShare(this.secretId, userId, encryptedData)
				await this.shareStore.fetchShares(this.secretId)
				this.$emit('shared')
				this.onClose()
			} catch (e) {
				this.error = e.response?.data?.message || e.message || t('doriath', 'Failed to share secret')
			} finally {
				this.sharing = false
			}
		},
	},
}
</script>

<style scoped>
.share-dialog {
	display: flex;
	flex-direction: column;
	gap: 12px;
	padding: 8px 0;
}

.share-dialog__hint {
	margin: 0;
	color: var(--color-text-maxcontrast);
}

.share-dialog__actions {
	display: flex;
	justify-content: flex-end;
	gap: 8px;
	margin-top: 8px;
}
</style>
