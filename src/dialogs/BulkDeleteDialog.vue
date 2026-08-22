<!--
  SPDX-License-Identifier: EUPL-1.2
  SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>

  Bulk-delete dialog (bulk-actions §5): an explicit, count-confirmed,
  IRREVERSIBLE hard delete (no trash exists) reusing the per-secret
  cascade; large sets require a typed confirmation. An already-gone
  secret reports skipped, not failed.

  @spec openspec/changes/bulk-actions/specs/bulk-actions/spec.md#requirement-bulk-delete
-->
<template>
	<NcDialog
		:name="t('keepiq', 'Delete {count} secrets', { count: bulk.selectionCount })"
		:open="open"
		size="normal"
		data-testid="bulk-delete-dialog"
		@update:open="$emit('close')">
		<div class="bulk-delete">
			<NcNoteCard type="warning" data-testid="bulk-delete-warning">
				{{
					t(
						'keepiq',
						'This permanently deletes {count} secrets and revokes their shares. There is no trash — this cannot be undone.',
						{ count: bulk.selectionCount },
					)
				}}
			</NcNoteCard>
			<label v-if="needsTypedConfirmation" class="bulk-delete__confirm">
				<span>{{
					t('keepiq', 'Type {word} to confirm', { word: confirmWord })
				}}</span>
				<input v-model="typed" type="text" data-testid="bulk-delete-typed" />
			</label>
			<BulkRunPanel @retry="onRetry" />
		</div>
		<template #actions>
			<NcButton variant="tertiary" @click="$emit('close')">
				{{ t('keepiq', 'Close') }}
			</NcButton>
			<NcButton
				variant="error"
				:disabled="!confirmed || bulk.progress.running"
				data-testid="bulk-delete-run"
				@click="onRun">
				{{
					t('keepiq', 'Delete {count} secrets', {
						count: bulk.selectionCount,
					})
				}}
			</NcButton>
		</template>
	</NcDialog>
</template>

<script>
import { NcButton, NcDialog, NcNoteCard } from '@nextcloud/vue'
import BulkRunPanel from '../components/BulkRunPanel.vue'
import { useBulkStore } from '../store/modules/bulk.js'
import { useSecretStore } from '../store/modules/secret.js'

const TYPED_CONFIRMATION_THRESHOLD = 10

export default {
	name: 'BulkDeleteDialog',
	components: {
		NcButton,
		NcDialog,
		NcNoteCard,
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
			typed: '',
		}
	},

	computed: {
		bulk() {
			return useBulkStore()
		},

		needsTypedConfirmation() {
			return this.bulk.selectionCount > TYPED_CONFIRMATION_THRESHOLD
		},

		confirmWord() {
			return `DELETE ${this.bulk.selectionCount}`
		},

		confirmed() {
			if (!this.needsTypedConfirmation) {
				return true
			}
			return this.typed === this.confirmWord
		},
	},

	methods: {
		/**
		 * The per-item hard delete; a 404 (already gone) is skipped,
		 * never failed.
		 *
		 * @param {string} secretId The secret id.
		 * @return {Promise<object>}
		 */
		async deleteOne(secretId) {
			try {
				await useSecretStore().deleteSecret(secretId)
				return { status: 'ok' }
			} catch (e) {
				if (e?.response?.status === 404) {
					return { status: 'skipped', reason: 'already deleted' }
				}
				throw e
			}
		},

		/**
		 * Run the chunked delete over the selection.
		 *
		 * @return {Promise<void>}
		 */
		async onRun() {
			await this.bulk.run(
				this.bulk.selectedIds,
				(id) => this.deleteOne(id),
				this.t('keepiq', 'Deleting secrets'),
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
				(id) => this.deleteOne(id),
				this.t('keepiq', 'Retrying delete'),
			)
			this.$emit('done')
		},
	},
}
</script>

<style scoped>
.bulk-delete {
	display: flex;
	flex-direction: column;
	gap: 12px;
	padding: 4px 12px 12px;
}

.bulk-delete__confirm {
	display: flex;
	flex-direction: column;
	gap: 4px;
}
</style>
