import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest'
import { fakeBrowser } from 'wxt/testing/fake-browser'
import type { OpenSecretRow } from '@/src/api/types'
import { addAccount } from '@/src/accounts/store'
import { pemToPkcs8, toBase64 } from '@/src/crypto'
import { lastUsedKey } from '@/src/last-used'
import type { BackgroundToContent } from '@/src/messages'
import { encrypt, secretRow, typeRows } from '@/src/testing/vault'
import { envelope, suiteRow } from '@/src/testing/vectors'
import { putKey } from '@/src/vault/key-store'
import { writeSnapshot } from '@/src/vault/store'
import { lock } from '@/src/vault/unlock'
import { pickCode, type CodeTarget } from './code-fill'

/** RFC 6238's SHA-1 key; at 59 s its code is `287082`. */
const SEED = 'GEZDGNBVGY3TQOJQGEZDGNBVGY3TQOJQ'
const TAB = 7
let accountId: string
let sent: Array<{ message: BackgroundToContent; frameId: number | undefined }>
let answer: unknown

const target = (overrides: Partial<CodeTarget> = {}): CodeTarget => ({
	itemId: 't1', tabId: TAB, confirmedInsecure: false, only: { frameId: 3, url: 'https://example.test/2fa', anchorKey: 'k1' }, ...overrides,
})

async function vault(overrides: Partial<OpenSecretRow> = {}) {
	const syncedAt = new Date().toISOString()
	const row = secretRow({ id: 't1', name: 'Example', url: 'https://example.test', login: await encrypt('alice'), key: await encrypt('hunter2'), totp: await encrypt(SEED), ...overrides })
	await writeSnapshot(accountId, {
		suite: { id: suiteRow.id, unlockKeyEpoch: 1 }, secrets: [row], folders: [], types: typeRows, syncedAt, listsSyncedAt: syncedAt, newestUpdatedAt: null, total: 1,
	})
}

beforeEach(async () => {
	fakeBrowser.reset()
	vi.useFakeTimers({ toFake: ['Date'] })
	vi.setSystemTime(59_000)
	accountId = (await addAccount({
		serverUrl: 'https://cloud.example.org', uid: 'alice', loginName: 'alice', displayName: 'Alice', email: null, avatarDataUrl: null, appPassword: 'pw', keepiqVersion: '0.3.12',
	}, { ...suiteRow })).id
	await putKey(accountId, toBase64(pemToPkcs8(envelope.privateKeyPem)))
	sent = []
	answer = { filled: 'none', code: true }
	vi.spyOn(browser.tabs, 'sendMessage').mockImplementation(async (_tabId: number, message: unknown, options?: { frameId?: number }) => {
		sent.push({ message: structuredClone(message) as BackgroundToContent, frameId: options?.frameId })
		return answer as never
	})
})
afterEach(() => {
	vi.useRealTimers()
})

describe('pickCode', () => {
	it('sends the login\'s current code, without its username or password, to the list\'s frame only, and records last used', async () => {
		await vault()
		expect(await pickCode(target())).toEqual({ filled: 'none', code: true })
		expect(sent).toEqual([{
			message: { kind: 'fill.execute', username: null, password: null, code: '287082', origin: 'https://example.test', anchorKey: 'k1' }, frameId: 3,
		}])
		expect((await browser.storage.local.get(lastUsedKey(accountId)))[lastUsedKey(accountId)]).toHaveProperty('t1')
	})

	it('records nothing when the page took no code', async () => {
		await vault()
		answer = { filled: 'none', code: false }
		expect(await pickCode(target())).toEqual({ filled: 'none', code: false })
		expect(await browser.storage.local.get(lastUsedKey(accountId))).toEqual({})
	})

	it('asks first for an https login on an http frame, then fills once confirmed', async () => {
		await vault()
		const insecure = { frameId: 3, url: 'http://example.test/2fa', anchorKey: 'k1' }
		expect(await pickCode(target({ only: insecure }))).toEqual({ filled: 'none', code: false, needsInsecureConfirm: true })
		expect(sent).toEqual([])
		expect(await pickCode(target({ only: insecure, confirmedInsecure: true }))).toEqual({ filled: 'none', code: true })
	})

	it('sends nothing for a login that no longer matches the frame, one without a code, an invalid seed or a standalone authenticator item', async () => {
		for (const overrides of [{ url: 'https://other.test' }, { totp: '' }, { totp: await encrypt('not-a-seed') }, { typeId: 't-totp', key: await encrypt(SEED), totp: null }]) {
			await vault(overrides)
			expect(await pickCode(target())).toEqual({ filled: 'none', code: false })
		}
		expect(sent).toEqual([])
	})

	it('fills a use-only copy\'s code', async () => {
		await vault({ useOnly: true })
		expect(await pickCode(target())).toEqual({ filled: 'none', code: true })
	})

	it('sends nothing while locked', async () => {
		await vault()
		await lock(accountId)
		expect(await pickCode(target())).toEqual({ filled: 'none', code: false })
		expect(sent).toEqual([])
	})
})
