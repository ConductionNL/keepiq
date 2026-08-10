<!--
  SPDX-License-Identifier: EUPL-1.2
  SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>

  Import wizard dialog (secret-import D1/D4/D5/D6/D8, tasks §4). A stepper:

    pick → mapping preview → folder mapping → duplicates → commit → summary

  The file is read entirely in the browser (FileReader); plaintext rows never
  leave the page. KDBX binaries are detected on pick and rejected with guidance.
  Sensitive cells are masked with per-cell reveal. The vault must be unlocked
  (CryptoKey in session) to start — a locked vault shows a lock guard and the
  wizard reads nothing. Closing the wizard resets the store, releasing every
  plaintext row (encryption-suites Session Mechanism).

  @spec openspec/changes/secret-import/specs/secret-import/spec.md
-->
<template>
	<NcDialog :name="t('doriath', 'Import secrets')"
		:open="open"
		size="large"
		@update:open="onUpdateOpen">
		<div class="import-wizard">
			<NcNoteCard v-if="locked" type="warning">
				{{ t('doriath', 'Unlock the vault before importing secrets.') }}
			</NcNoteCard>

			<NcNoteCard v-else-if="store.error" type="error">
				{{ store.error }}
			</NcNoteCard>

			<!-- Step: file pick + format select -->
			<div v-if="!locked && store.step === 'pick'" class="import-wizard__pick">
				<NcSelect v-model="format"
					:input-label="t('doriath', 'Source format')"
					:options="formatOptions"
					:reduce="opt => opt.value"
					:clearable="false" />

				<NcPasswordField v-if="requiresPassphrase"
					v-model="passphrase"
					:label="t('doriath', 'Backup passphrase')" />

				<label class="import-wizard__file">
					<span>{{ t('doriath', 'Choose a file to import') }}</span>
					<input ref="fileInput"
						type="file"
						data-testid="import-file"
						@change="onFilePicked">
				</label>

				<NcNoteCard v-if="kdbxDetected" type="error">
					{{ t('doriath', 'KDBX files are not supported. In KeePass, choose File → Export → KeePass XML (2.x) and import the resulting XML file instead.') }}
				</NcNoteCard>
			</div>

			<!-- Step: mapping preview -->
			<div v-else-if="store.step === 'mapping'" class="import-wizard__mapping">
				<p>{{ t('doriath', '{count} rows parsed. Review the field mapping before importing.', { count: store.rows.length }) }}</p>

				<table class="import-wizard__preview">
					<thead>
						<tr>
							<th scope="col">
								{{ t('doriath', 'Name') }}
							</th>
							<th scope="col">
								{{ t('doriath', 'URL') }}
							</th>
							<th scope="col">
								{{ t('doriath', 'Login') }}
							</th>
							<th scope="col">
								{{ t('doriath', 'Password') }}
							</th>
							<th scope="col">
								{{ t('doriath', 'Folder') }}
							</th>
						</tr>
					</thead>
					<tbody>
						<tr v-for="row in previewRows" :key="row.sourceRow">
							<td>{{ row.name }}</td>
							<td>{{ row.url }}</td>
							<td>{{ mask(row.login, row.sourceRow + '-login') }}</td>
							<td>{{ mask(row.password, row.sourceRow + '-pass') }}</td>
							<td>{{ row.folder }}</td>
						</tr>
					</tbody>
				</table>

				<NcNoteCard v-if="store.rejected.length" type="warning">
					{{ t('doriath', '{count} rows were rejected and will be listed in the summary.', { count: store.rejected.length }) }}
				</NcNoteCard>
			</div>

			<!-- Step: folder mapping -->
			<div v-else-if="store.step === 'folders'" class="import-wizard__folders">
				<NcCheckboxRadioSwitch :model-value="underOneFolder"
					@update:model-value="underOneFolder = $event">
					{{ t('doriath', 'Import everything under one new folder') }}
				</NcCheckboxRadioSwitch>
				<p class="import-wizard__hint">
					{{ t('doriath', 'Source folders are created beneath your vault, preserving their hierarchy. Existing folders with the same name are reused.') }}
				</p>
			</div>

			<!-- Step: duplicates -->
			<div v-else-if="store.step === 'duplicates'" class="import-wizard__duplicates">
				<template v-if="store.duplicates.length">
					<p>{{ t('doriath', '{count} rows match an existing secret.', { count: store.duplicates.length }) }}</p>
					<div class="import-wizard__bulk">
						<NcButton @click="store.resolveAllDuplicates('skip')">
							{{ t('doriath', 'Skip all') }}
						</NcButton>
						<NcButton @click="store.resolveAllDuplicates('copy')">
							{{ t('doriath', 'Import all as copies') }}
						</NcButton>
					</div>
					<table class="import-wizard__preview">
						<thead>
							<tr>
								<th scope="col">
									{{ t('doriath', 'Name') }}
								</th>
								<th scope="col">
									{{ t('doriath', 'URL') }}
								</th>
								<th scope="col">
									{{ t('doriath', 'Resolution') }}
								</th>
							</tr>
						</thead>
						<tbody>
							<tr v-for="dup in store.duplicates" :key="dup.sourceRow">
								<td>{{ dup.name }}</td>
								<td>{{ dup.url }}</td>
								<td>
									<NcSelect :model-value="store.duplicateResolutions[dup.sourceRow]"
										:input-label="t('doriath', 'Resolution')"
										:options="resolutionOptions"
										:reduce="opt => opt.value"
										:clearable="false"
										@update:model-value="store.resolveDuplicate(dup.sourceRow, $event)" />
								</td>
							</tr>
						</tbody>
					</table>
				</template>
				<NcEmptyContent v-else :name="t('doriath', 'No duplicates found')" />
			</div>

			<!-- Step: commit progress -->
			<div v-else-if="store.step === 'commit'" class="import-wizard__commit">
				<NcLoadingIcon :size="32" />
				<p>{{ t('doriath', 'Encrypting and importing… {done} of {total} batches.', { done: store.committedChunks, total: store.totalChunks }) }}</p>
			</div>

			<!-- Step: summary -->
			<div v-else-if="store.step === 'summary'" class="import-wizard__summary">
				<ul>
					<li>{{ t('doriath', 'Imported: {n}', { n: store.summary.imported }) }}</li>
					<li>{{ t('doriath', 'Skipped duplicates: {n}', { n: store.summary.skippedDuplicates }) }}</li>
					<li>{{ t('doriath', 'Rejected: {n}', { n: store.summary.rejected }) }}</li>
					<li>{{ t('doriath', 'Folders created: {n}', { n: store.summary.foldersCreated }) }}</li>
				</ul>
				<template v-if="store.summary.rejectedRows.length">
					<h4>{{ t('doriath', 'Rejected rows') }}</h4>
					<table class="import-wizard__preview">
						<thead>
							<tr>
								<th scope="col">
									{{ t('doriath', 'Row') }}
								</th>
								<th scope="col">
									{{ t('doriath', 'Name') }}
								</th>
								<th scope="col">
									{{ t('doriath', 'Reason') }}
								</th>
							</tr>
						</thead>
						<tbody>
							<tr v-for="r in store.summary.rejectedRows" :key="r.sourceRow + '-' + r.reason">
								<td>{{ r.sourceRow }}</td>
								<td>{{ r.name }}</td>
								<td>{{ r.reason }}</td>
							</tr>
						</tbody>
					</table>
					<NcButton @click="downloadRejected">
						{{ t('doriath', 'Download rejected rows') }}
					</NcButton>
				</template>
			</div>
		</div>

		<template #actions>
			<NcButton @click="onUpdateOpen(false)">
				{{ store.step === 'summary' ? t('doriath', 'Close') : t('doriath', 'Cancel') }}
			</NcButton>
			<NcButton v-if="canGoBack"
				:disabled="store.loading"
				@click="back">
				{{ t('doriath', 'Back') }}
			</NcButton>
			<NcButton v-if="store.step !== 'commit' && store.step !== 'summary'"
				variant="primary"
				:disabled="!canProceed || store.loading"
				@click="next">
				{{ nextLabel }}
			</NcButton>
		</template>
	</NcDialog>
