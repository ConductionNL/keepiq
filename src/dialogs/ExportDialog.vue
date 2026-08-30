<!--
  SPDX-License-Identifier: EUPL-1.2
  SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>

  Export dialog (secret-export-gdpr D1/D2, tasks §6.2). Two modes, with the
  encrypted backup visually primary:

  - Encrypted backup (.doriath-backup): a backup passphrase with a zxcvbn ≥ 3
    strength floor (submit disabled below it). Generated fully client-side
    (Argon2id + AES-256-GCM); the passphrase never reaches the server.
  - Plaintext CSV: gated by an explicit unencrypted-file warning that must be
    acknowledged, THEN fresh master-password re-entry (client-side proof of
    knowledge — verified by decrypting the private-key blob, never sent to the
    server), THEN download.

  Both modes support scope selection (whole vault or a folder subtree). The
  caller passes already-decrypted secrets + the folder list.

  @spec openspec/changes/secret-export-gdpr/specs/secret-export/spec.md
-->
<template>
	<NcDialog
		:name="t('keepiq', 'Export vault')"
		:open="open"
		size="normal"
		@update:open="onUpdateOpen">
		<div class="export-dialog">
			<NcNoteCard v-if="error" type="error">
				{{ error }}
			</NcNoteCard>

			<fieldset class="export-dialog__modes">
				<legend>{{ t('keepiq', 'Export format') }}</legend>
				<NcCheckboxRadioSwitch
					:modelValue="mode"
					value="encrypted-backup"
					name="export-mode"
					type="radio"
					@update:modelValue="mode = $event">
					{{ t('keepiq', 'Encrypted backup (recommended)') }}
				</NcCheckboxRadioSwitch>
				<NcCheckboxRadioSwitch
					:modelValue="mode"
					value="plaintext-csv"
					name="export-mode"
					type="radio"
					@update:modelValue="mode = $event">
					{{ t('keepiq', 'Plaintext CSV (unencrypted)') }}
				</NcCheckboxRadioSwitch>
				<NcCheckboxRadioSwitch
					:modelValue="mode"
					value="cxf"
					name="export-mode"
					type="radio"
					data-testid="export-mode-cxf"
					@update:modelValue="mode = $event">
					{{ t('keepiq', 'FIDO Credential Exchange (CXF, unencrypted)') }}
				</NcCheckboxRadioSwitch>
			</fieldset>

			<!-- CXF unmapped-item report: shown BEFORE the download so the
			     user knows exactly what will not survive the round-trip
			     (cxf-import-export D4). -->
			<NcNoteCard
				v-if="mode === 'cxf' && cxfReport && cxfReport.unmapped.length"
				type="warning"
				data-testid="cxf-unmapped-report">
				<p>
					{{ t('keepiq', 'The following will not survive a CXF export:') }}
				</p>
				<ul>
					<li v-for="(entry, idx) in cxfReport.unmapped" :key="idx">
						{{ entry }}
					</li>
				</ul>
				<p>{{ t('keepiq', 'Export again to proceed anyway.') }}</p>
			</NcNoteCard>

			<NcSelect
				v-model="scopeFolder"
				:inputLabel="t('keepiq', 'Scope')"
				:options="scopeOptions"
				:reduce="(opt) => opt.value"
				:clearable="false" />

			<!-- Encrypted backup path -->
			<div v-if="mode === 'encrypted-backup'" class="export-dialog__backup">
				<NcPasswordField
					v-model="passphrase"
					:label="t('keepiq', 'Backup passphrase')"
					@update:modelValue="onPassphraseInput" />
				<p class="export-dialog__hint">
					{{
						t(
							'keepiq',
							'Choose a strong passphrase and write it down. A backup is the one thing that survives a lost master password — but only if you remember its passphrase.',
						)
					}}
				</p>
				<p
					v-if="passphrase"
					class="export-dialog__strength"
					:class="'export-dialog__strength--' + passphraseScore">
					{{ strengthLabel }}
				</p>
			</div>

			<!-- Plaintext CSV path: warning -> ack -> re-auth -->
			<div v-else class="export-dialog__csv">
				<NcNoteCard type="warning">
					{{
						t(
							'keepiq',
							'A CSV export is UNENCRYPTED. Every password and login will be readable as plain text in the downloaded file. Store it securely and delete it immediately after use.',
						)
					}}
				</NcNoteCard>
				<NcCheckboxRadioSwitch
					:modelValue="warningAcknowledged"
					@update:modelValue="warningAcknowledged = $event">
					{{
						t(
							'keepiq',
							'I understand the file is unencrypted and will delete it after use',
						)
					}}
				</NcCheckboxRadioSwitch>
				<NcPasswordField
					v-if="warningAcknowledged"
					v-model="masterPassword"
					:label="t('keepiq', 'Re-enter your master password')" />
			</div>
		</div>

		<template #actions>
			<NcButton @click="onUpdateOpen(false)">
				{{ t('keepiq', 'Cancel') }}
			</NcButton>
			<NcButton
				variant="primary"
				:disabled="!canSubmit || loading"
				@click="onExport">
				{{ t('keepiq', 'Export') }}
			</NcButton>
		</template>
	</NcDialog>
