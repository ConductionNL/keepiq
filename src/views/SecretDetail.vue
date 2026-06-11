<template>
	<div class="secret-detail">
		<NcButton type="tertiary" class="secret-detail__back" @click="goBack">
			<template #icon>
				<ArrowLeft :size="20" />
			</template>
			{{ t('doriath', 'Back to vault') }}
		</NcButton>

		<NcLoadingIcon v-if="loading" :size="32" class="secret-detail__loading" />

		<NcEmptyContent v-else-if="error"
			:name="t('doriath', 'Cannot open secret')"
			:description="error">
			<template #icon>
				<Lock />
			</template>
		</NcEmptyContent>

		<div v-else-if="secret" class="secret-detail__card">
			<h2 class="secret-detail__title">
				{{ secret.name }}
			</h2>

			<div v-if="secret.url" class="secret-detail__field">
				<span class="secret-detail__label">{{ t('doriath', 'URL') }}</span>
				<a :href="secret.url" target="_blank" rel="noopener noreferrer">{{ secret.url }}</a>
			</div>

			<div v-if="secret.login" class="secret-detail__field">
				<span class="secret-detail__label">{{ t('doriath', 'Login') }}</span>
				<span class="secret-detail__value">{{ secret.login }}</span>
				<CopyButton :value="secret.login" :label="t('doriath', 'Copy login')" />
			</div>

			<div class="secret-detail__field">
				<span class="secret-detail__label">{{ keyLabel }}</span>
				<PasswordField :label="keyLabel" :resolve="resolveKey" />
			</div>

			<div v-if="hasAdditionalFields" class="secret-detail__field secret-detail__field--block">
				<span class="secret-detail__label">{{ t('doriath', 'Additional fields') }}</span>
				<dl class="secret-detail__extra">
					<template v-for="(value, key) in secret.additionalFields">
						<dt :key="`k-${key}`">
							{{ key }}
						</dt>
						<dd :key="`v-${key}`">
							{{ value }}
						</dd>
					</template>
				</dl>
			</div>

			<div class="secret-detail__actions">
				<NcButton type="primary" @click="openEdit">
					<template #icon>
						<Pencil :size="20" />
					</template>
					{{ t('doriath', 'Edit') }}
				</NcButton>
				<NcButton type="secondary" @click="openMove">
					<template #icon>
						<FolderMove :size="20" />
					</template>
					{{ t('doriath', 'Move') }}
				</NcButton>
				<NcButton type="secondary" @click="openShare">
					<template #icon>
						<ShareVariant :size="20" />
					</template>
					{{ t('doriath', 'Share') }}
				</NcButton>
				<NcButton type="error" @click="remove">
					<template #icon>
						<Delete :size="20" />
					</template>
					{{ t('doriath', 'Delete secret') }}
				</NcButton>
			</div>
		</div>
	</div>
</template>

<script>
import { NcButton, NcEmptyContent, NcLoadingIcon } from '@nextcloud/vue'
import ArrowLeft from 'vue-material-design-icons/ArrowLeft.vue'
import Delete from 'vue-material-design-icons/Delete.vue'
import Lock from 'vue-material-design-icons/Lock.vue'
import Pencil from 'vue-material-design-icons/Pencil.vue'
import FolderMove from 'vue-material-design-icons/FolderMove.vue'
import ShareVariant from 'vue-material-design-icons/ShareVariant.vue'
import CopyButton from '../components/CopyButton.vue'
import PasswordField from '../components/PasswordField.vue'
import { useSecretStore } from '../store/modules/secret.js'
import { useSecretTypeStore } from '../store/modules/secretType.js'

/**
 * The secret detail view. Encrypted fields are decrypted client-side on load
 * (login + additionalFields) while the key stays masked until revealed.
 */
export default {
	name: 'SecretDetail',

	components: {
		NcButton,
		NcEmptyContent,
		NcLoadingIcon,
		ArrowLeft,
		Delete,
		Lock,
		Pencil,
		FolderMove,
		ShareVariant,
		CopyButton,
		PasswordField,
	},

	inject: {
		/**
		 * Open a registry-registered modal. Provided by CnAppRoot; defaults to a
		 * no-op so the view still mounts in isolation.
		 */
		cnOpenModal: { default: () => () => {} },
	},

	data() {
		return {
			secret: null,
			loading: true,
			error: '',
		}
	},

	computed: {
		secretId() {
			return this.$route.params.id
		},
		hasAdditionalFields() {
			return this.secret
				&& this.secret.additionalFields
				&& typeof this.secret.additionalFields === 'object'
		},
		keyLabel() {
			const typeStore = useSecretTypeStore()
			const type = this.secret ? typeStore.typesById[this.secret.typeId] : null
			if (type && type.name === 'note') {
				return t('doriath', 'Note')
			}
			return t('doriath', 'Key')
		},
	},

	async mounted() {
		await useSecretTypeStore().fetchTypes()
		await this.load()
	},

	methods: {
		t,

		/**
		 * Load and decrypt the secret.
		 *
		 * @return {Promise<void>}
		 */
		async load() {
			this.loading = true
			this.error = ''
			try {
				this.secret = await useSecretStore().fetchSecret(this.secretId)
			} catch (e) {
				if (e?.response?.status === 403) {
					this.error = t('doriath', 'This secret is locked because its encryption suite was revoked.')
				} else {
					this.error = e?.response?.data?.message || t('doriath', 'Failed to load secret')
				}
			} finally {
				this.loading = false
			}
		},

		/**
		 * Resolve the decrypted key for the password field.
		 *
		 * @return {Promise<string>}
		 */
		async resolveKey() {
			return this.secret ? (this.secret.key || '') : ''
		},

		/**
		 * Open the edit dialog for this secret and reload on success.
		 *
		 * @return {void}
		 */
		openEdit() {
			this.cnOpenModal('secret-edit', {
				secretId: this.secretId,
				onSaved: () => this.load(),
			})
		},

		/**
		 * Open the move dialog for this secret and reload on success.
		 *
		 * @return {void}
		 */
		openMove() {
			this.cnOpenModal('secret-move', {
				secretId: this.secretId,
				currentFolderId: this.secret ? (this.secret.folderId || null) : null,
				onSaved: () => this.load(),
			})
		},

		/**
		 * Open the share dialog for this secret.
		 *
		 * @return {void}
		 */
		openShare() {
			this.cnOpenModal('secret-share', {
				secretId: this.secretId,
			})
		},

		/**
		 * Delete the secret and return to the vault.
		 *
		 * @return {Promise<void>}
		 */
		async remove() {
			await useSecretStore().deleteSecret(this.secretId)
			this.goBack()
		},

		/**
		 * Navigate back to the vault list.
		 *
		 * @return {void}
		 */
		goBack() {
			this.$router.push('/secrets')
		},
	},
}
</script>

<style scoped>
.secret-detail {
	padding: 16px;
	max-width: 720px;
	margin: 0 auto;
}

.secret-detail__field {
	display: flex;
	align-items: center;
	gap: 12px;
	padding: 10px 0;
	border-bottom: 1px solid var(--color-border);
}

.secret-detail__field--block {
	display: block;
}

.secret-detail__label {
	width: 140px;
	flex: 0 0 auto;
	color: var(--color-text-maxcontrast);
}

.secret-detail__value {
	flex: 1 1 auto;
}

.secret-detail__actions {
	display: flex;
	flex-wrap: wrap;
	gap: 8px;
	margin-top: 24px;
}
</style>
