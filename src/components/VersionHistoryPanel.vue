<!--
  SPDX-License-Identifier: EUPL-1.2
  SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>

  Version-history panel on the secret detail view
  (secret-version-history §7): newest-first list with version number,
  actor, and timestamp; a read-only view decrypting a version with the
  in-session key; and a confirmed restore that propagates to recipients
  via sync-on-update.

  @spec openspec/specs/secret-version-history/spec.md#requirement-list-view-and-restore-versions
-->
<template>
	<div class="version-history" data-testid="version-history-panel">
		<NcNoteCard v-if="store.error" type="error" data-testid="version-error">
			{{ store.error }}
		</NcNoteCard>

		<ul
			v-if="store.versions.length"
			class="version-history__list"
			data-testid="version-list">
			<li
				v-for="version in store.versions"
				:key="version.id"
				class="version-history__row">
				<History :size="16" />
				<span
					class="version-history__label"
					:data-testid="`version-${version.versionNumber}`">
					{{
						t('doriath', 'Version {number}', {
							number: version.versionNumber,
						})
					}}
					<span class="version-history__meta">
						{{ version.actorId }} · {{ formatDate(version.createdAt) }}
					</span>
				</span>
				<NcButton
					variant="tertiary"
					:data-testid="`version-view-${version.versionNumber}`"
					@click="onView(version)">
					{{ t('doriath', 'View') }}
				</NcButton>
				<NcButton
					v-if="canManage"
					variant="tertiary"
					:data-testid="`version-restore-${version.versionNumber}`"
					@click="confirmVersion = version">
					{{ t('doriath', 'Restore') }}
				</NcButton>
			</li>
		</ul>
		<p v-else class="version-history__empty">
			{{ t('doriath', 'No previous versions') }}
		</p>

		<!-- Read-only view of one decrypted version. -->
		<VersionDetailsDialog :version="viewed" @close="viewed = null" />

		<!-- Restore confirmation. -->
		<VersionRestoreDialog
			:version="confirmVersion"
			@close="confirmVersion = null"
			@confirm="onRestore" />
	</div>
</template>

<script>
import { NcButton, NcNoteCard } from '@nextcloud/vue'
import History from 'vue-material-design-icons/History.vue'
import VersionDetailsDialog from '../dialogs/VersionDetailsDialog.vue'
import VersionRestoreDialog from '../dialogs/VersionRestoreDialog.vue'
import { useSecretVersionStore } from '../store/modules/secretVersion.js'

export default {
	name: 'VersionHistoryPanel',
	components: {
		NcButton,
		NcNoteCard,
		History,
		VersionDetailsDialog,
		VersionRestoreDialog,
	},
	props: {
		secretId: {
			type: String,
			required: true,
		},
		/** Whether the viewer may restore (the secret's owner). */
		canManage: {
			type: Boolean,
			default: false,
		},
	},
	data() {
		return {
			viewed: null,
			confirmVersion: null,
		}
	},
	computed: {
		store() {
			return useSecretVersionStore()
		},
	},
	async mounted() {
		try {
			await this.store.fetchVersions(this.secretId)
		} catch {
			// Surfaced via store state.
		}
	},
	unmounted() {
		this.store.reset()
	},
	methods: {
		/**
		 * Decrypt and show one version read-only.
		 *
		 * @param {object} version The version metadata row.
		 * @return {Promise<void>}
		 * @spec openspec/specs/secret-version-history/spec.md#requirement-list-view-and-restore-versions
		 */
		async onView(version) {
			// The mask now resets inside VersionDetailsDialog, which re-masks
			// whenever a different version is passed in.
			try {
				this.viewed = await this.store.viewVersion(version.id)
			} catch {
				// Surfaced via store state.
			}
		},

		/**
		 * Confirmed restore + recipient propagation.
		 *
		 * @return {Promise<void>}
		 */
		async onRestore() {
			const version = this.confirmVersion
			this.confirmVersion = null
			if (!version) {
				return
			}
			try {
				await this.store.restore(version.id, this.secretId)
				this.$emit('restored')
			} catch {
				// Surfaced via store state.
			}
		},

		/**
		 * Locale date-time display.
		 *
		 * @param {string} iso The ISO timestamp.
		 * @return {string}
		 */
		formatDate(iso) {
			const parsed = Date.parse(iso ?? '')
			return Number.isNaN(parsed)
				? (iso ?? '')
				: new Date(parsed).toLocaleString()
		},
	},
}
</script>

<style scoped>
.version-history__list {
	display: flex;
	flex-direction: column;
	gap: 4px;
}

.version-history__row {
	display: flex;
	align-items: center;
	gap: 8px;
}

.version-history__label {
	flex: 1;
}

.version-history__meta {
	color: var(--color-text-maxcontrast, #777);
	font-size: 13px;
	margin-left: 8px;
}

.version-history__empty {
	color: var(--color-text-maxcontrast, #777);
}
</style>
