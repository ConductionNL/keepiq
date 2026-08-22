<!--
  SPDX-License-Identifier: EUPL-1.2
  SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>

  Bulk add-to-team-folder dialog (bulk-actions §6.2): move the selected
  secrets into a team folder's tree (metadata-only), then run the
  existing idempotent team-folder fan-out so every member receives a
  re-encrypted copy — resume/retry never double-shares.

  @spec openspec/specs/bulk-actions/spec.md#requirement-the-four-bulk-operations
-->
<template>
	<NcDialog
		:name="
			t('keepiq', 'Add {count} secrets to a team folder', {
				count: bulk.selectionCount,
			})
		"
		:open="open"
		size="normal"
		data-testid="bulk-team-folder-dialog"
		@update:open="$emit('close')">
		<div class="bulk-tf">
			<NcSelect
				v-model="target"
				:options="teamFolderOptions"
				:inputLabel="t('keepiq', 'Team folder')"
				label="label"
				data-testid="bulk-team-folder-select" />
			<p v-if="fanOut.running" data-testid="bulk-tf-fanout">
				{{
					t('keepiq', 'Fanning out to members — {done} / {total}', {
						done: fanOut.done,
						total: fanOut.total,
					})
				}}
			</p>
			<BulkRunPanel @retry="onRetry" />
		</div>
		<template #actions>
			<NcButton variant="tertiary" @click="$emit('close')">
				{{ t('keepiq', 'Close') }}
			</NcButton>
			<NcButton
				variant="primary"
				:disabled="!target || bulk.progress.running || fanOut.running"
				data-testid="bulk-team-folder-run"
				@click="onRun">
				{{ t('keepiq', 'Add to team folder') }}
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
import { useTeamFolderStore } from '../store/modules/teamFolder.js'

export default {
	name: 'BulkTeamFolderDialog',
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
			target: null,
		}
	},

	computed: {
		bulk() {
			return useBulkStore()
		},

		teamFolderStore() {
			return useTeamFolderStore()
		},

		fanOut() {
			return this.teamFolderStore.fanOut
		},

		teamFolderOptions() {
			const folderNames = Object.fromEntries(
				useFolderStore().folders.map((f) => [f.id, f.name]),
			)
			return this.teamFolderStore.owned.map((tf) => ({
				id: tf.id,
				folderId: tf.folderId,
				label: folderNames[tf.folderId] || tf.folderId,
			}))
		},
	},

	async mounted() {
		try {
			await this.teamFolderStore.fetchTeamFolders()
		} catch {
			// Surfaced via store state.
		}
	},

	methods: {
		/**
		 * The per-item step: metadata-only move into the team folder.
		 *
		 * @param {string} secretId The secret id.
		 * @return {Promise<object>}
		 */
		async moveOne(secretId) {
			await useSecretStore().updateSecret(secretId, {
				folderId: this.target.folderId,
			})
			return { status: 'ok' }
		},

		/**
		 * Move the selection into the team folder, then run the
		 * idempotent membership fan-out once for the whole folder.
		 *
		 * @return {Promise<void>}
		 */
		async onRun() {
			await this.bulk.run(
				this.bulk.selectedIds,
				(id) => this.moveOne(id),
				this.t('keepiq', 'Adding to team folder'),
			)
			try {
				await this.teamFolderStore.runFanOut(this.target.id)
			} catch {
				// Fan-out errors surface via the team-folder store; the
				// idempotent reconcile picks up missing pairs on retry.
			}
			this.$emit('done')
		},

		/**
		 * Retry only the failed moves, then re-run the fan-out (the
		 * reconcile step makes the re-run a no-op for shared pairs).
		 *
		 * @return {Promise<void>}
		 */
		async onRetry() {
			await this.bulk.retryFailed(
				(id) => this.moveOne(id),
				this.t('keepiq', 'Retrying'),
			)
			try {
				await this.teamFolderStore.runFanOut(this.target.id)
			} catch {
				// See onRun.
			}
			this.$emit('done')
		},
	},
}
</script>

<style scoped>
.bulk-tf {
	display: flex;
	flex-direction: column;
	gap: 12px;
	padding: 4px 12px 12px;
}
</style>
