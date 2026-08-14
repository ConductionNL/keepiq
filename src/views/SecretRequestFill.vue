<!--
  SPDX-License-Identifier: EUPL-1.2
  SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>

  Public secret-request fill-in page mounted at /share/request/:token.

  Phase 1 — fetch the request metadata via
  `useSecretRequestStore.fetchPublicRequest(token)`. If the request is
  expired / fulfilled / locked the page renders a status message and
  stops.

  Phase 2 — render an input per requested field. On submit the store
  encrypts each value client-side (RSA-OAEP-SHA256) and POSTs the
  blobs. Plaintext NEVER hits the network.

  @spec openspec/changes/implement-secret-requests/tasks.md#task-8.1
-->
<template>
	<div class="doriath-secret-request-fill" data-testid="secret-request-fill">
		<header>
			<h1>{{ t('doriath', 'Fill in secret') }}</h1>
		</header>

		<p
			v-if="store.loading && !store.publicRequest"
			class="doriath-secret-request-fill__loading">
			{{ t('doriath', 'Loading…') }}
		</p>

		<div
			v-else-if="loadError"
			class="doriath-secret-request-fill__error"
			data-testid="fill-load-error">
			<p>{{ loadError }}</p>
		</div>

		<div
			v-else-if="store.publicRequest && unavailableMessage"
			class="doriath-secret-request-fill__unavailable"
			data-testid="fill-unavailable">
			<p>{{ unavailableMessage }}</p>
		</div>

		<form
			v-else-if="
				store.publicRequest && store.publicRequest.status === 'pending'
			"
			class="doriath-secret-request-fill__form"
			data-testid="fill-form"
			@submit.prevent="onSubmit">
			<label
				v-for="field in store.publicRequest.requested_fields"
				:key="field"
				class="doriath-secret-request-fill__field">
				<span>{{ field }}</span>
				<input
					v-model="values[field]"
					:type="inputType(field)"
					autocomplete="off"
					required
					:data-testid="`fill-field-${field}`" />
			</label>

			<p
				v-if="submitError"
				class="doriath-secret-request-fill__submit-error"
				data-testid="fill-submit-error">
				{{ submitError }}
			</p>

			<p
				v-if="submitted"
				class="doriath-secret-request-fill__success"
				data-testid="fill-success">
				{{ t('doriath', 'Thanks — the secret has been delivered.') }}
			</p>

			<button
				v-if="!submitted"
				type="submit"
				class="primary"
				data-testid="fill-submit"
				:disabled="busy">
				{{ busy ? t('doriath', 'Encrypting…') : t('doriath', 'Submit') }}
			</button>
		</form>
	</div>
</template>

<script>
import { useSecretRequestStore } from '../store/modules/secretRequest.js'

export default {
	name: 'SecretRequestFill',
	props: {
		token: {
			type: String,
			required: true,
		},
	},
	data() {
		return {
			values: {},
			busy: false,
			submitted: false,
			submitError: null,
			loadError: null,
			store: useSecretRequestStore(),
		}
	},
	computed: {
		unavailableMessage() {
			const status = this.store.publicRequest?.status
			switch (status) {
				case 'fulfilled':
					return t('doriath', 'This request has already been fulfilled.')
				case 'declined':
					return t('doriath', 'This request was declined.')
				case 'locked':
					return t(
						'doriath',
						'This request is temporarily unavailable while a compromise recovery is in progress.',
					)
				case 'expired':
					return t('doriath', 'This request has expired.')
				default:
					return null
			}
		},
	},
	async mounted() {
		this.loadError = null
		try {
			await this.store.fetchPublicRequest(this.token)
		} catch (e) {
			this.loadError =
				e?.response?.data?.message
				|| e?.message
				|| t('doriath', 'Request not found')
		}
	},
	methods: {
		inputType(field) {
			const name = String(field || '').toLowerCase()
			if (
				name.includes('password')
				|| name.includes('key')
				|| name.includes('secret')
			) {
				return 'password'
			}
			return 'text'
		},
		async onSubmit() {
			this.submitError = null
			this.busy = true
			try {
				await this.store.submitFill(this.token, this.values)
				this.submitted = true
			} catch (e) {
				this.submitError =
					e?.response?.data?.message
					|| e?.message
					|| t('doriath', 'Failed to submit')
			} finally {
				this.busy = false
			}
		},
	},
}
</script>

<style scoped>
.doriath-secret-request-fill {
	max-width: 480px;
	margin: 48px auto;
	padding: 24px;
	border: 1px solid var(--color-border, #ddd);
	border-radius: var(--border-radius-large, 12px);
	background-color: var(--color-main-background, #fff);
}

.doriath-secret-request-fill__field {
	display: flex;
	flex-direction: column;
	gap: 4px;
	margin-bottom: 12px;
}

.doriath-secret-request-fill__field input {
	padding: 8px;
	border: 1px solid var(--color-border-dark, #999);
	border-radius: var(--border-radius, 4px);
}

.doriath-secret-request-fill__submit-error {
	color: var(--color-error-text);
	font-size: 13px;
}

.doriath-secret-request-fill__success {
	color: var(--color-success-text);
	font-weight: 600;
}

.primary {
	background-color: var(--color-primary-element, #0082c9);
	color: var(--color-primary-element-text, #fff);
	border: 0;
	padding: 10px 20px;
	border-radius: var(--border-radius, 4px);
}
</style>
