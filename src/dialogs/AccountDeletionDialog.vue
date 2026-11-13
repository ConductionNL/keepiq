<!--
  SPDX-License-Identifier: EUPL-1.2
  SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>

  Account deletion dialog (secret-export-gdpr D4, tasks §6.3). Irreversibly
  deletes all of the user's Keepiq data (GDPR Art. 17). Double-gated:

  - Fresh master-password re-entry (client-side proof of knowledge, verified by
    decrypting the private-key blob — never sent to the server).
  - A typed confirmation phrase that must match exactly.

  Offers a non-blocking "export first" suggestion. On success, shows the
  per-entity deletion report returned by the server.

  @spec openspec/specs/gdpr-compliance/spec.md
-->
<template>
	<NcDialog
		:name="t('keepiq', 'Delete my Keepiq data')"
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
					{{ t('keepiq', 'Your Keepiq data has been deleted.') }}
				</NcNoteCard>
				<ul>
					<li>
						{{ t('keepiq', 'Secrets deleted') }}:
						{{ report.secretsDeleted }}
					</li>
					<li>
						{{ t('keepiq', 'Secrets transferred to a delegate') }}:
						{{ report.secretsTransferred }}
					</li>
					<li>
						{{ t('keepiq', 'Shares detached') }}:
						{{ report.sharesDetached }}
					</li>
					<li>
						{{ t('keepiq', 'Received shares removed') }}:
						{{ report.sharesRemoved }}
					</li>
					<li>
						{{ t('keepiq', 'Encryption suites removed') }}:
						{{ report.suitesDeleted }}
					</li>
				</ul>
			</div>

			<div v-else class="deletion-dialog__form">
				<NcNoteCard type="warning">
					{{
						t(
							'keepiq',
							'This permanently deletes ALL of your secrets, folders, shares, link shares, requests, and encryption keys. This cannot be undone.',
						)
					}}
				</NcNoteCard>

				<p class="deletion-dialog__suggestion">
					<a href="#" @click.prevent="$emit('export-first')">
						{{ t('keepiq', 'Export your vault first (recommended)') }}
					</a>
				</p>

				<NcPasswordField
					v-model="masterPassword"
					:label="t('keepiq', 'Re-enter your master password')" />

				<NcTextField v-model="confirmation" :label="confirmationLabel" />
			</div>
		</div>

		<template #actions>
			<NcButton @click="onUpdateOpen(false)">
				{{ report ? t('keepiq', 'Close') : t('keepiq', 'Cancel') }}
			</NcButton>
			<NcButton
				v-if="!report"
				variant="error"
				:disabled="!canSubmit || loading"
				@click="onDelete">
				{{ t('keepiq', 'Delete everything') }}
			</NcButton>
		</template>
	</NcDialog>
</template>

<script>
import {
	NcButton,
	NcDialog,
	NcNoteCard,
	NcPasswordField,
	NcTextField,
} from '@nextcloud/vue'
import exportStoreDialog from '../mixins/exportStoreDialog.js'
import masterPasswordCheck from '../mixins/masterPasswordCheck.js'

/** The exact phrase the user must type (mirrors GdprController). */
const CONFIRMATION_PHRASE = 'DELETE MY KEEPIQ DATA'

export default {
	name: 'AccountDeletionDialog',
	components: {
		NcDialog,
		NcButton,
		NcNoteCard,
		NcTextField,
		NcPasswordField,
	},

	mixins: [exportStoreDialog, masterPasswordCheck],

	props: {
		open: {
			type: Boolean,
			default: false,
		},
	},

	emits: ['update:open', 'export-first'],
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
		 * The confirmation-field label including the exact required phrase.
		 *
		 * @return {string}
		 * @spec openspec/specs/gdpr-compliance/spec.md
		 */
		confirmationLabel() {
			return this.t('keepiq', 'Type "{phrase}" to confirm', {
				phrase: CONFIRMATION_PHRASE,
			})
		},

		/**
		 * Whether deletion may be submitted: BOTH a master password and the exact
		 * confirmation phrase must be present (double-gated).
		 *
		 * @return {boolean}
		 * @spec openspec/specs/gdpr-compliance/spec.md
		 */
		canSubmit() {
			return (
				this.masterPassword.length > 0
				&& this.confirmation === CONFIRMATION_PHRASE
			)
		},
	},

	methods: {
		/**
		 * Verify the master password client-side, then run the deletion cascade
		 * and lock the vault on success.
		 *
		 * @return {Promise<void>}
		 * @spec openspec/specs/gdpr-compliance/spec.md
		 */
		async onDelete() {
			this.error = null
			if (!(await this.masterPasswordVerified())) {
				return
			}
			try {
				const result = await this.exportStore.deleteAccountData(
					this.confirmation,
					this.masterPassword,
				)
				this.report = result.report || result
				this.sessionStore.lock()
			} catch (e) {
				this.error =
					this.exportStore.error
					|| (e && e.message)
					|| this.t('keepiq', 'Deletion failed')
			}
		},

		/**
		 * Reset the dialog state (no master password retained).
		 *
		 * @return {void}
		 * @spec openspec/specs/gdpr-compliance/spec.md
		 */
		reset() {
			this.masterPassword = ''
			this.confirmation = ''
			this.report = null
			this.error = null
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
