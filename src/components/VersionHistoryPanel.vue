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

		<ul v-if="store.versions.length" class="version-history__list" data-testid="version-list">
			<li v-for="version in store.versions" :key="version.id" class="version-history__row">
				<History :size="16" />
				<span class="version-history__label" :data-testid="`version-${version.versionNumber}`">
					{{ t('doriath', 'Version {number}', { number: version.versionNumber }) }}
					<span class="version-history__meta">
						{{ version.actorId }} · {{ formatDate(version.createdAt) }}
					</span>
				</span>
				<NcButton variant="tertiary"
					:data-testid="`version-view-${version.versionNumber}`"
					@click="onView(version)">
					{{ t('doriath', 'View') }}
				</NcButton>
				<NcButton v-if="canManage"
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
		<NcDialog :name="t('doriath', 'Version details')"
			:open="viewed !== null"
			size="normal"
			@update:open="viewed = null">
			<dl v-if="viewed" class="version-history__details" data-testid="version-details">
				<div><dt>{{ t('doriath', 'Name') }}</dt><dd>{{ viewed.name }}</dd></div>
				<div v-if="viewed.url"><dt>{{ t('doriath', 'URL') }}</dt><dd>{{ viewed.url }}</dd></div>
				<div>
					<dt>{{ t('doriath', 'Value') }}</dt>
					<dd class="version-history__value">
						<span data-testid="version-value">{{ revealed ? viewed.key : '••••••••••' }}</span>
						<NcButton variant="tertiary" @click="revealed = !revealed">
							{{ revealed ? t('doriath', 'Hide') : t('doriath', 'Show') }}
						</NcButton>
					</dd>
				</div>
				<div v-if="viewed.login"><dt>{{ t('doriath', 'Login') }}</dt><dd>{{ viewed.login }}</dd></div>
			</dl>
		</NcDialog>

		<!-- Restore confirmation. -->
		<NcDialog :name="t('doriath', 'Restore version')"
			:open="confirmVersion !== null"
			size="small"
			@update:open="confirmVersion = null">
			<p class="version-history__confirm">
				{{ t('doriath', 'Restore version {number}? The current value is kept as a new version, and shared recipients receive the restored value.', { number: confirmVersion ? confirmVersion.versionNumber : 0 }) }}
			</p>
			<template #actions>
				<NcButton variant="tertiary" @click="confirmVersion = null">
					{{ t('doriath', 'Cancel') }}
				</NcButton>
				<NcButton variant="primary" data-testid="version-restore-confirm" @click="onRestore">
					{{ t('doriath', 'Restore') }}
				</NcButton>
			</template>
		</NcDialog>
	</div>
</template>

<script>
import { NcButton, NcDialog, NcNoteCard } from '@nextcloud/vue'
import History from 'vue-material-design-icons/History.vue'
import { useSecretVersionStore } from '../store/modules/secretVersion.js'

export default {
	name: 'VersionHistoryPanel',
	components: {
		NcButton,
		NcDialog,
		NcNoteCard,
		History,
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
			revealed: false,
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
		 */
		async onView(version) {
			this.revealed = false
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
			return Number.isNaN(parsed) ? (iso ?? '') : new Date(parsed).toLocaleString()
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

.version-history__details {
	display: flex;
	flex-direction: column;
	gap: 8px;
	padding: 4px 12px 12px;
}

.version-history__details dt {
	font-weight: 600;
	font-size: 13px;
	color: var(--color-text-maxcontrast, #777);
}

.version-history__value {
	display: flex;
	align-items: center;
	gap: 8px;
	word-break: break-all;
}

.version-history__confirm {
	padding: 0 12px 12px;
}

.version-history__empty {
	color: var(--color-text-maxcontrast, #777);
}
</style>
