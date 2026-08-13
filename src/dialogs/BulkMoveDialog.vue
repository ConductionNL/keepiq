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
		:name="t('doriath', 'Move {count} secrets', { count: bulk.selectionCount })"
		:open="open"
		size="normal"
		data-testid="bulk-move-dialog"
		@update:open="$emit('close')">
		<div class="bulk-move">
			<NcSelect
				v-model="targetFolder"
				:options="folderOptions"
				:input-label="t('doriath', 'Target folder')"
				label="label"
				data-testid="bulk-move-folder" />
			<BulkRunPanel @retry="onRetry" />
		</div>
		<template #actions>
			<NcButton variant="tertiary" @click="$emit('close')">
				{{ t('doriath', 'Close') }}
			</NcButton>
			<NcButton
				variant="primary"
				:disabled="!targetFolder || bulk.progress.running"
				data-testid="bulk-move-run"
				@click="onRun">
				{{ t('doriath', 'Move') }}
			</NcButton>
		</template>
	</NcDialog>
</template>

<script>
import { NcButton, NcDialog, NcSelect } from '@nextcloud/vue'
import { useBulkStore } from '../store/modules/bulk.js'
import { useSecretStore } from '../store/modules/secret.js'
import { useFolderStore } from '../store/modules/folder.js'
import BulkRunPanel from '../components/BulkRunPanel.vue'

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
		folderOptions() {
			const options = [{ id: null, label: this.t('doriath', 'Vault root') }]
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
		 */
		async onRun() {
			await this.bulk.run(
				this.bulk.selectedIds,
				(id) => this.moveOne(id),
				this.t('doriath', 'Moving secrets'),
			)
			this.$emit('done')
		},

		/**
		 * Retry only the failed subset.
		 *
		 * @return {Promise<void>}
		 */
		async onRetry() {
			await this.bulk.retryFailed(
				(id) => this.moveOne(id),
				this.t('doriath', 'Retrying move'),
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
