import { beforeEach, describe, expect, it, vi } from 'vitest'
import { fakeBrowser } from 'wxt/testing/fake-browser'
import { defaultOptions, type GeneratorPolicy } from './options'
import { clearPolicy, generatorOptionsKey, generatorPolicyKey, readOptions, readPolicy, saveOptions, writePolicy } from './store'

const policy: GeneratorPolicy = { minLength: 16, requireUpper: true, requireLower: true, requireDigit: false, requireSymbol: false, allowPassphrase: false }

beforeEach(() => {
	fakeBrowser.reset()
})

describe('options', () => {
	it('reads the defaults until something is saved', async () => {
		expect(await readOptions('a')).toEqual(defaultOptions())
	})

	it('saves per account what sanitize leaves, and returns it', async () => {
		const saved = await saveOptions('a', { ...defaultOptions(), tab: 'passphrase', password: { ...defaultOptions().password, length: 500, minDigits: 9 } })
		expect(saved).toMatchObject({ tab: 'passphrase', password: { length: 128, minDigits: 9 } })
		expect(await readOptions('a')).toEqual(saved)
		expect(await readOptions('b')).toEqual(defaultOptions())
		expect((await browser.storage.local.get(generatorOptionsKey('a')))[generatorOptionsKey('a')]).toEqual(saved)
	})

	it('fills a partial save from what was stored', async () => {
		await saveOptions('a', { password: { ...defaultOptions().password, length: 30 } })
		expect((await saveOptions('a', { tab: 'username' })).password.length).toBe(30)
	})

	it('stores the last of several saves sent at once', async () => {
		const set = browser.storage.local.set.bind(browser.storage.local)
		// The first save's write is the slowest, as when a slider sends a save per step.
		let delayed = false
		vi.spyOn(browser.storage.local, 'set').mockImplementation(async (values) => {
			if (!delayed) {
				delayed = true
				await new Promise((resolve) => setTimeout(resolve, 10))
			}
			return set(values)
		})
		const lengths = [20, 21, 22, 23]
		const saved = await Promise.all(lengths.map((length) => saveOptions('a', { password: { ...defaultOptions().password, length } })))
		expect(saved.map((options) => options.password.length)).toEqual(lengths)
		expect((await readOptions('a')).password.length).toBe(23)
	})

	it('reads a damaged record as valid options', async () => {
		await browser.storage.local.set({ [generatorOptionsKey('a')]: { password: { length: 'x', includeUppercase: false, includeLowercase: false, includeDigits: false } } })
		expect(await readOptions('a')).toEqual(defaultOptions())
	})
})

describe('policy cache', () => {
	it('is empty, then holds what was written', async () => {
		expect(await readPolicy('a')).toBeNull()
		await writePolicy('a', policy)
		expect(await readPolicy('a')).toEqual(policy)
		expect(await readPolicy('b')).toBeNull()
	})

	it('removes the record when no policy applies', async () => {
		await writePolicy('a', policy)
		await writePolicy('a', null)
		expect(await browser.storage.local.get(generatorPolicyKey('a'))).toEqual({})
	})

	it('clears', async () => {
		await writePolicy('a', policy)
		await clearPolicy('a')
		expect(await readPolicy('a')).toBeNull()
	})

	it.each([
		['a floor above 128', { ...policy, minLength: 200 }],
		['a floor below 8', { ...policy, minLength: 4 }],
		['a fractional floor', { ...policy, minLength: 12.5 }],
		['no floor', { ...policy, minLength: Number.NaN }],
		['a flag that is not a boolean', { ...policy, requireUpper: 'yes' }],
		['a missing flag', { minLength: 16, requireUpper: true, requireLower: true, requireDigit: false, requireSymbol: false }],
		['an array', []],
	])('reads a damaged record with %s as no policy', async (_name, stored) => {
		await browser.storage.local.set({ [generatorPolicyKey('a')]: stored })
		expect(await readPolicy('a')).toBeNull()
	})
})
