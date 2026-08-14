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

  @spec openspec/changes/secret-export-gdpr/specs/gdpr-compliance/spec.md
-->
<template>
	<NcDialog
		:name="t('doriath', 'Download my data (GDPR)')"
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
						'doriath',
						'This downloads a machine-readable package of all personal data Doriath holds about you (GDPR Article 15).',
					)
				}}
			</p>

			<NcNoteCard v-if="locked" type="info">
				{{
					t(
						'doriath',
						'Your vault is locked. The package will contain your account metadata only; your secrets stay end-to-end encrypted and are not included unless you unlock the vault first.',
					)
				}}
			</NcNoteCard>
			<NcNoteCard v-else type="success">
				{{
					t(
						'doriath',
						'Your vault is unlocked. The package will include both your account metadata and your decrypted secrets.',
					)
				}}
			</NcNoteCard>
		</div>

		<template #actions>
			<NcButton @click="onUpdateOpen(false)">
				{{ t('doriath', 'Cancel') }}
			</NcButton>
			<NcButton variant="primary" :disabled="loading" @click="onDownload">
				{{
					locked
						? t('doriath', 'Download metadata only')
						: t('doriath', 'Download full package')
				}}
			</NcButton>
		</template>
	</NcDialog>
</template>

<script>
import { NcButton, NcDialog, NcNoteCard } from '@nextcloud/vue'
import { useExportStore } from '../store/modules/export.js'
import { useSessionStore } from '../store/modules/session.js'

export default {
	name: 'GdprExportDialog',
	components: {
		NcDialog,
		NcButton,
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
	},

	emits: ['update:open'],
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
			error: null,
		}
	},

	computed: {
		/**
		 * Whether an export is in flight (from the store).
		 *
		 * @return {boolean}
		 * @spec openspec/changes/secret-export-gdpr/specs/gdpr-compliance/spec.md
		 */
		loading() {
			return this.exportStore.loading
		},

		/**
		 * Whether the vault is locked (drives the metadata-only vs full variant).
		 *
		 * @return {boolean}
		 * @spec openspec/changes/secret-export-gdpr/specs/gdpr-compliance/spec.md
		 */
		locked() {
			return this.sessionStore.isLocked
		},
	},

	methods: {
		/**
		 * Produce and download the GDPR package; vault half included only when
		 * unlocked.
		 *
		 * @return {Promise<void>}
		 * @spec openspec/changes/secret-export-gdpr/specs/gdpr-compliance/spec.md
		 */
		async onDownload() {
			this.error = null
			try {
				const secrets = this.locked ? null : this.secrets
				await this.exportStore.exportGdprPackage(secrets, this.folders)
				this.$emit('update:open', false)
			} catch (e) {
				this.error =
					this.exportStore.error
					|| (e && e.message)
					|| this.t('doriath', 'GDPR export failed')
			}
		},

		/**
		 * Handle open-state changes, clearing the error on close.
		 *
		 * @param {boolean} value The new open state.
		 * @return {void}
		 * @spec openspec/changes/secret-export-gdpr/specs/gdpr-compliance/spec.md
		 */
		onUpdateOpen(value) {
			if (!value) {
				this.error = null
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
</style>
