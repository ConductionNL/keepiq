import { readdirSync, readFileSync } from 'node:fs'
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest'
import { fakeBrowser } from 'wxt/testing/fake-browser'
import background from '@/entrypoints/background'
import { dropAllCaptures } from '@/src/autofill/capture-queue'
import { addAccount, listAccounts } from '@/src/accounts/store'
import { pemToPkcs8, toBase64 } from '@/src/crypto'
import { inlineHeight, POPUP_PORT, POPUP_REQUEST_KINDS, type Result } from '@/src/messages'
import { installMenuFakes } from '@/src/testing/menus'
import { encrypt, secretRow, typeRows } from '@/src/testing/vault'
import { envelope, suiteRow } from '@/src/testing/vectors'
import { hasKey, putKey } from '@/src/vault/key-store'
import { writeSnapshot } from '@/src/vault/store'
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
const pageSender = { id: 'test-extension-id', url: 'https://evil.example/login', tab: { id: 7 }, frameId: 0 }
/** The extension pages framed in a tab: the save bar and the inline list. */
const barSender = { id: 'test-extension-id', url: 'chrome-extension://test-extension-id/notification.html', tab: { id: 7 }, frameId: 3 }
const listSender = { id: 'test-extension-id', url: 'chrome-extension://test-extension-id/inline-list.html', tab: { id: 7 }, frameId: 4 }

/** What the browser does: `true` keeps the channel open for `sendResponse`, anything else means no reply. */
async function deliver(message: unknown, sender: object): Promise<unknown> {
	let reply: (value: unknown) => void = () => {}
	const replied = new Promise((resolve) => (reply = resolve))
	const returned = messageListener(message, sender, reply)
	return returned === true ? replied : undefined
}

let onConnect: ReturnType<typeof fakeEvent<(port: unknown) => void>>
let onPermissionsAdded: ReturnType<typeof fakeEvent<(added: { permissions?: string[] }) => void>>
let menus: ReturnType<typeof installMenuFakes>
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
	onPermissionsAdded = fakeEvent()
	Object.assign(browser.permissions, { onAdded: onPermissionsAdded })
	menus = installMenuFakes()
	const addListener = vi.spyOn(browser.runtime.onMessage, 'addListener')
	background.main()
	messageListener = addListener.mock.calls[0]![0] as never
})

