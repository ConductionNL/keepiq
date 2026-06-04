<template>
	<NcDialog :name="t('doriath', 'Register application')"
		:open="open"
		size="normal"
		@update:open="onUpdateOpen">
		<div class="register-dialog">
			<NcNoteCard v-if="error" type="error">
				{{ error }}
			</NcNoteCard>

			<NcInputField :value.sync="name"
				:label="t('doriath', 'Name')"
				:required="true"
				:disabled="submitting" />

			<label class="register-dialog__label" for="register-dialog-description">
				{{ t('doriath', 'Description') }}
			</label>
			<textarea id="register-dialog-description"
				v-model="description"
				class="register-dialog__textarea"
				:disabled="submitting"
				rows="2" />

			<NcSelect v-model="type"
				:options="typeOptions"
				:input-label="t('doriath', 'Type')"
				:reduce="option => option.value"
				:clearable="false"
				:disabled="submitting" />

			<label class="register-dialog__label" for="register-dialog-csr">
				{{ t('doriath', 'Certificate signing request (optional)') }}
			</label>
			<textarea id="register-dialog-csr"
				v-model="csr"
				class="register-dialog__textarea register-dialog__textarea--mono"
				:placeholder="t('doriath', 'Paste a PKCS#10 CSR to keep your private key. Leave empty to have a key pair generated for you.')"
				:disabled="submitting"
				rows="4" />
		</div>

		<template #actions>
			<NcButton :disabled="submitting" @click="onUpdateOpen(false)">
				{{ t('doriath', 'Cancel') }}
			</NcButton>
			<NcButton type="primary"
				:disabled="!canSubmit || submitting"
				@click="submit">
				{{ submitting ? t('doriath', 'Registering…') : t('doriath', 'Register') }}
			</NcButton>
		</template>
	</NcDialog>
</template>

<script>
import { translate as t } from '@nextcloud/l10n'
import { NcButton, NcDialog, NcInputField, NcNoteCard, NcSelect } from '@nextcloud/vue'
import { useApplicationStore } from '../store/modules/application.js'

export default {
	name: 'ApplicationRegisterDialog',

	components: {
		NcButton,
		NcDialog,
		NcInputField,
		NcNoteCard,
		NcSelect,
	},

	props: {
		open: {
			type: Boolean,
			default: false,
		},
	},

	data() {
		return {
			name: '',
			description: '',
			type: 'external',
			csr: '',
			submitting: false,
			error: null,
			typeOptions: [
				{ value: 'external', label: t('doriath', 'External') },
				{ value: 'internal', label: t('doriath', 'Internal') },
			],
		}
	},

	computed: {
		canSubmit() {
			return this.name.trim().length > 0
		},
	},

	methods: {
		onUpdateOpen(value) {
			this.$emit('update:open', value)
			if (!value) {
				this.reset()
			}
		},

		async submit() {
			this.submitting = true
			this.error = null
			try {
				await useApplicationStore().registerApplication({
					name: this.name.trim(),
					description: this.description.trim() || null,
					type: this.type,
					csr: this.csr.trim() || null,
				})
				this.$emit('registered')
				this.onUpdateOpen(false)
			} catch (e) {
				this.error = e?.response?.data?.message || t('doriath', 'Registration failed')
			} finally {
				this.submitting = false
			}
		},

		reset() {
			this.name = ''
			this.description = ''
			this.type = 'external'
			this.csr = ''
			this.error = null
		},
	},
}
</script>

<style scoped>
.register-dialog {
	display: flex;
	flex-direction: column;
	gap: 12px;
	padding: 8px 0;
}

.register-dialog__label {
	font-weight: bold;
	margin-bottom: -8px;
}

.register-dialog__textarea {
	width: 100%;
	border: 2px solid var(--color-border-maxcontrast);
	border-radius: var(--border-radius-large);
	padding: 8px;
	background-color: var(--color-main-background);
	color: var(--color-main-text);
	resize: vertical;
}

.register-dialog__textarea--mono {
	font-family: monospace;
	font-size: 0.85em;
}
</style>
