import { beforeEach, describe, expect, it, vi } from 'vitest'
import { fakeBrowser } from 'wxt/testing/fake-browser'
import { addAccount } from '@/src/accounts/store'
import { pemToPkcs8, toBase64 } from '@/src/crypto'
import { listHistory } from '@/src/generator/history'
import { generatorPolicyKey } from '@/src/generator/store'
import { GENERATE_ROW_ID, inlineHeight, type BackgroundToContent, type PickInput } from '@/src/messages'
import { envelope, suiteRow } from '@/src/testing/vectors'
import { putKey } from '@/src/vault/key-store'
import { lock } from '@/src/vault/unlock'
import { handleInlineList, inlineSessionKey, openSession, registerInlineSessions } from './inline-session'

// Spec `extension-generator`, Suggest a strong password in a sign-up field: the background side of the offer.

const TAB = 7
const LIST_FRAME = 9
const OPEN = { kind: 'inline.open', mode: 'newPassword' } as const
const input: PickInput = { kind: 'pointer', at: 1, x: 20, y: 20 }
const POLICY = { minLength: 24, requireUpper: true, requireLower: true, requireDigit: true, requireSymbol: true, allowPassphrase: true }

let accountId: string
let verified: boolean
let sent: Array<{ message: BackgroundToContent; frameId: number | undefined }>

const pageUrl = 'https://shop.example.test/register'
const content = () => ({ id: browser.runtime.id, url: pageUrl, tab: { id: TAB }, frameId: 0 }) as never
const listPage = () => ({ id: browser.runtime.id, url: browser.runtime.getURL('/inline-list.html'), tab: { id: TAB }, frameId: LIST_FRAME }) as never
const stored = async () => (await browser.storage.session.get(inlineSessionKey(TAB)))[inlineSessionKey(TAB)] as string | undefined
const fills = () => sent.filter(({ message }) => message.kind === 'fill.newPassword')

async function openWithState() {
	const { key } = (await openSession(OPEN, content()))!
	const state = await handleInlineList({ kind: 'inline.state', key }, listPage())
	return { key, state }
}

const pick = (key: string, itemId = GENERATE_ROW_ID) => handleInlineList({ kind: 'inline.pick', key, itemId, input }, listPage())

beforeEach(async () => {
	fakeBrowser.reset()
	accountId = (await addAccount({
		serverUrl: 'https://cloud.example.org', uid: 'alice', loginName: 'alice', displayName: 'Alice', email: null, avatarDataUrl: null, appPassword: 'pw', keepiqVersion: '0.3.12',
	}, { ...suiteRow })).id
	await putKey(accountId, toBase64(pemToPkcs8(envelope.privateKeyPem)))
	await browser.storage.local.set({ [generatorPolicyKey(accountId)]: POLICY })
	verified = true
	sent = []
	vi.spyOn(browser.tabs, 'get').mockImplementation(async (id: number) => ({ id, url: pageUrl }) as never)
	vi.spyOn(browser.tabs, 'query').mockImplementation(async () => [{ id: TAB, url: pageUrl, active: true }] as never)
	vi.spyOn(browser.tabs, 'sendMessage').mockImplementation(async (_tabId: number, message: unknown, options?: { frameId?: number }) => {
		sent.push({ message: structuredClone(message) as BackgroundToContent, frameId: options?.frameId })
		const m = message as BackgroundToContent
		if (m.kind === 'inline.verify') return verified as never
		if (m.kind === 'fill.newPassword') return { filled: 2 } as never
		return undefined as never
	})
	registerInlineSessions()
})

describe('the strong-password offer', () => {
	it('opens one row high and shows no vault rows, as the list page draws the offer', async () => {
		expect(await openSession(OPEN, content())).toEqual({ key: expect.any(String), height: inlineHeight(1) })
		const { state } = await openWithState()
		expect(state).toMatchObject({ state: 'unlocked', mode: 'newPassword', host: 'shop.example.test', rows: [] })
	})

	it('on a verified press, fills a password made under the policy into the list\'s form, keeps it in the history and closes the list', async () => {
		const { key } = await openWithState()

		expect(await pick(key)).toEqual({ filled: 'password', code: false })

		const [fill] = fills()
		expect(fills()).toHaveLength(1)
		expect(fill!.frameId).toBe(0)
		const message = fill!.message as Extract<BackgroundToContent, { kind: 'fill.newPassword' }>
		expect(message).toMatchObject({ origin: 'https://shop.example.test', anchorKey: key })
		expect(message.password.length).toBeGreaterThanOrEqual(POLICY.minLength)
		expect(message.password).toMatch(/[A-Z]/)
		expect(message.password).toMatch(/[a-z]/)
		expect(message.password).toMatch(/\d/)
		expect(message.password).toMatch(/[^A-Za-z0-9]/)
		// The verification came first: nothing is generated for a press the host did not see.
		expect(sent.findIndex(({ message: m }) => m.kind === 'inline.verify')).toBeLessThan(sent.indexOf(fill!))
		expect((await listHistory(accountId)).map((entry) => entry.value)).toEqual([message.password])
		expect(await stored()).toBeUndefined()
	})

	it('generates and fills nothing for a press the host does not confirm, as a page script\'s click', async () => {
		const { key } = await openWithState()
		verified = false

		expect(await pick(key)).toEqual({ refused: 'not_visible' })
		expect(fills()).toEqual([])
		expect(await listHistory(accountId)).toEqual([])
	})

	it('fills nothing for any other row id, and nothing while the vault is locked', async () => {
		const { key } = await openWithState()
		expect(await pick(key, 'some-login')).toEqual({ refused: 'stale' })

		const again = await openWithState()
		await lock(accountId)
		// A lock closes every list, so the pick finds no session at all.
		expect(await pick(again.key)).toBeNull()
		expect(fills()).toEqual([])
	})
})