/** The kinds the popup's code sends through `request`, read from its source. */
function popupRequestKinds(): string[] {
	const root = new URL('../../entrypoints/popup/', import.meta.url)
	const files = readdirSync(root, { recursive: true, encoding: 'utf8' }).filter((file) => /\.tsx?$/.test(file) && !/\.test\.tsx?$/.test(file))
	const kinds = files.flatMap((file) => [...readFileSync(new URL(file, root), 'utf8').matchAll(/\brequest\(\{ kind: '([^']+)'/g)].map((match) => match[1]!))
	return [...new Set(kinds)]
}

async function storedAccount() {
	return addAccount({
		serverUrl: 'https://cloud.example.org', uid: 'alice', loginName: 'alice', displayName: 'Alice', email: null, avatarDataUrl: null, appPassword: 'pw', keepiqVersion: '0.3.12',
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

	it('routes every request kind the popup sends to the request handler', async () => {
		const kinds = popupRequestKinds()
		// So a change in how the popup calls `request` cannot leave this checking nothing.
		expect(kinds).toEqual(expect.arrayContaining(['vault.snapshot', 'item.fetch', 'item.update']))
		// A popout that opened would leave the next test expecting its window.
		vi.spyOn(browser.windows, 'create').mockRejectedValue(new Error('no window'))
		// The fake browser has no permissions; without `privacy` the default manager only reads `off`.
		vi.spyOn(browser.permissions, 'contains').mockImplementation(async () => false)
		vi.spyOn(browser.permissions, 'remove').mockImplementation(async () => true)
		for (const kind of kinds) {
			expect(POPUP_REQUEST_KINDS.has(kind), kind).toBe(true)
			// What the account router answers a kind it does not know.
			expect(await deliver({ kind, accountId: 'nobody', id: 'i1' }, popupSender), kind).not.toEqual({ ok: false, code: 'unknown' })
		}
	})

	it('turns the default password manager on from the grant, with the popup gone before it resolved', async () => {
		const setting = () => {
			let ours = false
			return {
				get: async () => (ours ? { value: false, levelOfControl: 'controlled_by_this_extension' } : { value: true, levelOfControl: 'controllable_by_this_extension' }),
				set: vi.fn(async () => void (ours = true)),
			}
		}
		const services = { passwordSavingEnabled: setting(), autofillAddressEnabled: setting() }
		Object.assign(browser, { privacy: { services } })
		vi.spyOn(browser.permissions, 'contains').mockImplementation(async () => true)
		onPermissionsAdded.trigger({ permissions: ['privacy'] })
		// The reopened popup only reads.
		expect(await deliver({ kind: 'defaultManager.get', id: 'i1' }, popupSender)).toBe('on')
		expect(services.passwordSavingEnabled.set).toHaveBeenCalledExactlyOnceWith({ value: false })
		expect(services.autofillAddressEnabled.set).toHaveBeenCalledExactlyOnceWith({ value: false })
	})

	it('ignores account messages from a content script', async () => {
		expect(await deliver({ kind: 'accounts.removeAll' }, pageSender)).toBeUndefined()
		expect(await deliver({ kind: 'vault.status' }, { id: 'other-extension', url: 'chrome-extension://other-extension/x.html' })).toBeUndefined()
		const account = await storedAccount()
		await deliver({ kind: 'accounts.remove', accountId: account.id }, pageSender)
		expect(await browser.storage.local.get('accounts')).toHaveProperty(`accounts.${account.id}`)
	})

	// Which page may send which kind is `acceptedFrom` in sender.test.ts; these check the listener applies it.
	describe('routing by sender page', () => {
		it.each([
			['the inline list', listSender],
			['the save bar', barSender],
			['another extension page', { id: 'test-extension-id', url: 'chrome-extension://test-extension-id/offscreen.html' }],
		])('ignores popup kinds from %s', async (_, sender) => {
			// The same messages the popup gets an answer to.
			expect(await deliver({ kind: 'vault.status' }, popupSender)).toBeDefined()
			expect(await deliver({ kind: 'vault.status' }, sender)).toBeUndefined()
			expect(await deliver({ kind: 'vault.snapshot' }, sender)).toBeUndefined()
			const account = await storedAccount()
			await deliver({ kind: 'accounts.remove', accountId: account.id }, sender)
			expect(await browser.storage.local.get('accounts')).toHaveProperty(`accounts.${account.id}`)
		})

		it('answers the popup in a pop-out window', async () => {
			const popout = { ...popupSender, url: `${popupSender.url}?popout=1&tabId=7`, tab: { id: 9 }, frameId: 0 }
			expect(await deliver({ kind: 'vault.snapshot' }, popout)).toEqual({ state: 'logged_out' })
		})

		it.each([
			{ kind: 'inline.state', key: 'k' },
			{ kind: 'capture.save', folderId: null },
			{ kind: 'inline.open' },
		])('does not hand $kind from the popup to the account router', async (message) => {
			// The router answers a kind it does not know with `{ ok: false, code: 'unknown' }`.
			expect(await deliver(message, popupSender)).toBeUndefined()
		})

		it.each([null, undefined, 'vault.status', 42, {}, { kind: 7 }, { kind: 'no.such.kind' }])('ignores %j without throwing', async (message) => {
			for (const sender of [popupSender, pageSender, listSender, barSender]) {
				expect(await deliver(message, sender)).toBeUndefined()
			}
		})
	})

	it('treats page_ready as fire-and-forget', async () => {
		expect(await deliver({ kind: 'page_ready', url: 'https://example.org/', passwordField: false }, pageSender)).toBeUndefined()
	})

	describe('fill', () => {
		const tab = { id: 7, url: 'https://example.test/login', active: true }
		const frameSender = (frameId: number, url: string) => ({ id: 'test-extension-id', url, tab: { id: 7 }, frameId })
		let toTab: Array<{ message: { kind: string }; frameId?: number }>

		beforeEach(async () => {
			const account = await storedAccount()
			await putKey(account.id, toBase64(pemToPkcs8(envelope.privateKeyPem)))
			const syncedAt = new Date().toISOString()
			const secrets = [secretRow({ id: 'i1', url: 'https://example.test/', login: await encrypt('alice'), key: await encrypt('hunter2') })]
			await writeSnapshot(account.id, {
				suite: { id: suiteRow.id, unlockKeyEpoch: 1 }, secrets, folders: [], types: typeRows, syncedAt, listsSyncedAt: syncedAt, newestUpdatedAt: null, total: 1,
			})
			toTab = []
			vi.spyOn(browser.tabs, 'get').mockResolvedValue(tab as never)
			vi.spyOn(browser.tabs, 'query').mockResolvedValue([tab] as never)
			vi.spyOn(browser.tabs, 'sendMessage').mockImplementation(async (_tabId: number, message: unknown, options?: { frameId?: number }) => {
				toTab.push({ message: message as { kind: string }, frameId: options?.frameId })
				return { filled: 'both' } as never
			})
			// Frames are remembered per worker; start each test from a fresh page.
			await fakeBrowser.tabs.onUpdated.trigger(7, { status: 'loading' }, tab as never)
		})

		it('answers fill.request from the popup, reaching the frames page_ready recorded', async () => {
			await deliver({ kind: 'page_ready', url: 'ignored', passwordField: false }, frameSender(3, 'https://login.example.test/'))
			await deliver({ kind: 'page_ready', url: 'ignored', passwordField: false }, frameSender(4, 'https://ads.other.test/'))
			expect(await deliver({ kind: 'fill.request', itemId: 'i1', tabId: 7 }, popupSender)).toEqual({ filled: 'both' })
			expect(toTab.map(({ frameId }) => frameId)).toEqual([0, 3])
		})

		it('forgets the frames when the tab starts loading a new page', async () => {
			await deliver({ kind: 'page_ready', url: 'ignored', passwordField: false }, frameSender(3, 'https://login.example.test/'))
			await fakeBrowser.tabs.onUpdated.trigger(7, { status: 'loading' }, tab as never)
			await deliver({ kind: 'fill.request', itemId: 'i1' }, popupSender)
			expect(toTab.map(({ frameId }) => frameId)).toEqual([0])
		})

		it('ignores a forged fill.request from page context, decrypting nothing', async () => {
			expect(await deliver({ kind: 'fill.request', itemId: 'i1', tabId: 7 }, pageSender)).toBeUndefined()
			expect(await deliver({ kind: 'fill.request', itemId: 'i1', tabId: 7 }, listSender)).toBeUndefined()
			await new Promise((resolve) => setTimeout(resolve, 10))
			expect(toTab).toEqual([])
		})

		it('fills on page load from the top frame’s page_ready when the setting is on', async () => {
			const top = frameSender(0, tab.url)
			await deliver({ kind: 'page_ready', url: tab.url, passwordField: true }, top)
			await new Promise((resolve) => setTimeout(resolve, 10))
			expect(toTab).toEqual([])
			const [account] = await listAccounts()
			await browser.storage.local.set({ [`settings.${account!.id}`]: { autofillOnPageLoad: true } })
			await deliver({ kind: 'page_ready', url: tab.url, passwordField: false }, top)
			await deliver({ kind: 'page_ready', url: tab.url, passwordField: true }, frameSender(3, 'https://login.example.test/'))
			await new Promise((resolve) => setTimeout(resolve, 10))
			expect(toTab).toEqual([])
			await deliver({ kind: 'page_ready', url: tab.url, passwordField: true }, top)
			// The matching subframe that announced itself is filled with it.
			await vi.waitFor(() => expect(toTab.map(({ message, frameId }) => [message.kind, frameId])).toEqual([['fill.execute', 0], ['fill.execute', 3]]))
		})

		it('does not fill again when a page comes back from the back/forward cache', async () => {
			const [account] = await listAccounts()
			await browser.storage.local.set({ [`settings.${account!.id}`]: { autofillOnPageLoad: true } })
			const top = frameSender(0, tab.url)
			await deliver({ kind: 'page_ready', url: tab.url, passwordField: true }, top)
			await vi.waitFor(() => expect(toTab).toHaveLength(1))
			// Going Back forgets the frames, then the restored page registers with what content.ts sends on `pageshow`.
			await fakeBrowser.tabs.onUpdated.trigger(7, { status: 'loading' }, tab as never)
			toTab = []
			await deliver({ kind: 'page_ready', url: tab.url, passwordField: false }, top)
			await new Promise((resolve) => setTimeout(resolve, 20))
			expect(toTab.filter(({ message }) => message.kind === 'fill.execute')).toEqual([])
		})

		it('rebuilds the context menu after the popup switches accounts or changes settings, not after a read', async () => {
			const [account] = await listAccounts()
			await deliver({ kind: 'vault.status' }, popupSender)
			await new Promise((resolve) => setTimeout(resolve, 10))
			expect(menus.entries).toEqual([])
			await deliver({ kind: 'accounts.switch', accountId: account!.id }, popupSender)
			await vi.waitFor(() => expect(menus.entries.map((e) => e.id)).toContain('autofill:i1'))
			await deliver({ kind: 'account.settings.set', accountId: account!.id, patch: { contextMenu: false } }, popupSender)
			await vi.waitFor(() => expect(menus.entries).toEqual([]))
		})

		it('fills from the autofill_login shortcut', async () => {
			menus.command('autofill_login', tab as never)
			await vi.waitFor(() => expect(toTab).toEqual([{ message: expect.objectContaining({ kind: 'fill.execute', username: 'alice' }), frameId: 0 }]))
		})

		describe('login capture', () => {
			const top = frameSender(0, tab.url)
			const posts = () => fetchMock.mock.calls.filter(([, init]) => init?.method && init.method !== 'GET')

			beforeEach(async () => {
				vi.mocked(browser.tabs.get).mockResolvedValue({ ...tab, status: 'complete' } as never)
				vi.mocked(browser.tabs.sendMessage).mockImplementation(async (_tabId: number, message: unknown, options?: { frameId?: number }) => {
					toTab.push({ message: message as { kind: string }, frameId: options?.frameId })
					return undefined as never
				})
				expect(await deliver({ kind: 'capture.submitted', username: 'bob', password: 'n3w-pass', changed: false }, top)).toBeUndefined()
				await vi.waitFor(() => expect(toTab).toEqual([{ message: { kind: 'capture.show', key: expect.stringMatching(/^[0-9a-f]{32}$/) }, frameId: 0 }]))
				key = (toTab[0]!.message as unknown as { key: string }).key
			})

			// The queue lives in module memory, which outlives each test's background.
			afterEach(() => dropAllCaptures())

			let key: string
			const input = () => ({ kind: 'pointer', at: Date.now(), x: 5, y: 5 })

			it('answers the bar page about the capture of its tab, with the key its host posted', async () => {
				expect(await deliver({ kind: 'capture.state' }, barSender)).toBeNull()
				expect(await deliver({ kind: 'capture.state', key }, barSender)).toMatchObject({ mode: 'add', host: 'example.test' })
				expect(await deliver({ kind: 'capture.dismiss', key, never: false }, barSender)).toBeNull()
				expect(await deliver({ kind: 'capture.state', key }, barSender)).toBeNull()
			})

			it('ignores a forged capture.save from page context, writing nothing', async () => {
				for (const sender of [top, pageSender, popupSender, listSender]) {
					expect(await deliver({ kind: 'capture.save', key, folderId: null, input: input() }, sender)).toBeUndefined()
					expect(await deliver({ kind: 'capture.update', key, input: input() }, sender)).toBeUndefined()
					expect(await deliver({ kind: 'capture.dismiss', key, never: true, input: input() }, sender)).toBeUndefined()
				}
				await new Promise((resolve) => setTimeout(resolve, 10))
				expect(posts()).toEqual([])
				expect(await deliver({ kind: 'capture.state', key }, barSender)).toMatchObject({ mode: 'add' })
				const [account] = await listAccounts()
				const stored = (await browser.storage.local.get(`settings.${account!.id}`))[`settings.${account!.id}`] as { excludedDomains?: string[] } | undefined
				expect(stored?.excludedDomains ?? []).toEqual([])
			})

			it('ignores a copy of the bar the page framed itself, which has no key', async () => {
				const framedCopy = { ...barSender, frameId: 8 }
				expect(await deliver({ kind: 'capture.state', key }, barSender)).not.toBeNull()
				expect(await deliver({ kind: 'capture.state', key }, framedCopy)).toBeNull()
				expect(await deliver({ kind: 'capture.dismiss', key, never: true, input: input() }, framedCopy)).toBeNull()
				expect(toTab.map(({ message }) => message.kind)).toEqual(['capture.show'])
			})

			it('drops every capture when the popup switches accounts', async () => {
				const [account] = await listAccounts()
				await deliver({ kind: 'accounts.switch', accountId: account!.id }, popupSender)
				expect(await deliver({ kind: 'capture.state', key }, barSender)).toBeNull()
			})

			it('shows the pending bar again, with a new key, when the top frame announces itself', async () => {
				await deliver({ kind: 'page_ready', url: tab.url, passwordField: false }, top)
				await vi.waitFor(() => expect(toTab).toHaveLength(2))
				expect(toTab[1]).toEqual({ message: { kind: 'capture.show', key: expect.not.stringMatching(key) }, frameId: 0 })
			})

			it('shows the bar again with a new key on the top frame\'s capture.reshow, as fire-and-forget', async () => {
				expect(await deliver({ kind: 'capture.reshow' }, top)).toBeUndefined()
				await vi.waitFor(() => expect(toTab).toHaveLength(2))
				expect(toTab[1]).toEqual({ message: { kind: 'capture.show', key: expect.not.stringMatching(key) }, frameId: 0 })
				expect(await deliver({ kind: 'capture.reshow' }, barSender)).toBeUndefined()
			})

			it('relays the focus_notification_bar command to the top frame', async () => {
				menus.command('focus_notification_bar', tab as never)
				await vi.waitFor(() => expect(toTab.at(-1)).toEqual({ message: { kind: 'capture.focus' }, frameId: 0 }))
			})
		})

		describe('inline list', () => {
			const top = frameSender(0, tab.url)

			beforeEach(() => {
				vi.mocked(browser.tabs.sendMessage).mockImplementation(async (_tabId: number, message: unknown, options?: { frameId?: number }) => {
					toTab.push({ message: message as { kind: string }, frameId: options?.frameId })
					return ((message as { kind: string }).kind === 'inline.verify' ? true : { filled: 'both' }) as never
				})
			})

			it('opens a session for a content script, answers the list page and fills on its pick', async () => {
				const opened = await deliver({ kind: 'inline.open' }, top) as { key: string }
				expect(opened).toEqual({ key: expect.any(String), height: inlineHeight(1) })
				const { key } = opened
				expect(await deliver({ kind: 'inline.state', key }, listSender)).toMatchObject({ state: 'unlocked', rows: [{ id: 'i1', username: 'alice' }] })
				expect(await deliver({ kind: 'inline.refocus', key }, listSender)).toBeUndefined()
				expect(await deliver({ kind: 'inline.resize', key, height: 130 }, listSender)).toBeUndefined()
				const input = { kind: 'pointer', at: 1, x: 5, y: 5 }
				expect(await deliver({ kind: 'inline.pick', key, itemId: 'i1', input }, listSender)).toEqual({ filled: 'both' })
				expect(toTab.map(({ message }) => message.kind)).toEqual(['inline.ready', 'inline.refocus', 'inline.resize', 'inline.verify', 'fill.execute', 'inline.close'])
				// A pick changes last used, which orders the context menu.
				await vi.waitFor(() => expect(menus.entries.map((e) => e.id)).toContain('autofill:i1'))
			})

			it('takes list kinds only from the list page, and inline.open only from a content script', async () => {
				const { key } = await deliver({ kind: 'inline.open' }, top) as { key: string }
				for (const sender of [top, popupSender, barSender]) {
					expect(await deliver({ kind: 'inline.state', key }, sender)).toBeUndefined()
					expect(await deliver({ kind: 'inline.pick', key, itemId: 'i1', input: { kind: 'key', at: 1, x: 1, y: 1 } }, sender)).toBeUndefined()
				}
				expect(await deliver({ kind: 'inline.open' }, listSender)).toBeUndefined()
				await new Promise((resolve) => setTimeout(resolve, 10))
				expect(toTab).toEqual([])
			})

			it('closes every list when the popup switches accounts', async () => {
				const [account] = await listAccounts()
				const { key } = await deliver({ kind: 'inline.open' }, top) as { key: string }
				await deliver({ kind: 'accounts.switch', accountId: account!.id }, popupSender)
				await vi.waitFor(() => expect(toTab).toEqual([{ message: { kind: 'inline.close', key, refocus: false }, frameId: 0 }]))
				expect(await deliver({ kind: 'inline.state', key }, listSender)).toBeNull()
			})

			it('drops the session on inline.closed', async () => {
				const { key } = await deliver({ kind: 'inline.open' }, top) as { key: string }
				expect(await deliver({ kind: 'inline.closed', key }, top)).toBeUndefined()
				await vi.waitFor(async () => expect(await deliver({ kind: 'inline.state', key }, listSender)).toBeNull())
			})
		})
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

	it('ignores writes from a content script', async () => {
		const account = await storedAccount()
		await deliver({ kind: 'vault.unlock', accountId: account.id, method: { type: 'masterPassword', masterPassword: envelope.password } }, popupSender)
		const draft = { typeId: 't', name: 'Planted', url: null, folderId: null, key: 'k', login: null, additionalFields: {} }
		expect(await deliver({ kind: 'item.create', accountId: account.id, draft }, pageSender)).toBeUndefined()
		expect(await deliver({ kind: 'folder.delete', accountId: account.id, id: 'f1', plan: { mode: 'cascade', cascade: 'delete' } }, pageSender)).toBeUndefined()
		// The unlock's own sync only reads.
		expect(fetchMock.mock.calls.filter(([, init]) => init?.method && init.method !== 'GET')).toEqual([])
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
