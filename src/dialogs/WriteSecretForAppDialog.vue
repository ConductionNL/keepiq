<!--
  SPDX-License-Identifier: EUPL-1.2
  SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>

  WriteSecretForAppDialog — collects plaintext fields, fetches the
  application's certificate, encrypts client-side, and POSTs the
  ciphertext to /api/v1/secrets with owner_type=application.

  The writing user CANNOT decrypt the result — only the application
  (holder of the matching private key) can. The dialog shows a
  confirmation on success and clears the form fields immediately so
  the plaintext does not linger in component state.

  @spec openspec/changes/implement-application-mgmt/tasks.md#task-10.6
-->
<template>
	<NcDialog
		:name="dialogName"
		:open="open"
		size="normal"
		@update:open="onUpdateOpen">
		<div class="write-secret-for-app">
			<NcNoteCard v-if="error" type="error">
				{{ error }}
			</NcNoteCard>
			<NcNoteCard
				v-if="success"
				type="success"
				data-testid="write-secret-success">
				{{
					t(
						'doriath',
						'Secret written. The application can decrypt it with its private key.',
					)
				}}
			</NcNoteCard>

			<p class="write-secret-for-app__intro">
				{{
					t(
						'doriath',
						"These fields are encrypted in your browser with the application's public key. You will not be able to read the secret back.",
					)
				}}
			</p>

			<NcTextField
				v-model="name"
				:label="t('doriath', 'Name')"
				:required="true"
				data-testid="write-secret-name" />

			<NcTextField v-model="url" :label="t('doriath', 'URL (optional)')" />

			<NcTextField v-model="login" :label="t('doriath', 'Login (optional)')" />

			<NcPasswordField
				v-model="value"
				:label="t('doriath', 'Secret value')"
				:required="true"
				data-testid="write-secret-value" />

			<NcTextArea
				v-model="additionalFields"
				:label="t('doriath', 'Additional fields (optional JSON)')"
				rows="3" />
		</div>

		<template #actions>
			<NcButton variant="secondary" @click="onUpdateOpen(false)">
				{{ t('doriath', 'Close') }}
			</NcButton>
			<NcButton
				variant="primary"
				:disabled="busy || !canSubmit"
				data-testid="write-secret-submit"
				@click="submit">
				{{ submitLabel }}
			</NcButton>
		</template>
	</NcDialog>
</template>

<script>
import {
	NcButton,
	NcDialog,
	NcNoteCard,
	NcPasswordField,
	NcTextArea,
	NcTextField,
} from '@nextcloud/vue'
import { useApplicationStore } from '../store/modules/application.js'

export default {
	name: 'WriteSecretForAppDialog',

	components: {
		NcButton,
		NcDialog,
		NcNoteCard,
		NcPasswordField,
		NcTextArea,
		NcTextField,
	},

	props: {
		open: {
			type: Boolean,
			default: false,
		},
		applicationId: {
			type: String,
			default: '',
		},
		applicationName: {
			type: String,
			default: '',
		},
	},

	emits: ['close', 'written'],

	data() {
		return {
			name: '',
			url: '',
			login: '',
			value: '',
			additionalFields: '',
			busy: false,
			error: '',
			success: false,
		}
	},

	computed: {
		dialogName() {
			return this.applicationName
				? this.t('doriath', 'Write secret for {app}', {
						app: this.applicationName,
					})
				: this.t('doriath', 'Write secret')
		},
		canSubmit() {
			return (
				this.applicationId !== ''
				&& this.name.trim() !== ''
				&& this.value !== ''
			)
		},
		submitLabel() {
			return this.busy
				? this.t('doriath', 'Encrypting…')
				: this.t('doriath', 'Write secret')
		},
	},

	methods: {
		onUpdateOpen(value) {
			if (!value) {
				this.reset()
				this.$emit('close')
			}
		},

		reset() {
			this.name = ''
			this.url = ''
			this.login = ''
			this.value = ''
			this.additionalFields = ''
			this.error = ''
			this.success = false
		},

		async submit() {
			if (!this.canSubmit || this.busy) {
				return
			}
			this.busy = true
			this.error = ''
			this.success = false

			try {
				const store = useApplicationStore()
				const result = await store.writeSecretForApplication(
					this.applicationId,
					{
						name: this.name.trim(),
						url: this.url || null,
						login: this.login || null,
						key: this.value,
						additionalFields: this.additionalFields
							? this.parseAdditional()
							: null,
					},
				)

				// Wipe the plaintext immediately so a stale dialog
				// cannot leak it back via the v-model.
				this.value = ''
				this.success = true
				this.$emit('written', result)
			} catch (e) {
				this.error =
					e?.response?.data?.message
					?? e?.message
					?? this.t('doriath', 'Write failed')
			} finally {
				this.busy = false
			}
		},

		parseAdditional() {
			const raw = this.additionalFields.trim()
			if (raw === '') {
				return null
			}
			try {
				// Try to JSON.parse; if it fails, send the raw string.
				return JSON.parse(raw)
			} catch {
				return raw
			}
		},
	},
}
</script>

<style scoped>
.write-secret-for-app {
	display: flex;
	flex-direction: column;
	gap: 12px;
	padding: 8px 0;
}

.write-secret-for-app__intro {
	color: var(--color-text-maxcontrast);
	font-size: 0.9em;
	margin: 0 0 4px 0;
}
</style>
