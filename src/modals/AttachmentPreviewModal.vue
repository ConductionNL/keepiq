<!--
  SPDX-License-Identifier: EUPL-1.2
  SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>

  AttachmentPreviewModal — shows an attachment the browser decrypted
  (vault-item-clone-preview-and-print D2): an image, a PDF in a sandboxed
  frame, or plain text. The plaintext only ever lives in an in-memory object
  URL, which is revoked when the modal closes or goes away; nothing is saved
  to the device and no server URL serves the plaintext.

  @spec openspec/changes/vault-item-clone-preview-and-print/specs/secret-item-actions/spec.md#requirement-preview-an-attachment-in-the-browser
-->
<template>
	<NcDialog
		:name="filename || t('keepiq', 'Attachment')"
		:open="true"
		size="large"
		@update:open="onUpdateOpen">
		<div class="attachment-preview" data-testid="attachment-preview">
			<img
				v-if="preview.kind === 'image'"
				class="attachment-preview__image"
				:src="preview.url"
				:alt="filename || t('keepiq', 'Attachment')"
				data-testid="attachment-preview-image">
			<iframe
				v-else-if="preview.kind === 'pdf'"
				class="attachment-preview__frame"
				:src="preview.url"
				sandbox=""
				:title="filename || t('keepiq', 'Attachment')"
				data-testid="attachment-preview-pdf" />
			<pre
				v-else-if="preview.kind === 'text'"
				class="attachment-preview__text"
				data-testid="attachment-preview-text">{{ preview.text }}</pre>
		</div>
		<template #actions>
			<NcButton variant="primary" data-testid="attachment-preview-close" @click="onUpdateOpen(false)">
				{{ t('keepiq', 'Close') }}
			</NcButton>
		</template>
	</NcDialog>
</template>

<script>
import { NcButton, NcDialog } from '@nextcloud/vue'

/**
 * The preview of one decrypted attachment. Emits `close` when dismissed.
 */
export default {
	name: 'AttachmentPreviewModal',

	components: {
		NcButton,
		NcDialog,
	},

	props: {
		/** What `useAttachmentStore().preview()` returned: `{kind, url, text}`. */
		preview: {
			type: Object,
			required: true,
		},

		/** The attachment's decrypted filename. */
		filename: {
			type: String,
			default: '',
		},
	},

	emits: ['close'],

	/**
	 * Non-reactive: whether the object URL was revoked already.
	 *
	 * @return {void}
	 */
	created() {
		this.revoked = false
	},

	/**
	 * Revoke the object URL however the modal goes away.
	 *
	 * @return {void}
	 * @spec openspec/changes/vault-item-clone-preview-and-print/specs/secret-item-actions/spec.md#requirement-preview-an-attachment-in-the-browser
	 */
	beforeUnmount() {
		this.revoke()
	},

	methods: {
		/**
		 * Close: revoke the object URL, then tell the parent.
		 *
		 * @param {boolean} open The dialog's new open state.
		 * @return {void}
		 * @spec openspec/changes/vault-item-clone-preview-and-print/specs/secret-item-actions/spec.md#requirement-preview-an-attachment-in-the-browser
		 */
		onUpdateOpen(open) {
			if (open) {
				return
			}
			this.revoke()
			this.$emit('close')
		},

		/**
		 * Revoke the preview's object URL, once.
		 *
		 * @return {void}
		 */
		revoke() {
			if (this.preview.url && !this.revoked) {
				URL.revokeObjectURL(this.preview.url)
				this.revoked = true
			}
		},
	},
}
</script>

<style scoped>
.attachment-preview {
	display: flex;
	justify-content: center;
	min-height: 200px;
}

.attachment-preview__image {
	max-width: 100%;
	max-height: 70vh;
	object-fit: contain;
}

.attachment-preview__frame {
	width: 100%;
	height: 70vh;
	border: 1px solid var(--color-border);
}

.attachment-preview__text {
	width: 100%;
	max-height: 70vh;
	overflow: auto;
	margin: 0;
	padding: 8px;
	white-space: pre-wrap;
	word-break: break-word;
	background: var(--color-background-dark);
	color: var(--color-main-text);
}
</style>
