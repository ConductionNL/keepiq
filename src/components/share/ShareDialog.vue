<!--
  SPDX-License-Identifier: EUPL-1.2
  SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>

  User-to-user share dialog. The owner picks a recipient Nextcloud user
  id, the parent component supplies the plaintext snapshot + the
  recipient's public certificate, and the dialog runs the browser-side
  RSA encryption through `useShareStore.encryptForRecipient` before
  emitting an `encrypted` event.

  The recipient's encrypted Secret copy is persisted via the secrets
  endpoint by the parent — this dialog only owns the picker UI and the
  encryption call.

  @spec openspec/changes/implement-user-sharing/tasks.md#task-12.1
-->
<template>
	<section v-if="open"
		class="doriath-share-dialog"
		role="dialog"
		data-testid="share-dialog">
		<header class="doriath-share-dialog__header">
			<h3>{{ t('doriath', 'Share with a Nextcloud user') }}</h3>
			<button type="button"
				class="doriath-share-dialog__close"
				data-testid="share-dialog-close"
				@click="$emit('close')">
				×
			</button>
		</header>
		<form @submit.prevent="onSubmit">
			<label class="doriath-share-dialog__field">
				<span>{{ t('doriath', 'Recipient user ID') }}</span>
				<input
					v-model.trim="targetUserId"
					type="text"
					required
					autocomplete="off"
					data-testid="share-dialog-target">
			</label>
			<p v-if="error" class="doriath-share-dialog__error" data-testid="share-dialog-error">
				{{ error }}
			</p>
			<div class="doriath-share-dialog__actions">
				<button type="button" data-testid="share-dialog-cancel" @click="$emit('close')">
					{{ t('doriath', 'Cancel') }}
				</button>
				<button
					type="submit"
					class="primary"
					data-testid="share-dialog-submit"
					:disabled="busy || targetUserId === ''">
					{{ busy ? t('doriath', 'Sharing…') : t('doriath', 'Share') }}
				</button>
			</div>
		</form>
	</section>
</template>

<script>
import { useShareStore } from '../../store/modules/share.js'

export default {
	name: 'ShareDialog',
	props: {
		open: {
			type: Boolean,
			default: false,
		},
		secretId: {
			type: String,
			required: true,
		},
		plaintextSnapshot: {
			type: Object,
			default: () => ({}),
		},
		recipientCertificate: {
			type: String,
			default: '',
		},
	},
	emits: ['close', 'shared'],
	data() {
		return {
			targetUserId: '',
			busy: false,
			error: null,
		}
	},
	watch: {
		open(val) {
			if (val === false) {
				this.targetUserId = ''
				this.error = null
				this.busy = false
			}
		},
	},
	methods: {
		async onSubmit() {
			this.error = null
			if (this.targetUserId === '') {
				this.error = t('doriath', 'Recipient is required')
				return
			}
			if (this.recipientCertificate === '') {
				this.error = t('doriath', 'Recipient has no active encryption suite')
				return
			}

			const store = useShareStore()
			this.busy = true
			try {
				const encrypted = await store.encryptForRecipient(this.plaintextSnapshot, this.recipientCertificate)
				this.$emit('shared', {
					targetUserId: this.targetUserId,
					encryptedFields: encrypted,
				})
			} catch (e) {
				this.error = e?.message || t('doriath', 'Failed to encrypt for recipient')
			} finally {
				this.busy = false
			}
		},
	},
}
</script>

<style scoped>
.doriath-share-dialog {
	border: 1px solid var(--color-border, #ddd);
	background-color: var(--color-main-background, #fff);
	padding: 16px;
	border-radius: var(--border-radius-large, 12px);
	max-width: 480px;
}

.doriath-share-dialog__header {
	display: flex;
	justify-content: space-between;
	align-items: center;
	margin-bottom: 12px;
}

.doriath-share-dialog__close {
	background: transparent;
	border: 0;
	font-size: 24px;
	cursor: pointer;
}

.doriath-share-dialog__field {
	display: flex;
	flex-direction: column;
	gap: 4px;
	margin-bottom: 12px;
}

.doriath-share-dialog__field input {
	padding: 8px;
	border: 1px solid var(--color-border-dark, #999);
	border-radius: var(--border-radius, 4px);
}

.doriath-share-dialog__error {
	color: var(--color-error, #e9322d);
	font-size: 13px;
}

.doriath-share-dialog__actions {
	display: flex;
	justify-content: flex-end;
	gap: 8px;
}

.doriath-share-dialog__actions .primary {
	background-color: var(--color-primary-element, #0082c9);
	color: var(--color-primary-element-text, #fff);
	border: 0;
	padding: 8px 16px;
	border-radius: var(--border-radius, 4px);
}

.doriath-share-dialog__actions button:not(.primary) {
	background-color: transparent;
	border: 1px solid var(--color-border-dark, #999);
	padding: 8px 16px;
	border-radius: var(--border-radius, 4px);
}
</style>
