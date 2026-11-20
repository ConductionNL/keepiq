<!--
  SPDX-License-Identifier: EUPL-1.2
  SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>

  SecretPrintModal — Print on a login (vault-item-clone-preview-and-print
  D3). The master password is entered again and verified in the browser,
  with the same proof of knowledge the plaintext export uses; only then is
  the print sheet rendered and the browser's print dialog opened. A wrong
  password renders no sheet. Nothing is sent to the server.

  @spec openspec/changes/vault-item-clone-preview-and-print/specs/secret-item-actions/spec.md#requirement-print-a-login-or-show-its-password-as-a-qr-code
-->
<template>
	<NcDialog
		:name="t('keepiq', 'Print login')"
		:open="open"
		size="normal"
		@update:open="onUpdateOpen">
		<div class="secret-print" data-testid="secret-print">
			<NcNoteCard type="warning">
				{{ t('keepiq', 'A printed sheet is outside Keepiq\'s control once it leaves the printer. Enter your master password to print the name, address, username and password.') }}
			</NcNoteCard>
			<NcNoteCard v-if="error" type="error" data-testid="secret-print-error">
				{{ error }}
			</NcNoteCard>
			<p v-if="verified" data-testid="secret-print-ready">
				{{ t('keepiq', 'The print dialog was opened. Close this window when you are done.') }}
			</p>
			<form v-else @submit.prevent="onVerify">
				<NcPasswordField
					v-model="masterPassword"
					:label="t('keepiq', 'Re-enter your master password')"
					autocomplete="current-password"
					data-testid="secret-print-password-field" />
			</form>
		</div>
		<SecretPrintSheet
			v-if="verified"
			:name="name"
			:url="url"
			:login="login"
			:password="password" />
		<template #actions>
			<NcButton data-testid="secret-print-close" @click="onUpdateOpen(false)">
				{{ t('keepiq', 'Close') }}
			</NcButton>
			<NcButton
				v-if="verified"
				variant="primary"
				data-testid="secret-print-again"
				@click="print">
				{{ t('keepiq', 'Print again') }}
			</NcButton>
			<NcButton
				v-else
				variant="primary"
				:disabled="masterPasswordPending"
				data-testid="secret-print-submit"
				@click="onVerify">
				{{ t('keepiq', 'Print') }}
			</NcButton>
		</template>
	</NcDialog>
</template>

<script>
import { t } from '@nextcloud/l10n'
import { NcButton, NcDialog, NcNoteCard, NcPasswordField } from '@nextcloud/vue'
import SecretPrintSheet from '../components/SecretPrintSheet.vue'
import secretPrintProps from '../components/secretPrintProps.js'
import masterPasswordCheck from '../mixins/masterPasswordCheck.js'
import { useSessionStore } from '../store/modules/session.js'

/**
 * Print one login, behind a master password check. Emits `close` when dismissed.
 */
export default {
	name: 'SecretPrintModal',

	components: {
		NcButton,
		NcDialog,
		NcNoteCard,
		NcPasswordField,
		SecretPrintSheet,
	},

	mixins: [masterPasswordCheck],

	props: secretPrintProps,

	emits: ['close'],

	data() {
		return {
			open: true,
			masterPassword: '',
			error: null,
			busy: false,
			verified: false,
		}
	},

	computed: {
		/**
		 * The session store, read by the master password check.
		 *
		 * @return {object}
		 * @spec openspec/changes/vault-item-clone-preview-and-print/specs/secret-item-actions/spec.md#requirement-print-a-login-or-show-its-password-as-a-qr-code
		 */
		sessionStore() {
			return useSessionStore()
		},
	},

	methods: {
		t,

		/**
		 * Check the master password; render the sheet and print only when it opens the vault key.
		 *
		 * @return {Promise<void>}
		 * @spec openspec/changes/vault-item-clone-preview-and-print/specs/secret-item-actions/spec.md#requirement-print-a-login-or-show-its-password-as-a-qr-code
		 */
		async onVerify() {
			if (!(await this.masterPasswordChecked())) {
				return
			}
			this.verified = true
			await this.$nextTick()
			this.print()
		},

		/**
		 * Open the browser's print dialog over the rendered sheet.
		 *
		 * @return {void}
		 * @spec openspec/changes/vault-item-clone-preview-and-print/specs/secret-item-actions/spec.md#requirement-print-a-login-or-show-its-password-as-a-qr-code
		 */
		print() {
			if (this.verified) {
				window.print()
			}
		},

		/**
		 * Forward the open state; emit `close` when dismissed, which also removes the sheet.
		 *
		 * @param {boolean} value The new open state.
		 * @return {void}
		 * @spec exclude Dialog open-state plumbing; no domain behaviour.
		 */
		onUpdateOpen(value) {
			this.open = value
			if (!value) {
				this.verified = false
				this.$emit('close')
			}
		},
	},
}
</script>

<style scoped>
.secret-print {
	display: flex;
	flex-direction: column;
	gap: calc(var(--default-grid-baseline) * 3);
}
</style>
