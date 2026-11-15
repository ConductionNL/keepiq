<!--
  SPDX-License-Identifier: EUPL-1.2
  SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>

  The master password re-prompt for flagged items (crypto-item-reprompt).
  Mounted once in the app shell. Every reveal path of a flagged item awaits
  `useRepromptPromptStore().ask(name)`; this dialog takes the password and
  the store checks it in the browser. The entered value stays in this
  component until it is submitted and is cleared when the prompt closes.
  Lives in its own file per ADR-004 (hydra gate-13).

  @spec openspec/changes/crypto-item-reprompt/tasks.md#task-2.1
-->
<template>
	<NcDialog
		v-if="prompt.open"
		:name="t('keepiq', 'Enter your master password')"
		:open="prompt.open"
		size="small"
		data-testid="reprompt-dialog"
		@update:open="onOpenChange">
		<form class="reprompt-dialog" @submit.prevent="onSubmit">
			<p>{{ t('keepiq', '"{name}" asks for your master password before it is shown, copied or filled.', { name: prompt.itemName }) }}</p>
			<NcPasswordField
				v-model="password"
				:label="t('keepiq', 'Your master password')"
				:disabled="prompt.checking"
				autocomplete="current-password"
				data-testid="reprompt-password" />
			<NcNoteCard
				v-if="prompt.error"
				type="error"
				data-testid="reprompt-error">
				{{ prompt.error }}
			</NcNoteCard>
			<p class="reprompt-dialog__hint">
				{{ t('keepiq', 'Your master password is checked on this device and never sent. This stops someone at your unlocked screen; it does not protect against a modified app.') }}
			</p>
		</form>
		<template #actions>
			<NcButton :disabled="prompt.checking" data-testid="reprompt-cancel" @click="onCancel">
				{{ t('keepiq', 'Cancel') }}
			</NcButton>
			<NcButton
				variant="primary"
				:disabled="prompt.checking || password === ''"
				data-testid="reprompt-confirm"
				@click="onSubmit">
				{{ t('keepiq', 'Confirm') }}
			</NcButton>
		</template>
	</NcDialog>
</template>

<script>
import { NcButton, NcDialog, NcNoteCard, NcPasswordField } from '@nextcloud/vue'
import { useRepromptPromptStore } from '../store/modules/repromptPrompt.js'

/**
 * The master password re-prompt for flagged items.
 *
 * @spec openspec/changes/crypto-item-reprompt/tasks.md#task-2.1
 */
export default {
	name: 'RepromptDialog',

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
		 * @spec openspec/changes/crypto-item-reprompt/tasks.md#task-2.1
		 */
		prompt() {
			return useRepromptPromptStore()
		},
	},

	watch: {
		/**
		 * Clear the entered password whenever the prompt closes.
		 *
		 * @param {boolean} open Whether the prompt is open.
		 * @return {void}
		 * @spec openspec/changes/crypto-item-reprompt/tasks.md#task-2.1
		 */
		'prompt.open': function(open) {
			if (!open) {
				this.password = ''
			}
		},
	},

	methods: {
		/**
		 * Hand the password to the store to check; it is cleared either way
		 * once accepted.
		 *
		 * @return {Promise<void>}
		 * @spec openspec/changes/crypto-item-reprompt/tasks.md#task-2.1
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
		 * Close the prompt; the waiting action does not run.
		 *
		 * @return {void}
		 * @spec openspec/changes/crypto-item-reprompt/tasks.md#task-2.1
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
		 * @spec openspec/changes/crypto-item-reprompt/tasks.md#task-2.1
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
.reprompt-dialog {
	display: flex;
	flex-direction: column;
	gap: 8px;
}

.reprompt-dialog__hint {
	color: var(--color-text-maxcontrast);
	font-size: 0.9em;
}
</style>
