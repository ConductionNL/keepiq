<!--
  SPDX-License-Identifier: EUPL-1.2
  SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>

  GDPR data-export dialog (secret-export-gdpr D3, tasks §6.4). Produces the
  GDPR Art. 15 package = server metadata + client-decrypted vault, assembled in
  the browser and downloaded locally.

  - Unlocked vault: offers the full package (metadata + decrypted vault).
  - Locked vault: offers the metadata-only package with an explicit statement
    that the end-to-end encrypted vault was not unlocked (the honest Art. 15
    answer under ADR-003). The user may choose to unlock first.

  @spec openspec/specs/gdpr-compliance/spec.md
-->
<template>
	<NcDialog
		:name="t('keepiq', 'Download my data (GDPR)')"
		:open="open"
		size="normal"
		@update:open="onUpdateOpen">
		<div class="gdpr-dialog">
			<NcNoteCard v-if="error" type="error">
				{{ error }}
			</NcNoteCard>

			<p>
				{{
					t(
						'keepiq',
						'This downloads a machine-readable package of all personal data Keepiq holds about you (GDPR Article 15).',
					)
				}}
			</p>

			<NcNoteCard v-if="locked" type="info">
				{{
					t(
						'keepiq',
						'Your vault is locked. The package will contain your account metadata only; your secrets stay end-to-end encrypted and are not included unless you unlock the vault first.',
					)
				}}
			</NcNoteCard>
			<NcNoteCard v-else type="success">
				{{
					t(
						'keepiq',
						'Your vault is unlocked. The package will include both your account metadata and your decrypted secrets.',
					)
				}}
			</NcNoteCard>

			<!-- Secrets that could not be decrypted are not in the package:
			     say how many, and hold the download until the user chooses
			     to continue without them (keepiq#874). -->
			<div
				v-if="!locked && skipped > 0"
				class="gdpr-dialog__skipped"
				data-testid="gdpr-skipped-warning">
				<NcNoteCard type="warning">
					{{
						n(
							'keepiq',
							'%n secret could not be decrypted and is not in this export.',
							'%n secrets could not be decrypted and are not in this export.',
							skipped,
						)
					}}
				</NcNoteCard>
				<NcCheckboxRadioSwitch
					:modelValue="skippedAcknowledged"
					data-testid="gdpr-skipped-ack"
					@update:modelValue="skippedAcknowledged = $event">
					{{
						t(
							'keepiq',
							'Continue without the secrets that could not be decrypted',
						)
					}}
				</NcCheckboxRadioSwitch>
			</div>
		</div>

		<template #actions>
			<NcButton @click="onUpdateOpen(false)">
				{{ t('keepiq', 'Cancel') }}
			</NcButton>
			<NcButton
				variant="primary"
				:disabled="!canDownload"
				data-testid="gdpr-download"
				@click="onDownload">
				{{
					locked
						? t('keepiq', 'Download metadata only')
						: t('keepiq', 'Download full package')
				}}
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
} from '@nextcloud/vue'
import { useExportStore } from '../store/modules/export.js'
import { useSessionStore } from '../store/modules/session.js'

export default {
	name: 'GdprExportDialog',
	components: {
		NcDialog,
		NcButton,
		NcCheckboxRadioSwitch,
		NcNoteCard,
	},

	props: {
		open: {
			type: Boolean,
			default: false,
		},

		/** Decrypted secrets (empty/ignored when locked). */
		secrets: {
			type: Array,
			default: () => [],
		},

		/** Folder rows. */
		folders: {
			type: Array,
			default: () => [],
		},

		/** How many secrets could not be decrypted and are not in `secrets`. */
		skipped: {
			type: Number,
			default: 0,
		},
	},

	emits: ['update:open'],
	/**
	 * Provide the export + session Pinia stores to the component.
	 *
	 * @return {object}
	 * @spec openspec/specs/gdpr-compliance/spec.md
	 */
	setup() {
		return {
			exportStore: useExportStore(),
			sessionStore: useSessionStore(),
		}
	},

	data() {
		return {
			error: null,
			/** Whether the user chose to download without the skipped secrets. */
			skippedAcknowledged: false,
		}
	},

	computed: {
		/**
		 * Whether an export is in flight (from the store).
		 *
		 * @return {boolean}
		 * @spec openspec/specs/gdpr-compliance/spec.md
		 */
		loading() {
			return this.exportStore.loading
		},

		/**
		 * Whether the vault is locked (drives the metadata-only vs full variant).
		 *
		 * @return {boolean}
		 * @spec openspec/specs/gdpr-compliance/spec.md
		 */
		locked() {
			return this.sessionStore.isLocked
		},

		/**
		 * Whether the download may start: not while one is in flight, and not
		 * while secrets are left out without the user choosing to continue.
		 *
		 * @return {boolean}
		 * @spec openspec/changes/portability-export-choice-and-restore-fidelity/specs/export-selection-and-restore/spec.md#requirement-nothing-is-left-out-of-an-export-in-silence
		 */
		canDownload() {
			if (this.loading) {
				return false
			}
			return this.locked || this.skipped === 0 || this.skippedAcknowledged
		},
	},

	methods: {
		/**
		 * Produce and download the GDPR package; vault half included only when
		 * unlocked.
		 *
		 * @return {Promise<void>}
		 * @spec openspec/specs/gdpr-compliance/spec.md
		 */
		async onDownload() {
			if (!this.canDownload) {
				return
			}
			this.error = null
			try {
				const secrets = this.locked ? null : this.secrets
				await this.exportStore.exportGdprPackage(secrets, this.folders)
				this.$emit('update:open', false)
			} catch (e) {
				this.error =
					this.exportStore.error
					|| (e && e.message)
					|| this.t('keepiq', 'GDPR export failed')
			}
		},

		/**
		 * Handle open-state changes, clearing the error on close.
		 *
		 * @param {boolean} value The new open state.
		 * @return {void}
		 * @spec openspec/specs/gdpr-compliance/spec.md
		 */
		onUpdateOpen(value) {
			if (!value) {
				this.error = null
				this.skippedAcknowledged = false
			}
			this.$emit('update:open', value)
		},
	},
}
</script>

<style scoped>
.gdpr-dialog {
	display: flex;
	flex-direction: column;
	gap: 12px;
	padding: 8px 4px;
	min-width: 320px;
}

.gdpr-dialog__skipped {
	display: flex;
	flex-direction: column;
	gap: 4px;
}
</style>
