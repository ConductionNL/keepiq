<!--
  SPDX-License-Identifier: EUPL-1.2
  SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>

  Files waiting to be attached to a secret from the create and edit
  dialogs. Nothing uploads until the dialog calls `uploadPending()` after
  its save; each file is then encrypted and uploaded through the attachment
  store, under the same limits as the sidebar upload.

  @spec openspec/changes/attachments-in-secret-modals/specs/encrypted-attachments/spec.md#requirement-add-attachments-from-the-create-and-edit-secret-dialogs
-->
<template>
	<div
		class="attachment-dropzone"
		:class="{ 'attachment-dropzone--over': dragging }"
		data-testid="attachment-dropzone"
		@dragenter.prevent="onDragOver"
		@dragover.prevent="onDragOver"
		@dragleave.prevent="dragging = false"
		@drop.prevent="onDrop">
		<div class="attachment-dropzone__prompt">
			<Paperclip :size="20" />
			<span>{{ t('keepiq', 'Drop files here to attach them') }}</span>
			<input
				ref="fileInput"
				type="file"
				multiple
				class="attachment-dropzone__input"
				:aria-label="t('keepiq', 'Add file')"
				:disabled="!canAdd"
				data-testid="attachment-dropzone-input"
				@change="onPicked" />
			<NcButton
				variant="secondary"
				:disabled="!canAdd"
				data-testid="attachment-dropzone-add"
				@click="$refs.fileInput.click()">
				{{ t('keepiq', 'Add file') }}
			</NcButton>
		</div>

		<p
			v-if="unavailable"
			class="attachment-dropzone__note"
			data-testid="attachment-dropzone-unavailable">
			{{ unavailable }}
		</p>

		<p
			v-if="droppedFolder"
			class="attachment-dropzone__error"
			data-testid="attachment-dropzone-folders">
			{{ t('keepiq', 'Folders cannot be attached. Drop the files inside them instead.') }}
		</p>

		<ul v-if="pending.length" class="attachment-dropzone__list">
			<li
				v-for="(entry, index) in pending"
				:key="entry.key"
				class="attachment-dropzone__row"
				:data-testid="`attachment-pending-${index}`">
				<span class="attachment-dropzone__name">{{ entry.file.name }}</span>
				<span class="attachment-dropzone__size">{{
					humanSize(entry.file.size)
				}}</span>
				<NcLoadingIcon v-if="entry.status === 'uploading'" :size="20" />
				<NcButton
					v-if="entry.status === 'failed' && secretId"
					variant="tertiary"
					:aria-label="t('keepiq', 'Retry upload of {name}', { name: entry.file.name })"
					:disabled="disabled || busy || Boolean(unavailable)"
					:data-testid="`attachment-pending-retry-${index}`"
					@click="retry(entry)">
					<template #icon>
						<Refresh :size="18" />
					</template>
				</NcButton>
				<NcButton
					variant="tertiary"
					:aria-label="t('keepiq', 'Remove {name}', { name: entry.file.name })"
					:disabled="disabled || entry.status === 'uploading'"
					:data-testid="`attachment-pending-remove-${index}`"
					@click="remove(entry)">
					<template #icon>
						<Close :size="18" />
					</template>
				</NcButton>
				<p
					v-if="entry.status === 'failed'"
					class="attachment-dropzone__error"
					:data-testid="`attachment-pending-error-${index}`">
					{{ entry.error }}
				</p>
			</li>
		</ul>
	</div>
</template>

<script>
import { NcButton, NcLoadingIcon } from '@nextcloud/vue'
import Close from 'vue-material-design-icons/Close.vue'
import Paperclip from 'vue-material-design-icons/Paperclip.vue'
import Refresh from 'vue-material-design-icons/Refresh.vue'
import { humanSize, useAttachmentStore } from '../store/modules/attachment.js'

let nextKey = 0

