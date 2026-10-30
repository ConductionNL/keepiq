import { beforeEach, describe, expect, it, vi } from 'vitest'
import { fakeBrowser } from 'wxt/testing/fake-browser'
import type { OpenSecretRow } from '@/src/api/types'
import { addAccount } from '@/src/accounts/store'
import { pemToPkcs8, rsaDecrypt, toBase64 } from '@/src/crypto'
import { lastUsedKey } from '@/src/last-used'
import type { BackgroundToContent } from '@/src/messages'
import { blockedRow, encrypt, folderRow, secretRow, typeRows } from '@/src/testing/vault'
import { envelope, suiteRow } from '@/src/testing/vectors'
import { putKey } from '@/src/vault/key-store'
import { writeSnapshot } from '@/src/vault/store'
import type { StoredSecretRow } from '@/src/vault/types'
import { lock } from '@/src/vault/unlock'
import { fill, handleFillRequest, matchingLogins, preferred } from './fill'
import { forgetTab, recordFrame } from './frames'

vi.mock('@/src/crypto', async (importOriginal) => {
	const actual = await importOriginal<typeof import('@/src/crypto')>()
	return { ...actual, rsaDecrypt: vi.fn(actual.rsaDecrypt) }
})

const TAB = 7
let accountId: string
let tabUrl: string
/** What each frame's content script answers, by frame id. */
let answers: Record<number, unknown>
let sent: Array<{ message: BackgroundToContent; frameId: number | undefined }>

async function vault(secrets: StoredSecretRow[]) {
	const syncedAt = new Date().toISOString()
	await writeSnapshot(accountId, {
		suite: { id: suiteRow.id, unlockKeyEpoch: 1 }, secrets, folders: [folderRow()], types: typeRows,
		syncedAt, listsSyncedAt: syncedAt, newestUpdatedAt: null, total: secrets.length,
	})
}

async function login(overrides: Partial<OpenSecretRow> = {}): Promise<OpenSecretRow> {
	return secretRow({ id: 'i1', name: 'Example', url: 'https://example.test/', login: await encrypt('alice'), key: await encrypt('hunter2'), ...overrides })
}

const executed = () => sent.filter(({ message }) => message.kind === 'fill.execute')

beforeEach(async () => {
	fakeBrowser.reset()
	forgetTab(TAB)
	vi.mocked(rsaDecrypt).mockClear()
	accountId = (await addAccount({
		serverUrl: 'https://cloud.example.org', uid: 'alice', loginName: 'alice', displayName: 'Alice', email: null, avatarDataUrl: null, appPassword: 'pw', keepiqVersion: '0.3.12',
	}, { ...suiteRow })).id
	await putKey(accountId, toBase64(pemToPkcs8(envelope.privateKeyPem)))
	tabUrl = 'https://example.test/login'
	answers = { 0: { filled: 'both' } }
	sent = []
	vi.spyOn(browser.tabs, 'get').mockImplementation(async (id: number) => (id === TAB ? { id: TAB, url: tabUrl } : undefined) as never)
	vi.spyOn(browser.tabs, 'query').mockImplementation(async () => [{ id: TAB, url: tabUrl, active: true }] as never)
	vi.spyOn(browser.tabs, 'sendMessage').mockImplementation(async (_tabId: number, message: unknown, options?: { frameId?: number }) => {
		// A copy, as the browser delivers it, so later changes to the sender's object do not show here.
		sent.push({ message: structuredClone(message) as BackgroundToContent, frameId: options?.frameId })
		return answers[options?.frameId ?? 0] as never
	})
})

