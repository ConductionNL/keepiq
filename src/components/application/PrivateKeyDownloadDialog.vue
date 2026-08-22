<!--
  SPDX-License-Identifier: EUPL-1.2
  SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>

  One-time display of the private key returned by the registration /
  approval endpoints. The user MUST tick the acknowledgment checkbox
  before the dialog can be dismissed; the parent must call
  `useApplicationStore.consumeOneTimePrivateKey()` after dismiss so the
  in-memory copy is cleared.

  @spec openspec/changes/implement-application-mgmt/tasks.md#task-10.4
-->
<template>
	<section
		v-if="open"
		class="keepiq-private-key-dialog"
		role="dialog"
		data-testid="private-key-dialog">
		<header>
			<h3>{{ t('keepiq', 'Your private key') }}</h3>
		</header>

		<p
			class="keepiq-private-key-dialog__warning"
			data-testid="private-key-warning">
			{{
				t(
					'keepiq',
					'This is the only time this private key will be shown. Save it securely; it cannot be recovered.',
				)
			}}
		</p>

		<textarea
			readonly
			rows="10"
			class="keepiq-private-key-dialog__key"
			:aria-label="t('keepiq', 'Private key')"
			data-testid="private-key-textarea"
			:value="privateKey" />

		<div class="keepiq-private-key-dialog__actions">
			<button type="button" data-testid="private-key-copy" @click="onCopy">
				{{ copyLabel }}
			</button>
			<button
				type="button"
				data-testid="private-key-download"
				@click="onDownload">
				{{ t('keepiq', 'Download as .pem') }}
			</button>
		</div>

		<label class="keepiq-private-key-dialog__ack">
			<input
				v-model="acknowledged"
				type="checkbox"
				data-testid="private-key-ack" />
			<span>{{
				t('keepiq', 'I have stored the private key in a safe place.')
			}}</span>
		</label>

		<div class="keepiq-private-key-dialog__close">
			<button
				type="button"
				class="primary"
				data-testid="private-key-dismiss"
				:disabled="!acknowledged"
				@click="$emit('close')">
				{{ t('keepiq', 'Dismiss') }}
			</button>
		</div>
	</section>
</template>

<script>
export default {
	name: 'PrivateKeyDownloadDialog',
	props: {
		open: {
			type: Boolean,
			default: false,
		},

		privateKey: {
			type: String,
			default: '',
		},

		filename: {
			type: String,
			default: 'keepiq-application.pem',
		},
	},

	emits: ['close'],
	data() {
		return {
			acknowledged: false,
			copied: false,
		}
	},

	computed: {
		copyLabel() {
			return this.copied
				? t('keepiq', 'Copied!')
				: t('keepiq', 'Copy to clipboard')
		},
	},

	watch: {
		open(val) {
			if (val === false) {
				this.acknowledged = false
				this.copied = false
			}
		},
	},

	methods: {
		async onCopy() {
			try {
				if (navigator?.clipboard?.writeText) {
					await navigator.clipboard.writeText(this.privateKey)
					this.copied = true
					setTimeout(() => {
						this.copied = false
					}, 1500)
				}
			} catch (_e) {
				// Silently ignore — the user can still copy from the textarea.
			}
		},

		onDownload() {
			try {
				const blob = new Blob([this.privateKey], {
					type: 'application/x-pem-file',
				})
				const url = URL.createObjectURL(blob)
				const a = document.createElement('a')
				a.href = url
				a.download = this.filename
				document.body.appendChild(a)
				a.click()
				document.body.removeChild(a)
				URL.revokeObjectURL(url)
			} catch (_e) {
				// Best-effort; if the browser blocks downloads we leave the
				// textarea as the fallback path.
			}
		},
	},
}
</script>

<style scoped>
.keepiq-private-key-dialog {
	max-width: 640px;
	background-color: var(--color-main-background, #fff);
	border: 1px solid var(--color-border, #ddd);
	border-radius: var(--border-radius-large, 12px);
	padding: 16px;
}

.keepiq-private-key-dialog__warning {
	/* This is the one-time private-key download warning, so it must be
	   unmissable in either theme. --color-error-rest does not exist and the pale
	   pink fallback left near-white dark-mode text unreadable on it; the
	   --color-error / --color-error-text pairing is guaranteed contrasty. */
	background-color: var(--color-error);
	color: var(--color-error-text);
	border: 1px solid var(--color-error-text);
	padding: 8px;
	border-radius: var(--border-radius, 4px);
	margin-bottom: 8px;
	font-weight: 600;
}

.keepiq-private-key-dialog__key {
	width: 100%;
	font-family: monospace;
	font-size: 12px;
	padding: 8px;
	border: 1px solid var(--color-border-dark, #999);
	border-radius: var(--border-radius, 4px);
}

.keepiq-private-key-dialog__actions {
	display: flex;
	gap: 8px;
	margin-top: 8px;
}

.keepiq-private-key-dialog__actions button {
	border: 1px solid var(--color-border-dark, #999);
	background-color: transparent;
	padding: 6px 12px;
	border-radius: var(--border-radius, 4px);
}

.keepiq-private-key-dialog__ack {
	display: flex;
	align-items: center;
	gap: 8px;
	margin-top: 12px;
}

.keepiq-private-key-dialog__close {
	display: flex;
	justify-content: flex-end;
	margin-top: 12px;
}

.keepiq-private-key-dialog__close .primary {
	background-color: var(--color-primary-element, #0082c9);
	color: var(--color-primary-element-text, #fff);
	border: 0;
	padding: 8px 16px;
	border-radius: var(--border-radius, 4px);
}

.keepiq-private-key-dialog__close .primary:disabled {
	background-color: var(--color-background-darker, #ccc);
	cursor: not-allowed;
}
</style>
