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
	<section class="keepiq-secret-request-form" data-testid="secret-request-form">
		<header>
			<h4>{{ t('keepiq', 'Request fill-in') }}</h4>
		</header>
		<form @submit.prevent="onSubmit">
			<fieldset class="keepiq-secret-request-form__fields">
				<legend>{{ t('keepiq', 'Fields to fill in') }}</legend>
				<label
					v-for="field in availableFields"
					:key="field"
					class="keepiq-secret-request-form__field">
					<input
						v-model="selectedFields"
						type="checkbox"
						:value="field"
						:data-testid="`field-${field}`" />
					<span>{{ field }}</span>
				</label>
			</fieldset>

			<label class="keepiq-secret-request-form__expires">
				<span>{{ t('keepiq', 'Expires at (optional)') }}</span>
				<input
					v-model="expiresAt"
					type="datetime-local"
					data-testid="secret-request-form-expires" />
			</label>

			<label v-if="canReRequest" class="keepiq-secret-request-form__rerequest">
				<input
					v-model="isReRequest"
					type="checkbox"
					data-testid="secret-request-form-rerequest" />
				<span>{{
					t('keepiq', 'Refresh existing values (re-request)')
				}}</span>
			</label>

			<p
				v-if="error"
				class="keepiq-secret-request-form__error"
				data-testid="secret-request-form-error">
				{{ error }}
			</p>

			<p
				v-if="createdLink"
				class="keepiq-secret-request-form__link"
				data-testid="secret-request-form-link">
				{{
					t(
						'keepiq',
						'Share this link with the recipient. It is only valid once.',
					)
				}}
				<br />
				<code>{{ createdLink }}</code>
			</p>

			<div class="keepiq-secret-request-form__actions">
				<button
					type="button"
					data-testid="secret-request-form-cancel"
					@click="$emit('cancel')">
					{{ t('keepiq', 'Cancel') }}
				</button>
				<button
					type="submit"
					class="primary"
					data-testid="secret-request-form-submit"
					:disabled="busy || selectedFields.length === 0">
					{{
						busy
							? t('keepiq', 'Creating…')
							: t('keepiq', 'Create request')
					}}
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
		/**
		 * Create the secret request (fresh or re-request) and surface its
		 * fill-in link.
		 *
		 * @return {Promise<void>}
		 * @spec openspec/specs/secret-requests/spec.md#requirement-create-secret-request
		 * @spec openspec/specs/secret-requests/spec.md#requirement-requestable-fields
		 * @spec openspec/specs/secret-requests/spec.md#requirement-optional-expiry
		 */
		async onSubmit() {
			this.error = null
			if (this.selectedFields.length === 0) {
				this.error = t('keepiq', 'Pick at least one field')
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
					expiresAt: this.expiresAt
						? new Date(this.expiresAt).toISOString()
						: null,
				}
				const row = await store.createRequest(payload)
				if (row && row.token) {
					this.createdLink = `${this.linkBaseUrl}/${row.token}`
				}
				this.$emit('created', row)
			} catch (e) {
				this.error =
					e?.response?.data?.message
					|| e?.message
					|| t('keepiq', 'Failed to create request')
			} finally {
				this.busy = false
			}
		},
	},
}
</script>

<style scoped>
.keepiq-secret-request-form__fields {
	display: flex;
	flex-direction: column;
	gap: 4px;
	border: 1px solid var(--color-border, #ddd);
	padding: 8px 12px;
	border-radius: var(--border-radius, 4px);
	margin-bottom: 12px;
}

.keepiq-secret-request-form__field,
.keepiq-secret-request-form__rerequest {
	display: flex;
	gap: 8px;
	align-items: center;
}

.keepiq-secret-request-form__expires {
	display: flex;
	flex-direction: column;
	gap: 4px;
	margin-bottom: 12px;
}

.keepiq-secret-request-form__expires input {
	padding: 8px;
	border: 1px solid var(--color-border-dark, #999);
	border-radius: var(--border-radius, 4px);
}

.keepiq-secret-request-form__error {
	color: var(--color-error-text);
	font-size: 13px;
}

.keepiq-secret-request-form__link {
	/* --color-success-rest is not a Nextcloud variable, so this always fell back
	   to the pale light-theme green. The panel inherits the surrounding text
	   colour, which is near-white in dark mode, leaving it unreadable. Keep the
	   semantic colour on the border and use a themed background with an
	   explicit foreground, which is contrast-guaranteed in both themes. */
	background-color: var(--color-background-dark);
	border: 1px solid var(--color-success-text);
	color: var(--color-main-text);
	padding: 8px;
	border-radius: var(--border-radius, 4px);
	font-size: 13px;
}

.keepiq-secret-request-form__actions {
	display: flex;
	justify-content: flex-end;
	gap: 8px;
}

.keepiq-secret-request-form__actions .primary {
	background-color: var(--color-primary-element, #0082c9);
	color: var(--color-primary-element-text, #fff);
	border: 0;
	padding: 8px 16px;
	border-radius: var(--border-radius, 4px);
}
</style>
