<!--
  SPDX-License-Identifier: EUPL-1.2
  SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>

  Share-request form (§12.4). A user who already holds a share of a
  secret asks the owner to share it with a third party. The owner gets
  a notification carrying the request payload; the request is resolved
  through the standard share flow once approved.

  This component owns the UI only — the actual POST goes through the
  parent (so the parent can show the same loading state next to the
  list of pending requests).

  @spec openspec/changes/implement-user-sharing/tasks.md#12.4
-->
<template>
	<section v-if="open"
		class="doriath-share-request-form"
		role="dialog"
		data-testid="share-request-form">
		<header class="doriath-share-request-form__header">
			<h3>{{ t('doriath', 'Request the owner share this secret with someone') }}</h3>
			<button
				type="button"
				class="doriath-share-request-form__close"
				data-testid="share-request-form-close"
				:aria-label="t('doriath', 'Close')"
				@click="$emit('close')">
				<span aria-hidden="true">×</span>
			</button>
		</header>
		<form @submit.prevent="onSubmit">
			<label class="doriath-share-request-form__field">
				<span>{{ t('doriath', 'Target Nextcloud user ID') }}</span>
				<input
					v-model.trim="targetUserId"
					type="text"
					required
					autocomplete="off"
					data-testid="share-request-form-target">
			</label>
			<p v-if="error" class="doriath-share-request-form__error" data-testid="share-request-form-error">
				{{ error }}
			</p>
			<div class="doriath-share-request-form__actions">
				<button
					type="button"
					data-testid="share-request-form-cancel"
					@click="$emit('close')">
					{{ t('doriath', 'Cancel') }}
				</button>
				<button
					type="submit"
					:disabled="!targetUserId || submitting"
					data-testid="share-request-form-submit">
					{{ submitting
						? t('doriath', 'Submitting…')
						: t('doriath', 'Send request') }}
				</button>
			</div>
		</form>
	</section>
</template>

<script>
import axios from '@nextcloud/axios'
import { generateUrl } from '@nextcloud/router'

export default {
	name: 'ShareRequestForm',

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

	emits: ['close', 'submitted'],

	data() {
		return {
			targetUserId: '',
			error: null,
			submitting: false,
		}
	},

	watch: {
		open(now) {
			if (now === true) {
				this.targetUserId = ''
				this.error = null
				this.submitting = false
			}
		},
	},

	methods: {
		async onSubmit() {
			this.error = null
			if (this.targetUserId === '') {
				this.error = t('doriath', 'Pick a recipient user')
				return
			}
			this.submitting = true
			try {
				await axios.post(
					generateUrl('/apps/doriath/api/v1/share-requests'),
					{
						sourceSecretId: this.secretId,
						targetUserId: this.targetUserId,
					},
				)
				this.$emit('submitted', { targetUserId: this.targetUserId })
				this.$emit('close')
			} catch (e) {
				this.error = e?.response?.data?.message || e?.message
					|| t('doriath', 'Failed to send the share request')
			} finally {
				this.submitting = false
			}
		},
	},
}
</script>

<style scoped>
.doriath-share-request-form {
	border: 1px solid var(--color-border, #e0e0e0);
	border-radius: 8px;
	padding: 16px;
	background: var(--color-main-background, #fff);
	margin: 16px 0;
}

.doriath-share-request-form__header {
	display: flex;
	justify-content: space-between;
	align-items: center;
	margin-bottom: 12px;
}

.doriath-share-request-form__header h3 {
	margin: 0;
	font-size: 1.1rem;
}

.doriath-share-request-form__close {
	background: none;
	border: none;
	font-size: 1.5rem;
	cursor: pointer;
}

.doriath-share-request-form__field {
	display: flex;
	flex-direction: column;
	margin-bottom: 12px;
}

.doriath-share-request-form__field input {
	margin-top: 4px;
	padding: 8px;
	border: 1px solid var(--color-border, #ccc);
	border-radius: 4px;
}

.doriath-share-request-form__error {
	color: var(--color-error, #c00);
	margin: 8px 0;
}

.doriath-share-request-form__actions {
	display: flex;
	justify-content: flex-end;
	gap: 8px;
}
</style>
