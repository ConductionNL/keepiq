/**
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 *
 * The app-wide master password prompt for vault-key proofs (keepiq#818).
 *
 * Sharing with a new recipient, registering a batch of shares and creating a
 * delegation need a vault-key proof. Those requests start in stores and
 * dialogs all over the app, so instead of a password field in each one, a
 * caller awaits `ask(reason)` and the single KeyProofPromptDialog in the app
 * shell collects the password. The prompt checks the password against the
 * session's private key envelope before it resolves, so a typo is caught in
 * the dialog rather than as a refused request. The password is handed to the
 * caller and never stored here after the prompt closes.
 *
 * @spec openspec/specs/user-sharing/spec.md#requirement-sharing-with-a-new-party-requires-a-verified-key-proof
 */

import { defineStore } from 'pinia'
import { decryptPrivateKey } from '../../crypto/index.js'
import { createPromptSlot } from './promptSlot.js'
import { useSessionStore } from './session.js'

/**
 * Error thrown to the caller when the user closes the prompt.
 */
export class KeyProofPromptCancelled extends Error {
	/**
	 * A cancelled prompt, with a stable code callers can test for.
	 *
	 * @spec openspec/specs/user-sharing/spec.md#requirement-sharing-with-a-new-party-requires-a-verified-key-proof
	 */
	constructor() {
		super('Master password prompt cancelled')
		this.name = 'KeyProofPromptCancelled'
		this.code = 'key_proof_cancelled'
	}
}

const slot = createPromptSlot(KeyProofPromptCancelled)

/**
 * Whether a password opens the session's private key envelope.
 *
 * @param {string} password The entered password.
 * @return {Promise<boolean>}
 * @spec openspec/specs/user-sharing/spec.md#requirement-sharing-with-a-new-party-requires-a-verified-key-proof
 */
async function opensPrivateKey(password) {
	try {
		await decryptPrivateKey(useSessionStore().encryptedPrivateKey, password)
		return true
	} catch {
		return false
	}
}

export const useKeyProofPromptStore = defineStore('keyProofPrompt', {
	state: () => ({
		/** @type {boolean} Whether the prompt is shown. */
		open: false,
		/** @type {string} Why the password is asked, shown in the prompt. */
		reason: '',
		/** @type {boolean} Whether the entered password is being checked. */
		checking: false,
		/** @type {string} The last check failure, or empty. */
		error: '',
	}),

	actions: {
		/**
		 * Ask for the master password. Resolves with it once it opens the
		 * session's private key envelope; rejects with KeyProofPromptCancelled
		 * when the user closes the prompt. A second ask while one is open
		 * cancels the first.
		 *
		 * @param {string} reason Why the password is needed, in the user's words.
		 * @return {Promise<string>} The master password.
		 * @spec openspec/specs/user-sharing/spec.md#requirement-sharing-with-a-new-party-requires-a-verified-key-proof
		 */
		ask(reason) {
			this.reason = reason
			return slot.open(this)
		},

		/**
		 * Check the entered password and resolve the pending ask with it.
		 *
		 * @param {string} masterPassword The entered password.
		 * @return {Promise<boolean>} Whether the password was accepted.
		 * @spec openspec/specs/user-sharing/spec.md#requirement-sharing-with-a-new-party-requires-a-verified-key-proof
		 */
		submit(masterPassword) {
			return slot.check(this, masterPassword, opensPrivateKey, masterPassword)
		},

		/**
		 * Close the prompt and reject the pending ask.
		 *
		 * @return {void}
		 * @spec openspec/specs/user-sharing/spec.md#requirement-sharing-with-a-new-party-requires-a-verified-key-proof
		 */
		cancel() {
			slot.cancel(this)
		},
	},
})
