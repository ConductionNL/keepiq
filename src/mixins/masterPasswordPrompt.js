/**
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 *
 * The behaviour of a master password dialog driven by a prompt store with
 * `open`, `checking`, `error`, `submit(password)` and `cancel()`: shared by
 * the vault-key proof prompt and the re-prompt for flagged items. The
 * component provides `prompt` (the store). The entered value stays in the
 * component until it is submitted and is cleared when the prompt closes.
 *
 * @spec openspec/changes/crypto-item-reprompt/tasks.md#task-2.1
 */

export default {
	data() {
		return {
			password: '',
		}
	},

	watch: {
		/**
		 * Clear the entered password whenever the prompt closes.
		 *
		 * @param {boolean} open Whether the prompt is open.
		 * @return {void}
		 * @spec openspec/changes/crypto-item-reprompt/tasks.md#task-2.1
		 */
		'prompt.open': function (open) {
			if (!open) {
				this.password = ''
			}
		},
	},

	methods: {
		/**
		 * Hand the password to the store to check; it is cleared once accepted.
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
		 * Close the prompt; the waiting action or request does not run.
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
