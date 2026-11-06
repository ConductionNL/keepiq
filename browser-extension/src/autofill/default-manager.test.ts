import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest'
import type { Browser } from 'wxt/browser'
import { disableDefaultManager, enableDefaultManager, readDefaultManager, registerDefaultManager } from './default-manager'

type Level = 'not_controllable' | 'controlled_by_other_extensions' | 'controllable_by_this_extension' | 'controlled_by_this_extension'

/** A Chrome setting as the browser keeps it: the user's value, and this extension's while it controls it. */
function fakeSetting(level: Level = 'controllable_by_this_extension', userValue = true) {
	let ours: boolean | undefined
	return {
		get: vi.fn(async () => (level === 'controlled_by_this_extension' || ours !== undefined
			? { value: ours ?? false, levelOfControl: 'controlled_by_this_extension' as Level }
			: { value: userValue, levelOfControl: level })),
		set: vi.fn(async ({ value }: { value: boolean }) => {
			if (level === 'not_controllable' || level === 'controlled_by_other_extensions') throw new Error('Cannot control')
			ours = value
		}),
		clear: vi.fn(async () => {
			ours = undefined
			if (level === 'controlled_by_this_extension') level = 'controllable_by_this_extension'
		}),
	}
}

let held: boolean
let passwordSaving: ReturnType<typeof fakeSetting>
let addresses: ReturnType<typeof fakeSetting> | undefined

function install(services: { passwordSaving?: Level; addresses?: Level | null } = {}) {
	passwordSaving = fakeSetting(services.passwordSaving)
	addresses = services.addresses === null ? undefined : fakeSetting(services.addresses)
	Object.assign(browser, { privacy: { services: { passwordSavingEnabled: passwordSaving, ...(addresses && { autofillAddressEnabled: addresses }) } } })
}

beforeEach(() => {
	held = true
	install()
	vi.spyOn(browser.permissions, 'contains').mockImplementation(async ({ permissions }) => held && permissions?.join() === 'privacy')
	vi.spyOn(browser.permissions, 'remove').mockImplementation(async () => {
		held = false
		return true
	})
})

const neitherSet = () => {
	expect(passwordSaving.set).not.toHaveBeenCalled()
	if (addresses) expect(addresses.set).not.toHaveBeenCalled()
}

describe('readDefaultManager', () => {
	it('is off without the permission, which the settings cannot be read without', async () => {
		held = false
		expect(await readDefaultManager()).toBe('off')
		expect(passwordSaving.get).not.toHaveBeenCalled()
	})

	it('is off while the user\'s own choices apply', async () => {
		expect(await readDefaultManager()).toBe('off')
	})

	it('is on once both are off under this extension', async () => {
		install({ passwordSaving: 'controlled_by_this_extension', addresses: 'controlled_by_this_extension' })
		expect(await readDefaultManager()).toBe('on')
	})

	it('is off when only one is under this extension', async () => {
		install({ passwordSaving: 'controlled_by_this_extension' })
		expect(await readDefaultManager()).toBe('off')
	})

	it.each(['not_controllable', 'controlled_by_other_extensions'] as const)('is managed when password saving is %s', async (level) => {
		install({ passwordSaving: level })
		expect(await readDefaultManager()).toBe('managed')
	})

	it.each([
		['password saving', { passwordSaving: 'not_controllable', addresses: 'controlled_by_this_extension' }],
		['address autofill', { passwordSaving: 'controlled_by_this_extension', addresses: 'controlled_by_other_extensions' }],
	] as const)('is managedOn when %s is managed elsewhere while the other is still this extension\'s', async (_, services) => {
		install(services)
		expect(await readDefaultManager()).toBe('managedOn')
	})

	it.each(['not_controllable', 'controlled_by_other_extensions'] as const)('is managed when address autofill is %s', async (level) => {
		install({ addresses: level })
		expect(await readDefaultManager()).toBe('managed')
	})

	it('is unsupported on a browser without address autofill', async () => {
		install({ addresses: null })
		expect(await readDefaultManager()).toBe('unsupported')
	})
})

describe('enableDefaultManager', () => {
	it('turns both off under this extension', async () => {
		expect(await enableDefaultManager()).toBe('on')
		expect(passwordSaving.set).toHaveBeenCalledWith({ value: false })
		expect(addresses!.set).toHaveBeenCalledWith({ value: false })
		expect(await passwordSaving.get()).toEqual({ value: false, levelOfControl: 'controlled_by_this_extension' })
		expect(await addresses!.get()).toEqual({ value: false, levelOfControl: 'controlled_by_this_extension' })
	})

	it('sets nothing without the permission, as when the user declined it', async () => {
		held = false
		expect(await enableDefaultManager()).toBe('off')
		neitherSet()
	})

	it.each([
		['password saving', 'not_controllable', { passwordSaving: 'not_controllable' }],
		['password saving', 'controlled_by_other_extensions', { passwordSaving: 'controlled_by_other_extensions' }],
		['address autofill', 'not_controllable', { addresses: 'not_controllable' }],
		['address autofill', 'controlled_by_other_extensions', { addresses: 'controlled_by_other_extensions' }],
	] as const)('sets neither when %s is %s', async (_, __, services) => {
		install(services)
		expect(await enableDefaultManager()).toBe('managed')
		neitherSet()
		expect(held).toBe(true)
	})

	it('sets nothing when one is managed elsewhere while the other is still this extension\'s', async () => {
		install({ passwordSaving: 'controlled_by_this_extension', addresses: 'not_controllable' })
		expect(await enableDefaultManager()).toBe('managedOn')
		neitherSet()
		expect(held).toBe(true)
	})

	it('sets nothing and gives the permission back on a browser without address autofill', async () => {
		install({ addresses: null })
		expect(await enableDefaultManager()).toBe('unsupported')
		neitherSet()
		expect(held).toBe(false)
	})

	it('sets each once when the popup and the grant ask together, and a read waits for them', async () => {
		const runs = [enableDefaultManager(), enableDefaultManager(), readDefaultManager()]
		expect(await Promise.all(runs)).toEqual(['on', 'on', 'on'])
		expect(passwordSaving.set).toHaveBeenCalledTimes(1)
		expect(addresses!.set).toHaveBeenCalledTimes(1)
		expect(await enableDefaultManager()).toBe('on')
		expect(passwordSaving.set).toHaveBeenCalledTimes(1)
	})
})