</template>

<script>
import { NcButton, NcCheckboxRadioSwitch, NcDialog, NcEmptyContent, NcLoadingIcon, NcNoteCard, NcPasswordField, NcSelect } from '@nextcloud/vue'
import { useImportStore } from '../store/modules/import.js'
import { useSessionStore } from '../store/modules/session.js'
import { listParsers } from '../import/parserRegistry.js'
import { isKdbx } from '../import/model.js'
import '../import/parsers/index.js'

/**
 * The import wizard: a six-step stepper over the client-side import pipeline.
 */
export default {
	name: 'ImportWizardDialog',

	components: {
		NcButton,
		NcCheckboxRadioSwitch,
		NcDialog,
		NcEmptyContent,
		NcLoadingIcon,
		NcNoteCard,
		NcPasswordField,
		NcSelect,
	},

	props: {
		/** Whether the dialog is open. */
		open: {
			type: Boolean,
			default: false,
		},
	},

	emits: ['update:open', 'imported'],

	data() {
		return {
			store: useImportStore(),
			session: useSessionStore(),
			format: 'csv',
			passphrase: '',
			kdbxDetected: false,
			underOneFolder: false,
			revealed: {},
		}
	},

	computed: {
		/**
		 * Whether the vault is locked (no CryptoKey in session). A locked vault
		 * blocks the wizard and reads no file (lock-screen guard, spec).
		 *
		 * @return {boolean}
		 * @spec openspec/changes/secret-import/specs/secret-import/spec.md#requirement-client-side-parsing-and-e2e-guarantee
		 */
		locked() {
			return this.session.isLocked
		},

		/**
		 * The registered parser formats as select options.
		 *
		 * @return {Array<object>}
		 * @spec openspec/changes/secret-import/specs/secret-import/spec.md#requirement-supported-import-formats
		 */
		formatOptions() {
			return listParsers().map(p => ({ value: p.id, label: p.label }))
		},

		/**
		 * Whether the selected format needs a passphrase (backup restore).
		 *
		 * @return {boolean}
		 * @spec openspec/changes/secret-import/specs/secret-import/spec.md#requirement-supported-import-formats
		 */
		requiresPassphrase() {
			const parser = listParsers().find(p => p.id === this.format)
			return !!(parser && parser.requiresPassphrase)
		},

		/**
		 * Duplicate-resolution select options.
		 *
		 * @return {Array<object>}
		 * @spec openspec/changes/secret-import/specs/secret-import/spec.md#requirement-duplicate-detection
		 */
		resolutionOptions() {
			return [
				{ value: 'skip', label: t('doriath', 'Skip') },
				{ value: 'copy', label: t('doriath', 'Import as copy') },
			]
		},

		/**
		 * The first five parsed rows, for the mapping preview.
		 *
		 * @return {Array<object>}
		 * @spec openspec/changes/secret-import/specs/secret-import/spec.md#requirement-field-mapping-preview
		 */
		previewRows() {
			return this.store.rows.slice(0, 5)
		},

		/**
		 * Whether a Back button is shown for the current step.
		 *
		 * @return {boolean}
		 * @spec openspec/changes/secret-import/specs/secret-import/spec.md#requirement-field-mapping-preview
		 */
		canGoBack() {
			return ['mapping', 'folders', 'duplicates'].includes(this.store.step)
		},

		/**
		 * Whether the primary action can proceed from the current step.
		 *
		 * @return {boolean}
		 * @spec openspec/changes/secret-import/specs/secret-import/spec.md#requirement-field-mapping-preview
		 */
		canProceed() {
			if (this.store.step === 'mapping') {
				return this.store.rows.length > 0
			}
			return true
		},

		/**
		 * The primary-button label for the current step.
		 *
		 * @return {string}
		 * @spec openspec/changes/secret-import/specs/secret-import/spec.md#requirement-field-mapping-preview
		 */
		nextLabel() {
			return this.store.step === 'duplicates' ? t('doriath', 'Import') : t('doriath', 'Next')
		},
	},

	methods: {
		t,

		/**
		 * Mask a sensitive cell value unless it has been revealed.
		 *
		 * @param {string} value The cell value.
		 * @param {string} key The reveal key.
		 * @return {string} The masked or revealed value.
		 * @spec openspec/changes/secret-import/specs/secret-import/spec.md#requirement-field-mapping-preview
		 */
		mask(value, key) {
			if (value == null || value === '') {
				return ''
			}
			return this.revealed[key] ? value : '••••••••'
		},

		/**
		 * Read the picked file in the browser, detect KDBX, and parse it.
		 *
		 * @param {Event} event The file input change event.
		 * @return {Promise<void>}
		 * @spec openspec/changes/secret-import/specs/secret-import/spec.md#requirement-supported-import-formats
		 */
		async onFilePicked(event) {
			this.kdbxDetected = false
			const file = event.target.files && event.target.files[0]
			if (!file) {
				return
			}
			// Lock guard: never read the file when the vault is locked.
			if (this.locked) {
				return
			}
			// KDBX detection on the first bytes (magic 0x9AA2D903).
			const head = new Uint8Array(await file.slice(0, 4).arrayBuffer())
			if (isKdbx(head)) {
				this.kdbxDetected = true
				return
			}
			const text = await file.text()
			try {
				await this.store.parseFile(text, this.format, { passphrase: this.passphrase })
				this.store.goToStep('mapping')
			} catch {
				// store.error is already populated; stay on the pick step.
			}
		},

		/**
		 * Advance the wizard from the current step.
		 *
		 * @return {Promise<void>}
		 * @spec openspec/changes/secret-import/specs/secret-import/spec.md#requirement-chunked-batch-commit
		 */
		async next() {
			if (this.store.step === 'mapping') {
				this.store.goToStep('folders')
			} else if (this.store.step === 'folders') {
				await this.store.detectDuplicates()
				this.store.goToStep('duplicates')
			} else if (this.store.step === 'duplicates') {
				try {
					await this.store.commit()
					this.$emit('imported')
				} catch {
					// store.error already surfaced.
				}
			}
		},

		/**
		 * Step backwards in the wizard.
		 *
		 * @return {void}
		 * @spec openspec/changes/secret-import/specs/secret-import/spec.md#requirement-field-mapping-preview
		 */
		back() {
			const order = ['pick', 'mapping', 'folders', 'duplicates']
			const index = order.indexOf(this.store.step)
			if (index > 0) {
				this.store.goToStep(order[index - 1])
			}
		},

		/**
		 * Download the rejected rows as a client-side CSV (never uploaded).
		 *
		 * @return {void}
		 * @spec openspec/changes/secret-import/specs/secret-import/spec.md#requirement-malformed-row-rejection
		 */
		downloadRejected() {
			const csv = this.store.rejectedCsv()
			const blob = new Blob([csv], { type: 'text/csv' })
			const url = URL.createObjectURL(blob)
			const a = document.createElement('a')
			a.href = url
			a.download = 'doriath-import-rejected.csv'
			document.body.appendChild(a)
			a.click()
			document.body.removeChild(a)
			URL.revokeObjectURL(url)
		},

		/**
		 * Close the wizard and reset the store (releases all plaintext rows).
		 *
		 * @param {boolean} value The open state.
		 * @return {void}
		 * @spec openspec/changes/secret-import/specs/secret-import/spec.md#requirement-client-side-parsing-and-e2e-guarantee
		 */
		onUpdateOpen(value) {
			if (!value) {
				this.store.reset()
				this.kdbxDetected = false
				this.passphrase = ''
				this.underOneFolder = false
				this.revealed = {}
			}
			this.$emit('update:open', value)
		},
	},
}
</script>

<style scoped lang="scss">
.import-wizard {
	display: flex;
	flex-direction: column;
	gap: 12px;
	min-width: 480px;

	&__file input {
		display: block;
		margin-top: 4px;
	}

	&__preview {
		width: 100%;
		border-collapse: collapse;

		th, td {
			text-align: start;
			padding: 4px 8px;
			border-bottom: 1px solid var(--color-border);
		}
	}

	&__bulk {
		display: flex;
		gap: 8px;
		margin-bottom: 8px;
	}

	&__commit {
		display: flex;
		flex-direction: column;
		align-items: center;
		gap: 8px;
	}
}
</style>