export default {
	name: 'AttachmentDropZone',
	components: {
		NcButton,
		NcLoadingIcon,
		Close,
		Paperclip,
		Refresh,
	},

	props: {
		disabled: {
			type: Boolean,
			default: false,
		},

		/**
		 * Why files cannot be added right now, '' when they can. Listed files
		 * stay, so the user can see and remove them.
		 */
		unavailable: {
			type: String,
			default: '',
		},

		/** The saved secret, once there is one: enables Retry per file. */
		secretId: {
			type: String,
			default: null,
		},
	},

	emits: ['update:pending', 'update:busy'],

	data() {
		return {
			/** @type {Array<{key: number, file: File, status: 'waiting'|'uploading'|'failed', error: string}>} */
			pending: [],
			dragging: false,
			/** Whether the last drop held a folder, which was left out. */
			droppedFolder: false,
		}
	},

	computed: {
		/**
		 * @spec exclude Derived UI state: whether an upload is running.
		 */
		busy() {
			return this.pending.some((entry) => entry.status === 'uploading')
		},

		/**
		 * @spec openspec/changes/attachments-in-secret-modals/specs/encrypted-attachments/spec.md#requirement-add-attachments-from-the-create-and-edit-secret-dialogs
		 */
		canAdd() {
			return !this.disabled && !this.unavailable
		},

		/**
		 * @spec exclude Derived UI state: how many files are listed.
		 */
		pendingCount() {
			return this.pending.length
		},
	},

	watch: {
		/**
		 * @param {number} count The files listed.
		 * @spec exclude Component plumbing: tells the dialog how many files are listed.
		 */
		pendingCount(count) {
			this.$emit('update:pending', count)
		},

		/**
		 * @param {boolean} busy Whether an upload is running.
		 * @spec exclude Component plumbing: lets the dialog hold its button while a file uploads.
		 */
		busy(busy) {
			this.$emit('update:busy', busy)
		},
	},

	/**
	 * A file dropped beside the zone would otherwise be opened by the
	 * browser in place of the app, losing the dialog.
	 *
	 * @spec openspec/changes/attachments-in-secret-modals/specs/encrypted-attachments/spec.md#requirement-add-attachments-from-the-create-and-edit-secret-dialogs
	 */
	mounted() {
		window.addEventListener('dragover', this.swallowFileDrop)
		window.addEventListener('drop', this.swallowFileDrop)
	},

	unmounted() {
		window.removeEventListener('dragover', this.swallowFileDrop)
		window.removeEventListener('drop', this.swallowFileDrop)
	},

	methods: {
		humanSize,

		/**
		 * Upload every listed file to the saved secret, one at a time. A file
		 * that uploads leaves the list; a refused one stays with its reason.
		 *
		 * @param {string} secretId The saved secret.
		 * @return {Promise<boolean>} Whether every file uploaded.
		 * @spec openspec/changes/attachments-in-secret-modals/specs/encrypted-attachments/spec.md#requirement-add-attachments-from-the-create-and-edit-secret-dialogs
		 */
		async uploadPending(secretId) {
			// A file whose own Retry is running is not sent a second time.
			const queued = this.pending.filter((entry) => entry.status !== 'uploading')
			for (const entry of queued) {
				await this.uploadOne(secretId, entry)
			}
			return this.pending.length === 0
		},

		/**
		 * @param {string} secretId The saved secret.
		 * @param {object} entry The listed file.
		 * @return {Promise<void>}
		 * @spec openspec/changes/attachments-in-secret-modals/specs/encrypted-attachments/spec.md#requirement-add-attachments-from-the-create-and-edit-secret-dialogs
		 */
		async uploadOne(secretId, entry) {
			if (entry.status === 'uploading') {
				return
			}
			entry.status = 'uploading'
			entry.error = ''
			try {
				await useAttachmentStore().uploadFile(secretId, entry.file)
				this.remove(entry)
			} catch (e) {
				entry.status = 'failed'
				entry.error = e?.message || t('keepiq', 'Upload failed')
			}
		},

		/**
		 * @param {object} entry The refused file.
		 * @return {Promise<void>}
		 * @spec openspec/changes/attachments-in-secret-modals/specs/encrypted-attachments/spec.md#requirement-add-attachments-from-the-create-and-edit-secret-dialogs
		 */
		retry(entry) {
			return this.uploadOne(this.secretId, entry)
		},

		/**
		 * @param {object} entry The listed file.
		 * @return {void}
		 * @spec openspec/changes/attachments-in-secret-modals/specs/encrypted-attachments/spec.md#requirement-add-attachments-from-the-create-and-edit-secret-dialogs
		 */
		remove(entry) {
			this.pending = this.pending.filter((other) => other !== entry)
		},

		/**
		 * @param {FileList|Array<File>|null} files The picked or dropped files.
		 * @return {void}
		 * @spec openspec/changes/attachments-in-secret-modals/specs/encrypted-attachments/spec.md#requirement-add-attachments-from-the-create-and-edit-secret-dialogs
		 */
		add(files) {
			for (const file of Array.from(files || [])) {
				this.pending.push({
					key: nextKey++,
					file,
					status: 'waiting',
					error: '',
				})
			}
		},

		/**
		 * @param {Event} event The input's change event.
		 * @return {void}
		 * @spec exclude Event plumbing: hands the picked files to add().
		 */
		onPicked(event) {
			if (this.canAdd) {
				this.add(event.target.files)
			}
			event.target.value = ''
		},

		/**
		 * @spec exclude Event plumbing: highlights the zone while a drag is over it.
		 */
		onDragOver() {
			this.dragging = this.canAdd
		},

		/**
		 * @param {DragEvent} event The drop.
		 * @return {void}
		 * @spec exclude Event plumbing: hands the dropped files to add().
		 */
		onDrop(event) {
			this.dragging = false
			if (!this.canAdd) {
				return
			}
			const items = Array.from(event.dataTransfer?.items || [])
			if (items.length === 0) {
				this.droppedFolder = false
				this.add(event.dataTransfer?.files)
				return
			}
			// A folder arrives as a file that cannot be read, so it is left out.
			const fileItems = items.filter((item) => item.kind === 'file')
			const folders = fileItems.filter(
				(item) => item.webkitGetAsEntry?.()?.isDirectory,
			)
			this.droppedFolder = folders.length > 0
			this.add(
				fileItems
					.filter((item) => !folders.includes(item))
					.map((item) => item.getAsFile())
					.filter(Boolean),
			)
		},

		/**
		 * @param {DragEvent} event A drag over or drop anywhere on the page.
		 * @return {void}
		 * @spec openspec/changes/attachments-in-secret-modals/specs/encrypted-attachments/spec.md#requirement-add-attachments-from-the-create-and-edit-secret-dialogs
		 */
		swallowFileDrop(event) {
			if (Array.from(event.dataTransfer?.types || []).includes('Files')) {
				event.preventDefault()
			}
		},
	},
}
</script>

