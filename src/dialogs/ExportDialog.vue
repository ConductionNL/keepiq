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

  Every mode supports scope selection (whole vault, chosen folders with their
  subfolders and chosen types, or the vault list's selection), and the backup
  and CSV can leave out usernames, additional fields or seeds. The
  caller passes already-decrypted secrets + the folder list.

  @spec openspec/specs/secret-export/spec.md
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

			<!-- Secrets that could not be decrypted are not in the file: say
			     how many before anything is written, and let the user choose
			     to continue or cancel (keepiq#794). -->
			<SkippedSecretsNotice
				v-if="skipped > 0"
				v-model:acknowledged="skippedAcknowledged"
				class="export-dialog__skipped"
				name="export"
				:skipped="skipped" />

			<!-- The export ban (admin-vault-policies §2.2): every file mode is
			     hidden; the personal data package stays available. -->
			<NcNoteCard
				v-if="exportBlocked"
				type="info"
				data-testid="export-blocked-by-policy">
				{{
					t(
						'keepiq',
						'Your organisation does not allow exporting your personal vault. Your personal data package in your settings stays available.',
					)
				}}
			</NcNoteCard>

			<template v-if="!exportBlocked">
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
						{{
							t(
								'keepiq',
								'FIDO Credential Exchange (CXF, unencrypted)',
							)
						}}
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
						{{
							t(
								'keepiq',
								'The following will not survive a CXF export:',
							)
						}}
					</p>
					<ul>
						<li v-for="(entry, idx) in cxfReport.unmapped" :key="idx">
							{{ entry }}
						</li>
					</ul>
					<p>{{ t('keepiq', 'Export again to proceed anyway.') }}</p>
				</NcNoteCard>

				<!-- What goes in (portability-export-choice-and-restore-fidelity
				     D1): the entire vault, chosen folders and types (the two
				     combine), or the selection made in the vault list. -->
				<fieldset class="export-dialog__scope" data-testid="export-scope">
					<legend>{{ t('keepiq', 'What to export') }}</legend>
					<NcCheckboxRadioSwitch
						:modelValue="scopeKind"
						value="vault"
						name="export-scope"
						type="radio"
						@update:modelValue="scopeKind = $event">
						{{ t('keepiq', 'Entire vault') }}
					</NcCheckboxRadioSwitch>
					<NcCheckboxRadioSwitch
						:modelValue="scopeKind"
						value="chosen"
						name="export-scope"
						type="radio"
						data-testid="export-scope-chosen"
						@update:modelValue="scopeKind = $event">
						{{ t('keepiq', 'Chosen folders and types') }}
					</NcCheckboxRadioSwitch>
					<NcCheckboxRadioSwitch
						v-if="selectedIds.length > 0"
						:modelValue="scopeKind"
						value="selection"
						name="export-scope"
						type="radio"
						data-testid="export-scope-selection"
						@update:modelValue="scopeKind = $event">
						{{
							n(
								'keepiq',
								'The selected secret',
								'The %n selected secrets',
								selectedIds.length,
							)
						}}
					</NcCheckboxRadioSwitch>
				</fieldset>

				<template v-if="scopeKind === 'chosen'">
					<NcSelect
						v-model="scopeFolderIds"
						:inputLabel="t('keepiq', 'Folders, with their subfolders')"
						:options="folderOptions"
						:reduce="(opt) => opt.value"
						:multiple="true"
						data-testid="export-scope-folders" />
					<NcSelect
						v-model="scopeTypeIds"
						:inputLabel="t('keepiq', 'Secret types')"
						:options="typeOptions"
						:reduce="(opt) => opt.value"
						:multiple="true"
						data-testid="export-scope-types" />
					<p class="export-dialog__hint">
						{{
							t(
								'keepiq',
								'Choose at least one folder or type. With both, only secrets that match both are exported.',
							)
						}}
					</p>
				</template>

				<!-- Fields to leave out (D2): the name and the value always go in. -->
				<fieldset
					v-if="mode !== 'cxf'"
					class="export-dialog__fields"
					data-testid="export-fields">
					<legend>{{ t('keepiq', 'Leave out') }}</legend>
					<NcCheckboxRadioSwitch
						v-for="option in fieldOptions"
						:key="option.value"
						:modelValue="omitFields.includes(option.value)"
						:data-testid="'export-omit-' + option.value"
						@update:modelValue="toggleOmitField(option.value, $event)">
						{{ option.label }}
					</NcCheckboxRadioSwitch>
					<p class="export-dialog__hint">
						{{
							t(
								'keepiq',
								'Names and values are always exported. A backup with fields left out is marked as partial.',
							)
						}}
					</p>
				</fieldset>

				<!-- Encrypted backup path -->
				<div
					v-if="mode === 'encrypted-backup'"
					class="export-dialog__backup">
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

				<!-- Plaintext CSV or CXF path: warning -> ack -> re-auth. Each
			     names its own format (keepiq#749). -->
				<div v-else class="export-dialog__csv">
					<NcNoteCard
						type="warning"
						data-testid="export-plaintext-warning">
						{{
							mode === 'cxf'
								? t(
										'keepiq',
										'A CXF export is UNENCRYPTED. Every password and login will be readable as plain text in the downloaded file. Store it securely and delete it immediately after use.',
									)
								: t(
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
			</template>
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
import SkippedSecretsNotice from '../components/SkippedSecretsNotice.vue'
import { OMITTABLE_FIELDS } from '../export/serializer.js'
import exportSourceProps from '../mixins/exportSourceProps.js'
import exportStoreDialog from '../mixins/exportStoreDialog.js'
import masterPasswordCheck from '../mixins/masterPasswordCheck.js'
import { fetchPolicy, resetPolicyCache } from '../policy/policy.js'
import { useSecretTypeStore } from '../store/modules/secretType.js'

/** The zxcvbn score floor for a backup passphrase (D1). */
const PASSPHRASE_FLOOR = 3

export default {
	name: 'ExportDialog',
	components: {
		SkippedSecretsNotice,
		NcDialog,
		NcButton,
		NcNoteCard,
		NcSelect,
		NcPasswordField,
		NcCheckboxRadioSwitch,
	},

	mixins: [exportSourceProps, exportStoreDialog, masterPasswordCheck],

	props: {
		/**
		 * The secrets selected in the vault list, when the dialog was opened
		 * with Export selected; the dialog then exports only those (D1).
		 */
		selectedIds: {
			type: Array,
			default: () => [],
		},
	},

	emits: ['update:open'],
	data() {
		return {
			mode: 'encrypted-backup',
			passphrase: '',
			passphraseScore: 0,
			masterPassword: '',
			warningAcknowledged: false,
			/** Whether the user chose to export without the skipped secrets. */
			skippedAcknowledged: false,
			/** The scope (D1): 'vault', 'chosen' (folders and types) or 'selection'. */
			scopeKind: 'vault',
			/** The chosen folder ids; each exports with its subfolders. */
			scopeFolderIds: [],
			/** The chosen secret type ids. */
			scopeTypeIds: [],
			/** The fields to leave out (D2), from OMITTABLE_FIELDS. */
			omitFields: [],
			error: null,
			/** CXF pre-download unmapped-item report (null = not built yet). */
			cxfReport: null,
			/** Whether the export ban applies to this user (admin-vault-policies). */
			exportBlocked: false,
		}
	},

	computed: {
		/**
		 * typeId → type-name map for the CXF export mapping.
		 *
		 * @spec openspec/specs/cxf-import-export/spec.md#requirement-cxf-entity-to-keepiq-type-mapping
		 */
		typeNamesById() {
			return Object.fromEntries(
				useSecretTypeStore().types.map((type) => [type.id, type.name]),
			)
		},

		/**
		 * The folder options of a chosen-folders scope.
		 *
		 * @return {Array<object>}
		 * @spec openspec/changes/portability-export-choice-and-restore-fidelity/specs/export-selection-and-restore/spec.md#requirement-choose-what-an-export-contains
		 */
		folderOptions() {
			return this.folders.map((folder) => ({
				label: folder.name,
				value: folder.id,
			}))
		},

		/**
		 * The secret type options of a chosen-types scope.
		 *
		 * @return {Array<object>}
		 * @spec openspec/changes/portability-export-choice-and-restore-fidelity/specs/export-selection-and-restore/spec.md#requirement-choose-what-an-export-contains
		 */
		typeOptions() {
			return useSecretTypeStore().types.map((type) => ({
				label: type.label || type.name,
				value: type.id,
			}))
		},

		/**
		 * The fields the user may leave out (D2), labelled.
		 *
		 * @return {Array<object>}
		 * @spec openspec/changes/portability-export-choice-and-restore-fidelity/specs/export-selection-and-restore/spec.md#requirement-choose-what-an-export-contains
		 */
		fieldOptions() {
			const labels = {
				login: this.t('keepiq', 'Usernames'),
				additionalFields: this.t('keepiq', 'Additional fields'),
				totp: this.t('keepiq', 'One-time code seeds'),
			}
			return OMITTABLE_FIELDS.map((value) => ({ value, label: labels[value] }))
		},

		/**
		 * Whether the chosen scope names something to export: a chosen
		 * scope needs a folder or a type, a selection needs a secret.
		 *
		 * @return {boolean}
		 * @spec openspec/changes/portability-export-choice-and-restore-fidelity/specs/export-selection-and-restore/spec.md#requirement-choose-what-an-export-contains
		 */
		scopeComplete() {
			if (this.scopeKind === 'chosen') {
				return this.scopeFolderIds.length > 0 || this.scopeTypeIds.length > 0
			}
			if (this.scopeKind === 'selection') {
				return this.selectedIds.length > 0
			}
			return true
		},

		/**
		 * The live passphrase-strength feedback label.
		 *
		 * @return {string}
		 * @spec openspec/specs/secret-export/spec.md
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
		 * Whether the export may be submitted: when secrets could not be
		 * decrypted the user must first choose to continue without them; backup
		 * needs a passphrase at/above the strength floor; plaintext CSV needs the
		 * warning acknowledged and a master password entered.
		 *
		 * @return {boolean}
		 * @spec openspec/specs/secret-export/spec.md
		 * @spec openspec/changes/portability-export-choice-and-restore-fidelity/specs/export-selection-and-restore/spec.md#requirement-nothing-is-left-out-of-an-export-in-silence
		 */
		canSubmit() {
			if (this.exportBlocked) {
				return false
			}
			if (this.skipped > 0 && !this.skippedAcknowledged) {
				return false
			}
			if (!this.scopeComplete) {
				return false
			}
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
		/**
		 * @spec openspec/specs/cxf-import-export/spec.md#requirement-unmapped-item-report
		 */
		mode() {
			// A mode switch invalidates the CXF pre-download report.
			this.cxfReport = null
		},

		/**
		 * Read the export ban again each time the dialog opens. The dialog is
		 * mounted with the vault page, and the page's cached policy can predate
		 * a ban an administrator switched on during this session.
		 *
		 * @param {boolean} open Whether the dialog is open now.
		 * @return {Promise<void>}
		 * @spec openspec/specs/vault-policies/spec.md#requirement-personal-vault-export-can-be-blocked
		 */
		async open(open) {
			if (open !== true) {
				return
			}
			// Export selected opens the dialog on the selection (D1).
			if (this.selectedIds.length > 0) {
				this.scopeKind = 'selection'
			}
			resetPolicyCache()
			this.exportBlocked =
				(await fetchPolicy())?.vault_export_disabled === true
		},
	},

	/**
	 * Read whether the export ban applies, so the blocked modes are hidden
	 * before anything is decrypted.
	 *
	 * @return {Promise<void>}
	 * @spec openspec/specs/vault-policies/spec.md#requirement-personal-vault-export-can-be-blocked
	 */
	async created() {
		this.exportBlocked = (await fetchPolicy())?.vault_export_disabled === true
	},

	methods: {
		/**
		 * Recompute the live zxcvbn passphrase score on each keystroke.
		 *
		 * @return {void}
		 * @spec openspec/specs/secret-export/spec.md
		 */
		onPassphraseInput() {
			this.passphraseScore = this.passphrase
				? zxcvbn(this.passphrase).score
				: 0
		},

		/**
		 * Build the scope selector for the store action (D1): the whole vault,
		 * the chosen folders (with subfolders) and types combined, or the
		 * selected secrets on their own.
		 *
		 * @return {object}
		 * @spec openspec/specs/secret-export/spec.md
		 * @spec openspec/changes/portability-export-choice-and-restore-fidelity/specs/export-selection-and-restore/spec.md#requirement-choose-what-an-export-contains
		 */
		buildScope() {
			if (this.scopeKind === 'selection') {
				return { secretIds: [...this.selectedIds] }
			}
			if (this.scopeKind !== 'chosen') {
				return { mode: 'vault' }
			}
			const scope = {}
			if (this.scopeFolderIds.length > 0) {
				scope.folderIds = [...this.scopeFolderIds]
			}
			if (this.scopeTypeIds.length > 0) {
				scope.typeIds = [...this.scopeTypeIds]
			}
			return scope
		},

		/**
		 * The serializer options: the vault's types, so each secret carries
		 * its type name and custom label (D3), and the fields left out (D2).
		 *
		 * @return {{types: Array<object>, omitFields: Array<string>}}
		 * @spec openspec/changes/portability-export-choice-and-restore-fidelity/specs/export-selection-and-restore/spec.md#requirement-a-restored-backup-keeps-types-and-row-positions
		 */
		buildOptions() {
			return {
				types: useSecretTypeStore().types,
				omitFields: [...this.omitFields],
			}
		},

		/**
		 * Leave a field out of the export, or put it back.
		 *
		 * @param {string} field The field, from OMITTABLE_FIELDS.
		 * @param {boolean} omit Whether to leave it out.
		 * @return {void}
		 * @spec openspec/changes/portability-export-choice-and-restore-fidelity/specs/export-selection-and-restore/spec.md#requirement-choose-what-an-export-contains
		 */
		toggleOmitField(field, omit) {
			const rest = this.omitFields.filter((value) => value !== field)
			this.omitFields = omit ? [...rest, field] : rest
		},

		/**
		 * Run the chosen export. Plaintext CSV is gated by a client-side
		 * master-password re-auth before the store action runs.
		 *
		 * @return {Promise<void>}
		 * @spec openspec/specs/secret-export/spec.md
		 * @spec openspec/changes/portability-export-choice-and-restore-fidelity/specs/export-selection-and-restore/spec.md#requirement-nothing-is-left-out-of-an-export-in-silence
		 */
		async onExport() {
			this.error = null
			// Nothing is written until the user chose to continue without the
			// secrets that could not be decrypted (keepiq#794).
			if (this.skipped > 0 && !this.skippedAcknowledged) {
				return
			}
			try {
				if (await this.runExport(this.buildScope())) {
					this.reset()
					this.$emit('update:open', false)
				}
			} catch (e) {
				this.showExportFailure(e)
			}
		},

		/**
		 * Write the chosen export. A plaintext one first takes a fresh
		 * master-password re-auth.
		 *
		 * @param {object} scope The scope selector.
		 * @return {Promise<boolean>} Whether the file was written; false when
		 *   the re-auth failed or the CXF report is shown first.
		 * @spec openspec/specs/secret-export/spec.md
		 */
		async runExport(scope) {
			if (this.mode === 'encrypted-backup') {
				await this.exportStore.exportBackup(
					this.secrets,
					this.folders,
					this.passphrase,
					scope,
					this.buildOptions(),
				)
				return true
			}
			if (!(await this.masterPasswordVerified())) {
				return false
			}
			if (this.mode === 'cxf') {
				return this.exportCxf(scope)
			}
			await this.exportStore.exportCsv(
				this.secrets,
				this.folders,
				scope,
				this.buildOptions(),
			)
			return true
		},

		/**
		 * Write a CXF export. The first pass builds and shows the
		 * unmapped-item report BEFORE any download (cxf-import-export D4); the
		 * second pass (or a clean report) proceeds to the download.
		 *
		 * @param {object} scope The scope selector.
		 * @return {Promise<boolean>} Whether the file was written.
		 * @spec openspec/specs/cxf-import-export/spec.md#requirement-cxf-entity-to-keepiq-type-mapping
		 */
		async exportCxf(scope) {
			if (this.cxfReport === null && (await this.showCxfReport(scope))) {
				return false
			}
			await this.exportStore.exportCxf(this.secrets, this.folders, scope, {
				typeNamesById: this.typeNamesById,
			})
			return true
		},

		/**
		 * Build the CXF report without downloading, and keep it to show when
		 * it lists items it cannot map.
		 *
		 * @param {object} scope The scope selector.
		 * @return {Promise<boolean>} Whether the report is shown.
		 * @spec openspec/specs/cxf-import-export/spec.md#requirement-cxf-entity-to-keepiq-type-mapping
		 */
		async showCxfReport(scope) {
			const report = await this.exportStore.exportCxf(
				this.secrets,
				this.folders,
				scope,
				{ typeNamesById: this.typeNamesById, dryRun: true },
			)
			if (report.unmapped.length > 0) {
				this.cxfReport = report
				return true
			}
			return false
		},

		/**
		 * Show why the export failed. The server refuses the report under the
		 * export ban, which aborts the download; show the policy instead of a
		 * raw error.
		 *
		 * @param {Error|object} e The failure.
		 * @return {void}
		 * @spec openspec/specs/vault-policies/spec.md#requirement-personal-vault-export-can-be-blocked
		 */
		showExportFailure(e) {
			if (e?.response?.data?.code === 'export_disabled_by_policy') {
				this.exportBlocked = true
				return
			}
			this.error =
				this.exportStore.error
				|| (e && e.message)
				|| this.t('keepiq', 'Export failed')
		},

		/**
		 * Reset the dialog to its initial state (no plaintext retained).
		 *
		 * @return {void}
		 * @spec openspec/specs/secret-export/spec.md
		 */
		reset() {
			this.mode = 'encrypted-backup'
			this.passphrase = ''
			this.passphraseScore = 0
			this.masterPassword = ''
			this.warningAcknowledged = false
			this.skippedAcknowledged = false
			this.scopeKind = 'vault'
			this.scopeFolderIds = []
			this.scopeTypeIds = []
			this.omitFields = []
			this.error = null
			this.cxfReport = null
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

.export-dialog__skipped {
	display: flex;
	flex-direction: column;
	gap: 4px;
}

.export-dialog__modes,
.export-dialog__scope,
.export-dialog__fields {
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