describe('fill', () => {
	it('sends one credential to the top frame, records last used and drops nothing on disk', async () => {
		await vault([await login()])
		expect(await fill({ itemId: 'i1', tabId: TAB, ask: 'reply' })).toEqual({ filled: 'both' })
		expect(sent).toEqual([{ message: { kind: 'fill.execute', username: 'alice', password: 'hunter2', origin: 'https://example.test' }, frameId: 0 }])
		expect(Object.keys((await browser.storage.local.get(lastUsedKey(accountId)))[lastUsedKey(accountId)] as object)).toEqual(['i1'])
		const stored = JSON.stringify([await browser.storage.local.get(null), await browser.storage.session.get(null)])
		expect(stored).not.toContain('hunter2')
	})

	it('fills a login form in a same-site iframe', async () => {
		await vault([await login()])
		recordFrame(TAB, 0, 'https://example.test/')
		recordFrame(TAB, 4, 'https://login.example.test/frame')
		answers = { 0: { filled: 'none' }, 4: { filled: 'both' } }
		expect(await fill({ itemId: 'i1', tabId: TAB, ask: 'reply' })).toEqual({ filled: 'both' })
		expect(executed().map(({ message, frameId }) => [frameId, (message as { origin: string }).origin])).toEqual([[0, 'https://example.test'], [4, 'https://login.example.test']])
	})

	it('sends a cross-origin iframe that does not match nothing', async () => {
		await vault([await login()])
		recordFrame(TAB, 5, 'https://ads.other.test/')
		answers = { 0: { filled: 'none' }, 5: { filled: 'both' } }
		expect(await fill({ itemId: 'i1', tabId: TAB, ask: 'reply' })).toEqual({ filled: 'none' })
		expect(sent.map(({ frameId }) => frameId)).toEqual([0])
	})

	it('without a record checks the tab itself and sends nothing to a tab that no longer matches', async () => {
		await vault([await login()])
		tabUrl = 'https://other.test/'
		recordFrame(TAB, 0, 'https://example.test/')
		expect(await fill({ itemId: 'i1', tabId: TAB, ask: 'reply' })).toEqual({ filled: 'none' })
		expect(sent).toEqual([])
		expect(rsaDecrypt).not.toHaveBeenCalled()
	})

	it('combines a username in one frame and a password in another', async () => {
		await vault([await login()])
		recordFrame(TAB, 4, 'https://login.example.test/')
		answers = { 0: { filled: 'username' }, 4: { filled: 'password' } }
		expect(await fill({ itemId: 'i1', tabId: TAB, ask: 'reply' })).toEqual({ filled: 'both' })
	})

	it('answers none for a page without login fields and records nothing', async () => {
		await vault([await login()])
		answers = { 0: { filled: 'none' } }
		expect(await fill({ itemId: 'i1', tabId: TAB, ask: 'reply' })).toEqual({ filled: 'none' })
		expect(await browser.storage.local.get(lastUsedKey(accountId))).toEqual({})
	})

	it('treats a frame without a content script, or a malformed reply, as nothing filled', async () => {
		await vault([await login()])
		recordFrame(TAB, 4, 'https://login.example.test/')
		answers = { 0: { filled: 'everything' } }
		vi.mocked(browser.tabs.sendMessage).mockImplementationOnce(async () => answers[0] as never).mockRejectedValueOnce(new Error('Receiving end does not exist.'))
		expect(await fill({ itemId: 'i1', tabId: TAB, ask: 'reply' })).toEqual({ filled: 'none' })
	})

	it('refuses while locked, without decrypting', async () => {
		await vault([await login()])
		await lock(accountId)
		expect(await fill({ itemId: 'i1', tabId: TAB, ask: 'reply' })).toEqual({ filled: 'none' })
		expect(sent).toEqual([])
		expect(rsaDecrypt).not.toHaveBeenCalled()
	})

	it('refuses blocked, unknown and non-login items', async () => {
		await vault([blockedRow({ id: 'b', url: 'https://example.test/' }), await login({ id: 'note', typeId: 't-note' })])
		for (const itemId of ['b', 'note', 'missing']) expect(await fill({ itemId, tabId: TAB, ask: 'reply' })).toEqual({ filled: 'none' })
		expect(sent).toEqual([])
	})

	it('fills a use-only copy, which exists to be filled', async () => {
		await vault([await login({ useOnly: true })])
		expect(await fill({ itemId: 'i1', tabId: TAB, ask: 'reply' })).toEqual({ filled: 'both' })
	})

	it('sends a null username for an item without a login', async () => {
		await vault([await login({ login: null })])
		await fill({ itemId: 'i1', tabId: TAB, ask: 'reply' })
		expect(executed()[0]!.message).toMatchObject({ username: null, password: 'hunter2' })
	})

	it('fills only the inline list’s frame, with its key', async () => {
		await vault([await login()])
		recordFrame(TAB, 4, 'https://login.example.test/')
		answers = { 4: { filled: 'both' } }
		await fill({ itemId: 'i1', tabId: TAB, ask: 'reply', only: { frameId: 4, url: 'https://login.example.test/', anchorKey: 'k1' } })
		expect(executed()).toEqual([{ message: expect.objectContaining({ anchorKey: 'k1', origin: 'https://login.example.test' }), frameId: 4 }])
	})

	it('fills the inline list’s frame by the session’s url when the worker restarted and forgot the frames', async () => {
		await vault([await login()])
		answers = { 4: { filled: 'both' } }
		expect(await fill({ itemId: 'i1', tabId: TAB, ask: 'reply', only: { frameId: 4, url: 'https://login.example.test/', anchorKey: 'k1' } })).toEqual({ filled: 'both' })
		expect(executed().map(({ frameId }) => frameId)).toEqual([4])
	})

	it('sends nothing to the inline list’s frame when the item no longer matches it', async () => {
		await vault([await login()])
		expect(await fill({ itemId: 'i1', tabId: TAB, ask: 'reply', only: { frameId: 4, url: 'https://other.test/', anchorKey: 'k1' } })).toEqual({ filled: 'none' })
		expect(executed()).toEqual([])
	})

	describe('on an insecure page', () => {
		beforeEach(() => {
			tabUrl = 'http://example.test/login'
		})

		it('asks the popup to confirm, decrypting and sending nothing', async () => {
			await vault([await login()])
			expect(await fill({ itemId: 'i1', tabId: TAB, ask: 'reply' })).toEqual({ filled: 'none', needsInsecureConfirm: true })
			expect(sent).toEqual([])
			expect(rsaDecrypt).not.toHaveBeenCalled()
		})

		it('fills once the popup confirmed', async () => {
			await vault([await login()])
			expect(await fill({ itemId: 'i1', tabId: TAB, ask: 'reply', confirmedInsecure: true })).toEqual({ filled: 'both' })
		})

		it('cancels when the user declines in the page', async () => {
			await vault([await login()])
			answers = { 0: false }
			expect(await fill({ itemId: 'i1', tabId: TAB, ask: 'page' })).toEqual({ filled: 'none' })
			expect(sent).toEqual([{ message: { kind: 'fill.confirmInsecure', itemHost: 'example.test' }, frameId: 0 }])
			expect(rsaDecrypt).not.toHaveBeenCalled()
		})

		it('fills when the user accepts in the page', async () => {
			await vault([await login()])
			vi.mocked(browser.tabs.sendMessage).mockResolvedValueOnce(true as never)
			expect(await fill({ itemId: 'i1', tabId: TAB, ask: 'page' })).toEqual({ filled: 'both' })
			expect(sent.map(({ message }) => message.kind)).toEqual(['fill.execute'])
		})

		it('asks for an item saved as a bare host, which means https', async () => {
			await vault([await login({ url: 'example.test' })])
			expect(await fill({ itemId: 'i1', tabId: TAB, ask: 'reply' })).toEqual({ filled: 'none', needsInsecureConfirm: true })
			answers = { 0: false }
			expect(await fill({ itemId: 'i1', tabId: TAB, ask: 'page' })).toEqual({ filled: 'none' })
			expect(sent).toEqual([{ message: { kind: 'fill.confirmInsecure', itemHost: 'example.test' }, frameId: 0 }])
		})

		it('cancels when no content script answers the question', async () => {
			await vault([await login()])
			vi.mocked(browser.tabs.sendMessage).mockRejectedValueOnce(new Error('Receiving end does not exist.'))
			expect(await fill({ itemId: 'i1', tabId: TAB, ask: 'page' })).toEqual({ filled: 'none' })
			expect(executed()).toEqual([])
			expect(rsaDecrypt).not.toHaveBeenCalled()
		})

		it('asks for an http subframe under an https top frame', async () => {
			tabUrl = 'https://example.test/'
			recordFrame(TAB, 4, 'http://login.example.test/')
			await vault([await login()])
			expect(await fill({ itemId: 'i1', tabId: TAB, ask: 'reply' })).toEqual({ filled: 'none', needsInsecureConfirm: true })
			expect(sent).toEqual([])
		})

		it('does not ask for an item saved on http', async () => {
			await vault([await login({ url: 'http://example.test/' })])
			expect(await fill({ itemId: 'i1', tabId: TAB, ask: 'reply' })).toEqual({ filled: 'both' })
		})
	})
})

