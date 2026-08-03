<!--
  SPDX-License-Identifier: EUPL-1.2
  SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>

  Create-a-secret-request form. Lets the owner pick the fields they
  want filled in, an optional expiry, and optionally mark the create
  as a re-request. The store builds the POST body; this component is
  pure UI + emit.

  @spec openspec/changes/implement-secret-requests/tasks.md#task-8.2
-->
<template>
	<section class="doriath-secret-request-form" data-testid="secret-request-form">
		<header>
			<h4>{{ t('doriath', 'Request fill-in') }}</h4>
		</header>
		<form @submit.prevent="onSubmit">
			<fieldset class="doriath-secret-request-form__fields">
				<legend>{{ t('doriath', 'Fields to fill in') }}</legend>
				<label
					v-for="field in availableFields"
					:key="field"
					class="doriath-secret-request-form__field">
					<input
						v-model="selectedFields"
						type="checkbox"
						:value="field"
						:data-testid="`field-${field}`">
					<span>{{ field }}</span>
				</label>
			</fieldset>

			<label class="doriath-secret-request-form__expires">
				<span>{{ t('doriath', 'Expires at (optional)') }}</span>
				<input v-model="expiresAt" type="datetime-local" data-testid="secret-request-form-expires">
			</label>

			<label v-if="canReRequest" class="doriath-secret-request-form__rerequest">
				<input v-model="isReRequest" type="checkbox" data-testid="secret-request-form-rerequest">
				<span>{{ t('doriath', 'Refresh existing values (re-request)') }}</span>
			</label>

			<p v-if="error" class="doriath-secret-request-form__error" data-testid="secret-request-form-error">
				{{ error }}
			</p>

			<p v-if="createdLink" class="doriath-secret-request-form__link" data-testid="secret-request-form-link">
				{{ t('doriath', 'Share this link with the recipient. It is only valid once.') }}
				<br>
				<code>{{ createdLink }}</code>
			</p>

			<div class="doriath-secret-request-form__actions">
				<button type="button" data-testid="secret-request-form-cancel" @click="$emit('cancel')">
					{{ t('doriath', 'Cancel') }}
				</button>
				<button
					type="submit"
					class="primary"
					data-testid="secret-request-form-submit"
					:disabled="busy || selectedFields.length === 0">
					{{ busy ? t('doriath', 'Creating…') : t('doriath', 'Create request') }}
				</button>
			</div>
		</form>
	</section>
</template>

<script>
import { useSecretRequestStore } from '../../store/modules/secretRequest.js'

export default {
	name: 'SecretRequestForm',
	props: {
		secretId: {
			type: String,
			required: true,
		},
		encryptionSuiteId: {
			type: String,
			required: true,
		},
		availableFields: {
			type: Array,
			default: () => ['key', 'login', 'url'],
		},
		canReRequest: {
			type: Boolean,
			default: false,
		},
		linkBaseUrl: {
			type: String,
			default: '/share/request',
		},
	},
	emits: ['cancel', 'created'],
	data() {
		return {
			selectedFields: [],
			expiresAt: '',
			isReRequest: false,
			busy: false,
			error: null,
			createdLink: null,
		}
	},
	methods: {
		async onSubmit() {
			this.error = null
			if (this.selectedFields.length === 0) {
				this.error = t('doriath', 'Pick at least one field')
				return
			}

			const store = useSecretRequestStore()
			this.busy = true
			try {
				const payload = {
					secretId: this.secretId,
					encryptionSuiteId: this.encryptionSuiteId,
					requestedFields: [...this.selectedFields],
					isReRequest: this.isReRequest,
					expiresAt: this.expiresAt ? new Date(this.expiresAt).toISOString() : null,
				}
				const row = await store.createRequest(payload)
				if (row && row.token) {
					this.createdLink = `${this.linkBaseUrl}/${row.token}`
				}
				this.$emit('created', row)
			} catch (e) {
				this.error = e?.response?.data?.message || e?.message || t('doriath', 'Failed to create request')
			} finally {
				this.busy = false
			}
		},
	},
}
</script>

<style scoped>
.doriath-secret-request-form__fields {
	display: flex;
	flex-direction: column;
	gap: 4px;
	border: 1px solid var(--color-border, #ddd);
	padding: 8px 12px;
	border-radius: var(--border-radius, 4px);
	margin-bottom: 12px;
}

.doriath-secret-request-form__field,
.doriath-secret-request-form__rerequest {
	display: flex;
	gap: 8px;
	align-items: center;
}

.doriath-secret-request-form__expires {
	display: flex;
	flex-direction: column;
	gap: 4px;
	margin-bottom: 12px;
}

.doriath-secret-request-form__expires input {
	padding: 8px;
	border: 1px solid var(--color-border-dark, #999);
	border-radius: var(--border-radius, 4px);
}

.doriath-secret-request-form__error {
	color: var(--color-error, #e9322d);
	font-size: 13px;
}

.doriath-secret-request-form__link {
	background-color: var(--color-success-rest, #e6f4ea);
	border: 1px solid var(--color-success, #46ba61);
	padding: 8px;
	border-radius: var(--border-radius, 4px);
	font-size: 13px;
}

.doriath-secret-request-form__actions {
	display: flex;
	justify-content: flex-end;
	gap: 8px;
}

.doriath-secret-request-form__actions .primary {
	background-color: var(--color-primary-element, #0082c9);
	color: var(--color-primary-element-text, #fff);
	border: 0;
	padding: 8px 16px;
	border-radius: var(--border-radius, 4px);
}
</style>
