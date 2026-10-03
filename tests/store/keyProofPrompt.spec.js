/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * The app-wide master password prompt for vault-key proofs (keepiq#818): it
 * resolves only with a password that opens the session's key envelope, keeps
 * asking after a wrong one, and rejects when the user cancels.
 *
 * @spec openspec/specs/user-sharing/spec.md#requirement-sharing-with-a-new-party-requires-a-verified-key-proof
 */

import { createPinia, setActivePinia } from 'pinia'
import { beforeEach, describe, expect, it, vi } from 'vitest'
import {
	KeyProofPromptCancelled,
	useKeyProofPromptStore,
} from '../../src/store/modules/keyProofPrompt.js'
import { useSessionStore } from '../../src/store/modules/session.js'

vi.mock('../../src/crypto/index.js', async (importOriginal) => ({
	...(await importOriginal()),
	decryptPrivateKey: vi.fn(async (envelope, password) => {
		if (password !== 'right') {
			throw new Error('OperationError')
		}
		return 'PEM'
	}),
}))

describe('useKeyProofPromptStore', () => {
	beforeEach(() => {
		setActivePinia(createPinia())
		useSessionStore().encryptedPrivateKey = 'ENVELOPE'
	})

	it('keeps asking after a wrong password and resolves with the right one', async () => {
		const prompt = useKeyProofPromptStore()
		const asked = prompt.ask('why')
		expect(prompt.open).toBe(true)
		expect(prompt.reason).toBe('why')

		expect(await prompt.submit('wrong')).toBe(false)
		expect(prompt.open).toBe(true)
		expect(prompt.error).not.toBe('')

		expect(await prompt.submit('right')).toBe(true)
		await expect(asked).resolves.toBe('right')
		expect(prompt.open).toBe(false)
	})

	it('rejects the waiting caller when the user cancels', async () => {
		const prompt = useKeyProofPromptStore()
		const asked = prompt.ask('why')
		prompt.cancel()

		await expect(asked).rejects.toBeInstanceOf(KeyProofPromptCancelled)
		expect(prompt.open).toBe(false)
	})
})
