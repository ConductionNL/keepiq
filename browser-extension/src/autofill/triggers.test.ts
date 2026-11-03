import { beforeEach, describe, expect, it, vi } from 'vitest'
import { fakeBrowser } from 'wxt/testing/fake-browser'
import type { OpenSecretRow } from '@/src/api/types'
import { writeSettings } from '@/src/accounts/settings'
import { addAccount } from '@/src/accounts/store'
import { pemToPkcs8, toBase64 } from '@/src/crypto'
import { lastUsedKey } from '@/src/last-used'
import { encrypt, folderRow, secretRow, typeRows } from '@/src/testing/vault'
import { envelope, suiteRow } from '@/src/testing/vectors'
import { putKey } from '@/src/vault/key-store'
import { writeSnapshot } from '@/src/vault/store'
import { lock } from '@/src/vault/unlock'
import { forgetTab } from './frames'
import { fillFromShortcut, fillOnPageLoad } from './triggers'

const TAB = 7
let accountId: string
let tabUrl: string
let toTab: Array<{ kind: string; username?: string }>
let openPopup: ReturnType<typeof vi.fn>

const tab = () => ({ id: TAB, url: tabUrl, active: true }) as never
const filledUsers = () => toTab.filter((m) => m.kind === 'fill.execute').map((m) => m.username)

async function vault(secrets: OpenSecretRow[]) {
	const syncedAt = new Date().toISOString()
	await writeSnapshot(accountId, {
		suite: { id: suiteRow.id, unlockKeyEpoch: 1 }, secrets, folders: [folderRow()], types: typeRows,
		syncedAt, listsSyncedAt: syncedAt, newestUpdatedAt: null, total: secrets.length,
	})
}

async function logins(...ids: string[]) {
	await vault(await Promise.all(ids.map(async (id) => secretRow({ id, name: id, url: 'https://example.test/', login: await encrypt(id), key: await encrypt('pw') }))))
}

const used = (lastUsed: Record<string, number>) => browser.storage.local.set({ [lastUsedKey(accountId)]: lastUsed })

beforeEach(async () => {
	fakeBrowser.reset()
	forgetTab(TAB)
	accountId = (await addAccount({
		serverUrl: 'https://cloud.example.org', uid: 'alice', loginName: 'alice', displayName: 'Alice', email: null, avatarDataUrl: null, appPassword: 'pw', keepiqVersion: '0.3.12',
	}, { ...suiteRow })).id
	await putKey(accountId, toBase64(pemToPkcs8(envelope.privateKeyPem)))
	tabUrl = 'https://example.test/login'
	toTab = []
	vi.spyOn(browser.tabs, 'query').mockImplementation(async () => [tab()])
	vi.spyOn(browser.tabs, 'get').mockImplementation(async () => tab())
	vi.spyOn(browser.tabs, 'sendMessage').mockImplementation(async (_tabId: number, message: unknown) => {
		toTab.push(structuredClone(message) as never)
		return ((message as { kind: string }).kind === 'fill.execute' ? { filled: 'both' } : true) as never
	})
	openPopup = vi.fn(async () => {})
	Object.assign(browser.action, { openPopup })
})

describe('fill on page load', () => {
	const pageLoadOn = () => writeSettings(accountId, { autofillOnPageLoad: true })

	it('fills nothing with the setting off, as it ships', async () => {
		await logins('a')
		await fillOnPageLoad(TAB, tabUrl)
		expect(toTab).toEqual([])
	})

	it('fills nothing when the default autofill setting is Never', async () => {
		await logins('a')
		await writeSettings(accountId, { autofillOnPageLoad: true, defaultAutofill: 'never' })
		await fillOnPageLoad(TAB, tabUrl)
		expect(toTab).toEqual([])
	})

	it('fills the single match, without counting as interaction', async () => {
		await logins('a')
		await pageLoadOn()
		await fillOnPageLoad(TAB, tabUrl)
		expect(filledUsers()).toEqual(['a'])
		expect(await browser.storage.session.get('lastInteractionAt')).toEqual({})
	})

	it('fills nothing for two matches never used', async () => {
		await logins('a', 'b')
		await pageLoadOn()
		await fillOnPageLoad(TAB, tabUrl)
		expect(toTab).toEqual([])
	})

	it('fills the one used before of several', async () => {
		await logins('a', 'b', 'c')
		await used({ b: 1 })
		await pageLoadOn()
		await fillOnPageLoad(TAB, tabUrl)
		expect(filledUsers()).toEqual(['b'])
	})

	it('fills nothing while locked', async () => {
		await logins('a')
		await pageLoadOn()
		await lock(accountId)
		await fillOnPageLoad(TAB, tabUrl)
		expect(toTab).toEqual([])
	})

	it('asks in the page before filling an https login into an http page', async () => {
		tabUrl = 'http://example.test/login'
		await logins('a')
		await pageLoadOn()
		await fillOnPageLoad(TAB, tabUrl)
		expect(toTab.map((m) => m.kind)).toEqual(['fill.confirmInsecure', 'fill.execute'])
	})
})

describe('the autofill shortcut', () => {
	it('fills the one used before of several matches', async () => {
		await logins('a', 'b', 'c')
		await used({ c: 1 })
		await fillFromShortcut(tab())
		expect(filledUsers()).toEqual(['c'])
		expect(openPopup).not.toHaveBeenCalled()
	})

	it('counts a fill as interaction, unlike page load', async () => {
		await logins('a')
		await fillFromShortcut(tab())
		// `touch()` stores the time as a string.
		expect((await browser.storage.session.get('lastInteractionAt')).lastInteractionAt).toEqual(expect.any(String))
	})

	it('fills the single match, in the active tab when the command names none', async () => {
		await logins('a')
		await fillFromShortcut(undefined)
		expect(filledUsers()).toEqual(['a'])
	})

	it('opens the popup when nothing matches', async () => {
		await logins('a')
		tabUrl = 'https://other.test/'
		await fillFromShortcut(tab())
		expect(toTab).toEqual([])
		expect(openPopup).toHaveBeenCalledOnce()
	})

	it('opens the popup for several matches none used, and while locked', async () => {
		await logins('a', 'b')
		await fillFromShortcut(tab())
		await lock(accountId)
		await used({ a: 1 })
		await fillFromShortcut(tab())
		expect(toTab).toEqual([])
		expect(openPopup).toHaveBeenCalledTimes(2)
	})
})