<style scoped>
.attachment-dropzone {
	display: flex;
	flex-direction: column;
	gap: 8px;
	padding: 12px;
	border: 2px dashed var(--color-border-maxcontrast, #949494);
	border-radius: var(--border-radius-large, 10px);
}

.attachment-dropzone--over {
	border-color: var(--color-primary-element, #0082c9);
	background-color: var(--color-primary-element-light, #e5f2fa);
}

.attachment-dropzone__prompt {
	display: flex;
	align-items: center;
	gap: 8px;
	flex-wrap: wrap;
	color: var(--color-text-maxcontrast, #767676);
}

.attachment-dropzone__note {
	color: var(--color-text-maxcontrast, #767676);
}

.attachment-dropzone__input {
	display: none;
}

.attachment-dropzone__list {
	display: flex;
	flex-direction: column;
	gap: 4px;
}

.attachment-dropzone__row {
	display: flex;
	align-items: center;
	flex-wrap: wrap;
	gap: 8px;
}

.attachment-dropzone__name {
	flex: 1;
	word-break: break-all;
}

.attachment-dropzone__size {
	color: var(--color-text-maxcontrast, #767676);
	font-size: 13px;
}

.attachment-dropzone__error {
	flex-basis: 100%;
	color: var(--color-error-text, #c9302c);
}
</style>
