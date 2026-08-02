<!--
  SPDX-License-Identifier: EUPL-1.2
  SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>

  Attachment panel on the secret detail view (encrypted-attachments §6.1):
  file-picker upload with encrypt-before-upload, a list with decrypted
  filenames + human sizes, and download/delete actions. All crypto lives
  in the attachment store; this component never sees the server's
  ciphertext representation.

  @spec openspec/changes/encrypted-attachments/specs/encrypted-attachments/spec.md#requirement-listing-and-download
-->
<template>
	<div class="attachment-panel" data-testid="attachment-panel">
		<NcNoteCard v-if="store.error" type="error" data-testid="attachment-error">
			{{ store.error }}
		</NcNoteCard>

		<ul v-if="store.attachments.length" class="attachment-panel__list" data-testid="attachment-list">
			<li v-for="attachment in store.attachments" :key="attachment.id" class="attachment-panel__row">
				<Paperclip :size="16" />
				<span class="attachment-panel__name" :data-testid="`attachment-name-${attachment.id}`">
					{{ attachment.filename || t('doriath', '(undecryptable attachment)') }}
				</span>
				<span class="attachment-panel__size">{{ humanSize(attachment.sizeBytes) }}</span>
				<NcButton variant="tertiary"
					:aria-label="t('doriath', 'Download attachment')"
					:data-testid="`attachment-download-${attachment.id}`"
					@click="store.download(attachment)">
					<template #icon>
						<Download :size="18" />
					</template>
				</NcButton>
				<NcButton v-if="canManage"
					variant="tertiary"
					:aria-label="t('doriath', 'Delete attachment')"
					:data-testid="`attachment-delete-${attachment.id}`"
					@click="store.remove(secretId, attachment.id)">
					<template #icon>
						<Delete :size="18" />
					</template>
				</NcButton>
			</li>
		</ul>
		<p v-else class="attachment-panel__empty">
			{{ t('doriath', 'No attachments') }}
		</p>

		<div v-if="canManage" class="attachment-panel__upload">
			<input ref="fileInput"
				type="file"
				class="attachment-panel__file-input"
				data-testid="attachment-file-input"
				@change="onFilePicked">
			<NcButton variant="secondary"
				:disabled="store.loading"
				data-testid="attachment-upload"
				@click="$refs.fileInput.click()">
				<template #icon>
					<Paperclip :size="18" />
				</template>
				{{ t('doriath', 'Add attachment') }}
			</NcButton>
		</div>
	</div>
</template>

<script>
import { NcButton, NcNoteCard } from '@nextcloud/vue'
import Paperclip from 'vue-material-design-icons/Paperclip.vue'
import Download from 'vue-material-design-icons/Download.vue'
import Delete from 'vue-material-design-icons/Delete.vue'
import { useAttachmentStore } from '../store/modules/attachment.js'

export default {
	name: 'AttachmentPanel',
	components: {
		NcButton,
		NcNoteCard,
		Paperclip,
		Download,
		Delete,
	},
	props: {
		secretId: {
			type: String,
			required: true,
		},
		/** Whether the viewer may upload/delete (the secret's owner). */
		canManage: {
			type: Boolean,
			default: false,
		},
	},
	computed: {
		store() {
			return useAttachmentStore()
		},
	},
	async mounted() {
		try {
			await this.store.fetchAttachments(this.secretId)
		} catch {
			// Error surfaced via store state.
		}
	},
	unmounted() {
		this.store.reset()
	},
	methods: {
		/**
		 * Encrypt-and-upload the picked file, then clear the input.
		 *
		 * @param {Event} event The change event.
		 * @return {Promise<void>}
		 */
		async onFilePicked(event) {
			const file = event.target.files && event.target.files[0]
			if (!file) {
				return
			}
			try {
				await this.store.upload(this.secretId, file)
			} finally {
				event.target.value = ''
			}
		},

		/**
		 * Human-readable size.
		 *
		 * @param {number} bytes The ciphertext byte count.
		 * @return {string} e.g. "1.2 MB".
		 */
		humanSize(bytes) {
			if (bytes == null) {
				return ''
			}
			if (bytes < 1024) {
				return `${bytes} B`
			}
			if (bytes < 1048576) {
				return `${(bytes / 1024).toFixed(1)} KB`
			}
			return `${(bytes / 1048576).toFixed(1)} MB`
		},
	},
}
</script>

<style scoped>
.attachment-panel__list {
	display: flex;
	flex-direction: column;
	gap: 4px;
}

.attachment-panel__row {
	display: flex;
	align-items: center;
	gap: 8px;
}

.attachment-panel__name {
	flex: 1;
	word-break: break-all;
}

.attachment-panel__size {
	color: var(--color-text-maxcontrast, #777);
	font-size: 13px;
}

.attachment-panel__file-input {
	display: none;
}

.attachment-panel__empty {
	color: var(--color-text-maxcontrast, #777);
}
</style>
