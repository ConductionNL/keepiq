<!--
  SPDX-License-Identifier: EUPL-1.2
  SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>

  Edit-secret dialog. Loads + decrypts the secret, lets the user change name /
  type / URL / login / value, and PUTs only the changed fields via the store.
  Sensitive fields (value / login) are re-encrypted client-side; metadata-only
  edits skip encryption (zero-knowledge, ADR-003).
-->
<template>
	<NcDialog :name="t('doriath', 'Edit secret')"
		:open="open"
		size="normal"
		@update:open="onUpdateOpen">
		<NcLoadingIcon v-if="loading" :size="32" class="secret-form__loading" />

		<div v-else class="secret-form">
			<NcNoteCard v-if="error" type="error">
				{{ error }}
			</NcNoteCard>

			<NcTextField :value.sync="name"
				:label="t('doriath', 'Name')"
				:required="true" />

			<NcSelect v-model="typeId"
				:options="typeOptions"
				:reduce="opt => opt.value"
				:input-label="t('doriath', 'Type')"
				:clearable="false" />

			<NcPasswordField :value.sync="value"
				:label="valueLabel" />

			<NcTextField :value.sync="url"
				:label="t('doriath', 'URL (optional)')" />

			<NcTextField :value.sync="login"
				:label="t('doriath', 'Login (optional)')" />
		</div>

		<template #actions>
			<NcButton type="tertiary" @click="onUpdateOpen(false)">
				{{ t('doriath', 'Cancel') }}
			</NcButton>
			<NcButton type="primary"
				:disabled="!canSubmit"
				@click="submit">
				<template #icon>
					<NcLoadingIcon v-if="saving" :size="20" />
					<ContentSave v-else :size="20" />
				</template>
				{{ t('doriath', 'Save') }}
			</NcButton>
		</template>
	</NcDialog>
</template>

<script>
import { NcButton, NcDialog, NcLoadingIcon, NcNoteCard, NcPasswordField, NcSelect, NcTextField } from '@nextcloud/vue'
import ContentSave from 'vue-material-design-icons/ContentSave.vue'
import { useSecretStore } from '../store/modules/secret.js'
import { useSecretTypeStore } from '../store/modules/secretType.js'

/**
 * Edit a secret. Loads + decrypts on mount; on save sends only changed fields,
 * re-encrypting sensitive ones via the store. Emits `saved` on success and
 * `close` on dismiss.
 */
export default {
	name: 'SecretEditDialog',

	components: {
		NcButton,
		NcDialog,
		NcLoadingIcon,
		NcNoteCard,
		NcPasswordField,
		NcSelect,
		NcTextField,
		ContentSave,
	},

	props: {
		/** The ID of the secret to edit. */
		secretId: {
			type: String,
			required: true,
		},
		/** Optional callback fired with the updated secret after success. */
		onSaved: {
			type: Function,
			default: null,
		},
	},

	data() {
		return {
			open: true,
			loading: true,
			saving: false,
			error: '',
			original: null,
			name: '',
			typeId: null,
			value: '',
			url: '',
			login: '',
		}
	},

	computed: {
		typeOptions() {
			return useSecretTypeStore().types.map(type => ({
				value: type.id,
				label: type.label || type.name,
			}))
		},
		valueLabel() {
			const type = useSecretTypeStore().typesById[this.typeId]
			return type && type.name === 'note'
				? t('doriath', 'Note')
				: t('doriath', 'Secret value')
		},
		canSubmit() {
			return !this.loading && !this.saving && this.name.trim() !== ''
		},
	},

	async mounted() {
		const typeStore = useSecretTypeStore()
		if (typeStore.types.length === 0) {
			await typeStore.fetchTypes()
		}
		await this.load()
	},

	methods: {
		t,

		/**
		 * Load + decrypt the secret and seed the form fields.
		 *
		 * @return {Promise<void>}
		 */
		async load() {
			this.loading = true
			this.error = ''
			try {
				const secret = await useSecretStore().fetchSecret(this.secretId)
				this.original = secret
				this.name = secret.name || ''
				this.typeId = secret.typeId || null
				this.value = secret.key || ''
				this.url = secret.url || ''
				this.login = secret.login || ''
			} catch (e) {
				this.error = e?.response?.data?.message || e?.message || t('doriath', 'Failed to load secret')
			} finally {
				this.loading = false
			}
		},

		/**
		 * Forward the open-state change; emit `close` when dismissed.
		 *
		 * @param {boolean} value The new open state.
		 * @return {void}
		 */
		onUpdateOpen(value) {
			this.open = value
			if (!value) {
				this.$emit('close')
			}
		},

		/**
		 * Compute the changed-fields diff and PUT it via the store.
		 *
		 * @return {Promise<void>}
		 */
		async submit() {
			if (!this.canSubmit) {
				return
			}
			this.saving = true
			this.error = ''
			try {
				const diff = {}
				const o = this.original
				if (this.name.trim() !== (o.name || '')) {
					diff.name = this.name.trim()
				}
				if ((this.typeId || null) !== (o.typeId || null)) {
					diff.typeId = this.typeId
				}
				if ((this.url || '') !== (o.url || '')) {
					diff.url = this.url || null
				}
				if ((this.value || '') !== (o.key || '')) {
					diff.key = this.value
				}
				if ((this.login || '') !== (o.login || '')) {
					diff.login = this.login
				}

				let updated = this.original
				if (Object.keys(diff).length > 0) {
					updated = await useSecretStore().updateSecret(this.secretId, diff)
				}
				this.$emit('saved', updated)
				if (this.onSaved) {
					this.onSaved(updated)
				}
				this.onUpdateOpen(false)
			} catch (e) {
				this.error = e?.response?.data?.message || e?.message || t('doriath', 'Failed to save secret')
			} finally {
				this.saving = false
			}
		},
	},
}
</script>

<style scoped>
.secret-form {
	display: flex;
	flex-direction: column;
	gap: 12px;
	padding: 4px 0;
}

.secret-form__loading {
	display: flex;
	justify-content: center;
	margin: 24px 0;
}
</style>
