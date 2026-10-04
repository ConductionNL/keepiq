/**
 * @spec openspec/changes/clients-extension-gaps/specs/extension-pin-unlock/spec.md
 *
 * Unlock with a PIN on the REAL router and popup: set with the master
 * password, unlock until the browser closes, five tries, forgotten on log
 * out and when the master password changed.
 */
import { readFileSync } from 'node:fs'
import { resolve } from 'node:path'
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest'
import { areaOrMemory } from '../../browser-extension/src/background/generator-handlers.js'
import {
	buildPinUnlock,
	PIN_MAX_ATTEMPTS,
	pinProblem,
} from '../../browser-extension/src/lib/pin-unlock.js'
import {
	installChrome,
	installServer,
	makeVault,
	POPUP,
} from './fixtures/fakeBrowser.js'

const SLOW = 120000

describe('the PIN store', () => {
	it('refuses a PIN shorter than six characters', () => {
		expect(pinProblem('12345')).toBe('A PIN has at least 6 characters')
		expect(pinProblem('123456')).toBeNull()
	})

	it(
		'opens the key with the right PIN, counts wrong ones, and forgets the PIN after five',
		async () => {
			const session = areaOrMemory(null)
			const pins = buildPinUnlock(session)
			const key = crypto.getRandomValues(new Uint8Array(32))
			await pins.set('a', key, '246810')
			expect(await pins.open('a', '246810')).toEqual(key)
			for (let i = 1; i < PIN_MAX_ATTEMPTS; i++) {
				await expect(pins.open('a', '000000')).rejects.toThrow(
					`Wrong PIN. ${PIN_MAX_ATTEMPTS - i} ${PIN_MAX_ATTEMPTS - i === 1 ? 'try' : 'tries'} left.`,
				)
			}
			await expect(pins.open('a', '000000')).rejects.toThrow(
				'Too many wrong PINs. Unlock with your master password.',
			)
			expect(await pins.has('a')).toBe(false)
			// Nothing about the PIN or the key is stored in the clear.
			await pins.set('a', key, '246810')
			const stored = JSON.stringify(await session.get(null))
			expect(stored).not.toContain('246810')
		},
		SLOW,
	)
})

const SERVER = 'https://one.example'
let router
let browser
let state

/**
 * Send a message to the router as the popup.
 *
 * @param {string} type The type.
 * @param {object} [payload] The payload.
 * @return {Promise<object>}
 */
function send(type, payload = {}) {
	return router.handleMessage({ type, payload }, POPUP)
}

beforeEach(async () => {
	vi.resetModules()
	browser = installChrome()
	const fixture = await makeVault('m', 'x')
	state = { ...fixture, types: [{ id: 't1', name: 'login' }], folders: [] }
	installServer({ [SERVER]: state })
	router = await import('../../browser-extension/src/background/router.js')
	await send('pair', { url: SERVER, user: 'ann', appPassword: 'p' })
	await send('unlock', { masterPassword: 'm' })
})

afterEach(async () => {
	await new Promise((r) => setTimeout(r, 300))
	document.body.innerHTML = ''
})

describe('in the worker', () => {
	it(
		'sets a PIN only with the right master password, and unlocks with it after a lock',
		async () => {
			const wrong = await send('pin-set', {
				masterPassword: 'nope',
				pin: '246810',
			})
			expect(wrong.error).toBe('Invalid master password')
			expect(
				(await send('pin-set', { masterPassword: 'm', pin: '246810' })).ok,
			).toBe(true)
			expect((await send('get-state')).pinSet).toBe(true)
			// Session storage only: nothing in local storage.
			expect(
				[...browser.storage.keys()].some((k) => k.startsWith('pin:')),
			).toBe(false)
			expect(
				[...browser.session.keys()].some((k) => k.startsWith('pin:')),
			).toBe(true)
			await send('lock', {})
			const bad = await send('pin-unlock', { pin: '000000' })
			expect(bad.error).toBe('Wrong PIN. 4 tries left.')
			expect((await send('pin-unlock', { pin: '246810' })).ok).toBe(true)
			expect((await send('get-state')).unlocked).toBe(true)
		},
		SLOW,
	)

	it(
		'forgets the PIN when the master password changed, and on log out',
		async () => {
			await send('pin-set', { masterPassword: 'm', pin: '246810' })
			await send('lock', {})
			// The same key, now wrapped under another master password.
			state.suite = {
				...state.suite,
				privateKey: (await makeVault('m2', 'x')).suite.privateKey,
			}
			const res = await send('pin-unlock', { pin: '246810' })
			expect(res.error).toBe(
				'Your master password changed. Unlock with it, then set the PIN again.',
			)
			expect((await send('get-state')).pinSet).toBe(false)
			expect((await send('unlock', { masterPassword: 'm2' })).ok).toBe(true)
			await send('pin-set', { masterPassword: 'm2', pin: '246810' })
			await send('logout', {})
			expect(
				[...browser.session.keys()].some((k) => k.startsWith('pin:')),
			).toBe(false)
		},
		SLOW,
	)
})

describe('in the popup', () => {
	it(
		'sets the PIN in Settings and unlocks with it on the lock screen',
		async () => {
			const html = readFileSync(
				resolve(__dirname, '../../browser-extension/src/popup/popup.html'),
				'utf8',
			)
			document.body.innerHTML = html
				.replace(/^[\s\S]*<body>/, '')
				.replace(/<\/body>[\s\S]*$/, '')
			globalThis.chrome.runtime.sendMessage = (msg, cb) => {
				const pending = router.handleMessage(msg, POPUP)
				if (pending) pending.then(cb)
			}
			vi.resetModules()
			await import('../../browser-extension/src/popup/popup.js')
			const $ = (id) => document.getElementById(id)
			await vi.waitFor(() => expect($('view-unlocked').hidden).toBe(false))
			$('tab-settings').click()
			await vi.waitFor(() => expect($('view-settings').hidden).toBe(false))
			expect($('pin-remove').hidden).toBe(true)
			$('pin-master').value = 'm'
			$('pin-new').value = '246810'
			$('pin-set').click()
			await vi.waitFor(() => expect($('pin-remove').hidden).toBe(false), {
				timeout: 30000,
			})
			$('settings-lock-all').click()
			await vi.waitFor(() => expect($('view-locked').hidden).toBe(false))
			expect($('pin-block').hidden).toBe(false)
			expect(document.activeElement).toBe($('unlock-pin'))
			$('unlock-pin').value = '246810'
			$('unlock-pin').dispatchEvent(
				new KeyboardEvent('keydown', { key: 'Enter' }),
			)
			await vi.waitFor(() => expect($('view-unlocked').hidden).toBe(false), {
				timeout: 30000,
			})
		},
		SLOW,
	)
})
