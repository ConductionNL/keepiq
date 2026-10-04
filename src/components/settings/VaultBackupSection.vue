<!--
  SPDX-License-Identifier: EUPL-1.2
  SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>

  Admin "Vault backups" section (admin-scheduled-vault-backups §4.1):
  schedule, retention, an optional backup public key, the last result, the
  archive list and "Back up now". It offers no download: archives are read
  and restored from the command line only (design D6).

  @spec openspec/specs/vault-backups/spec.md#requirement-administrator-schedules-vault-backups
-->
<template>
	<CnSettingsSection
		:name="t('keepiq', 'Vault backups')"
		:description="
			t(
				'keepiq',
				'Back up every vault on a schedule. Archives hold ciphertext only and are restored with occ.',
			)
		">
		<div class="vault-backup" data-testid="vault-backup-section">
			<NcNoteCard v-if="error" type="error" data-testid="vault-backup-error">
				{{ error }}
			</NcNoteCard>

			<label class="vault-backup__check">
				<input
					v-model="settings.backup_enabled"
					type="checkbox"
					data-testid="vault-backup-enabled"
					@change="save" />
				<span>{{ t('keepiq', 'Back up every vault automatically') }}</span>
			</label>
			<label class="vault-backup__field">
				<span>{{ t('keepiq', 'Every (hours)') }}</span>
				<input
					v-model.number="settings.backup_interval_hours"
					type="number"
					min="1"
					data-testid="vault-backup-interval"
					@change="save" />
			</label>
			<label class="vault-backup__field">
				<span>{{ t('keepiq', 'Archives to keep') }}</span>
				<input
					v-model.number="settings.backup_retention_count"
					type="number"
					min="1"
					data-testid="vault-backup-retention"
					@change="save" />
			</label>
			<label class="vault-backup__field">
				<span>{{ t('keepiq', 'Backup public key (PEM, optional)') }}</span>
				<textarea
					v-model.trim="settings.backup_recipient_public_key"
					rows="4"
					data-testid="vault-backup-public-key"
					@change="save" />
			</label>
			<p class="vault-backup__hint">
				{{
					t(
						'keepiq',
						'With a key, every archive is encrypted to it. Keep the private key off this server: you need it to verify or restore.',
					)
				}}
			</p>

			<p v-if="status.lastRunAt" data-testid="vault-backup-last">
				{{ lastResultText }}
			</p>

			<NcButton
				variant="secondary"
				:disabled="!settings.backup_enabled || requested"
				data-testid="vault-backup-run"
				@click="runNow">
				{{
					requested
						? t('keepiq', 'Backup requested for the next cron run')
						: t('keepiq', 'Back up now')
				}}
			</NcButton>

			<table
				v-if="archives.length > 0"
				class="vault-backup__list"
				data-testid="vault-backup-list">
				<thead>
					<tr>
						<th scope="col">{{ t('keepiq', 'Archive') }}</th>
						<th scope="col">{{ t('keepiq', 'Size') }}</th>
						<th scope="col">{{ t('keepiq', 'Written') }}</th>
						<th scope="col">{{ t('keepiq', 'Encrypted') }}</th>
					</tr>
				</thead>
				<tbody>
					<tr v-for="archive in archives" :key="archive.name">
						<td>{{ archive.name }}</td>
						<td>{{ formatSize(archive.size) }}</td>
						<td>{{ formatTime(archive.createdAt) }}</td>
						<td>
							{{
								archive.encrypted
									? t('keepiq', 'Yes')
									: t('keepiq', 'No')
							}}
						</td>
					</tr>
				</tbody>
			</table>
			<p v-else class="vault-backup__hint" data-testid="vault-backup-empty">
				{{ t('keepiq', 'No archives yet.') }}
			</p>
		</div>
	</CnSettingsSection>
</template>

<script>
import { CnSettingsSection } from '@conduction/nextcloud-vue'
import axios from '@nextcloud/axios'
import { generateUrl } from '@nextcloud/router'
import { NcButton, NcNoteCard } from '@nextcloud/vue'

const KEYS = [
	'backup_enabled',
	'backup_interval_hours',
	'backup_retention_count',
	'backup_recipient_public_key',
]