describe('handleFillRequest', () => {
	it('fills the active tab when no tab is named, and counts as interaction', async () => {
		await vault([await login()])
		expect(await handleFillRequest({ kind: 'fill.request', itemId: 'i1' })).toEqual({ filled: 'both' })
		// `touch()` stores the time as a string.
		expect((await browser.storage.session.get('lastInteractionAt')).lastInteractionAt).toEqual(expect.any(String))
	})

	it('ignores a request without an item id', async () => {
		expect(await handleFillRequest({ kind: 'fill.request', itemId: 7 } as never)).toEqual({ filled: 'none' })
		expect(sent).toEqual([])
	})
})

describe('matchingLogins and preferred', () => {
	it('lists matching logins last used first', async () => {
		await vault([await login({ id: 'a', name: 'A' }), await login({ id: 'b', name: 'B' }), await login({ id: 'c', url: 'https://other.test/' })])
		await browser.storage.local.set({ [lastUsedKey(accountId)]: { b: 5 } })
		const { rows, lastUsed } = await matchingLogins(accountId, tabUrl)
		expect(rows.map((row) => row.id)).toEqual(['b', 'a'])
		expect(preferred(rows, lastUsed)?.id).toBe('b')
	})

	it('prefers the only match, and none of several unused', () => {
		expect(preferred([{ id: 'a' }], {})?.id).toBe('a')
		expect(preferred([{ id: 'a' }, { id: 'b' }], {})).toBeNull()
		expect(preferred([], {})).toBeNull()
	})
})
