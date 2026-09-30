<!--
  SPDX-License-Identifier: EUPL-1.2
  SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>

  Bulk trash and archive actions (vault-trash-and-archive): archive or
  unarchive a selection, restore it from the trash, or delete it for good.
  Same two phases as BulkDeleteDialog: it asks, then it reports with only a
  Close left. Deleting for good is the one step that cannot be undone, so it
  is the one that warns.

  @spec openspec/specs/vault-trash-and-archive/spec.md#requirement-restoring-and-purging-trashed-secrets
  @spec openspec/specs/vault-trash-and-archive/spec.md#requirement-archiving-a-secret
-->
<template>
	<NcDialog
		:name="title"
		:open="open"
		size="normal"
		data-testid="bulk-state-dialog"
		@update:open="$emit('close')">
		<div class="bulk-state">
			<NcNoteCard
				v-if="!finished"
				:type="action === 'purge' ? 'warning' : 'info'"
				data-testid="bulk-state-note">
				{{ note }}
			</NcNoteCard>
			<BulkRunPanel v-if="ran || bulk.progress.running" @retry="onRetry" />
		</div>
		<template #actions>
			<NcButton
				:variant="finished ? 'primary' : 'tertiary'"
				data-testid="bulk-state-close"
				@click="$emit('close')">
				{{ t('keepiq', 'Close') }}
			</NcButton>
			<NcButton
				v-if="!finished"
				:variant="action === 'purge' ? 'error' : 'primary'"
				:disabled="bulk.progress.running"
				data-testid="bulk-state-run"
				@click="onRun">
				{{ command }}
			</NcButton>
		</template>
	</NcDialog>
</template>

<script>
import { NcButton, NcDialog, NcNoteCard } from '@nextcloud/vue'
import BulkRunPanel from '../components/BulkRunPanel.vue'
import { useBulkStore } from '../store/modules/bulk.js'
import { useSecretStore } from '../store/modules/secret.js'

export default {
	name: 'BulkStateDialog',
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

		/** One of archive, unarchive, restore, purge. */
		action: {
			type: String,
			required: true,
			validator: (value) =>
				['archive', 'unarchive', 'restore', 'purge'].includes(value),
		},
	},

	emits: ['close', 'done'],
	data() {
		return {
			/** Whether a run was started from this dialog. */
			ran: false,
		}
	},

	computed: {
		bulk() {
			return useBulkStore()
		},

		finished() {
			return this.ran && !this.bulk.progress.running
		},

		/**
		 * The button label: what happens to how many secrets.
		 *
		 * @return {string}
		 * @spec openspec/specs/vault-trash-and-archive/spec.md#requirement-restoring-and-purging-trashed-secrets
		 */
		command() {
			const count = this.bulk.selectionCount
			return {
				archive: this.t('keepiq', 'Archive {count} secrets', { count }),
				unarchive: this.t('keepiq', 'Unarchive {count} secrets', { count }),
				restore: this.t('keepiq', 'Restore {count} secrets', { count }),
				purge: this.t('keepiq', 'Delete {count} secrets for good', { count }),
			}[this.action]
		},

		/**
		 * The title: the command while asking, the outcome once done.
		 *
		 * @return {string}
		 * @spec openspec/specs/vault-trash-and-archive/spec.md#requirement-restoring-and-purging-trashed-secrets
		 */
		title() {
			if (this.finished) {
				return this.t('keepiq', 'Done for {ok} of {total} secrets', {
					ok: this.bulk.report.filter((r) => r.status === 'ok').length,
					total: this.bulk.report.length,
				})
			}
			return this.command
		},

		/**
		 * What the action does, said before it runs.
		 *
		 * @return {string}
		 * @spec openspec/specs/vault-trash-and-archive/spec.md#requirement-archiving-a-secret
		 */
		note() {
			return {
				archive: this.t('keepiq', 'Archived secrets leave the vault list, search, autofill and the health report. They keep their shares. You find them under Archive.'),
				unarchive: this.t('keepiq', 'These secrets come back to the vault list, search and autofill.'),
				restore: this.t('keepiq', 'These secrets come back to the vault list. Their old shares do not come back, so share them again where needed.'),
				purge: this.t('keepiq', 'This deletes the secrets with their attachments and version history. This cannot be undone.'),
			}[this.action]
		},
	},

	methods: {
		/**
		 * The per-item state change; a 404 (already gone) is skipped.
		 *
		 * @param {string} secretId The secret id.
		 * @return {Promise<object>}
		 */
		async changeOne(secretId) {
			try {
				await useSecretStore().changeSecretState(secretId, this.action)
				return { status: 'ok' }
			} catch (e) {
				if (e?.response?.status === 404) {
					return { status: 'skipped', reason: 'already deleted' }
				}
				throw e
			}
		},

		/**
		 * Run the action over the selection.
		 *
		 * @return {Promise<void>}
		 * @spec openspec/specs/vault-trash-and-archive/spec.md#requirement-restoring-and-purging-trashed-secrets
		 */
		async onRun() {
			this.ran = true
			await this.bulk.run(
				this.bulk.selectedIds,
				(id) => this.changeOne(id),
				this.command,
			)
			this.$emit('done')
		},

		/**
		 * Retry only the failed subset.
		 *
		 * @return {Promise<void>}
		 * @spec openspec/specs/vault-trash-and-archive/spec.md#requirement-restoring-and-purging-trashed-secrets
		 */
		async onRetry() {
			await this.bulk.retryFailed((id) => this.changeOne(id), this.command)
			this.$emit('done')
		},
	},
}
</script>

<style scoped>
.bulk-state {
	display: flex;
	flex-direction: column;
	gap: 12px;
	padding: 4px 12px 12px;
}
</style>
