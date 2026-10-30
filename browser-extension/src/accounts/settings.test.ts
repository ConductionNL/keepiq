import { beforeEach, describe, expect, it } from 'vitest'
import { fakeBrowser } from 'wxt/testing/fake-browser'
import { patchAccountPolicy } from './policy'
import { addExcludedDomain, AUTOFILL_FEATURES, DEFAULT_SETTINGS, effectiveSettings, readSettings, settingsKey, writeSettings, type AccountSettings } from './settings'

const defaults: AccountSettings = {
	vaultTimeout: 15,
	vaultTimeoutAction: 'lock',
	clearClipboardMs: 30_000,
	autofillOnPageLoad: false,
	defaultAutofill: 'onPageLoad',
	uriMatch: 'baseDomain',
	inlineSuggestions: true,
	contextMenu: true,
	askToAdd: true,
	askToUpdate: true,
	excludedDomains: [],
	defaultItemType: 'login',
}

async function stored() {
	return (await browser.storage.local.get(settingsKey('a')))[settingsKey('a')]
}

beforeEach(() => {
	fakeBrowser.reset()
})

describe('reading', () => {
	it('gives every default on a fresh profile', async () => {
		expect(DEFAULT_SETTINGS).toEqual(defaults)
		expect(await readSettings('a')).toEqual(defaults)
	})

	it.each<[keyof AccountSettings, unknown]>([
		['vaultTimeout', 'tomorrow'],
		['vaultTimeout', 0],
		['vaultTimeout', 2.5],
		['vaultTimeoutAction', 'explode'],
		['clearClipboardMs', 15_000],
		['clearClipboardMs', -1],
		['clearClipboardMs', 'soon'],
		['clearClipboardMs', Number.POSITIVE_INFINITY],
		['autofillOnPageLoad', 'yes'],
		['defaultAutofill', 'always'],
		['uriMatch', 'fuzzy'],
		['inlineSuggestions', 1],
		['contextMenu', null],
		['askToAdd', 'true'],
		['askToUpdate', 0],
		['excludedDomains', 'example.test'],
		['excludedDomains', ['example.test', 5]],
		['excludedDomains', ['example.test', '']],
		['excludedDomains', ['example.test', 'example.test']],
		['excludedDomains', ['login.example.co.uk']],
		['excludedDomains', ['Example.test']],
		['excludedDomains', ['https://example.test']],
		['defaultItemType', ''],
		['defaultItemType', 7],
	])('reads an invalid %s (%j) as its default', async (key, value) => {
		await browser.storage.local.set({ [settingsKey('a')]: { ...defaults, clearClipboardMs: 20_000, [key]: value } })
		const settings = await readSettings('a')
		expect(settings[key]).toEqual(key === 'clearClipboardMs' ? 30_000 : defaults[key])
		if (key !== 'clearClipboardMs') expect(settings.clearClipboardMs).toBe(20_000)
	})

	it.each([0, 10_000, 20_000, 30_000, 60_000, 120_000, 300_000])('accepts the clipboard delay %i', async (clearClipboardMs) => {
		await browser.storage.local.set({ [settingsKey('a')]: { clearClipboardMs } })
		expect((await readSettings('a')).clearClipboardMs).toBe(clearClipboardMs)
	})

	it('keeps valid values', async () => {
		const chosen: AccountSettings = {
			vaultTimeout: 'onSystemLock', vaultTimeoutAction: 'logout', clearClipboardMs: 0, autofillOnPageLoad: true, defaultAutofill: 'never',
			uriMatch: 'regex', inlineSuggestions: false, contextMenu: false, askToAdd: false, askToUpdate: false,
			excludedDomains: ['example.test', 'example.co.uk', 'user.github.io', '192.168.1.10', 'localhost'], defaultItemType: 'note',
		}
		await browser.storage.local.set({ [settingsKey('a')]: chosen })
		expect(await readSettings('a')).toEqual(chosen)
	})

	it('hands out a fresh default list each time', async () => {
		;(await readSettings('a')).excludedDomains.push('example.test')
		expect((await readSettings('a')).excludedDomains).toEqual([])
	})

	it('lists every autofill behaviour ext-autofill delivered', () => {
		expect(AUTOFILL_FEATURES).toEqual(['pageLoad', 'uriMatch', 'inlineSuggestions', 'contextMenu', 'savePrompts'])
	})
})

