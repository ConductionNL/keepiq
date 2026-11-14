import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest'
import { fakeBrowser } from 'wxt/testing/fake-browser'
import type { OpenSecretRow } from '@/src/api/types'
import { writeSettings } from '@/src/accounts/settings'
import { addAccount } from '@/src/accounts/store'
import { writeFromBackground } from '@/src/clipboard'
import { pemToPkcs8, rsaDecrypt, toBase64 } from '@/src/crypto'
import { lastUsedKey } from '@/src/last-used'
import type { BackgroundToContent, FillExecuteForm, FormConfirmation } from '@/src/messages'
import { blockedRow, encrypt, folderRow, secretRow, typeRows } from '@/src/testing/vault'
import { envelope, suiteRow } from '@/src/testing/vectors'
import { putKey } from '@/src/vault/key-store'
import { writeSnapshot } from '@/src/vault/store'
import type { StoredSecretRow } from '@/src/vault/types'
import { lock } from '@/src/vault/unlock'
import { fill, handleFillRequest, matchingLogins, preferred } from './fill'
import { forgetTab, framesOf, recordFrame } from './frames'

vi.mock('@/src/clipboard', () => ({ writeFromBackground: vi.fn(async () => true) }))
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
	vi.mocked(writeFromBackground).mockClear()
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
		expect(await fill({ itemId: 'i1', tabId: TAB, ask: 'reply', code: true })).toEqual({ filled: 'both', code: false })
		expect(sent).toEqual([{ message: { kind: 'fill.execute', username: 'alice', password: 'hunter2', code: null, origin: 'https://example.test' }, frameId: 0 }])
		expect(Object.keys((await browser.storage.local.get(lastUsedKey(accountId)))[lastUsedKey(accountId)] as object)).toEqual(['i1'])
		const stored = JSON.stringify([await browser.storage.local.get(null), await browser.storage.session.get(null)])
		expect(stored).not.toContain('hunter2')
	})

	it('fills a login form in a same-site iframe', async () => {
		await vault([await login()])
		recordFrame(TAB, 0, 'https://example.test/')
		recordFrame(TAB, 4, 'https://login.example.test/frame')
		answers = { 0: { filled: 'none' }, 4: { filled: 'both' } }
		expect(await fill({ itemId: 'i1', tabId: TAB, ask: 'reply', code: true })).toEqual({ filled: 'both', code: false })
		expect(executed().map(({ message, frameId }) => [frameId, (message as { origin: string }).origin])).toEqual([[0, 'https://example.test'], [4, 'https://login.example.test']])
	})

	it('sends a cross-origin iframe that does not match nothing', async () => {
		await vault([await login()])
		recordFrame(TAB, 5, 'https://ads.other.test/')
		answers = { 0: { filled: 'none' }, 5: { filled: 'both' } }
		expect(await fill({ itemId: 'i1', tabId: TAB, ask: 'reply', code: true })).toEqual({ filled: 'none', code: false, noFields: true })
		expect(sent.map(({ frameId }) => frameId)).toEqual([0])
	})

	it('without a record checks the tab itself and sends nothing to a tab that no longer matches', async () => {
		await vault([await login()])
		tabUrl = 'https://other.test/'
		recordFrame(TAB, 0, 'https://example.test/')
		expect(await fill({ itemId: 'i1', tabId: TAB, ask: 'reply', code: true })).toEqual({ filled: 'none', code: false, noFields: true })
		expect(sent).toEqual([])
		expect(rsaDecrypt).not.toHaveBeenCalled()
	})

	it('combines a username in one frame and a password in another', async () => {
		await vault([await login()])
		recordFrame(TAB, 4, 'https://login.example.test/')
		answers = { 0: { filled: 'username' }, 4: { filled: 'password' } }
		expect(await fill({ itemId: 'i1', tabId: TAB, ask: 'reply', code: true })).toEqual({ filled: 'both', code: false })
	})

	it('answers no fields for a page without login fields and records nothing', async () => {
		await vault([await login()])
		answers = { 0: { filled: 'none' } }
		expect(await fill({ itemId: 'i1', tabId: TAB, ask: 'reply', code: true })).toEqual({ filled: 'none', code: false, noFields: true })
		expect(await browser.storage.local.get(lastUsedKey(accountId))).toEqual({})
	})

	it('treats a frame without a content script, or a malformed reply, as nothing filled', async () => {
		await vault([await login()])
		recordFrame(TAB, 4, 'https://login.example.test/')
		answers = { 0: { filled: 'everything' } }
		vi.mocked(browser.tabs.sendMessage).mockImplementationOnce(async () => answers[0] as never).mockRejectedValueOnce(new Error('Receiving end does not exist.'))
		expect(await fill({ itemId: 'i1', tabId: TAB, ask: 'reply', code: true })).toEqual({ filled: 'none', code: false })
	})

	it('refuses while locked, without decrypting', async () => {
		await vault([await login()])
		await lock(accountId)
		expect(await fill({ itemId: 'i1', tabId: TAB, ask: 'reply', code: true })).toEqual({ filled: 'none', code: false })
		expect(sent).toEqual([])
		expect(rsaDecrypt).not.toHaveBeenCalled()
	})

	it('answers plain none, not no fields, when the decrypt fails', async () => {
		await vault([await login()])
		vi.mocked(rsaDecrypt).mockRejectedValueOnce(new Error('bad padding'))
		vi.spyOn(console, 'error').mockImplementation(() => {})
		expect(await fill({ itemId: 'i1', tabId: TAB, ask: 'page', code: true })).toEqual({ filled: 'none', code: false })
		expect(sent).toEqual([])
	})

	it('refuses blocked, unknown and non-login items', async () => {
		await vault([blockedRow({ id: 'b', url: 'https://example.test/' }), await login({ id: 'note', typeId: 't-note' })])
		for (const itemId of ['b', 'missing']) expect(await fill({ itemId, tabId: TAB, ask: 'reply', code: true })).toEqual({ filled: 'none', code: false })
		// A note fits no frame, as a login would on another site.
		expect(await fill({ itemId: 'note', tabId: TAB, ask: 'reply', code: true })).toEqual({ filled: 'none', code: false, noFields: true })
		expect(sent).toEqual([])
	})

	it('fills a use-only copy, which exists to be filled', async () => {
		await vault([await login({ useOnly: true })])
		expect(await fill({ itemId: 'i1', tabId: TAB, ask: 'reply', code: true })).toEqual({ filled: 'both', code: false })
	})

	it('sends a null username for an item without a login', async () => {
		await vault([await login({ login: null })])
		await fill({ itemId: 'i1', tabId: TAB, ask: 'reply', code: true })
		expect(executed()[0]!.message).toMatchObject({ username: null, password: 'hunter2' })
	})

	it('fills only the inline list’s frame, with its key', async () => {
		await vault([await login()])
		recordFrame(TAB, 4, 'https://login.example.test/')
		answers = { 4: { filled: 'both' } }
		await fill({ itemId: 'i1', tabId: TAB, ask: 'reply', code: true, only: { frameId: 4, url: 'https://login.example.test/', anchorKey: 'k1' } })
		expect(executed()).toEqual([{ message: expect.objectContaining({ anchorKey: 'k1', origin: 'https://login.example.test' }), frameId: 4 }])
	})

	it('fills the inline list’s frame by the session’s url when the worker restarted and forgot the frames', async () => {
		await vault([await login()])
		answers = { 4: { filled: 'both' } }
		expect(await fill({ itemId: 'i1', tabId: TAB, ask: 'reply', code: true, only: { frameId: 4, url: 'https://login.example.test/', anchorKey: 'k1' } })).toEqual({ filled: 'both', code: false })
		expect(executed().map(({ frameId }) => frameId)).toEqual([4])
	})

	it('sends nothing to the inline list’s frame when the item no longer matches it', async () => {
		await vault([await login()])
		expect(await fill({ itemId: 'i1', tabId: TAB, ask: 'reply', code: true, only: { frameId: 4, url: 'https://other.test/', anchorKey: 'k1' } })).toEqual({ filled: 'none', code: false, noFields: true })
		expect(executed()).toEqual([])
	})

	describe('on an insecure page', () => {
		beforeEach(() => {
			tabUrl = 'http://example.test/login'
		})

		it('asks the popup to confirm, decrypting and sending nothing', async () => {
			await vault([await login()])
			expect(await fill({ itemId: 'i1', tabId: TAB, ask: 'reply', code: true })).toEqual({ filled: 'none', code: false, needsInsecureConfirm: true, savedSecure: true })
			expect(sent).toEqual([])
			expect(rsaDecrypt).not.toHaveBeenCalled()
		})

		it('fills once the popup confirmed', async () => {
			await vault([await login()])
			expect(await fill({ itemId: 'i1', tabId: TAB, ask: 'reply', code: true, confirmedInsecure: true })).toEqual({ filled: 'both', code: false })
		})

		it('cancels when the user declines in the page', async () => {
			await vault([await login()])
			answers = { 0: false }
			expect(await fill({ itemId: 'i1', tabId: TAB, ask: 'page', code: true })).toEqual({ filled: 'none', code: false })
			expect(sent).toEqual([{ message: { kind: 'fill.confirmInsecure', itemHost: 'example.test', savedSecure: true }, frameId: 0 }])
			expect(rsaDecrypt).not.toHaveBeenCalled()
		})

		it('fills when the user accepts in the page', async () => {
			await vault([await login()])
			vi.mocked(browser.tabs.sendMessage).mockResolvedValueOnce(true as never)
			expect(await fill({ itemId: 'i1', tabId: TAB, ask: 'page', code: true })).toEqual({ filled: 'both', code: false })
			expect(sent.map(({ message }) => message.kind)).toEqual(['fill.execute'])
		})

		it('asks for an item saved as a bare host, which means https', async () => {
			await vault([await login({ url: 'example.test' })])
			expect(await fill({ itemId: 'i1', tabId: TAB, ask: 'reply', code: true })).toEqual({ filled: 'none', code: false, needsInsecureConfirm: true, savedSecure: true })
			answers = { 0: false }
			expect(await fill({ itemId: 'i1', tabId: TAB, ask: 'page', code: true })).toEqual({ filled: 'none', code: false })
			expect(sent).toEqual([{ message: { kind: 'fill.confirmInsecure', itemHost: 'example.test', savedSecure: true }, frameId: 0 }])
		})

		it('cancels when no content script answers the question', async () => {
			await vault([await login()])
			vi.mocked(browser.tabs.sendMessage).mockRejectedValueOnce(new Error('Receiving end does not exist.'))
			expect(await fill({ itemId: 'i1', tabId: TAB, ask: 'page', code: true })).toEqual({ filled: 'none', code: false })
			expect(executed()).toEqual([])
			expect(rsaDecrypt).not.toHaveBeenCalled()
		})

		it('asks for an http subframe under an https top frame', async () => {
			tabUrl = 'https://example.test/'
			recordFrame(TAB, 4, 'http://login.example.test/')
			await vault([await login()])
			expect(await fill({ itemId: 'i1', tabId: TAB, ask: 'reply', code: true })).toEqual({ filled: 'none', code: false, needsInsecureConfirm: true, savedSecure: true })
			expect(sent).toEqual([])
		})

		// Spec `health-site-checks`: an http page asks first, whatever address the login was saved with.
		it('asks for an item saved on http, saying it was not saved on https', async () => {
			await vault([await login({ url: 'http://example.test/' })])
			expect(await fill({ itemId: 'i1', tabId: TAB, ask: 'reply', code: true })).toEqual({ filled: 'none', code: false, needsInsecureConfirm: true, savedSecure: false })
			expect(rsaDecrypt).not.toHaveBeenCalled()
			answers = { 0: false }
			expect(await fill({ itemId: 'i1', tabId: TAB, ask: 'page', code: true })).toEqual({ filled: 'none', code: false })
			expect(sent).toEqual([{ message: { kind: 'fill.confirmInsecure', itemHost: 'example.test', savedSecure: false }, frameId: 0 }])
			expect(executed()).toEqual([])
		})

		it('fills an item saved on http once the popup confirmed', async () => {
			await vault([await login({ url: 'http://example.test/' })])
			expect(await fill({ itemId: 'i1', tabId: TAB, ask: 'reply', code: true, confirmedInsecure: true })).toEqual({ filled: 'both', code: false })
		})

	})

	describe('on an https page', () => {
		it('does not ask for an item saved on http', async () => {
			tabUrl = 'https://example.test/login'
			await vault([await login({ url: 'http://example.test/' })])
			expect(await fill({ itemId: 'i1', tabId: TAB, ask: 'reply', code: true })).toEqual({ filled: 'both', code: false })
			expect(sent.map(({ message }) => message.kind)).toEqual(['fill.execute'])
		})
	})
})

