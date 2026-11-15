/**
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 *
 * The one waiting caller of an app-wide prompt store (the vault-key proof
 * prompt and the re-prompt for flagged items). A store opens the slot from
 * `ask()`, settles it from `submit()` once the password is accepted, and
 * cancels it from `cancel()`. A second open while a caller waits rejects the
 * first with the store's cancellation error.
 *
 * @spec openspec/changes/crypto-item-reprompt/tasks.md#task-2.1
 */

import { translate as t } from '@nextcloud/l10n'

/**
 * Create the slot for one prompt store.
 *
 * @param {new () => Error} Cancelled The error class a cancelled caller is rejected with.
 * @return {object} The slot: open(), check() and cancel().
 * @spec openspec/changes/crypto-item-reprompt/tasks.md#task-2.1
 */
export function createPromptSlot(Cancelled) {
	let pending = null

	/**
	 * Close the prompt and let the waiting caller go ahead.
	 *
	 * @param {object} store The prompt store's state.
	 * @param {string} [value] What the caller's promise resolves with.
	 * @return {void}
	 */
	function settle(store, value) {
		const { resolve } = pending
		pending = null
		store.open = false
		resolve(value)
	}

	return {
		/**
		 * Show the prompt and return the promise the caller awaits.
		 *
		 * @param {object} store The prompt store's state.
		 * @return {Promise<string|undefined>}
		 */
		open(store) {
			if (pending !== null) {
				pending.reject(new Cancelled())
			}
			store.error = ''
			store.checking = false
			store.open = true
			return new Promise((resolve, reject) => {
				pending = { resolve, reject }
			})
		},

		/**
		 * Check an entered password while a caller waits. Accepted: close the
		 * prompt and resolve the caller with `value`. Refused: keep the prompt
		 * open with an error.
		 *
		 * @param {object} store The prompt store's state.
		 * @param {string} password The entered password.
		 * @param {(password: string) => Promise<boolean>} verify Whether the password is right.
		 * @param {string} [value] What the caller's promise resolves with.
		 * @return {Promise<boolean>} Whether the password was accepted.
		 */
		async check(store, password, verify, value) {
			if (pending === null || !password) {
				return false
			}
			store.checking = true
			store.error = ''
			let ok
			try {
				ok = await verify(password)
			} finally {
				store.checking = false
			}
			if (!ok) {
				store.error = t('keepiq', 'That master password is not right.')
				return false
			}
			settle(store, value)
			return true
		},

		/**
		 * Close the prompt and reject the waiting caller, if any.
		 *
		 * @param {object} store The prompt store's state.
		 * @return {void}
		 */
		cancel(store) {
			store.open = false
			store.error = ''
			if (pending !== null) {
				const { reject } = pending
				pending = null
				reject(new Cancelled())
			}
		},
	}
}
