/**
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 *
 * Pins what opening a send shows: the payload and whether it burned, the
 * attempts left after a wrong password, the destroyed message after the
 * last one, a gone send, and the text of any other failure.
 *
 * The method runs on a plain context carrying the component's methods, so
 * the page's mounted() peek request stays out of it.
 *
 * @spec openspec/specs/ephemeral-send/spec.md#requirement-anonymous-recipient-access-with-no-account
 */

import { createPinia, setActivePinia } from 'pinia'
import { beforeEach, describe, expect, it, vi } from 'vitest'
import EphemeralSendAccess from '../../src/views/EphemeralSendAccess.vue'
import { useEphemeralSendStore } from '../../src/store/modules/ephemeralSend.js'

/**
 * Open the send with the store answering as given.
 *
 * @param {Function} answer The accessSend implementation.
 * @return {Promise<object>} The page state after opening.
 */
async function opened(answer) {
	const store = useEphemeralSendStore()
	store.accessSend = vi.fn(answer)
	const page = {
		token: 'tok',
		fragmentKey: 'KEY',
		password: 'pw',
		payload: null,
		burned: false,
		gone: false,
		busy: false,
		error: 'old',
		t: (app, text, vars) => (vars ? `${text} ${JSON.stringify(vars)}` : text),
		...EphemeralSendAccess.methods,
	}
	await page.onOpen()
	expect(store.accessSend).toHaveBeenCalledWith('tok', 'KEY', 'pw')
	const { payload, burned, gone, busy, error } = page
	return { payload, burned, gone, busy, error }
}

const wrong = (fields) => Object.assign(new Error('wrong-password'), fields)

beforeEach(() => {
	setActivePinia(createPinia())
})

describe('EphemeralSendAccess.onOpen', () => {
	it('shows the payload and whether reading it burned the send', async () => {
		expect(await opened(async () => ({ payload: 'P', burned: true }))).toEqual({
			payload: 'P',
			burned: true,
			gone: false,
			busy: false,
			error: null,
		})
	})

	it('names the attempts left after a wrong password', async () => {
		expect(await opened(async () => {
			throw wrong({ attemptsLeft: 2, burned: false })
		})).toMatchObject({
			gone: false,
			error: 'Wrong password — {count} attempt(s) left before the message is destroyed. {"count":2}',
		})
	})

	it('says the message is destroyed after the last wrong password', async () => {
		expect(await opened(async () => {
			throw wrong({ attemptsLeft: 0, burned: true })
		})).toMatchObject({
			gone: true,
			error: 'Too many wrong passwords — the message has been destroyed.',
		})
	})

	it('shows a send that is gone, and the text of any other failure', async () => {
		expect(await opened(async () => {
			throw { response: { status: 404 } }
		})).toMatchObject({ gone: true, error: null })
		expect((await opened(async () => {
			throw { response: { status: 500, data: { message: 'server says' } } }
		})).error).toBe('server says')
		expect((await opened(async () => {
			throw new Error('plain')
		})).error).toBe('plain')
		expect((await opened(async () => {
			throw {}
		})).error).toBe('Failed to open the message')
	})
})
