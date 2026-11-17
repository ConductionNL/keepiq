<!--
  SPDX-License-Identifier: EUPL-1.2
  SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>

  The master password dialog both app-wide prompts render: the vault-key
  proof prompt (KeyProofPromptDialog) and the re-prompt for flagged items
  (RepromptDialog). The prompt store passed in decides what happens with the
  password; this dialog only takes it, hands it to `prompt.submit()` and
  clears it when the prompt closes. The slot holds the prompt's own text.
  Lives in its own file per ADR-004 (hydra gate-13).

  @spec openspec/changes/archive/2026-11-17-crypto-item-reprompt/tasks.md#task-2.1
-->
<template>
	<NcDialog
		v-if="prompt.open"
		:name="name"
		:open="prompt.open"
		size="small"
		:data-testid="testid"
		@update:open="onOpenChange">
		<form class="master-password-prompt" :class="[formClass]" @submit.prevent="onSubmit">
			<slot />
			<NcPasswordField
				v-model="password"
				:label="t('keepiq', 'Your master password')"
				:disabled="prompt.checking"
				autocomplete="current-password"
				:data-testid="`${testid}-password`" />
			<NcNoteCard
				v-if="prompt.error"
				type="error"
				:data-testid="`${testid}-error`">
				{{ prompt.error }}
			</NcNoteCard>
			<slot name="hint" />
		</form>
		<template #actions>
			<NcButton :disabled="prompt.checking" :data-testid="`${testid}-cancel`" @click="onCancel">
				{{ t('keepiq', 'Cancel') }}
			</NcButton>
			<NcButton
				variant="primary"
				:disabled="prompt.checking || password === ''"
				:data-testid="`${testid}-confirm`"
				@click="onSubmit">
				{{ t('keepiq', 'Confirm') }}
			</NcButton>
		</template>
	</NcDialog>
</template>

<script>
import { NcButton, NcDialog, NcNoteCard, NcPasswordField } from '@nextcloud/vue'
import masterPasswordPrompt from '../mixins/masterPasswordPrompt.js'

/**
 * A master password dialog driven by the prompt store passed in.
 *
 * @spec openspec/changes/archive/2026-11-17-crypto-item-reprompt/tasks.md#task-2.1
 */
export default {
	name: 'MasterPasswordPromptDialog',

	components: { NcButton, NcDialog, NcNoteCard, NcPasswordField },

	mixins: [masterPasswordPrompt],

	props: {
		/** The prompt store. */
		prompt: {
			type: Object,
			required: true,
		},

		/** The dialog title. */
		name: {
			type: String,
			required: true,
		},

		/** The data-testid of the dialog; its fields and buttons add a suffix. */
		testid: {
			type: String,
			required: true,
		},

		/** The class of the form. */
		formClass: {
			type: String,
			default: '',
		},
	},
}
</script>

<style scoped lang="scss">
.master-password-prompt {
	display: flex;
	flex-direction: column;
	gap: 8px;
}
</style>
