<!--
  SPDX-License-Identifier: EUPL-1.2
  SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>

  A secret changed on the server while the user edited it offline. Both
  versions are decrypted here, in the browser, and the user keeps one: their
  offline change (replayed on top of the server version, which stays in the
  version history) or the server version (the offline change is dropped).

  @spec openspec/specs/offline-edit-queue/spec.md#requirement-concurrent-server-changes-are-never-overwritten-silently
-->
<template>
	<NcDialog
		:name="t('keepiq', 'This secret changed while you were offline')"
		:open="open"
		size="normal"
		@update:open="onUpdateOpen">
		<NcNoteCard v-if="error" type="error">
			{{ error }}
		</NcNoteCard>
		<p>
			{{
				entry.op === 'delete'
					? t(
							'keepiq',
							'You deleted this secret offline, but it was changed on the server since. Choose which version to keep.',
						)
					: t(
							'keepiq',
							'Someone changed this secret on the server after your offline copy was made. Choose which version to keep.',
						)
			}}
		</p>
		<div class="offline-conflict__columns">
			<section data-testid="offline-conflict-mine">
				<h3>{{ t('keepiq', 'Your offline change') }}</h3>
				<p v-if="entry.op === 'delete'">{{ t('keepiq', 'Deleted') }}</p>
				<dl v-else>
					<dt>{{ t('keepiq', 'Name') }}</dt>
					<dd>{{ mine.name ?? server.name }}</dd>
					<dt>{{ t('keepiq', 'Value') }}</dt>
					<dd>{{ mine.key ?? server.key }}</dd>
				</dl>
			</section>
			<section data-testid="offline-conflict-server">
				<h3>{{ t('keepiq', 'The server version') }}</h3>
				<dl>
					<dt>{{ t('keepiq', 'Name') }}</dt>
					<dd>{{ server.name }}</dd>
					<dt>{{ t('keepiq', 'Value') }}</dt>
					<dd>{{ server.key }}</dd>
				</dl>
			</section>
		</div>

		<template #actions>
			<NcButton
				variant="secondary"
				:disabled="busy"
				data-testid="offline-conflict-keep-server"
				@click="choose('server')">
				{{ t('keepiq', 'Keep the server version') }}
			</NcButton>
			<NcButton
				variant="primary"
				:disabled="busy"
				data-testid="offline-conflict-keep-mine"
				@click="choose('mine')">
				{{ t('keepiq', 'Keep my offline change') }}
			</NcButton>
		</template>
	</NcDialog>
</template>

<script>
import { translate as t } from '@nextcloud/l10n'
import { NcButton, NcDialog, NcNoteCard } from '@nextcloud/vue'
import { rsaDecrypt } from '../crypto/rsa.js'
import { useOfflineStore } from '../store/modules/offline.js'
import { useSessionStore } from '../store/modules/session.js'

export default {
	name: 'OfflineConflictDialog',

	components: { NcButton, NcDialog, NcNoteCard },

	props: {
		open: { type: Boolean, default: false },
		/** The opened queue entry in conflict. */
		entry: { type: Object, required: true },
	},

	emits: ['update:open', 'resolved'],

	data() {
		return { mine: {}, server: {}, busy: false, error: null }
	},

	/**
	 * Decrypt both versions in the browser.
	 *
	 * @spec openspec/specs/offline-edit-queue/spec.md#requirement-concurrent-server-changes-are-never-overwritten-silently
	 */
	async created() {
		const offline = useOfflineStore()
		const plain = await offline.plaintextOf(this.entry.body || {})
		this.mine = { name: this.entry.body?.name, key: plain.key }
		const current = offline.conflicts[this.entry.entryId] || {}
		let key = ''
		if (current.key) {
			try {
				key = await rsaDecrypt(current.key, useSessionStore().cryptoKey)
			} catch {
				key = ''
			}
		}
		this.server = { name: current.name, key }
	},

	methods: {
		t,

		/**
		 * Apply the user's choice.
		 *
		 * @param {'mine'|'server'} choice Which version to keep.
		 * @return {Promise<void>}
		 * @spec openspec/specs/offline-edit-queue/spec.md#requirement-concurrent-server-changes-are-never-overwritten-silently
		 */
		async choose(choice) {
			this.busy = true
			this.error = null
			try {
				await useOfflineStore().resolveConflict(this.entry.entryId, choice)
				this.$emit('resolved', choice)
				this.$emit('update:open', false)
			} catch (e) {
				this.error = e?.message || String(e)
			} finally {
				this.busy = false
			}
		},

		onUpdateOpen(value) {
			this.$emit('update:open', value)
		},
	},
}
</script>

<style scoped>
.offline-conflict__columns {
	display: grid;
	grid-template-columns: 1fr 1fr;
	gap: 16px;
}

.offline-conflict__columns dd {
	margin: 0 0 8px;
	word-break: break-all;
}
</style>
