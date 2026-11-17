<!--
  SPDX-License-Identifier: EUPL-1.2
  SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>

  The master password re-prompt for flagged items (crypto-item-reprompt).
  Mounted once in the app shell. Every reveal path of a flagged item awaits
  `useRepromptPromptStore().ask(name)`; this dialog takes the password and
  the store checks it in the browser. The entered value stays in this
  component until it is submitted and is cleared when the prompt closes.
  Lives in its own file per ADR-004 (hydra gate-13).

  @spec openspec/changes/archive/2026-11-17-crypto-item-reprompt/tasks.md#task-2.1
-->
<template>
	<MasterPasswordPromptDialog
		:prompt="prompt"
		:name="t('keepiq', 'Enter your master password')"
		testid="reprompt-dialog"
		formClass="reprompt-dialog">
		<p>{{ t('keepiq', '"{name}" asks for your master password before it is shown, copied or filled.', { name: prompt.itemName }) }}</p>
		<template #hint>
			<p class="reprompt-dialog__hint">
				{{ t('keepiq', 'Your master password is checked on this device and never sent. This stops someone at your unlocked screen; it does not protect against a modified app.') }}
			</p>
		</template>
	</MasterPasswordPromptDialog>
</template>

<script>
import MasterPasswordPromptDialog from './MasterPasswordPromptDialog.vue'
import { useRepromptPromptStore } from '../store/modules/repromptPrompt.js'

/**
 * The master password re-prompt for flagged items.
 *
 * @spec openspec/changes/archive/2026-11-17-crypto-item-reprompt/tasks.md#task-2.1
 */
export default {
	name: 'RepromptDialog',

	components: {
		MasterPasswordPromptDialog,
	},

	computed: {
		/**
		 * The prompt store.
		 *
		 * @return {object}
		 * @spec openspec/changes/archive/2026-11-17-crypto-item-reprompt/tasks.md#task-2.1
		 */
		prompt() {
			return useRepromptPromptStore()
		},
	},
}
</script>

<style scoped lang="scss">
.reprompt-dialog__hint {
	color: var(--color-text-maxcontrast);
	font-size: 0.9em;
}
</style>