describe('fill with a code', () => {
	/** RFC 6238's SHA-1 key; at 59 s its code is `287082`. */
	const SEED = 'GEZDGNBVGY3TQOJQGEZDGNBVGY3TQOJQ'
	const withCode = async (overrides: Partial<OpenSecretRow> = {}) => login({ totp: await encrypt(SEED), ...overrides })
	const codes = () => executed().map(({ message, frameId }) => [frameId, (message as { code: string | null }).code])

	beforeEach(() => {
		vi.useFakeTimers({ toFake: ['Date'] })
		vi.setSystemTime(59_000)
	})
	afterEach(() => {
		vi.useRealTimers()
	})

	it('hands the frame the login\'s own code and copies it after the fill, for the account', async () => {
		await vault([await withCode()])
		expect(await fill({ itemId: 'i1', tabId: TAB, ask: 'reply', code: true })).toEqual({ filled: 'both', code: false })
		expect(codes()).toEqual([[0, '287082']])
		expect(writeFromBackground).toHaveBeenCalledWith('287082', 30_000, { codeOf: accountId })
	})

	it('copies after a fill from the menu or shortcut too, with the account\'s delay', async () => {
		await writeSettings(accountId, { clearClipboardMs: 120_000 })
		await vault([await withCode()])
		await fill({ itemId: 'i1', tabId: TAB, ask: 'page', code: true })
		expect(writeFromBackground).toHaveBeenCalledWith('287082', 120_000, { codeOf: accountId })
	})

	it('copies after an inline pick, and hands the code to the list\'s frame only', async () => {
		await vault([await withCode()])
		answers = { 4: { filled: 'both' } }
		await fill({ itemId: 'i1', tabId: TAB, ask: 'reply', code: true, only: { frameId: 4, url: 'https://login.example.test/', anchorKey: 'k1' } })
		expect(codes()).toEqual([[4, '287082']])
		expect(writeFromBackground).toHaveBeenCalledOnce()
	})

	it('hands every frame that gets the login its code', async () => {
		await vault([await withCode()])
		recordFrame(TAB, 4, 'https://login.example.test/')
		answers = { 0: { filled: 'none' }, 4: { filled: 'both' } }
		await fill({ itemId: 'i1', tabId: TAB, ask: 'reply', code: true })
		expect(codes()).toEqual([[0, '287082'], [4, '287082']])
	})

	it('counts a fill of only the code as filling something: last used, the copy and the reply', async () => {
		await vault([await withCode()])
		answers = { 0: { filled: 'none', code: true } }
		expect(await fill({ itemId: 'i1', tabId: TAB, ask: 'reply', code: true })).toEqual({ filled: 'none', code: true })
		expect(Object.keys((await browser.storage.local.get(lastUsedKey(accountId)))[lastUsedKey(accountId)] as object)).toEqual(['i1'])
		expect(writeFromBackground).toHaveBeenCalledOnce()
	})

	it('hands over no code on page load and copies nothing', async () => {
		await vault([await withCode()])
		await fill({ itemId: 'i1', tabId: TAB, ask: 'page', code: false })
		expect(codes()).toEqual([[0, null]])
		expect(writeFromBackground).not.toHaveBeenCalled()
	})

	it('still fills the code but copies nothing with Copy TOTP automatically off', async () => {
		await writeSettings(accountId, { autoCopyTotp: false })
		await vault([await withCode()])
		await fill({ itemId: 'i1', tabId: TAB, ask: 'reply', code: true })
		expect(codes()).toEqual([[0, '287082']])
		expect(writeFromBackground).not.toHaveBeenCalled()
	})

	it('copies nothing when nothing was filled', async () => {
		await vault([await withCode()])
		answers = { 0: { filled: 'none', code: false } }
		expect(await fill({ itemId: 'i1', tabId: TAB, ask: 'reply', code: true })).toEqual({ filled: 'none', code: false, noFields: true })
		expect(writeFromBackground).not.toHaveBeenCalled()
	})

	it('hands over and copies nothing without a code, with an empty one or an invalid seed', async () => {
		for (const overrides of [{}, { totp: '' }, { totp: null }, { totp: await encrypt('not-a-seed') }]) {
			await vault([await login(overrides)])
			await fill({ itemId: 'i1', tabId: TAB, ask: 'reply', code: true })
		}
		expect(codes()).toEqual([[0, null], [0, null], [0, null], [0, null]])
		expect(writeFromBackground).not.toHaveBeenCalled()
	})

	it('never takes a code from a standalone authenticator item for the same site and username', async () => {
		const standalone = secretRow({ id: 't1', name: 'Example (TOTP)', url: 'https://example.test', typeId: 't-totp', login: await encrypt('alice'), key: await encrypt(SEED) })
		await vault([await login(), standalone])
		await fill({ itemId: 'i1', tabId: TAB, ask: 'reply', code: true })
		expect(codes()).toEqual([[0, null]])
		expect(await fill({ itemId: 't1', tabId: TAB, ask: 'reply', code: true })).toEqual({ filled: 'none', code: false, noFields: true })
		expect(writeFromBackground).not.toHaveBeenCalled()
	})

	it('writes neither the seed nor the code to any storage', async () => {
		await vault([await withCode()])
		await fill({ itemId: 'i1', tabId: TAB, ask: 'reply', code: true })
		const stored = JSON.stringify([await browser.storage.local.get(null), await browser.storage.session.get(null)])
		expect(stored).not.toContain(SEED)
		expect(stored).not.toContain('287082')
	})
})

