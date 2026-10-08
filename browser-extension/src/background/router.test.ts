import { beforeEach, describe, expect, it, vi } from 'vitest'
import { fakeBrowser } from 'wxt/testing/fake-browser'
import { setUnauthorizedHandler } from '@/src/api/client'
import { addAccount, getAccount, markLoggedOut } from '@/src/accounts/store'
import { envelope, suiteRow } from '@/src/testing/vectors'
import type { PopupState, PopupToBackground, Result } from '@/src/messages'
import { handlePopupMessage } from './router'

const fetchMock = vi.fn<typeof fetch>()
const json = (body: unknown, status = 200) => new Response(JSON.stringify(body), { status })

function nextcloud(uid = 'alice') {
	fetchMock.mockImplementation(async (url) => {
		const path = String(url)
		if (path.includes('/cloud/user')) return json({ ocs: { data: { id: uid, displayname: 'Alice', email: 'a@x' } } })
		if (path.includes('/api/v1/suites')) return json([suiteRow])
		return new Response(null, { status: 404 })
	})
}

/** Origins the user granted; the fake browser has no permissions implementation. */
let granted: string[] = []
const grant = () => granted.push('https://cloud.example.org/*')

async function send(message: PopupToBackground): Promise<Result> {
	return handlePopupMessage(message)
}

async function state(message: PopupToBackground): Promise<PopupState> {
	const result = await send(message)
	if (!result.ok) throw new Error(`${result.code}: ${result.message}`)
	return result.state
}

async function stored(uid = 'alice') {
	return addAccount({
		origin: 'https://cloud.example.org', uid, loginName: uid, displayName: uid, email: null, avatarDataUrl: null, appPassword: 'pw',
	}, { ...suiteRow })
}

const add = { kind: 'accounts.add', serverUrl: 'cloud.example.org', username: 'alice', appPassword: 'pw' } as const
const unlockWith = (accountId: string, masterPassword = envelope.password) =>
	({ kind: 'vault.unlock', accountId, method: { type: 'masterPassword', masterPassword } }) as const

beforeEach(() => {
	fakeBrowser.reset()
	vi.stubGlobal('fetch', fetchMock)
	fetchMock.mockReset()
	setUnauthorizedHandler((id) => markLoggedOut(id, true))
	granted = []
	vi.spyOn(browser.permissions, 'contains').mockImplementation(async ({ origins }) => origins!.every((o) => granted.includes(o)))
})

describe('screens', () => {
	it('shows Add account on a fresh profile', async () => {
		expect(await state({ kind: 'vault.status' })).toEqual({
			screen: 'add_account', accounts: [], active: null, notice: null, canAddAccount: true,
		})
	})

	it('walks add, unlock, lock', async () => {
		grant()
		nextcloud()
		const added = await state(add)
		expect(added.screen).toBe('unlock')
		expect(added.active).toMatchObject({ uid: 'alice', host: 'cloud.example.org', status: 'locked', displayName: 'Alice' })

		const id = added.active!.id
		expect((await state(unlockWith(id))).screen).toBe('unlocked')
		expect((await state({ kind: 'vault.lock', accountId: id })).screen).toBe('unlock')
	})

	it('shows Log in again with the revoked notice after a 401', async () => {
		const account = await stored()
		await markLoggedOut(account.id, true)
		const s = await state({ kind: 'vault.status' })
		expect(s.screen).toBe('reauthenticate')
		expect(s.notice).toBe('Session revoked, please log in again')
	})
})

describe('adding', () => {
	it('sends nothing when the permission was declined', async () => {
		expect(await send(add)).toMatchObject({ ok: false, code: 'permission_denied' })
		expect(fetchMock).not.toHaveBeenCalled()
		expect(await browser.storage.local.get(null)).toEqual({})
	})

	it('rejects plain http on a public host before any request', async () => {
		expect(await send({ ...add, serverUrl: 'http://cloud.example.org' })).toMatchObject({ ok: false, code: 'insecure_url' })
		expect(fetchMock).not.toHaveBeenCalled()
	})

	it('rejects a duplicate', async () => {
		await stored()
		grant()
		nextcloud()
		expect(await send(add)).toMatchObject({ ok: false, code: 'duplicate' })
	})

	it('rejects a sixth account', async () => {
		for (let i = 0; i < 5; i++) await stored(`u${i}`)
		grant()
		expect(await send(add)).toMatchObject({ ok: false, code: 'limit_reached' })
		expect((await state({ kind: 'accounts.list' })).canAddAccount).toBe(false)
	})
})

describe('re-login', () => {
	it('restores the app password and locks', async () => {
		const account = await stored()
		await markLoggedOut(account.id, true)
		nextcloud()
		const s = await state({ kind: 'accounts.reauthenticate', accountId: account.id, appPassword: 'new' })
		expect(s.screen).toBe('unlock')
		expect(s.notice).toBeNull()
		expect((await getAccount(account.id))?.appPassword).toBe('new')
	})

	it('refuses an app password of another user', async () => {
		const account = await stored()
		await markLoggedOut(account.id)
		nextcloud('mallory')
		expect(await send({ kind: 'accounts.reauthenticate', accountId: account.id, appPassword: 'x' })).toMatchObject({ ok: false, code: 'unauthorized' })
	})
})

describe('accounts', () => {
	it('switching keeps the other account unlocked', async () => {
		const a = await stored('a')
		const b = await stored('b')
		await state({ kind: 'accounts.switch', accountId: a.id })
		await state(unlockWith(a.id))
		const s = await state({ kind: 'accounts.switch', accountId: b.id })
		expect(s.screen).toBe('unlock')
		expect(s.accounts.map((x) => [x.uid, x.status, x.active])).toEqual([['a', 'unlocked', false], ['b', 'locked', true]])
	})

	it('Log out all returns to Add account', async () => {
		await stored('a')
		await stored('b')
		expect((await state({ kind: 'accounts.removeAll' })).screen).toBe('add_account')
	})

	it('maps a wrong master password to its code', async () => {
		const account = await stored()
		expect(await send(unlockWith(account.id, 'nope'))).toEqual({ ok: false, code: 'invalid_master_password', message: 'Invalid master password' })
	})
})

describe('unknown messages', () => {
	it('fail instead of answering with the state', async () => {
		expect(await send({ kind: 'get_state' } as never)).toEqual({ ok: false, code: 'unknown', message: 'Unknown message get_state' })
	})
})

describe('timeout', () => {
	it('locks before answering vault.status', async () => {
		const account = await stored()
		await state(unlockWith(account.id))
		await browser.storage.local.set({ [`settings.${account.id}`]: { vaultTimeout: 1, vaultTimeoutAction: 'lock' } })
		vi.spyOn(Date, 'now').mockReturnValue(Date.now() + 2 * 60_000)
		expect((await state({ kind: 'vault.status' })).screen).toBe('unlock')
	})
})
