<!--
  SPDX-License-Identifier: EUPL-1.2
  SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>

  The app-wide master password prompt for vault-key proofs (keepiq#818).

  Mounted once in the app shell. Any store that needs a proof awaits
  `useKeyProofPromptStore().ask(reason)`; this dialog shows the reason, takes
  the password, and lets the store check it against the session's private key
  envelope before the caller gets it. The entered value stays in this
  component until it is submitted, and is cleared when the prompt closes.

  Lives in its own file per ADR-004 (hydra gate-13).

  @spec openspec/specs/user-sharing/spec.md#requirement-sharing-with-a-new-party-requires-a-verified-key-proof
-->
<template>
	<NcDialog
		v-if="prompt.open"
		:name="t('keepiq', 'Confirm with your master password')"
		:open="prompt.open"
		size="small"
		data-testid="key-proof-prompt"
		@update:open="onOpenChange">
		<form class="key-proof-prompt" @submit.prevent="onSubmit">
			<p>{{ prompt.reason }}</p>
			<NcPasswordField
				v-model="password"
				:label="t('keepiq', 'Your master password')"
				:disabled="prompt.checking"
				autocomplete="current-password"
				data-testid="key-proof-prompt-password" />
			<NcNoteCard
				v-if="prompt.error"
				type="error"
				data-testid="key-proof-prompt-error">
				{{ prompt.error }}
			</NcNoteCard>
		</form>
		<template #actions>
			<NcButton :disabled="prompt.checking" @click="onCancel">
				{{ t('keepiq', 'Cancel') }}
			</NcButton>
			<NcButton
				variant="primary"
				:disabled="prompt.checking || password === ''"
				data-testid="key-proof-prompt-confirm"
				@click="onSubmit">
				{{ t('keepiq', 'Confirm') }}
			</NcButton>
		</template>
	</NcDialog>
</template>

<script>
import { NcButton, NcDialog, NcNoteCard, NcPasswordField } from '@nextcloud/vue'
import { useKeyProofPromptStore } from '../store/modules/keyProofPrompt.js'

/**
 * The master password prompt for vault-key proofs.
 *
 * @spec openspec/specs/user-sharing/spec.md#requirement-sharing-with-a-new-party-requires-a-verified-key-proof
 */
export default {
	name: 'KeyProofPromptDialog',

	components: {
		NcButton,
		NcDialog,
		NcNoteCard,
		NcPasswordField,
	},

	data() {
		return {
			password: '',
		}
	},

	computed: {
		/**
		 * The prompt store.
		 *
		 * @return {object}
		 * @spec openspec/specs/user-sharing/spec.md#requirement-sharing-with-a-new-party-requires-a-verified-key-proof
		 */
		prompt() {
			return useKeyProofPromptStore()
		},
	},

	watch: {
		/**
		 * Clear the entered password whenever the prompt closes.
		 *
		 * @param {boolean} open Whether the prompt is open.
		 * @return {void}
		 * @spec openspec/specs/user-sharing/spec.md#requirement-sharing-with-a-new-party-requires-a-verified-key-proof
		 */
		'prompt.open': function (open) {
			if (!open) {
				this.password = ''
			}
		},
	},

	methods: {
		/**
		 * Hand the password to the store to check and resolve.
		 *
		 * @return {Promise<void>}
		 * @spec openspec/specs/user-sharing/spec.md#requirement-sharing-with-a-new-party-requires-a-verified-key-proof
		 */
		async onSubmit() {
			if (this.password === '' || this.prompt.checking) {
				return
			}
			const accepted = await this.prompt.submit(this.password)
			if (accepted) {
				this.password = ''
			}
		},

		/**
		 * Close the prompt; the waiting request is cancelled.
		 *
		 * @return {void}
		 * @spec openspec/specs/user-sharing/spec.md#requirement-sharing-with-a-new-party-requires-a-verified-key-proof
		 */
		onCancel() {
			this.password = ''
			this.prompt.cancel()
		},

		/**
		 * NcDialog closed itself (Escape, the close button).
		 *
		 * @param {boolean} open The new open state.
		 * @return {void}
		 * @spec openspec/specs/user-sharing/spec.md#requirement-sharing-with-a-new-party-requires-a-verified-key-proof
		 */
		onOpenChange(open) {
			if (!open) {
				this.onCancel()
			}
		},
	},
}
</script>

<style scoped lang="scss">
.key-proof-prompt {
	display: flex;
	flex-direction: column;
	gap: 8px;
}
</style>
