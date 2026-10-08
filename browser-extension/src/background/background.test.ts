import { beforeEach, describe, expect, it, vi } from 'vitest'
import { fakeBrowser } from 'wxt/testing/fake-browser'
import background from '@/entrypoints/background'
import { addAccount } from '@/src/accounts/store'
import { POPUP_PORT, type Result } from '@/src/messages'
import { envelope, suiteRow } from '@/src/testing/vectors'
import { hasKey } from '@/src/vault/key-store'
import { SYNC_ALARM } from '@/src/vault/sync'
import { TIMEOUT_ALARM } from '@/src/vault/timeout'

/** Minimal stand-in for events the fake browser lacks. */
function fakeEvent<T extends (...args: never[]) => unknown>() {
	const listeners: T[] = []
	return {
		addListener: (listener: T) => void listeners.push(listener),
		trigger: (...args: Parameters<T>) => listeners.map((listener) => listener(...args)),
	}
}

const popupSender = { id: 'test-extension-id', url: 'chrome-extension://test-extension-id/popup.html' }
const pageSender = { id: 'test-extension-id', url: 'https://evil.example/login', tab: { id: 7 } }

/** What the browser does: `true` keeps the channel open for `sendResponse`, anything else means no reply. */
async function deliver(message: unknown, sender: object): Promise<unknown> {
	let reply: (value: unknown) => void = () => {}
	const replied = new Promise((resolve) => (reply = resolve))
	const returned = messageListener(message, sender, reply)
	return returned === true ? replied : undefined
}

let onConnect: ReturnType<typeof fakeEvent<(port: unknown) => void>>
let messageListener: (message: unknown, sender: object, sendResponse: (reply: unknown) => void) => unknown

const fetchMock = vi.fn<typeof fetch>()

beforeEach(() => {
	fakeBrowser.reset()
	// Unlocking starts a sync; nothing here may reach a network.
	vi.stubGlobal('fetch', fetchMock)
	fetchMock.mockReset()
	fetchMock.mockResolvedValue(new Response(null, { status: 503 }))
	onConnect = fakeEvent()
	Object.assign(browser.runtime, { onConnect })
	Object.assign(browser, { idle: { onStateChanged: fakeEvent() } })
	const addListener = vi.spyOn(browser.runtime.onMessage, 'addListener')
	background.main()
	messageListener = addListener.mock.calls[0]![0] as never
})

async function storedAccount() {
	return addAccount({
		serverUrl: 'https://cloud.example.org', uid: 'alice', loginName: 'alice', displayName: 'Alice', email: null, avatarDataUrl: null, appPassword: 'pw',
	}, { ...suiteRow })
}

describe('background entrypoint', () => {
	it('answers a popup message through sendResponse', async () => {
		// Native Chrome only accepts a returned promise in recent versions; `true` plus `sendResponse` works everywhere.
		const sendResponse = vi.fn()
		expect(messageListener({ kind: 'vault.status' }, popupSender, sendResponse)).toBe(true)
		await vi.waitFor(() => expect(sendResponse).toHaveBeenCalledOnce())
		expect(sendResponse.mock.calls[0]![0]).toEqual({ ok: true, state: { screen: 'add_account', accounts: [], active: null, notice: null, canAddAccount: true, lastTab: 'vault' } })
	})

	it('answers every popup message kind with a Result', async () => {
		const account = await storedAccount()
		const messages = [
			{ kind: 'vault.status' },
			{ kind: 'accounts.list' },
			{ kind: 'vault.unlock', accountId: account.id, method: { type: 'masterPassword', masterPassword: envelope.password } },
			{ kind: 'vault.lock', accountId: account.id },
			{ kind: 'vault.lockAll' },
			{ kind: 'accounts.switch', accountId: account.id },
			{ kind: 'accounts.remove', accountId: account.id },
			{ kind: 'accounts.removeAll' },
		]
		for (const message of messages) {
			const reply = await deliver(message, popupSender) as Result
			expect(reply, message.kind).toHaveProperty('ok')
			expect(reply.ok ? typeof reply.state.screen : reply.code, message.kind).toBeTypeOf('string')
		}
	})

	it('ignores account messages from a content script', async () => {
		expect(await deliver({ kind: 'accounts.removeAll' }, pageSender)).toBeUndefined()
		expect(await deliver({ kind: 'vault.status' }, { id: 'other-extension', url: 'chrome-extension://other-extension/x.html' })).toBeUndefined()
		const account = await storedAccount()
		await deliver({ kind: 'accounts.remove', accountId: account.id }, pageSender)
		expect(await browser.storage.local.get('accounts')).toHaveProperty(`accounts.${account.id}`)
	})

	it('treats page_ready as fire-and-forget', async () => {
		expect(await deliver({ kind: 'page_ready', url: 'https://example.org/' }, pageSender)).toBeUndefined()
	})

	it('locks on the timeout alarm', async () => {
		const account = await storedAccount()
		await deliver({ kind: 'vault.unlock', accountId: account.id, method: { type: 'masterPassword', masterPassword: envelope.password } }, popupSender)
		await browser.storage.local.set({ [`settings.${account.id}`]: { vaultTimeout: 1, vaultTimeoutAction: 'lock' } })
		vi.spyOn(Date, 'now').mockReturnValue(Date.now() + 2 * 60_000)
		await fakeBrowser.alarms.onAlarm.trigger({ name: TIMEOUT_ALARM, scheduledTime: Date.now(), persistAcrossSessions: false })
		await vi.waitFor(async () => expect(await hasKey(account.id)).toBe(false))
	})

	it('locks Immediately accounts when the popup port closes', async () => {
		const account = await storedAccount()
		await deliver({ kind: 'vault.unlock', accountId: account.id, method: { type: 'masterPassword', masterPassword: envelope.password } }, popupSender)
		await browser.storage.local.set({ [`settings.${account.id}`]: { vaultTimeout: 'immediately', vaultTimeoutAction: 'lock' } })
		const onDisconnect = fakeEvent<() => void>()
		onConnect.trigger({ name: POPUP_PORT, onDisconnect })
		onDisconnect.trigger()
		await vi.waitFor(async () => expect(await hasKey(account.id)).toBe(false))
	})

	it('answers vault requests from the popup only', async () => {
		expect(await deliver({ kind: 'vault.snapshot' }, popupSender)).toEqual({ state: 'logged_out' })
		expect(await deliver({ kind: 'popup.lastTab.set', tab: 'send' }, popupSender)).toBeNull()
		expect(await deliver({ kind: 'vault.snapshot' }, pageSender)).toBeUndefined()
		expect(await deliver({ kind: 'item.decrypt', ids: ['x'], fields: ['key'] }, pageSender)).toBeUndefined()
	})

	it('creates the sync alarm on unlock and syncs the active account when it fires', async () => {
		const account = await storedAccount()
		await deliver({ kind: 'vault.unlock', accountId: account.id, method: { type: 'masterPassword', masterPassword: envelope.password } }, popupSender)
		expect(await browser.alarms.get(SYNC_ALARM)).toMatchObject({ periodInMinutes: 15 })
		await vi.waitFor(() => expect(fetchMock).toHaveBeenCalled())
		fetchMock.mockClear()
		await fakeBrowser.alarms.onAlarm.trigger({ name: SYNC_ALARM, scheduledTime: Date.now(), persistAcrossSessions: false })
		await vi.waitFor(() => expect(fetchMock).toHaveBeenCalled())
		await deliver({ kind: 'vault.lock', accountId: account.id }, popupSender)
		expect(await browser.alarms.get(SYNC_ALARM)).toBeUndefined()
	})
})
