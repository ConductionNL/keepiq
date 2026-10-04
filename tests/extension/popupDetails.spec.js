/**
 * @spec openspec/specs/extension-list-and-settings/spec.md#requirement-a-list-that-says-what-it-shows
 * @spec openspec/specs/extension-send/spec.md#requirement-create-a-send-from-the-popup
 * @spec openspec/specs/extension-generator/spec.md#requirement-password-options
 *
 * Three details of the REAL popup, with popup.css applied, that the
 * screenshots in docs/browser-extension/using.md showed wrong: Copy and
 * Open ran together ("CopyOpen"), the Hours field of a custom expiry showed
 * next to a 1 hour expiry, and a special-character minimum showed while
 * special characters were off. jsdom applies the stylesheet's cascade, so
 * these read what a user sees, not only the hidden attribute.
 */
import { readFileSync } from 'node:fs'
import { resolve } from 'node:path'
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest'
import {
	installChrome,
	installServer,
	makeVault,
	POPUP,
} from './fixtures/fakeBrowser.js'

const SERVER = 'https://one.example'
let router

/**
 * Element by id.
 *
 * @param {string} id The id.
 * @return {HTMLElement}
 */
const $ = (id) => document.getElementById(id)

/**
 * Whether the user sees an element: the stylesheet decides, not the attribute.
 *
 * @param {HTMLElement} el The element.
 * @return {boolean}
 */
const shown = (el) => getComputedStyle(el).display !== 'none'

/** Open the real popup with its stylesheet. */
async function openPopup() {
	const style = document.createElement('style')
	style.textContent = readFileSync(
		resolve(__dirname, '../../browser-extension/src/popup/popup.css'),
		'utf8',
	)
	document.head.appendChild(style)
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
	await vi.waitFor(() => expect($('view-unlocked').hidden).toBe(false))
}

beforeEach(async () => {
	vi.resetModules()
	installChrome()
	globalThis.chrome.tabs.create = vi.fn()
	const fixture = await makeVault('m', 'x')
	installServer({
		[SERVER]: {
			...fixture,
			types: [{ id: 't1', name: 'login' }],
			folders: [],
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
	document.head.innerHTML = ''
})

describe('the popup details', () => {
	it('keeps Copy and Open apart, each with room around it', async () => {
		await openPopup()
		$('tab-vault').click()
		await vi.waitFor(() =>
			expect($('vault-list').querySelectorAll('button.link')).toHaveLength(2),
		)
		for (const button of $('vault-list').querySelectorAll('button.link')) {
			const css = getComputedStyle(button)
			expect(parseFloat(css.paddingLeft)).toBeGreaterThanOrEqual(4)
			expect(parseFloat(css.paddingRight)).toBeGreaterThanOrEqual(4)
		}
	})

	it('shows Hours only for a custom expiry', async () => {
		await openPopup()
		$('tab-send').click()
		await vi.waitFor(() => expect($('send-expiry').options.length).toBeGreaterThan(0))
		const select = $('send-expiry')
		select.value = '1h'
		select.dispatchEvent(new Event('change'))
		expect(shown($('send-custom-label'))).toBe(false)
		select.value = 'custom'
		select.dispatchEvent(new Event('change'))
		expect(shown($('send-custom-label'))).toBe(true)
	})

	it('shows a minimum only while its kind of character is on', async () => {
		await openPopup()
		$('tab-generator').click()
		await vi.waitFor(() => expect($('gen-output').textContent.length).toBeGreaterThan(0))
		const special = $('gen-min-special').closest('label')
		// Special characters are off by default.
		expect($('gen-symbols').checked).toBe(false)
		expect(shown(special)).toBe(false)
		expect($('gen-output').dataset.value).toMatch(/^[A-Za-z0-9]+$/)

		$('gen-symbols').checked = true
		$('gen-symbols').dispatchEvent(new Event('change'))
		await vi.waitFor(() => expect(shown(special)).toBe(true))
		await vi.waitFor(() =>
			expect($('gen-output').dataset.value).toMatch(/[^A-Za-z0-9]/),
		)

		$('gen-digits').checked = false
		$('gen-digits').dispatchEvent(new Event('change'))
		await vi.waitFor(() =>
			expect(shown($('gen-min-digits').closest('label'))).toBe(false),
		)
		await vi.waitFor(() => expect($('gen-output').dataset.value).not.toMatch(/\d/))
	})
})
