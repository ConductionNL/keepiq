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

		<div
			v-if="canManage && store.versions.length"
			class="version-history__compare"
			data-testid="version-compare-bar">
			<label class="version-history__pick">
				<input
					type="checkbox"
					data-testid="version-pick-current"
					:checked="picked.includes(CURRENT)"
					:disabled="!picked.includes(CURRENT) && picked.length >= 2"
					@change="togglePick(CURRENT)" />
				{{ t('keepiq', 'Current value') }}
			</label>
			<NcButton
				variant="secondary"
				data-testid="version-compare"
				:disabled="picked.length !== 2 || comparing"
				@click="onCompare">
				{{ t('keepiq', 'Compare') }}
			</NcButton>
			<span class="version-history__meta">
				{{ t('keepiq', 'Pick two to compare.') }}
			</span>
		</div>

		<ul
			v-if="store.versions.length"
			class="version-history__list"
			data-testid="version-list">
			<li
				v-for="version in store.versions"
				:key="version.id"
				class="version-history__row">
				<input
					v-if="canManage"
					type="checkbox"
					:aria-label="t('keepiq', 'Pick version {number} to compare', { number: version.versionNumber })"
					:data-testid="`version-pick-${version.versionNumber}`"
					:checked="picked.includes(version.id)"
					:disabled="!picked.includes(version.id) && picked.length >= 2"
					@change="togglePick(version.id)" />
				<History :size="16" />
				<span
					class="version-history__label"
					:data-testid="`version-${version.versionNumber}`">
					{{
						t('keepiq', 'Version {number}', {
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
					{{ t('keepiq', 'View') }}
				</NcButton>
				<NcButton
					v-if="canManage"
					variant="tertiary"
					:data-testid="`version-restore-${version.versionNumber}`"
					@click="confirmVersion = version">
					{{ t('keepiq', 'Restore') }}
				</NcButton>
			</li>
		</ul>
		<p v-else class="version-history__empty">
			{{ t('keepiq', 'No previous versions') }}
		</p>

		<!-- Read-only view of one decrypted version. -->
		<VersionDetailsDialog :version="viewed" @close="viewed = null" />

		<!-- Side-by-side comparison of two decrypted states. -->
		<VersionCompareDialog :comparison="comparison" @close="comparison = null" />

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
import VersionCompareDialog from '../dialogs/VersionCompareDialog.vue'
import VersionDetailsDialog from '../dialogs/VersionDetailsDialog.vue'
import VersionRestoreDialog from '../dialogs/VersionRestoreDialog.vue'
import { useSecretStore } from '../store/modules/secret.js'
import { useSecretVersionStore } from '../store/modules/secretVersion.js'
import { localDateTime } from '../utils/dateTime.js'

/**
 * The pick standing for the secret's current value.
 */
const CURRENT = 'current'

export default {
	name: 'VersionHistoryPanel',
	components: {
		NcButton,
		NcNoteCard,
		History,
		VersionCompareDialog,
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
			// The two picks to compare: version ids, or CURRENT for the current value.
			picked: [],
			comparison: null,
			comparing: false,
			CURRENT,
		}
	},

	computed: {
		/**
		 * @spec exclude Store-ref passthrough: returns the Pinia secret-version store with no domain logic.
		 */
		store() {
			return useSecretVersionStore()
		},
	},

	/**
	 * @spec openspec/specs/secret-version-history/spec.md#requirement-list-view-and-restore-versions
	 */
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
		 *
		 * @spec openspec/specs/secret-version-history/spec.md#scenario-restore-becomes-a-new-head-and-syncs
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
		 * Pick or unpick one entry to compare; at most two are picked.
		 *
		 * @param {string} id A version id, or CURRENT.
		 * @return {void}
		 * @spec openspec/changes/capability-requirements-from-the-matrix/specs/secret-version-history/spec.md#requirement-compare-two-versions-side-by-side
		 */
		togglePick(id) {
			if (this.picked.includes(id)) {
				this.picked = this.picked.filter((p) => p !== id)
				return
			}
			if (this.picked.length < 2) {
				this.picked = [...this.picked, id]
			}
		},

		/**
		 * Decrypt the two picks in the browser and open the comparison, the
		 * older one on the left. Nothing decrypted is sent anywhere.
		 *
		 * @return {Promise<void>}
		 * @spec openspec/changes/capability-requirements-from-the-matrix/specs/secret-version-history/spec.md#requirement-compare-two-versions-side-by-side
		 */
		async onCompare() {
			if (this.picked.length !== 2) {
				return
			}
			const order = (id) => {
				if (id === CURRENT) {
					return Number.POSITIVE_INFINITY
				}
				const version = this.store.versions.find((v) => v.id === id)
				return version ? version.versionNumber : 0
			}
			const [first, second] = [...this.picked].sort((a, b) => order(a) - order(b))
			this.comparing = true
			try {
				const [left, right] = await Promise.all([this.stateOf(first), this.stateOf(second)])
				this.comparison = { left, right, leftLabel: this.labelOf(first), rightLabel: this.labelOf(second) }
			} catch {
				// A version failure is surfaced via store state.
			} finally {
				this.comparing = false
			}
		},

		/**
		 * The decrypted state of one pick.
		 *
		 * @param {string} id A version id, or CURRENT.
		 * @return {Promise<object>}
		 */
		stateOf(id) {
			if (id === CURRENT) {
				return useSecretStore().fetchSecret(this.secretId)
			}
			return this.store.viewVersion(id)
		},

		/**
		 * The column heading of one pick.
		 *
		 * @param {string} id A version id, or CURRENT.
		 * @return {string}
		 */
		labelOf(id) {
			if (id === CURRENT) {
				return this.t('keepiq', 'Current value')
			}
			const version = this.store.versions.find((v) => v.id === id)
			return this.t('keepiq', 'Version {number}', { number: version ? version.versionNumber : '?' })
		},

		/** Locale date-time display of an ISO timestamp. */
		formatDate: localDateTime,
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
	margin-inline-start: 8px;
}

.version-history__compare {
	display: flex;
	flex-wrap: wrap;
	align-items: center;
	gap: 8px;
	margin-bottom: 8px;
}

.version-history__pick {
	display: inline-flex;
	align-items: center;
	gap: 6px;
}

.version-history__empty {
	color: var(--color-text-maxcontrast, #777);
}
</style>
