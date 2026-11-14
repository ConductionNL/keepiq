/**
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 *
 * The master password re-prompt for flagged items (crypto-item-reprompt).
 *
 * An item its holder flagged is shown, copied, edited, cloned, printed, shown
 * as a QR code or filled only after the master password is typed again. Every
 * such action awaits `ask(name)`; the single RepromptDialog in the app shell
 * collects the password and this store checks it with verifyMasterPassword()
 * against the session's private key envelope. The password is checked in the
 * browser and dropped: it is never returned, stored or sent to the server.
 * There is no grace period: every action asks again (design D3).
 *
 * @spec openspec/changes/crypto-item-reprompt/tasks.md#task-2.1
 */

import { translate as t } from '@nextcloud/l10n'
import { defineStore } from 'pinia'
import { verifyMasterPassword } from '../../crypto/reauth.js'
import { useSessionStore } from './session.js'

/**
 * Error the waiting action gets when the person closes the prompt.
 */
export class RepromptCancelled extends Error {
	/**
	 * A cancelled re-prompt, with a stable code callers can test for.
	 *
	 * @spec openspec/changes/crypto-item-reprompt/tasks.md#task-2.1
	 */
	constructor() {
		super('Master password re-prompt cancelled')
		this.name = 'RepromptCancelled'
		this.code = 'reprompt_cancelled'
	}
}

let pending = null

export const useRepromptPromptStore = defineStore('repromptPrompt', {
	state: () => ({
		/** @type {boolean} Whether the prompt is shown. */
		open: false,
		/** @type {string} The name of the item asked about. */
		itemName: '',
		/** @type {boolean} Whether the entered password is being checked. */
		checking: false,
		/** @type {string} The last check failure, or empty. */
		error: '',
	}),

	actions: {
		/**
		 * Ask for the master password before an action on a flagged item.
		 * Resolves once the password opens the session's private key envelope;
		 * rejects with RepromptCancelled when the person closes the prompt. A
		 * second ask while one is open cancels the first.
		 *
		 * @param {string} itemName The item's name, shown in the prompt.
		 * @return {Promise<void>}
		 * @spec openspec/changes/crypto-item-reprompt/tasks.md#task-2.1
		 */
		ask(itemName) {
			if (pending !== null) {
				pending.reject(new RepromptCancelled())
			}
			this.itemName = String(itemName || '')
			this.error = ''
			this.checking = false
			this.open = true
			return new Promise((resolve, reject) => {
				pending = { resolve, reject }
			})
		},

		/**
		 * Check the entered password; on success close the prompt and let the
		 * waiting action go ahead. A wrong password keeps the prompt open.
		 *
		 * @param {string} masterPassword The entered password.
		 * @return {Promise<boolean>} Whether the password was accepted.
		 * @spec openspec/changes/crypto-item-reprompt/tasks.md#task-2.1
		 */
		async submit(masterPassword) {
			if (pending === null || !masterPassword) {
				return false
			}
			this.checking = true
			this.error = ''
			const ok = await verifyMasterPassword(useSessionStore().encryptedPrivateKey, masterPassword)
			this.checking = false
			if (!ok) {
				this.error = t('keepiq', 'That master password is not right.')
				return false
			}
			const { resolve } = pending
			pending = null
			this.open = false
			resolve()
			return true
		},

		/**
		 * Close the prompt and refuse the waiting action.
		 *
		 * @return {void}
		 * @spec openspec/changes/crypto-item-reprompt/tasks.md#task-2.1
		 */
		cancel() {
			this.open = false
			this.error = ''
			if (pending !== null) {
				const { reject } = pending
				pending = null
				reject(new RepromptCancelled())
			}
		},
	},
})
