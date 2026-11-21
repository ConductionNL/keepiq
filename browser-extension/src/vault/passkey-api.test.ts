import { beforeEach, describe, expect, it, vi } from 'vitest'
import { fakeBrowser } from 'wxt/testing/fake-browser'
import { addAccount, getAccount, updateAccount } from '@/src/accounts/store'
import { envelope, suiteRow } from '@/src/testing/vectors'
import { handleVaultPasskey } from './passkey-api'
import { unlock } from './unlock'

const fetchMock = vi.fn<typeof fetch>()
const json = (body: unknown, status = 200) => new Response(JSON.stringify(body), { status })
const assertion = { id: 'c1', rawId: 'c1', type: 'public-key' as const, response: { clientDataJSON: 'x' } }
const body = { credentialId: 'c1', wrappedUnlockKey: 'w', prfSalt: 's', attestation: assertion, assertion, label: 'Laptop', transports: 'internal', clientKind: 'extension' as const }

async function newAccount() {
	return addAccount({
		serverUrl: 'https://cloud.example.org', uid: 'alice', loginName: 'alice', displayName: 'alice', email: null, avatarDataUrl: null, appPassword: 'pw', keepiqVersion: '0.3.12',
	}, { ...suiteRow })
}

const sent = (index = 0) => {
	const [url, init] = fetchMock.mock.calls[index]!
	return { url: String(url), method: init?.method, body: init?.body === undefined ? undefined : JSON.parse(String(init.body)) }
}

beforeEach(() => {
	fakeBrowser.reset()
	vi.stubGlobal('fetch', fetchMock)
	fetchMock.mockReset()
})

describe('enrolment', () => {
	it('asks the server for extension options and adds the cached key envelope, only while unlocked', async () => {
		const account = await newAccount()
		expect(await handleVaultPasskey({ kind: 'vaultPasskey.enrolOptions', accountId: account.id })).toEqual({ ok: false, code: 'passkey_failed' })
		expect(fetchMock).not.toHaveBeenCalled()
		await unlock(account.id, { type: 'masterPassword', masterPassword: envelope.password })
		fetchMock.mockResolvedValue(json({ challenge: 'c', assertionChallenge: 'a', rpId: 'cloud.example.org', userHandle: 'h' }))
		expect(await handleVaultPasskey({ kind: 'vaultPasskey.enrolOptions', accountId: account.id })).toEqual({
			ok: true, value: { challenge: 'c', assertionChallenge: 'a', rpId: 'cloud.example.org', userHandle: 'h', privateKey: suiteRow.privateKey },
		})
		expect(sent()).toMatchObject({ url: 'https://cloud.example.org/index.php/apps/keepiq/api/v1/passkeys/challenge?client=extension', method: 'GET' })
	})

	it('posts only the fields the server takes, as an extension credential, and remembers it', async () => {
		const account = await newAccount()
		fetchMock.mockResolvedValue(json({ id: 'p1' }, 201))
		const extra = { ...body, masterPassword: 'never', rpId: 'elsewhere.example', clientKind: 'web' } as unknown as typeof body
		expect(await handleVaultPasskey({ kind: 'vaultPasskey.enrol', accountId: account.id, body: extra })).toEqual({ ok: true, value: null })
		expect(sent()).toEqual({ url: 'https://cloud.example.org/index.php/apps/keepiq/api/v1/passkeys', method: 'POST', body })
		expect((await getAccount(account.id))?.passkeyUnlock).toBe(true)
	})

	it('answers a refusal with one code and remembers nothing', async () => {
		const account = await newAccount()
		fetchMock.mockResolvedValue(json({ message: 'The passkey could not be verified' }, 400))
		expect(await handleVaultPasskey({ kind: 'vaultPasskey.enrol', accountId: account.id, body })).toEqual({ ok: false, code: 'passkey_failed' })
		expect((await getAccount(account.id))?.passkeyUnlock).toBeUndefined()
	})
})

describe('unlock', () => {
	it('reads the extension options and forgets the passkey once none is offered', async () => {
		const account = await newAccount()
		await updateAccount(account.id, { passkeyUnlock: true })
		fetchMock.mockResolvedValue(json({ unlockKeyEpoch: 1, challenge: null, rpId: 'cloud.example.org', credentials: [] }))
		expect(await handleVaultPasskey({ kind: 'vaultPasskey.unlockOptions', accountId: account.id })).toEqual({
			ok: true, value: { challenge: null, rpId: 'cloud.example.org', credentials: [] },
		})
		expect(sent()).toMatchObject({ url: 'https://cloud.example.org/index.php/apps/keepiq/api/v1/passkeys/login-options?client=extension', method: 'GET' })
		expect((await getAccount(account.id))?.passkeyUnlock).toBe(false)
	})

	it('posts the assertion for the extension and returns only the released key', async () => {
		const account = await newAccount()
		fetchMock.mockResolvedValue(json({ id: 'p1', credentialId: 'c1', wrappedUnlockKey: 'w', unlockKeyEpoch: 1 }))
		expect(await handleVaultPasskey({ kind: 'vaultPasskey.release', accountId: account.id, assertion })).toEqual({ ok: true, value: { credentialId: 'c1', wrappedUnlockKey: 'w' } })
		expect(sent()).toEqual({ url: 'https://cloud.example.org/index.php/apps/keepiq/api/v1/passkeys/unlock', method: 'POST', body: { assertion, client: 'extension' } })
	})

	it('names a logged out account and an unreachable server', async () => {
		const account = await newAccount()
		fetchMock.mockRejectedValue(new TypeError('offline'))
		expect(await handleVaultPasskey({ kind: 'vaultPasskey.release', accountId: account.id, assertion })).toEqual({ ok: false, code: 'unreachable' })
		await updateAccount(account.id, { appPassword: null })
		expect(await handleVaultPasskey({ kind: 'vaultPasskey.unlockOptions', accountId: account.id })).toEqual({ ok: false, code: 'session_revoked' })
	})
})