describe('effective settings under the organisation maximum', () => {
	async function effective(vaultTimeout: AccountSettings['vaultTimeout'], maxIdleMinutes?: number) {
		await writeSettings('a', { vaultTimeout, vaultTimeoutAction: 'logout' })
		if (maxIdleMinutes !== undefined) await patchAccountPolicy('a', { maxIdleMinutes })
		return effectiveSettings('a')
	}

	it('leaves every choice unclamped before the first read', async () => {
		for (const timeout of [240, 'never', 'onRestart', 'onSystemLock', 'immediately'] as const) {
			expect(await effective(timeout)).toMatchObject({ vaultTimeout: timeout, maxIdleMinutes: null })
		}
	})

	it('lowers a longer timeout to the maximum and keeps the stored choice', async () => {
		expect(await effective(240, 30)).toMatchObject({ vaultTimeout: 30, maxIdleMinutes: 30 })
		expect((await readSettings('a')).vaultTimeout).toBe(240)
		await patchAccountPolicy('a', { maxIdleMinutes: 240 })
		expect((await effectiveSettings('a')).vaultTimeout).toBe(240)
	})

	it('keeps a shorter timeout', async () => {
		expect((await effective(5, 30)).vaultTimeout).toBe(5)
	})

	it.each(['never', 'onRestart'] as const)('reads %s as the maximum', async (timeout) => {
		expect((await effective(timeout, 60)).vaultTimeout).toBe(60)
	})

	it.each(['immediately', 'onSystemLock'] as const)('keeps %s, which enforce() caps', async (timeout) => {
		expect((await effective(timeout, 60)).vaultTimeout).toBe(timeout)
	})

	it('forces no action', async () => {
		expect((await effective(240, 30)).vaultTimeoutAction).toBe('logout')
	})
})

describe('writing', () => {
	it('keeps every key a write does not name', async () => {
		await writeSettings('a', { clearClipboardMs: 20_000 })
		await writeSettings('a', { vaultTimeout: 60 })
		expect(await readSettings('a')).toMatchObject({ vaultTimeout: 60, clearClipboardMs: 20_000 })
	})

	it('ignores patch values that fail validation and keys it does not know', async () => {
		await writeSettings('a', { vaultTimeout: 5 })
		await writeSettings('a', { vaultTimeout: 'tomorrow', askToAdd: false, bogus: 1 } as never)
		expect(await stored()).toEqual({ vaultTimeout: 5, askToAdd: false })
	})

	it('loses neither of two writes made at once', async () => {
		await Promise.all([writeSettings('a', { askToAdd: false }), writeSettings('a', { askToUpdate: false })])
		expect(await readSettings('a')).toMatchObject({ askToAdd: false, askToUpdate: false })
	})
})

describe('addExcludedDomain', () => {
	it('appends a domain once, keeping the rest of the record', async () => {
		await writeSettings('a', { excludedDomains: ['github.com'], askToAdd: false })
		await addExcludedDomain('a', 'example.test')
		await addExcludedDomain('a', 'example.test')
		expect(await stored()).toEqual({ excludedDomains: ['github.com', 'example.test'], askToAdd: false })
	})

	it('loses neither a domain nor another write made at the same moment', async () => {
		await Promise.all([addExcludedDomain('a', 'example.test'), writeSettings('a', { excludedDomains: ['github.com'] }), addExcludedDomain('a', 'other.test')])
		expect((await readSettings('a')).excludedDomains).toEqual(['github.com', 'other.test'])
		await Promise.all([addExcludedDomain('a', 'one.test'), addExcludedDomain('a', 'two.test')])
		expect((await readSettings('a')).excludedDomains).toEqual(['github.com', 'other.test', 'one.test', 'two.test'])
	})

	it('adds nothing that is not a base domain', async () => {
		await addExcludedDomain('a', 'login.example.test')
		expect((await readSettings('a')).excludedDomains).toEqual([])
	})
})
