import type { Browser } from 'wxt/browser'
import type { DefaultManagerState } from '@/src/messages'

const PRIVACY: Browser.permissions.Permissions = { permissions: ['privacy'] }
const MANAGED_ELSEWHERE = new Set(['not_controllable', 'controlled_by_other_extensions'])
const OURS = 'controlled_by_this_extension'

type Setting = Browser.types.ChromeSetting<boolean>

/** The run in flight, which a read waits for so it never shows the settings half taken. */
let enabling: Promise<DefaultManagerState> | undefined

/** Chrome's password saving and its address autofill, which also offers typed values; `null` on a browser without both. */
function settings(): Setting[] | null {
	// Absent until the permission is granted, and on Firefox.
	const services = (browser as Partial<typeof browser>).privacy?.services
	return services?.passwordSavingEnabled && services.autofillAddressEnabled ? [services.passwordSavingEnabled, services.autofillAddressEnabled] : null
}

async function stateOf(pair: Setting[]): Promise<DefaultManagerState> {
	const read = await Promise.all(pair.map((setting) => setting.get({})))
	if (read.some(({ levelOfControl }) => MANAGED_ELSEWHERE.has(levelOfControl))) {
		return read.some(({ levelOfControl }) => levelOfControl === OURS) ? 'managedOn' : 'managed'
	}
	return read.every(({ value, levelOfControl }) => value === false && levelOfControl === OURS) ? 'on' : 'off'
}

/** The settings can only be read once the permission is held, so before that the toggle is off and enabled. */
export async function readDefaultManager(): Promise<DefaultManagerState> {
	if (enabling) return enabling
	if (!(await browser.permissions.contains(PRIVACY))) return 'off'
	const pair = settings()
	return pair ? stateOf(pair) : 'unsupported'
}

/** Sets nothing unless the extension can take both settings; the grant's listener and the popup share one run. */
export function enableDefaultManager(): Promise<DefaultManagerState> {
	enabling ??= takeControl().finally(() => {
		enabling = undefined
	})
	return enabling
}

/** Chrome closes the popup when it shows the permission prompt, so the grant itself turns it on. */
export function registerDefaultManager(): void {
	if (import.meta.env.BROWSER === 'firefox') return
	browser.permissions.onAdded.addListener(({ permissions }) => {
		if (permissions?.includes('privacy')) void enableDefaultManager().catch((error: unknown) => console.error('[keepiq] default manager', error))
	})
}

async function takeControl(): Promise<DefaultManagerState> {
	if (!(await browser.permissions.contains(PRIVACY))) return 'off'
	const pair = settings()
	if (!pair) {
		await browser.permissions.remove(PRIVACY)
		return 'unsupported'
	}
	const state = await stateOf(pair)
	if (state !== 'off') return state
	await Promise.all(pair.map((setting) => setting.set({ value: false })))
	return stateOf(pair)
}

/** Hands what this extension controls back to the user's own choices, then the permission, even when a clear fails. */
export async function disableDefaultManager(): Promise<DefaultManagerState> {
	const pair = await browser.permissions.contains(PRIVACY) ? settings() : null
	if (pair) {
		await Promise.allSettled(pair.map(async (setting) => {
			if ((await setting.get({})).levelOfControl === OURS) await setting.clear({})
		}))
	}
	await browser.permissions.remove(PRIVACY)
	return readDefaultManager()
}
