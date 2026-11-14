/**
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 *
 * The one guard every reveal path of a secret goes through
 * (crypto-item-reprompt D2): showing or copying its value or username,
 * editing, cloning, printing, showing a QR code and filling.
 *
 * @spec openspec/changes/crypto-item-reprompt/tasks.md#task-2.1
 */

import { RepromptCancelled, useRepromptPromptStore } from '../store/modules/repromptPrompt.js'

/**
 * Whether a secret asks for the master password again before it is used.
 *
 * @param {object|null} secret The secret.
 * @return {boolean}
 * @spec openspec/changes/crypto-item-reprompt/tasks.md#task-2.1
 */
export function needsReprompt(secret) {
	return secret?.reprompt === true
}

/**
 * The guard for one secret: a function that resolves at once for an item
 * that is not flagged, and for a flagged one only after the master password
 * was typed again and accepted. It rejects with RepromptCancelled when the
 * person closes the prompt. Every call asks again.
 *
 * @param {object|Function} secret The secret, or a getter returning the current one.
 * @param {Function} [prompt] The prompt store factory, for tests.
 * @return {Function} `() => Promise<void>`
 * @spec openspec/changes/crypto-item-reprompt/tasks.md#task-2.1
 */
export function useReprompt(secret, prompt = useRepromptPromptStore) {
	return async () => {
		const current = typeof secret === 'function' ? secret() : secret
		if (!needsReprompt(current)) {
			return
		}
		await prompt().ask(current?.name || '')
	}
}

/**
 * Run an action on a secret behind its guard. A closed prompt runs nothing
 * and resolves to null; any other failure is passed on.
 *
 * @param {object} secret The secret.
 * @param {Function} action The action to run once allowed.
 * @param {Function} [prompt] The prompt store factory, for tests.
 * @return {Promise<*>} What the action returns, or null when the person cancelled.
 * @spec openspec/changes/crypto-item-reprompt/tasks.md#task-2.1
 */
export async function withReprompt(secret, action, prompt = useRepromptPromptStore) {
	try {
		await useReprompt(secret, prompt)()
	} catch (err) {
		if (err instanceof RepromptCancelled) {
			return null
		}
		throw err
	}
	return action()
}

export { RepromptCancelled }
