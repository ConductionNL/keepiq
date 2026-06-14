<!--
  SPDX-License-Identifier: EUPL-1.2
  SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>

  Account deletion dialog (secret-export-gdpr D4, tasks §6.3). Irreversibly
  deletes all of the user's Doriath data (GDPR Art. 17). Double-gated:

  - Fresh master-password re-entry (client-side proof of knowledge, verified by
    decrypting the private-key blob — never sent to the server).
  - A typed confirmation phrase that must match exactly.

  Offers a non-blocking "export first" suggestion. On success, shows the
  per-entity deletion report returned by the server.

  @spec openspec/changes/secret-export-gdpr/specs/gdpr-compliance/spec.md
-->
<template>
	<NcDialog :name="t('doriath', 'Delete my Doriath data')"
		:open="open"
		size="normal"
		@update:open="onUpdateOpen">
		<div class="deletion-dialog">
			<NcNoteCard v-if="error" type="error">
				{{ error }}
			</NcNoteCard>

			<!-- Success: show the report -->
			<div v-if="report" class="deletion-dialog__report">
				<NcNoteCard type="success">
					{{ t('doriath', 'Your Doriath data has been deleted.') }}
				</NcNoteCard>
				<ul>
					<li>{{ t('doriath', 'Secrets deleted') }}: {{ report.secretsDeleted }}</li>
					<li>{{ t('doriath', 'Secrets transferred to a delegate') }}: {{ report.secretsTransferred }}</li>
					<li>{{ t('doriath', 'Shares detached') }}: {{ report.sharesDetached }}</li>
					<li>{{ t('doriath', 'Received shares removed') }}: {{ report.sharesRemoved }}</li>
					<li>{{ t('doriath', 'Encryption suites removed') }}: {{ report.suitesDeleted }}</li>
				</ul>
			</div>

			<div v-else class="deletion-dialog__form">
				<NcNoteCard type="warning">
					{{ t('doriath', 'This permanently deletes ALL of your secrets, folders, shares, link shares, requests, and encryption keys. This cannot be undone.') }}
				</NcNoteCard>

				<p class="deletion-dialog__suggestion">
					<a href="#" @click.prevent="$emit('export-first')">
						{{ t('doriath', 'Export your vault first (recommended)') }}
					</a>
				</p>

				<NcPasswordField :value.sync="masterPassword"
					:label="t('doriath', 'Re-enter your master password')" />

				<NcTextField :value.sync="confirmation"
					:label="confirmationLabel" />
			</div>
		</div>

		<template #actions>
			<NcButton @click="onUpdateOpen(false)">
				{{ report ? t('doriath', 'Close') : t('doriath', 'Cancel') }}
			</NcButton>
			<NcButton v-if="!report"
				type="error"
				:disabled="!canSubmit || loading"
				@click="onDelete">
				{{ t('doriath', 'Delete everything') }}
			</NcButton>
		</template>
	</NcDialog>
</template>

<script>
import { NcButton, NcDialog, NcNoteCard, NcPasswordField, NcTextField } from '@nextcloud/vue'
import { useExportStore } from '../store/modules/export.js'
import { useSessionStore } from '../store/modules/session.js'
import { verifyMasterPassword } from '../crypto/reauth.js'

/** The exact phrase the user must type (mirrors GdprController). */
const CONFIRMATION_PHRASE = 'DELETE MY DORIATH DATA'

export default {
	name: 'AccountDeletionDialog',
	components: {
		NcDialog,
		NcButton,
		NcNoteCard,
		NcTextField,
		NcPasswordField,
	},
	props: {
		open: {
			type: Boolean,
			default: false,
		},
	},
	emits: ['update:open', 'export-first'],
	/**
	 * Provide the export + session Pinia stores to the component.
	 *
	 * @return {object}
	 * @spec openspec/changes/secret-export-gdpr/specs/gdpr-compliance/spec.md
	 */
	setup() {
		return {
			exportStore: useExportStore(),
			sessionStore: useSessionStore(),
		}
	},
	data() {
		return {
			masterPassword: '',
			confirmation: '',
			report: null,
			error: null,
		}
	},
	computed: {
		/**
		 * Whether a deletion is in flight (from the store).
		 *
		 * @return {boolean}
		 * @spec openspec/changes/secret-export-gdpr/specs/gdpr-compliance/spec.md
		 */
		loading() {
			return this.exportStore.loading
		},
		/**
		 * The confirmation-field label including the exact required phrase.
		 *
		 * @return {string}
		 * @spec openspec/changes/secret-export-gdpr/specs/gdpr-compliance/spec.md
		 */
		confirmationLabel() {
			return this.t('doriath', 'Type "{phrase}" to confirm', { phrase: CONFIRMATION_PHRASE })
		},
		/**
		 * Whether deletion may be submitted: BOTH a master password and the exact
		 * confirmation phrase must be present (double-gated).
		 *
		 * @return {boolean}
		 * @spec openspec/changes/secret-export-gdpr/specs/gdpr-compliance/spec.md
		 */
		canSubmit() {
			return this.masterPassword.length > 0 && this.confirmation === CONFIRMATION_PHRASE
		},
	},
	methods: {
		/**
		 * Verify the master password client-side, then run the deletion cascade
		 * and lock the vault on success.
		 *
		 * @return {Promise<void>}
		 * @spec openspec/changes/secret-export-gdpr/specs/gdpr-compliance/spec.md
		 */
		async onDelete() {
			this.error = null
			const ok = await verifyMasterPassword(
				this.sessionStore.encryptedPrivateKey,
				this.masterPassword,
			)
			if (!ok) {
				this.error = this.t('doriath', 'Incorrect master password')
				return
			}
			try {
				const result = await this.exportStore.deleteAccountData(this.confirmation)
				this.report = result.report || result
				this.sessionStore.lock()
			} catch (e) {
				this.error = this.exportStore.error || (e && e.message) || this.t('doriath', 'Deletion failed')
			}
		},
		/**
		 * Reset the dialog state (no master password retained).
		 *
		 * @return {void}
		 * @spec openspec/changes/secret-export-gdpr/specs/gdpr-compliance/spec.md
		 */
		reset() {
			this.masterPassword = ''
			this.confirmation = ''
			this.report = null
			this.error = null
		},
		/**
		 * Handle open-state changes, clearing state on close.
		 *
		 * @param {boolean} value The new open state.
		 * @return {void}
		 * @spec openspec/changes/secret-export-gdpr/specs/gdpr-compliance/spec.md
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
.deletion-dialog {
	display: flex;
	flex-direction: column;
	gap: 12px;
	padding: 8px 4px;
	min-width: 320px;
}

.deletion-dialog__suggestion {
	font-size: 0.9em;
}
</style>
