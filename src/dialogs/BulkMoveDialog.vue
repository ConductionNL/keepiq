<!--
  SPDX-License-Identifier: EUPL-1.2
  SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>

  Bulk-move dialog (bulk-actions §4): move the selected secrets to a
  target folder via metadata-only updates (no re-encryption). Runs
  through the shared chunked runner with progress + per-item report.

  @spec openspec/changes/bulk-actions/specs/bulk-actions/spec.md#requirement-bulk-move
-->
<template>
	<NcDialog
		:name="t('keepiq', 'Move {count} secrets', { count: bulk.selectionCount })"
		:open="open"
		size="normal"
		data-testid="bulk-move-dialog"
		@update:open="$emit('close')">
		<div class="bulk-move">
			<NcSelect
				v-model="targetFolder"
				:options="folderOptions"
				:inputLabel="t('keepiq', 'Target folder')"
				label="label"
				data-testid="bulk-move-folder" />
			<BulkRunPanel @retry="onRetry" />
		</div>
		<template #actions>
			<NcButton variant="tertiary" @click="$emit('close')">
				{{ t('keepiq', 'Close') }}
			</NcButton>
			<NcButton
				variant="primary"
				:disabled="!targetFolder || bulk.progress.running"
				data-testid="bulk-move-run"
				@click="onRun">
				{{ t('keepiq', 'Move') }}
			</NcButton>
		</template>
	</NcDialog>
</template>

<script>
import { NcButton, NcDialog, NcSelect } from '@nextcloud/vue'
import BulkRunPanel from '../components/BulkRunPanel.vue'
import { useBulkStore } from '../store/modules/bulk.js'
import { useFolderStore } from '../store/modules/folder.js'
import { useSecretStore } from '../store/modules/secret.js'

export default {
	name: 'BulkMoveDialog',
	components: {
		NcButton,
		NcDialog,
		NcSelect,
		BulkRunPanel,
	},

	props: {
		open: {
			type: Boolean,
			default: false,
		},
	},

	emits: ['close', 'done'],
	data() {
		return {
			targetFolder: null,
		}
	},

	computed: {
		bulk() {
			return useBulkStore()
		},

		/**
		 * The move-target picker options: the vault root plus every folder
		 * the user owns.
		 *
		 * @return {Array<{id: string|null, label: string}>}
		 * @spec openspec/specs/bulk-actions/spec.md#requirement-the-four-bulk-operations
		 */
		folderOptions() {
			const options = [{ id: null, label: this.t('keepiq', 'Vault root') }]
			for (const folder of useFolderStore().folders) {
				options.push({ id: folder.id, label: folder.name })
			}
			return options
		},
	},

	methods: {
		/**
		 * The per-item move: metadata-only folderId update.
		 *
		 * @param {string} secretId The secret id.
		 * @return {Promise<object>}
		 */
		async moveOne(secretId) {
			await useSecretStore().updateSecret(secretId, {
				folderId: this.targetFolder.id,
			})
			return { status: 'ok' }
		},

		/**
		 * Run the chunked move over the selection.
		 *
		 * @return {Promise<void>}
		 * @spec openspec/specs/bulk-actions/spec.md#requirement-the-four-bulk-operations
		 * @spec openspec/specs/bulk-actions/spec.md#requirement-chunked-execution-with-a-per-item-report
		 */
		async onRun() {
			await this.bulk.run(
				this.bulk.selectedIds,
				(id) => this.moveOne(id),
				this.t('keepiq', 'Moving secrets'),
			)
			this.$emit('done')
		},

		/**
		 * Retry only the failed subset.
		 *
		 * @return {Promise<void>}
		 * @spec openspec/specs/bulk-actions/spec.md#requirement-chunked-execution-with-a-per-item-report
		 */
		async onRetry() {
			await this.bulk.retryFailed(
				(id) => this.moveOne(id),
				this.t('keepiq', 'Retrying move'),
			)
			this.$emit('done')
		},
	},
}
</script>

<style scoped>
.bulk-move {
	display: flex;
	flex-direction: column;
	gap: 12px;
	padding: 4px 12px 12px;
}
</style>