describe('registerDefaultManager', () => {
	let listeners: Array<(added: Browser.permissions.Permissions) => void>
	const grant = (added: Browser.permissions.Permissions) => listeners.forEach((listener) => listener(added))

	beforeEach(() => {
		listeners = []
		Object.assign(browser.permissions, { onAdded: { addListener: (listener: (typeof listeners)[number]) => void listeners.push(listener) } })
	})

	afterEach(() => {
		vi.unstubAllEnvs()
	})

	it('turns it on from the grant alone, as Chrome closes the popup at the prompt', async () => {
		registerDefaultManager()
		grant({ permissions: ['privacy'], origins: [] })
		expect(await readDefaultManager()).toBe('on')
		expect(passwordSaving.set).toHaveBeenCalledWith({ value: false })
		expect(addresses!.set).toHaveBeenCalledWith({ value: false })
	})

	it('ignores the host access granted when an account is added', async () => {
		registerDefaultManager()
		grant({ permissions: [], origins: ['https://cloud.example/*'] })
		await new Promise((resolve) => setTimeout(resolve))
		expect(browser.permissions.contains).not.toHaveBeenCalled()
		neitherSet()
	})

	it('sets nothing when the settings are managed elsewhere', async () => {
		install({ passwordSaving: 'not_controllable' })
		registerDefaultManager()
		grant({ permissions: ['privacy'] })
		// Synchronous: the grant started a run, which the read then waits for.
		expect(browser.permissions.contains).toHaveBeenCalledTimes(1)
		expect(await readDefaultManager()).toBe('managed')
		neitherSet()
		expect(held).toBe(true)
	})

	it('listens for nothing on Firefox', () => {
		vi.stubEnv('BROWSER', 'firefox')
		registerDefaultManager()
		expect(listeners).toEqual([])
	})
})

describe('disableDefaultManager', () => {
	it('clears both, then gives the permission back', async () => {
		install({ passwordSaving: 'controlled_by_this_extension', addresses: 'controlled_by_this_extension' })
		const order: string[] = []
		passwordSaving.clear.mockImplementationOnce(async () => void order.push('clear'))
		addresses!.clear.mockImplementationOnce(async () => void order.push('clear'))
		vi.mocked(browser.permissions.remove).mockImplementationOnce(async () => {
			order.push('remove')
			held = false
			return true
		})
		expect(await disableDefaultManager()).toBe('off')
		expect(order).toEqual(['clear', 'clear', 'remove'])
		expect(held).toBe(false)
	})

	it('returns both to the user\'s choices', async () => {
		await enableDefaultManager()
		await disableDefaultManager()
		expect(await passwordSaving.get()).toEqual({ value: true, levelOfControl: 'controllable_by_this_extension' })
		expect(await addresses!.get()).toEqual({ value: true, levelOfControl: 'controllable_by_this_extension' })
	})

	it('gives back the one it still controls after a policy took the other', async () => {
		await enableDefaultManager()
		passwordSaving.get.mockResolvedValue({ value: false, levelOfControl: 'not_controllable' })
		expect(await readDefaultManager()).toBe('managedOn')
		expect(await disableDefaultManager()).toBe('off')
		expect(passwordSaving.clear).not.toHaveBeenCalled()
		expect(await addresses!.get()).toEqual({ value: true, levelOfControl: 'controllable_by_this_extension' })
		expect(held).toBe(false)
	})

	it('clears nothing it does not control', async () => {
		install({ passwordSaving: 'controlled_by_other_extensions', addresses: 'not_controllable' })
		expect(await disableDefaultManager()).toBe('off')
		expect(passwordSaving.clear).not.toHaveBeenCalled()
		expect(addresses!.clear).not.toHaveBeenCalled()
		expect(held).toBe(false)
	})

	it('still clears the other and gives the permission back when one cannot be cleared', async () => {
		await enableDefaultManager()
		passwordSaving.clear.mockRejectedValueOnce(new Error('Cannot clear'))
		expect(await disableDefaultManager()).toBe('off')
		expect(addresses!.clear).toHaveBeenCalled()
		expect(await addresses!.get()).toEqual({ value: true, levelOfControl: 'controllable_by_this_extension' })
		expect(held).toBe(false)
	})

	it('only removes the permission when it is not held', async () => {
		held = false
		expect(await disableDefaultManager()).toBe('off')
		expect(passwordSaving.clear).not.toHaveBeenCalled()
		expect(browser.permissions.remove).toHaveBeenCalledWith({ permissions: ['privacy'] })
	})
})
