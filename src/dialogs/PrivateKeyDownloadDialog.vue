<!--
  SPDX-License-Identifier: EUPL-1.2
  SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>

  One-time display of the private key returned by the registration /
  approval endpoints. The user MUST tick the acknowledgment checkbox
  before the dialog can be dismissed; the parent must call
  `useApplicationStore.consumeOneTimePrivateKey()` after dismiss so the
  in-memory copy is cleared.

  Rendered as a real NcDialog overlay: the first cut was a bare
  <section role="dialog"> in document flow, which painted the one-time,
  unrecoverable key below the fold where nobody saw it. `noClose` keeps
  every library escape hatch (close button, Esc, outside click) shut,
  so the acknowledgment gate on the Dismiss button is the only way out.

  @spec openspec/changes/implement-application-mgmt/tasks.md#task-10.4
-->
<template>
	<NcDialog
		:name="t('keepiq', 'Your private key')"
		:open="open"
		size="normal"
		:noClose="true"
		data-testid="private-key-dialog">
		<div class="private-key">
			<!-- The warning must be unmissable in either theme; NcNoteCard's
			     error variant is the library's guaranteed-contrast pairing. -->
			<NcNoteCard type="error" data-testid="private-key-warning">
				{{
					t(
						'keepiq',
						'This is the only time this private key will be shown. Save it securely; it cannot be recovered.',
					)
				}}
			</NcNoteCard>

			<textarea
				readonly
				rows="10"
				class="private-key__key"
				:aria-label="t('keepiq', 'Private key')"
				data-testid="private-key-textarea"
				:value="privateKey" />

			<div class="private-key__actions">
				<NcButton data-testid="private-key-copy" @click="onCopy">
					<template #icon>
						<ContentCopy :size="20" />
					</template>
					{{ copyLabel }}
				</NcButton>
				<NcButton data-testid="private-key-download" @click="onDownload">
					<template #icon>
						<Download :size="20" />
					</template>
					{{ t('keepiq', 'Download as .pem') }}
				</NcButton>
			</div>

			<NcCheckboxRadioSwitch
				:modelValue="acknowledged"
				data-testid="private-key-ack"
				@update:modelValue="acknowledged = $event">
				{{ t('keepiq', 'I have stored the private key in a safe place.') }}
			</NcCheckboxRadioSwitch>
		</div>

		<template #actions>
			<NcButton
				variant="primary"
				:disabled="!acknowledged"
				data-testid="private-key-dismiss"
				@click="$emit('close')">
				{{ t('keepiq', 'Dismiss') }}
			</NcButton>
		</template>
	</NcDialog>
</template>

<script>
import {
	NcButton,
	NcCheckboxRadioSwitch,
	NcDialog,
	NcNoteCard,
} from '@nextcloud/vue'
import ContentCopy from 'vue-material-design-icons/ContentCopy.vue'
import Download from 'vue-material-design-icons/Download.vue'

export default {
	name: 'PrivateKeyDownloadDialog',

	components: {
		ContentCopy,
		Download,
		NcButton,
		NcCheckboxRadioSwitch,
		NcDialog,
		NcNoteCard,
	},

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
		/**
		 * The copy button's label, which flips to a transient confirmation
		 * after the key has been copied.
		 *
		 * @return {string}
		 * @spec openspec/specs/application-mgmt/spec.md#requirement-encryptionsuite-via-csr
		 */
		copyLabel() {
			return this.copied
				? t('keepiq', 'Copied!')
				: t('keepiq', 'Copy to clipboard')
		},
	},

	watch: {
		/**
		 * Reset the acknowledgment when the parent closes the dialog, so a
		 * later key starts unacknowledged again.
		 *
		 * @param {boolean} val The new open state.
		 * @spec exclude Dialog open-state reset plumbing; no domain behaviour.
		 */
		open(val) {
			if (val === false) {
				this.acknowledged = false
				this.copied = false
			}
		},
	},

	methods: {
		t,

		/**
		 * Copy the one-time key to the clipboard, with a transient
		 * confirmation on the button.
		 *
		 * @return {Promise<void>}
		 * @spec openspec/specs/application-mgmt/spec.md#requirement-encryptionsuite-via-csr
		 */
		async onCopy() {
			try {
				if (navigator?.clipboard?.writeText) {
					await navigator.clipboard.writeText(this.privateKey)
					this.copied = true
					setTimeout(() => {
						this.copied = false
					}, 1500)
				}
			} catch {
				// Silently ignore — the user can still copy from the textarea.
			}
		},

		/**
		 * Download the one-time key as a .pem file.
		 *
		 * @return {void}
		 * @spec openspec/specs/application-mgmt/spec.md#requirement-encryptionsuite-via-csr
		 */
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
			} catch {
				// Best-effort; if the browser blocks downloads we leave the
				// textarea as the fallback path.
			}
		},
	},
}
</script>

<style scoped>
.private-key {
	display: flex;
	flex-direction: column;
	gap: 12px;
	padding: 4px 0;
}

.private-key__key {
	width: 100%;
	font-family: monospace;
	font-size: 12px;
	padding: 8px;
	border: 1px solid var(--color-border-dark, #999);
	border-radius: var(--border-radius, 4px);
}

.private-key__actions {
	display: flex;
	gap: 8px;
}
</style>
