/**
 * @spec openspec/changes/clients-extension-finish/specs/extension-generator-policy/spec.md
 *
 * The generator under a policy, and every chosen kind of character present,
 * in the shared generator and the REAL popup.
 */
import { readFileSync } from 'node:fs'
import { resolve } from 'node:path'
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest'
import { generateKey } from '../../src/generator/generator.js'
import { sanitizeOptions } from '../../browser-extension/src/lib/generator-state.js'
import {
	installChrome,
	installServer,
	makeVault,
	POPUP,
} from './fixtures/fakeBrowser.js'

const POLICY = {
	policy_enabled: true,
	generator_min_length: 14,
	generator_require_digit: true,
	generator_require_symbol: true,
}

describe('the generator', () => {
	it('puts every chosen kind of character in, even with no minimum', () => {
		for (let i = 0; i < 300; i++) {
			const value = generateKey({
				length: 8,
				includeUppercase: true,
				includeLowercase: true,
				includeDigits: true,
				includeSpecialCharacters: true,
				minDigits: 0,
				minSpecial: 0,
			})
			expect(value).toMatch(/[A-Z]/)
			expect(value).toMatch(/[a-z]/)
			expect(value).toMatch(/\d/)
			expect(value).toMatch(/[^A-Za-z0-9]/)
		}
	})

	it('leaves out a kind that is not chosen', () => {
		const value = generateKey({
			length: 20,
			includeUppercase: false,
			includeLowercase: true,
			includeDigits: true,
			includeSpecialCharacters: false,
		})
		expect(value).toMatch(/^[a-z0-9]+$/)
	})

	it('starts the minimum of a required kind at one', () => {
		const options = sanitizeOptions(
			{ password: { minDigits: 0, minSpecial: 0 } },
			POLICY,
		)
		expect(options.password).toMatchObject({
			minDigits: 1,
			minSpecial: 1,
			includeDigits: true,
			includeSpecialCharacters: true,
		})
		expect(options.password.length).toBeGreaterThanOrEqual(14)
	})
})

const SERVER = 'https://one.example'
let router

beforeEach(async () => {
	vi.resetModules()
	installChrome()
	const fixture = await makeVault('m', 'x')
	installServer({
		[SERVER]: {
			...fixture,
			types: [{ id: 't1', name: 'login' }],
			folders: [],
			policy: POLICY,
		},
	})
	router = await import('../../browser-extension/src/background/router.js')
	await router.handleMessage(
		{ type: 'pair', payload: { url: SERVER, user: 'ann', appPassword: 'p' } },
		POPUP,
	)
	await router.handleMessage(
		{ type: 'unlock', payload: { masterPassword: 'm' } },
		POPUP,
	)
})

afterEach(async () => {
	await new Promise((r) => setTimeout(r, 300))
	document.body.innerHTML = ''
})

describe('in the popup', () => {
	it('locks the controls the policy decides and says why', async () => {
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
		$('tab-generator').click()
		await vi.waitFor(() => expect($('gen-symbols').disabled).toBe(true))
		expect($('gen-digits').disabled).toBe(true)
		expect($('gen-upper').disabled).toBe(false)
		expect($('gen-symbols').closest('label').textContent).toContain(
			'required by your organisation',
		)
		expect($('gen-min-special').min).toBe('1')
		expect($('gen-length').min).toBe('14')
		expect(Number($('gen-min-special').value)).toBeGreaterThanOrEqual(1)
	})
})