</template>

<script>
import {
	NcButton,
	NcCheckboxRadioSwitch,
	NcDialog,
	NcNoteCard,
	NcPasswordField,
	NcSelect,
} from '@nextcloud/vue'
import zxcvbn from 'zxcvbn'
import { verifyMasterPassword } from '../crypto/reauth.js'
import { useExportStore } from '../store/modules/export.js'
import { useSecretTypeStore } from '../store/modules/secretType.js'
import { useSessionStore } from '../store/modules/session.js'

/** The zxcvbn score floor for a backup passphrase (D1). */
const PASSPHRASE_FLOOR = 3

export default {
	name: 'ExportDialog',
	components: {
		NcDialog,
		NcButton,
		NcNoteCard,
		NcSelect,
		NcPasswordField,
		NcCheckboxRadioSwitch,
	},

	props: {
		/** Whether the dialog is open. */
		open: {
			type: Boolean,
			default: false,
		},

		/** Already-decrypted secrets to export. */
		secrets: {
			type: Array,
			default: () => [],
		},

		/** Folder rows ({ id, name, parentId }). */
		folders: {
			type: Array,
			default: () => [],
		},
	},

	emits: ['update:open'],
	/**
	 * Provide the export + session Pinia stores to the component.
	 *
	 * @return {object}
	 * @spec openspec/changes/secret-export-gdpr/specs/secret-export/spec.md
	 */
	setup() {
		return {
			exportStore: useExportStore(),
			sessionStore: useSessionStore(),
		}
	},

	data() {
		return {
			mode: 'encrypted-backup',
			passphrase: '',
			passphraseScore: 0,
			masterPassword: '',
			warningAcknowledged: false,
			scopeFolder: 'vault',
			error: null,
			/** CXF pre-download unmapped-item report (null = not built yet). */
			cxfReport: null,
		}
	},

	computed: {
		/** typeId → type-name map for the CXF export mapping. */
		typeNamesById() {
			return Object.fromEntries(
				useSecretTypeStore().types.map((type) => [type.id, type.name]),
			)
		},

		/**
		 * Whether an export is in flight (from the store).
		 *
		 * @return {boolean}
		 * @spec openspec/changes/secret-export-gdpr/specs/secret-export/spec.md
		 */
		loading() {
			return this.exportStore.loading
		},

		/**
		 * The scope selector options: the whole vault plus each folder.
		 *
		 * @return {Array<object>}
		 * @spec openspec/changes/secret-export-gdpr/specs/secret-export/spec.md
		 */
		scopeOptions() {
			const opts = [
				{ label: this.t('keepiq', 'Entire vault'), value: 'vault' },
			]
			for (const folder of this.folders) {
				opts.push({ label: folder.name, value: folder.id })
			}
			return opts
		},

		/**
		 * The live passphrase-strength feedback label.
		 *
		 * @return {string}
		 * @spec openspec/changes/secret-export-gdpr/specs/secret-export/spec.md
		 */
		strengthLabel() {
			if (this.passphraseScore >= PASSPHRASE_FLOOR) {
				return this.t('keepiq', 'Passphrase strength: strong enough')
			}
			return this.t(
				'keepiq',
				'Passphrase too weak — choose a longer, less predictable passphrase',
			)
		},

		/**
		 * Whether the export may be submitted: backup needs a passphrase at/above
		 * the strength floor; plaintext CSV needs the warning acknowledged and a
		 * master password entered.
		 *
		 * @return {boolean}
		 * @spec openspec/changes/secret-export-gdpr/specs/secret-export/spec.md
		 */
		canSubmit() {
			if (this.mode === 'encrypted-backup') {
				return (
					this.passphrase.length > 0
					&& this.passphraseScore >= PASSPHRASE_FLOOR
				)
			}
			// Plaintext CSV: warning acknowledged + a master password entered.
			return this.warningAcknowledged && this.masterPassword.length > 0
		},
	},

	watch: {
		mode() {
			// A mode switch invalidates the CXF pre-download report.
			this.cxfReport = null
		},
	},

	methods: {
		/**
		 * Recompute the live zxcvbn passphrase score on each keystroke.
		 *
		 * @return {void}
		 * @spec openspec/changes/secret-export-gdpr/specs/secret-export/spec.md
		 */
		onPassphraseInput() {
			this.passphraseScore = this.passphrase
				? zxcvbn(this.passphrase).score
				: 0
		},

		/**
		 * Build the scope selector for the store action.
		 *
		 * @return {object}
		 * @spec openspec/changes/secret-export-gdpr/specs/secret-export/spec.md
		 */
		buildScope() {
			if (this.scopeFolder === 'vault') {
				return { mode: 'vault' }
			}
			return { mode: 'folders', folderIds: [this.scopeFolder] }
		},

		/**
		 * Run the chosen export. Plaintext CSV is gated by a client-side
		 * master-password re-auth before the store action runs.
		 *
		 * @return {Promise<void>}
		 * @spec openspec/changes/secret-export-gdpr/specs/secret-export/spec.md
		 */
		async onExport() {
			this.error = null
			try {
				const scope = this.buildScope()
				if (this.mode === 'encrypted-backup') {
					await this.exportStore.exportBackup(
						this.secrets,
						this.folders,
						this.passphrase,
						scope,
					)
				} else {
					// Fresh master-password re-auth (client-side proof of knowledge).
					const ok = await verifyMasterPassword(
						this.sessionStore.encryptedPrivateKey,
						this.masterPassword,
					)
					if (!ok) {
						this.error = this.t('keepiq', 'Incorrect master password')
						return
					}
					if (this.mode === 'cxf') {
						// First pass builds and shows the unmapped-item report
						// BEFORE any download (cxf-import-export D4); the second
						// pass (or a clean report) proceeds to the download.
						if (this.cxfReport === null) {
							const report = await this.exportStore.exportCxf(
								this.secrets,
								this.folders,
								scope,
								{ typeNamesById: this.typeNamesById, dryRun: true },
							)
							if (report.unmapped.length > 0) {
								this.cxfReport = report
								return
							}
						}
						await this.exportStore.exportCxf(
							this.secrets,
							this.folders,
							scope,
							{ typeNamesById: this.typeNamesById },
						)
					} else {
						await this.exportStore.exportCsv(
							this.secrets,
							this.folders,
							scope,
						)
					}
				}
				this.reset()
				this.$emit('update:open', false)
			} catch (e) {
				this.error =
					this.exportStore.error
					|| (e && e.message)
					|| this.t('keepiq', 'Export failed')
			}
		},

		/**
		 * Reset the dialog to its initial state (no plaintext retained).
		 *
		 * @return {void}
		 * @spec openspec/changes/secret-export-gdpr/specs/secret-export/spec.md
		 */
		reset() {
			this.mode = 'encrypted-backup'
			this.passphrase = ''
			this.passphraseScore = 0
			this.masterPassword = ''
			this.warningAcknowledged = false
			this.scopeFolder = 'vault'
			this.error = null
			this.cxfReport = null
		},

		/**
		 * Handle open-state changes, clearing transient secrets on close.
		 *
		 * @param {boolean} value The new open state.
		 * @return {void}
		 * @spec openspec/changes/secret-export-gdpr/specs/secret-export/spec.md
		 */
		onUpdateOpen(value) {
			if (!value) {
				this.reset()
			}
			this.$emit('update:open', value)
		},
	},
}
</script>

<style scoped>
.export-dialog {
	display: flex;
	flex-direction: column;
	gap: 12px;
	padding: 8px 4px;
	min-width: 320px;
}

.export-dialog__modes {
	border: none;
	margin: 0;
	padding: 0;
}

.export-dialog__hint {
	color: var(--color-text-maxcontrast);
	font-size: 0.85em;
}

.export-dialog__strength--0,
.export-dialog__strength--1,
.export-dialog__strength--2 {
	color: var(--color-error-text);
}

.export-dialog__strength--3,
.export-dialog__strength--4 {
	color: var(--color-success-text);
}
</style>