export default {
	name: 'VaultBackupSection',
	components: { CnSettingsSection, NcButton, NcNoteCard },

	data() {
		return {
			settings: {
				backup_enabled: false,
				backup_interval_hours: 24,
				backup_retention_count: 7,
				backup_recipient_public_key: '',
			},

			status: {
				lastRunAt: 0,
				lastStatus: '',
				lastError: '',
				runRequested: false,
			},

			archives: [],
			requested: false,
			error: null,
		}
	},

	computed: {
		/**
		 * The last run, in words.
		 *
		 * @return {string}
		 * @spec openspec/specs/vault-backups/spec.md#requirement-administrator-schedules-vault-backups
		 */
		lastResultText() {
			const when = this.formatTime(this.status.lastRunAt)
			return this.status.lastStatus === 'failed'
				? this.t('keepiq', 'Last backup {when} failed: {error}', {
						when,
						error: this.status.lastError,
					})
				: this.t('keepiq', 'Last backup {when} succeeded.', { when })
		},
	},

	/**
	 * Load the settings, the last result and the archive list.
	 *
	 * @return {Promise<void>}
	 * @spec openspec/specs/vault-backups/spec.md#requirement-administrator-schedules-vault-backups
	 */
	async created() {
		try {
			const backups = await axios.get(
				generateUrl('/apps/keepiq/api/settings/admin/backups'),
			)
			for (const key of KEYS) {
				if (backups.data?.settings?.[key] !== undefined) {
					this.settings[key] = backups.data.settings[key]
				}
			}
			this.status = backups.data?.status ?? this.status
			this.archives = backups.data?.archives ?? []
			this.requested = this.status.runRequested === true
		} catch (e) {
			this.error = e?.response?.data?.message || e?.message
		}
	},

	methods: {
		/**
		 * Save the schedule, retention and key; the server validates.
		 *
		 * @return {Promise<void>}
		 * @spec openspec/specs/vault-backups/spec.md#requirement-administrator-schedules-vault-backups
		 */
		async save() {
			this.error = null
			const payload = {}
			for (const key of KEYS) {
				payload[key] = this.settings[key]
			}
			try {
				await axios.put(
					generateUrl('/apps/keepiq/api/settings/admin/backups'),
					payload,
				)
			} catch (e) {
				this.error = e?.response?.data?.message || e?.message
			}
		},

		/**
		 * Ask the next cron run to back up.
		 *
		 * @return {Promise<void>}
		 * @spec openspec/specs/vault-backups/spec.md#requirement-administrator-schedules-vault-backups
		 */
		async runNow() {
			this.error = null
			try {
				await axios.post(
					generateUrl('/apps/keepiq/api/settings/admin/backups/run'),
				)
				this.requested = true
			} catch (e) {
				this.error = e?.response?.data?.message || e?.message
			}
		},

		/**
		 * A byte count for people.
		 *
		 * @param {number} bytes The size.
		 * @return {string}
		 * @spec openspec/specs/vault-backups/spec.md#requirement-administrator-schedules-vault-backups
		 */
		formatSize(bytes) {
			if (bytes >= 1048576) {
				return (bytes / 1048576).toFixed(1) + ' MB'
			}
			return Math.max(1, Math.round(bytes / 1024)) + ' kB'
		},

		/**
		 * A unix time as a local date and time.
		 *
		 * @param {number} seconds The unix time.
		 * @return {string}
		 * @spec openspec/specs/vault-backups/spec.md#requirement-administrator-schedules-vault-backups
		 */
		formatTime(seconds) {
			return new Date(seconds * 1000).toLocaleString()
		},
	},
}
</script>

<style scoped>
.vault-backup {
	display: flex;
	flex-direction: column;
	gap: 12px;
	max-width: 640px;
}

.vault-backup__check {
	display: flex;
	align-items: center;
	gap: 8px;
}

.vault-backup__field {
	display: flex;
	flex-direction: column;
	gap: 4px;
}

.vault-backup__hint {
	font-size: 0.85rem;
	color: var(--color-text-maxcontrast);
}

.vault-backup__list {
	border-collapse: collapse;
}

.vault-backup__list th,
.vault-backup__list td {
	padding: 4px 12px 4px 0;
	text-align: start;
}
</style>
