import { beforeEach, describe, expect, it, vi } from 'vitest'
import { fakeBrowser } from 'wxt/testing/fake-browser'
import { setUnauthorizedHandler } from '@/src/api/client'
import { addAccount, getAccount, markLoggedOut } from '@/src/accounts/store'
import { pemToPkcs8 } from '@/src/crypto'
import { readPolicy } from '@/src/generator/store'
import { forgetPin, pinGeneration, sealPin, storePinCopies, wrapPin } from '@/src/vault/pin'
import { envelope, suiteRow } from '@/src/testing/vectors'
import type { PopupState, PopupToBackground, Result } from '@/src/messages'
import { handlePopupMessage } from './router'

const fetchMock = vi.fn<typeof fetch>()
const json = (body: unknown, status = 200) => new Response(JSON.stringify(body), { status })

function nextcloud(uid = 'alice', policy?: unknown) {
	fetchMock.mockImplementation(async (url) => {
		const path = String(url)
		if (path.includes('/api/settings/policy') && policy) return json(policy)
		if (path.includes('/cloud/user')) return json({ ocs: { data: { id: uid, displayname: 'Alice', email: 'a@x' } } })
		if (path.includes('/api/v1/suites')) return json([suiteRow])
		if (path.includes('/api/health')) return json({ status: 'ok', version: '0.3.12' })
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
	if (!result.ok) throw new Error(result.code)
	return result.state
}

async function stored(uid = 'alice') {
	return addAccount({
		serverUrl: 'https://cloud.example.org', uid, loginName: uid, displayName: uid, email: null, avatarDataUrl: null, appPassword: 'pw', keepiqVersion: '0.3.12',
	}, { ...suiteRow })
}

const add = { kind: 'accounts.add', serverUrl: 'cloud.example.org', username: 'alice', appPassword: 'pw' } as const
const policyBody = { policy_enabled: true, generator_min_length: 20 }
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
			screen: 'add_account', accounts: [], active: null, notice: null, canAddAccount: true, lastTab: 'vault',
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
		expect(s.notice).toBe('session_revoked')
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

	it('caches the generator policy before the first unlock', async () => {
		grant()
		nextcloud('alice', policyBody)
		const id = (await state(add)).active!.id
		await vi.waitFor(async () => expect(await readPolicy(id)).toMatchObject({ minLength: 20 }))
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

	it('caches the generator policy again, which the logout dropped', async () => {
		const account = await stored()
		await markLoggedOut(account.id, true)
		nextcloud('alice', policyBody)
		await state({ kind: 'accounts.reauthenticate', accountId: account.id, appPassword: 'new' })
		await vi.waitFor(async () => expect(await readPolicy(account.id)).toMatchObject({ minLength: 20 }))
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
		expect(await send(unlockWith(account.id, 'nope'))).toEqual({ ok: false, code: 'invalid_master_password' })
	})
})

describe('PIN', () => {
	async function withPin() {
		const account = await stored()
		// The sync after the unlock finds the server offline.
		fetchMock.mockRejectedValue(new TypeError('Failed to fetch'))
		await state(unlockWith(account.id))
		const bytes = pemToPkcs8(envelope.privateKeyPem)
		await storePinCopies(account.id, await wrapPin('246813', bytes, suiteRow), await sealPin(account.id, '246813', bytes, suiteRow), pinGeneration(account.id))
		return account.id
	}

	it('reports whether the account has one', async () => {
		const id = await withPin()
		expect((await state({ kind: 'vault.lock', accountId: id })).active).toMatchObject({ pin: true, pinAfterRestart: false })
		await forgetPin(id)
		expect((await state({ kind: 'vault.status' })).active).toMatchObject({ pin: false, pinAfterRestart: false })
	})

	it('reports a PIN that needs the master password once after a restart', async () => {
		const id = await withPin()
		await state({ kind: 'vault.lock', accountId: id })
		await browser.storage.session.clear()
		expect((await state({ kind: 'vault.status' })).active).toMatchObject({ pin: false, pinAfterRestart: true })
	})

	it('unlocks with the PIN', async () => {
		const id = await withPin()
		await state({ kind: 'vault.lock', accountId: id })
		expect((await state({ kind: 'vault.unlock', accountId: id, method: { type: 'pin', pin: '246813' } })).screen).toBe('unlocked')
	})

	it('copies the attempts left into the result', async () => {
		const id = await withPin()
		await state({ kind: 'vault.lock', accountId: id })
		expect(await send({ kind: 'vault.unlock', accountId: id, method: { type: 'pin', pin: '0000' } })).toEqual({ ok: false, code: 'invalid_pin', attemptsLeft: 4 })
	})
})

describe('unknown messages', () => {
	it('fail instead of answering with the state', async () => {
		expect(await send({ kind: 'get_state' } as never)).toEqual({ ok: false, code: 'unknown' })
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

	it('does not count a passive status refresh as interaction', async () => {
		await state({ kind: 'vault.status' })
		const before = await browser.storage.session.get('lastInteractionAt')
		await new Promise((resolve) => setTimeout(resolve, 5))
		await state({ kind: 'vault.status', passive: true })
		expect(await browser.storage.session.get('lastInteractionAt')).toEqual(before)
	})
})