describe('handleFillRequest', () => {
	it('fills the active tab when no tab is named, and counts as interaction', async () => {
		await vault([await login()])
		expect(await handleFillRequest({ kind: 'fill.request', itemId: 'i1' })).toEqual({ filled: 'both', code: false })
		// `touch()` stores the time as a string.
		expect((await browser.storage.session.get('lastInteractionAt')).lastInteractionAt).toEqual(expect.any(String))
	})

	it('ignores a request without an item id', async () => {
		expect(await handleFillRequest({ kind: 'fill.request', itemId: 7 } as never)).toEqual({ filled: 'none', code: false })
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

describe('card and identity fill', () => {
	const CARD = { cardholder: 'Anna de Vries', number: '4111 1111 1111 1111', expiry: '03/27', cvv: '123', pin: '9876' }
	const IDENTITY = { firstName: 'Anna', lastName: 'de Vries', email: 'anna@example.test', phone: '0612345678', address: 'Kerkstraat 1', bsn: '123456782' }
	const CARD_FIELDS = { roles: ['number', 'expMonth', 'expYear', 'securityCode'] }
	const CARD_MEMBERS = { number: '4111111111111111', expMonth: '03', expYear: '2027', cvv: '123' }
	/** Each frame's reply to `fill.probe`, by frame id. */
	let probes: Record<number, unknown>
	let confirmAnswer: boolean
	/** Runs while the frames are probed. */
	let duringProbe: () => Promise<void>

	const card = async () => secretRow({ id: 'c1', name: 'Personal', url: 'https://bank.test/', typeId: 't-card', key: await encrypt(JSON.stringify(CARD)) })
	const identity = async () => secretRow({ id: 'p1', name: 'Me', url: null, typeId: 't-identity', key: await encrypt(JSON.stringify(IDENTITY)) })
	const forms = () => sent.filter(({ message }) => message.kind === 'fill.executeForm').map(({ message, frameId }) => [frameId, (message as FillExecuteForm).values])
	const kinds = () => sent.map(({ message, frameId }) => `${message.kind}@${frameId}`)
	const fillCard = (overrides: Partial<Parameters<typeof fill>[0]> = {}) => fill({ itemId: 'c1', tabId: TAB, ask: 'reply', code: true, ...overrides })
	/** A probe reply carries the frame's own origin, unless the test gives another. */
	const originOf = (frameId: number) => new URL(frameId === 0 ? tabUrl : framesOf(TAB).find((frame) => frame.frameId === frameId)!.url).origin
	const probeReply = (frameId: number) => {
		const reply = probes[frameId]
		return reply instanceof Promise || !reply ? reply : { origin: originOf(frameId), ...reply }
	}
	const shop = (confirmed: Omit<FormConfirmation, 'origin'>): FormConfirmation => ({ ...confirmed, origin: 'https://shop.test' })

	beforeEach(async () => {
		tabUrl = 'https://shop.test/checkout'
		probes = { 0: { roles: ['cardholder'] } }
		confirmAnswer = true
		duringProbe = async () => {}
		await vault([await card(), await identity()])
		vi.mocked(browser.tabs.sendMessage).mockImplementation(async (_tabId: number, message: unknown, options?: { frameId?: number }) => {
			const frameId = options?.frameId ?? 0
			const m = message as BackgroundToContent
			sent.push({ message: structuredClone(m), frameId })
			if (m.kind === 'fill.probe') await duringProbe()
			if (m.kind === 'fill.probe') return probeReply(frameId) as never
			if (m.kind === 'fill.executeForm') return { filled: Object.keys(m.values).length } as never
			return (m.kind === 'fill.confirmForm' ? confirmAnswer : undefined) as never
		})
	})

	it('fills the name in the page and the card in a same-site frame without a question, decrypting once', async () => {
		recordFrame(TAB, 3, 'https://pay.shop.test/card')
		recordFrame(TAB, 4, 'https://shop.test/newsletter')
		probes = { 0: { roles: ['cardholder'] }, 3: CARD_FIELDS, 4: { roles: [] } }
		expect(await fillCard()).toEqual({ filled: 'form', code: false })
		expect(forms()).toEqual([[0, { cardholder: 'Anna de Vries' }], [3, CARD_MEMBERS]])
		expect(sent.find(({ frameId, message }) => frameId === 3 && message.kind === 'fill.executeForm')?.message).toMatchObject({ form: 'card', origin: 'https://pay.shop.test' })
		expect(kinds()).not.toContain('fill.confirmForm@0')
		expect(rsaDecrypt).toHaveBeenCalledTimes(1)
		expect(Object.keys((await browser.storage.local.get(lastUsedKey(accountId)))[lastUsedKey(accountId)] as object)).toEqual(['c1'])
	})

	it('probes only the top frame without a frame record', async () => {
		await fillCard()
		expect(kinds()).toEqual(['fill.probe@0', 'fill.executeForm@0'])
	})

	it('asks about a cross-site frame, naming its host, before decrypting anything', async () => {
		recordFrame(TAB, 3, 'https://js.stripe.test/v3')
		probes = { 0: { roles: ['cardholder'] }, 3: CARD_FIELDS }
		expect(await fillCard()).toEqual({ filled: 'none', code: false, needsFormConfirm: { insecure: false, host: 'shop.test', origin: 'https://shop.test', frameHosts: ['js.stripe.test'] } })
		expect(rsaDecrypt).not.toHaveBeenCalled()
		expect(forms()).toEqual([])
	})

	it('sends a cross-site frame nothing after "Only {host}", and its members after "Fill"', async () => {
		recordFrame(TAB, 3, 'https://js.stripe.test/v3')
		probes = { 0: { roles: ['cardholder'] }, 3: CARD_FIELDS }
		expect(await fillCard({ confirmed: shop({ frames: 'sameSite', frameHosts: ['js.stripe.test'] }) })).toEqual({ filled: 'form', code: false })
		expect(forms()).toEqual([[0, { cardholder: 'Anna de Vries' }]])
		sent = []
		expect(await fillCard({ confirmed: shop({ frames: 'all', frameHosts: ['js.stripe.test'] }) })).toEqual({ filled: 'form', code: false })
		expect(forms()).toEqual([[0, { cardholder: 'Anna de Vries' }], [3, CARD_MEMBERS]])
	})

	it('asks again when the answer was given for another page', async () => {
		recordFrame(TAB, 3, 'https://js.stripe.test/v3')
		probes = { 0: { roles: ['cardholder'] }, 3: CARD_FIELDS }
		const answer = { frames: 'all' as const, frameHosts: ['js.stripe.test'] }
		tabUrl = 'https://evil.test/checkout'
		expect((await fillCard({ confirmed: shop(answer) })).needsFormConfirm).toEqual({ insecure: false, host: 'evil.test', origin: 'https://evil.test', frameHosts: ['js.stripe.test'] })
		expect(rsaDecrypt).not.toHaveBeenCalled()
		expect(forms()).toEqual([])
	})

	it('asks again when the answer was given on the same host over another scheme or port', async () => {
		probes = { 0: { roles: ['cardholder'] } }
		tabUrl = 'http://shop.test/checkout'
		const answer = { insecure: true as const, frames: 'all' as const, frameHosts: [] }
		expect((await fillCard({ confirmed: shop(answer) })).needsFormConfirm).toEqual({ insecure: true, host: 'shop.test', origin: 'http://shop.test', frameHosts: [] })
		expect(await fillCard({ confirmed: { ...answer, origin: 'http://shop.test' } })).toEqual({ filled: 'form', code: false })
	})

	it('asks about no host whose frame left its recorded origin before the probe', async () => {
		recordFrame(TAB, 3, 'https://pay.shop.test/card')
		probes = { 0: { roles: ['cardholder'] }, 3: { ...CARD_FIELDS, origin: 'https://ads.test' } }
		expect(await fillCard()).toEqual({ filled: 'form', code: false })
		expect(forms()).toEqual([[0, { cardholder: 'Anna de Vries' }]])
		sent = []
		probes = { 0: { roles: ['cardholder'], origin: undefined } }
		expect(await fillCard()).toEqual({ filled: 'none', code: false, noFields: true })
		expect(kinds()).toEqual(['fill.probe@0', 'fill.probe@3'])
	})

	it('asks again, filling nothing, about a frame added after the answer', async () => {
		recordFrame(TAB, 3, 'https://js.stripe.test/v3')
		recordFrame(TAB, 5, 'https://ads.test/')
		probes = { 0: { roles: ['cardholder'] }, 3: CARD_FIELDS, 5: { roles: ['number'] } }
		const result = await fillCard({ confirmed: shop({ frames: 'all', frameHosts: ['js.stripe.test'] }) })
		expect(result.needsFormConfirm?.frameHosts).toContain('ads.test')
		expect(rsaDecrypt).not.toHaveBeenCalled()
		expect(forms()).toEqual([])
	})

	it('asks before a card reaches an insecure page, never before an identity', async () => {
		tabUrl = 'http://shop.test/checkout'
		expect((await fillCard()).needsFormConfirm).toEqual({ insecure: true, host: 'shop.test', origin: 'http://shop.test', frameHosts: [] })
		expect(rsaDecrypt).not.toHaveBeenCalled()
		expect(await fillCard({ confirmed: { insecure: true, frames: 'all', frameHosts: [], origin: 'http://shop.test' } })).toEqual({ filled: 'form', code: false })
		probes = { 0: { roles: ['email'] } }
		expect(await fillCard({ itemId: 'p1' })).toEqual({ filled: 'form', code: false })
		tabUrl = 'http://localhost:8100/card.html'
		probes = { 0: { roles: ['cardholder'] } }
		expect(await fillCard()).toEqual({ filled: 'form', code: false })
	})

	it('decrypts nothing when the vault locks between the probe and the fill', async () => {
		duringProbe = () => lock(accountId)
		expect(await fillCard()).toEqual({ filled: 'none', code: false })
		expect(rsaDecrypt).not.toHaveBeenCalled()
		expect(forms()).toEqual([])
	})

	it('decrypts and sends nothing to a page without fields of the kind, which says so', async () => {
		probes = { 0: { roles: [] } }
		expect(await fillCard()).toEqual({ filled: 'none', code: false, noFields: true })
		expect(rsaDecrypt).not.toHaveBeenCalled()
		expect(kinds()).toEqual(['fill.probe@0'])
	})

	it('probes nothing on a page that is not on the web', async () => {
		tabUrl = 'chrome://newtab/'
		expect(await fillCard()).toEqual({ filled: 'none', code: false })
		expect(sent).toEqual([])
	})

	it('ignores roles of another kind and a frame that does not answer the probe in time', async () => {
		vi.useFakeTimers({ toFake: ['setTimeout', 'clearTimeout'] })
		try {
			recordFrame(TAB, 3, 'https://pay.shop.test/')
			probes = { 0: { roles: ['cardholder', 'email', 'pin'] }, 3: new Promise(() => {}) }
			const result = fillCard()
			await vi.advanceTimersByTimeAsync(500)
			expect(await result).toEqual({ filled: 'form', code: false })
			expect(forms()).toEqual([[0, { cardholder: 'Anna de Vries' }]])
		} finally {
			vi.useRealTimers()
		}
	})

	it('never sends a content script the PIN, the BSN or another frame’s members', async () => {
		recordFrame(TAB, 3, 'https://pay.shop.test/')
		probes = { 0: { roles: ['cardholder', 'fullName', 'email', 'streetAddress'] }, 3: CARD_FIELDS }
		await fillCard()
		probes = { 0: { roles: ['fullName', 'email', 'streetAddress', 'phone'] }, 3: { roles: ['cardholder'] } }
		await fillCard({ itemId: 'p1' })
		const everything = JSON.stringify(sent)
		expect(everything).not.toContain(CARD.pin)
		expect(everything).not.toContain(IDENTITY.bsn)
		expect(JSON.stringify(sent.filter(({ frameId }) => frameId === 0))).not.toContain(CARD_MEMBERS.number)
		expect(JSON.stringify(sent.filter(({ message }) => message.kind === 'fill.probe'))).not.toContain('Anna')
		const stored = JSON.stringify([await browser.storage.local.get(null), await browser.storage.session.get(null)])
		expect(stored).not.toContain('Anna')
	})

	it('fills the inline list’s form in its frame and follows the plan in the others', async () => {
		recordFrame(TAB, 3, 'https://pay.shop.test/')
		probes = { 0: { roles: ['cardholder'] }, 3: CARD_FIELDS }
		await fillCard({ only: { frameId: 3, url: 'https://pay.shop.test/', anchorKey: 'k1' } })
		const withKey = sent.filter(({ message }) => (message as { anchorKey?: string }).anchorKey === 'k1').map(({ message, frameId }) => `${message.kind}@${frameId}`)
		expect(withKey).toEqual(['fill.probe@3', 'fill.executeForm@3'])
		expect(forms().map(([frameId]) => frameId)).toEqual([0, 3])
	})

	describe('from the context menu', () => {
		beforeEach(() => {
			recordFrame(TAB, 3, 'https://js.stripe.test/v3')
			probes = { 0: { roles: ['cardholder'] }, 3: CARD_FIELDS }
		})

		it('asks in the top frame and fills after OK, probing again first', async () => {
			expect(await fillCard({ ask: 'page' })).toEqual({ filled: 'form', code: false })
			expect(sent.find(({ message }) => message.kind === 'fill.confirmForm'))
				.toEqual({ message: { kind: 'fill.confirmForm', insecure: false, host: 'shop.test', origin: 'https://shop.test', frameHosts: ['js.stripe.test'] }, frameId: 0 })
			expect(kinds().filter((kind) => kind.startsWith('fill.probe'))).toHaveLength(4)
			expect(forms().map(([frameId]) => frameId)).toEqual([0, 3])
		})

		it('fills nothing after Cancel', async () => {
			confirmAnswer = false
			expect(await fillCard({ ask: 'page' })).toEqual({ filled: 'none', code: false })
			expect(rsaDecrypt).not.toHaveBeenCalled()
			expect(forms()).toEqual([])
		})
	})

	it('takes the popup’s confirmation through fill.request', async () => {
		recordFrame(TAB, 3, 'https://js.stripe.test/v3')
		probes = { 0: { roles: ['cardholder'] }, 3: CARD_FIELDS }
		const confirmed = { frames: 'sameSite', frameHosts: ['js.stripe.test'], origin: 'https://shop.test', extra: 'ignored' }
		expect(await handleFillRequest({ kind: 'fill.request', itemId: 'c1', tabId: TAB, confirmed } as never)).toEqual({ filled: 'form', code: false })
		expect(forms()).toEqual([[0, { cardholder: 'Anna de Vries' }]])
		sent = []
		const withoutOrigin = { ...confirmed, origin: undefined }
		expect((await handleFillRequest({ kind: 'fill.request', itemId: 'c1', tabId: TAB, confirmed: withoutOrigin } as never)).needsFormConfirm?.frameHosts).toEqual(['js.stripe.test'])
		expect(forms()).toEqual([])
	})
})
