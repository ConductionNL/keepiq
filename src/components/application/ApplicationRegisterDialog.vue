<!--
  SPDX-License-Identifier: EUPL-1.2
  SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>

  Developer-facing application registration form. Lets a developer or
  admin register a new application by name + description + type, with
  an optional CSR upload.

  When the server returns a one-time private key (because no CSR was
  supplied) the parent view should mount PrivateKeyDownloadDialog to
  surface it; that flow is owned by the parent so the key can be
  copied or downloaded before this dialog closes.

  @spec openspec/changes/implement-application-mgmt/tasks.md#task-10.3
-->
<template>
	<section v-if="open" class="doriath-app-register-dialog" role="dialog" data-testid="application-register-dialog">
		<header class="doriath-app-register-dialog__header">
			<h3>{{ t('doriath', 'Register application') }}</h3>
			<button type="button" data-testid="application-register-close" @click="$emit('close')">
				×
			</button>
		</header>

		<form @submit.prevent="onSubmit">
			<label class="doriath-app-register-dialog__field">
				<span>{{ t('doriath', 'Name') }} *</span>
				<input
					v-model.trim="form.name"
					type="text"
					required
					maxlength="120"
					data-testid="application-register-name">
			</label>

			<label class="doriath-app-register-dialog__field">
				<span>{{ t('doriath', 'Description') }}</span>
				<textarea
					v-model="form.description"
					rows="2"
					maxlength="500"
					data-testid="application-register-description" />
			</label>

			<label class="doriath-app-register-dialog__field">
				<span>{{ t('doriath', 'Type') }}</span>
				<select v-model="form.type" data-testid="application-register-type">
					<option value="internal">{{ t('doriath', 'Internal (Nextcloud app)') }}</option>
					<option value="external">{{ t('doriath', 'External (offsite service)') }}</option>
				</select>
			</label>

			<label class="doriath-app-register-dialog__field">
				<span>{{ t('doriath', 'CSR (optional, PEM-encoded)') }}</span>
				<textarea
					v-model="form.csr"
					rows="6"
					placeholder="-----BEGIN CERTIFICATE REQUEST-----..."
					data-testid="application-register-csr" />
				<input
					type="file"
					accept=".csr,.pem,text/plain"
					data-testid="application-register-csr-upload"
					@change="onCsrUpload">
			</label>

			<p v-if="error" class="doriath-app-register-dialog__error" data-testid="application-register-error">
				{{ error }}
			</p>

			<div class="doriath-app-register-dialog__actions">
				<button type="button" data-testid="application-register-cancel" @click="$emit('close')">
					{{ t('doriath', 'Cancel') }}
				</button>
				<button
					type="submit"
					class="primary"
					data-testid="application-register-submit"
					:disabled="busy || form.name === ''">
					{{ busy ? t('doriath', 'Submitting…') : t('doriath', 'Register') }}
				</button>
			</div>
		</form>
	</section>
</template>

<script>
import { useApplicationStore } from '../../store/modules/application.js'

export default {
	name: 'ApplicationRegisterDialog',
	props: {
		open: {
			type: Boolean,
			default: false,
		},
	},
	emits: ['close', 'registered'],
	data() {
		return {
			form: {
				name: '',
				description: '',
				type: 'internal',
				csr: '',
			},
			busy: false,
			error: null,
		}
	},
	watch: {
		open(val) {
			if (val === false) {
				this.form = { name: '', description: '', type: 'internal', csr: '' }
				this.busy = false
				this.error = null
			}
		},
	},
	methods: {
		async onSubmit() {
			this.error = null
			if (this.form.name === '') {
				this.error = t('doriath', 'Name is required')
				return
			}
			const store = useApplicationStore()
			this.busy = true
			try {
				const row = await store.registerApplication({
					name: this.form.name,
					description: this.form.description,
					type: this.form.type,
					csr: this.form.csr || null,
				})
				this.$emit('registered', row)
			} catch (e) {
				this.error = e?.response?.data?.message || e?.message || t('doriath', 'Failed to register')
			} finally {
				this.busy = false
			}
		},
		onCsrUpload(event) {
			const file = event.target.files && event.target.files[0]
			if (!file) {
				return
			}
			const reader = new FileReader()
			reader.onload = () => {
				this.form.csr = String(reader.result || '')
			}
			reader.readAsText(file)
		},
	},
}
</script>

<style scoped>
.doriath-app-register-dialog {
	max-width: 560px;
	background-color: var(--color-main-background, #fff);
	border: 1px solid var(--color-border, #ddd);
	border-radius: var(--border-radius-large, 12px);
	padding: 16px;
}
.doriath-app-register-dialog__header {
	display: flex;
	justify-content: space-between;
	align-items: center;
	margin-bottom: 12px;
}
.doriath-app-register-dialog__field {
	display: flex;
	flex-direction: column;
	gap: 4px;
	margin-bottom: 12px;
}
.doriath-app-register-dialog__field input,
.doriath-app-register-dialog__field textarea,
.doriath-app-register-dialog__field select {
	padding: 8px;
	border: 1px solid var(--color-border-dark, #999);
	border-radius: var(--border-radius, 4px);
	font-family: inherit;
}
.doriath-app-register-dialog__error {
	color: var(--color-error, #e9322d);
	font-size: 13px;
}
.doriath-app-register-dialog__actions {
	display: flex;
	justify-content: flex-end;
	gap: 8px;
}
.doriath-app-register-dialog__actions .primary {
	background-color: var(--color-primary-element, #0082c9);
	color: var(--color-primary-element-text, #fff);
	border: 0;
	padding: 8px 16px;
	border-radius: var(--border-radius, 4px);
}
</style>
