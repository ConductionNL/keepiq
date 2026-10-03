<!--
  SPDX-License-Identifier: EUPL-1.2
  SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>

  Shell-level state of the offline edit queue: how many changes wait to sync,
  the changes that need a choice after the server changed them meanwhile, the
  changes the server refused (copy the value, or discard), and changes made
  under keys that were rotated on another device (reopen with the previous
  master password, or discard). Renders nothing when the queue is empty.

  @spec openspec/specs/offline-edit-queue/spec.md#requirement-failed-entries-are-kept-never-dropped-silently
-->
<template>
	<div
		v-if="visible"
		class="keepiq-offline-sync"
		role="status"
		data-testid="offline-sync-panel">
		<p v-if="offline.pendingCount > 0" data-testid="offline-sync-pending">
			{{
				n(
					'keepiq',
					'%n change waiting to sync',
					'%n changes waiting to sync',
					offline.pendingCount,
				)
			}}
		</p>

		<ul v-if="offline.conflictEntries.length" class="keepiq-offline-sync__list">
			<li v-for="entry in offline.conflictEntries" :key="entry.entryId">
				{{ entryName(entry) }}
				<NcButton
					variant="secondary"
					:data-testid="`offline-sync-resolve-${entry.entryId}`"
					@click="conflictEntry = entry">
					{{ t('keepiq', 'Choose a version') }}
				</NcButton>
			</li>
		</ul>

		<template v-if="offline.failedEntries.length">
			<h3>{{ t('keepiq', 'Changes that could not sync') }}</h3>
			<ul class="keepiq-offline-sync__list" data-testid="offline-sync-failed">
				<li v-for="entry in offline.failedEntries" :key="entry.entryId">
					{{ entryName(entry) }}
					<NcButton
						variant="secondary"
						:data-testid="`offline-sync-copy-${entry.entryId}`"
						@click="copyValue(entry)">
						{{ t('keepiq', 'Copy value') }}
					</NcButton>
					<NcButton
						variant="tertiary"
						:data-testid="`offline-sync-discard-${entry.entryId}`"
						@click="offline.discardEntry(entry.entryId)">
						{{ t('keepiq', 'Discard') }}
					</NcButton>
				</li>
			</ul>
		</template>

		<form
			v-if="offline.foreignEntries.length"
			class="keepiq-offline-sync__reopen"
			data-testid="offline-sync-reopen"
			@submit.prevent="reopen">
			<p>
				{{
					t(
						'keepiq',
						'Your keys were changed on another device. Enter your previous master password to sync the changes you made offline, or discard them.',
					)
				}}
			</p>
			<NcPasswordField
				v-model="previousPassword"
				:label="t('keepiq', 'Your previous master password')" />
			<NcButton
				type="submit"
				variant="primary"
				:disabled="previousPassword === ''">
				{{ t('keepiq', 'Open my changes') }}
			</NcButton>
			<NcButton variant="tertiary" @click="offline.discardForeignEntries()">
				{{ t('keepiq', 'Discard') }}
			</NcButton>
		</form>

		<p v-if="message" class="keepiq-offline-sync__message">
			{{ message }}
		</p>

		<OfflineConflictDialog
			v-if="conflictEntry"
			:open="true"
			:entry="conflictEntry"
			@update:open="(v) => !v && (conflictEntry = null)" />
	</div>
</template>

<script>
import { translatePlural as n, translate as t } from '@nextcloud/l10n'
import { NcButton, NcPasswordField } from '@nextcloud/vue'
import OfflineConflictDialog from '../dialogs/OfflineConflictDialog.vue'
import { useOfflineStore } from '../store/modules/offline.js'

export default {
	name: 'OfflineSyncPanel',

	components: { NcButton, NcPasswordField, OfflineConflictDialog },

	data() {
		return {
			offline: useOfflineStore(),
			conflictEntry: null,
			previousPassword: '',
			message: '',
		}
	},

	computed: {
		/**
		 * Whether anything about the queue needs showing.
		 *
		 * @spec openspec/specs/offline-edit-queue/spec.md#requirement-failed-entries-are-kept-never-dropped-silently
		 */
		visible() {
			return (
				this.offline.pendingCount > 0
				|| this.offline.failedEntries.length > 0
				|| this.offline.foreignEntries.length > 0
			)
		},
	},

	methods: {
		t,
		n,

		/**
		 * The name a queued change is shown under.
		 *
		 * @spec openspec/specs/offline-edit-queue/spec.md#requirement-failed-entries-are-kept-never-dropped-silently
		 */
		entryName(entry) {
			return entry.body?.name || t('keepiq', 'Secret')
		},

		/**
		 * Copy the value of a refused change, decrypted in the browser.
		 *
		 * @param {object} entry The failed entry.
		 * @return {Promise<void>}
		 * @spec openspec/specs/offline-edit-queue/spec.md#requirement-failed-entries-are-kept-never-dropped-silently
		 */
		async copyValue(entry) {
			const plain = await this.offline.plaintextOf(entry.body || {})
			await navigator.clipboard.writeText(plain.key ?? '')
			this.message = t('keepiq', 'Copied!')
		},

		/**
		 * Reopen changes sealed under the previous keys, then sync them.
		 *
		 * @return {Promise<void>}
		 * @spec openspec/specs/offline-edit-queue/spec.md#requirement-pending-changes-block-logout-and-rotation
		 */
		async reopen() {
			try {
				await this.offline.reopenWithPreviousPassword(this.previousPassword)
				this.previousPassword = ''
				await this.offline.replayQueue()
				this.message = ''
			} catch {
				this.message = t(
					'keepiq',
					'That password did not open your changes.',
				)
			}
		},
	},
}
</script>

<style scoped>
.keepiq-offline-sync {
	padding: 8px 16px;
	border-bottom: 1px solid var(--color-border);
	background-color: var(--color-main-background);
}

.keepiq-offline-sync__list li {
	display: flex;
	align-items: center;
	gap: 8px;
	margin: 4px 0;
}

.keepiq-offline-sync__reopen {
	display: flex;
	flex-wrap: wrap;
	align-items: flex-end;
	gap: 8px;
}
</style>
