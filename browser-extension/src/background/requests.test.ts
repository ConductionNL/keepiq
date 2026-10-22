import { beforeEach, describe, expect, it, vi } from 'vitest'
import { fakeBrowser } from 'wxt/testing/fake-browser'
import { addAccount, folderNoticeKey, markLoggedOut, removeAccount, updateAccount } from '@/src/accounts/store'
import { settingsKey, writeSettings } from '@/src/accounts/settings'
import { CLIPBOARD_ALARM } from '@/src/clipboard'
import { pemToPkcs8, toBase64 } from '@/src/crypto'
import { defaultOptions, type GeneratorPolicy } from '@/src/generator/options'
import { writePolicy } from '@/src/generator/store'
import type { PopupReplies, PopupRequest } from '@/src/messages'
import { blockedRow, encrypt, folderRow, secretRow, typeRows } from '@/src/testing/vault'
import { envelope, suiteRow } from '@/src/testing/vectors'
import { editOpenedKey, hasKey, putKey, sessionValues } from '@/src/vault/key-store'
import { writeSnapshot } from '@/src/vault/store'
import type { VaultSnapshot } from '@/src/vault/types'
import { onPopupClosed, onPopupOpened } from '@/src/vault/timeout'
import { lock } from '@/src/vault/unlock'
import { handlePopupRequest, readLastTab } from './requests'

const fetchMock = vi.fn<typeof fetch>()

function send<K extends PopupRequest['kind']>(message: Extract<PopupRequest, { kind: K }>): Promise<PopupReplies[K]> {
	return handlePopupRequest(message) as Promise<PopupReplies[K]>
}

let accountId: string

async function snapshot(secrets: VaultSnapshot['secrets'], syncedAt = new Date().toISOString()) {
	await writeSnapshot(accountId, {
		suite: { id: suiteRow.id, unlockKeyEpoch: 1 }, secrets, folders: [folderRow()], types: typeRows,
		syncedAt, listsSyncedAt: syncedAt, newestUpdatedAt: null, total: secrets.length,
	})
}

beforeEach(async () => {
	fakeBrowser.reset()
	vi.stubGlobal('fetch', fetchMock)
	fetchMock.mockReset()
	fetchMock.mockResolvedValue(new Response(JSON.stringify({ message: 'Down' }), { status: 503 }))
	accountId = (await addAccount({
		serverUrl: 'https://cloud.example.org', uid: 'alice', loginName: 'alice', displayName: 'Alice', email: null, avatarDataUrl: null, appPassword: 'pw', keepiqVersion: '0.3.12',
	}, { ...suiteRow })).id
	await putKey(accountId, toBase64(pemToPkcs8(envelope.privateKeyPem)))
})

describe('vault.snapshot', () => {
	it('answers locked and logged out without rows', async () => {
		await lock(accountId)
		expect(await send({ kind: 'vault.snapshot' })).toEqual({ state: 'locked' })
		await markLoggedOut(accountId)
		expect(await send({ kind: 'vault.snapshot' })).toEqual({ state: 'logged_out' })
	})

	it('sends metadata only, plus the ids matching the tab', async () => {
		const login = await encrypt('alice')
		await snapshot([
			secretRow({ id: 'a', url: 'https://login.example.co.uk', login, additionalFields: await encrypt('{}') }),
			secretRow({ id: 'b', url: 'https://other.test', useOnly: true }),
			blockedRow({ id: 'c', url: 'example.co.uk' }),
		])
		const reply = await send({ kind: 'vault.snapshot', tabUrl: 'https://www.example.co.uk/' })
		if (reply.state !== 'unlocked') throw new Error(reply.state)
		expect(reply.suggestionIds).toEqual(['a', 'c'])
		expect(reply.items?.map((item) => [item.id, item.hasLogin, item.blocked, item.useOnly])).toEqual([['a', true, false, false], ['b', false, false, true], ['c', false, true, false]])
		expect(reply.items?.[2]).toMatchObject({ blockedReason: 'suite_revoked' })
		expect(reply.folders).toEqual([{ id: 'f1', name: 'Work', parentId: null }])
		expect(reply.types[0]).toEqual({ id: 't-login', name: 'login', label: 'Login' })
		expect(reply.webAppUrl).toBe('https://cloud.example.org/index.php/apps/keepiq/')
		for (const item of reply.items!) expect(Object.keys(item)).not.toEqual(expect.arrayContaining([expect.stringMatching(/^(key|login|additionalFields)$/)]))
		expect(JSON.stringify(reply)).not.toContain(login)
		expect(JSON.stringify(reply)).not.toContain('ciphertext')
	})

	it('starts a first sync and says so when there is no snapshot', async () => {
		const reply = await send({ kind: 'vault.snapshot', opened: true })
		expect(reply).toMatchObject({ state: 'unlocked', items: null, sync: { syncing: true } })
		expect(fetchMock).toHaveBeenCalled()
	})

	it('does not retry from a re-read, so a failing server is not hammered', async () => {
		vi.spyOn(console, 'error').mockImplementation(() => {})
		await send({ kind: 'vault.snapshot', opened: true })
		await vi.waitFor(async () => expect((await send({ kind: 'vault.snapshot' }) as { sync: { syncing: boolean } }).sync.syncing).toBe(false))
		fetchMock.mockClear()
		expect(await send({ kind: 'vault.snapshot' })).toMatchObject({ items: null, sync: { syncing: false, offline: true } })
		expect(fetchMock).not.toHaveBeenCalled()
	})

	it('does not count a re-read as interaction', async () => {
		await snapshot([])
		await send({ kind: 'vault.snapshot', opened: true })
		const opened = await browser.storage.session.get('lastInteractionAt')
		await new Promise((resolve) => setTimeout(resolve, 5))
		await send({ kind: 'vault.snapshot' })
		expect(await browser.storage.session.get('lastInteractionAt')).toEqual(opened)
	})

	it('does not count a passive re-decrypt as interaction', async () => {
		await snapshot([secretRow({ id: 'a', key: await encrypt('pw') })])
		await send({ kind: 'item.decrypt', ids: ['a'], fields: ['key'] })
		const before = await browser.storage.session.get('lastInteractionAt')
		await new Promise((resolve) => setTimeout(resolve, 5))
		await send({ kind: 'item.decrypt', ids: ['a'], fields: ['key'], passive: true })
		expect(await browser.storage.session.get('lastInteractionAt')).toEqual(before)
	})

	it('does not sync a fresh snapshot', async () => {
		await snapshot([])
		expect(await send({ kind: 'vault.snapshot' })).toMatchObject({ sync: { syncing: false } })
		expect(fetchMock).not.toHaveBeenCalled()
	})

	it('probes a stale snapshot and serves the cache meanwhile', async () => {
		await snapshot([secretRow()], new Date(Date.now() - 20 * 60_000).toISOString())
		const reply = await send({ kind: 'vault.snapshot', opened: true })
		expect(reply).toMatchObject({ items: [{ id: 's1' }], sync: { syncing: true } })
		await vi.waitFor(() => expect(fetchMock.mock.calls.some(([input]) => String(input).includes('sort=updated_at'))).toBe(true))
	})
})

describe('item.decrypt', () => {
	it('decrypts the asked fields of a batch without writing storage', async () => {
		await snapshot([
			secretRow({ id: 'a', login: await encrypt('alice@example.com'), key: await encrypt('hunter2') }),
			secretRow({ id: 'b', login: null }),
		])
		const set = vi.spyOn(browser.storage.local, 'set')
		const sessionSet = vi.spyOn(browser.storage.session, 'set')
		expect(await send({ kind: 'item.decrypt', ids: ['a', 'b'], fields: ['login'] })).toEqual({
			ok: true, items: { a: { login: 'alice@example.com' }, b: {} },
		})
		expect(set).not.toHaveBeenCalled()
		// `touch` writes the interaction time and nothing else.
		expect(sessionSet.mock.calls.every(([values]) => Object.keys(values).every((k) => k === 'lastInteractionAt'))).toBe(true)
	})

	it('leaves bad rows out of a batch instead of failing it', async () => {
		vi.spyOn(console, 'error').mockImplementation(() => {})
		await snapshot([blockedRow({ id: 'x' }), secretRow({ id: 'bad', login: 'AAAA' }), secretRow({ id: 'good', login: await encrypt('alice') })])
		expect(await send({ kind: 'item.decrypt', ids: ['x', 'bad', 'good'], fields: ['login'] })).toEqual({ ok: true, items: { good: { login: 'alice' } } })
	})

	it('refuses a blocked row', async () => {
		await snapshot([blockedRow({ id: 'x' })])
		expect(await send({ kind: 'item.decrypt', ids: ['x'], fields: ['key'] })).toEqual({ ok: false, error: 'blocked' })
	})

	it('gives a use-only copy its login and refuses everything else', async () => {
		await snapshot([secretRow({ id: 'u', useOnly: true, login: await encrypt('bob'), key: await encrypt('hunter2'), additionalFields: await encrypt('{"PIN":"1234"}') })])
		expect(await send({ kind: 'item.decrypt', ids: ['u'], fields: ['login'] })).toEqual({ ok: true, items: { u: { login: 'bob' } } })
		for (const fields of [['key'], ['additionalFields'], ['login', 'key', 'additionalFields']] as const) {
			expect(await send({ kind: 'item.decrypt', ids: ['u'], fields: [...fields] })).toEqual({ ok: false, error: 'use_only' })
		}
	})

	it('withholds a use-only authenticator\'s seed too', async () => {
		await snapshot([secretRow({ id: 't', useOnly: true, typeId: 't-totp', key: await encrypt('JBSWY3DPEHPK3PXP') })])
		expect(await send({ kind: 'item.decrypt', ids: ['t'], fields: ['key'] })).toEqual({ ok: false, error: 'use_only' })
	})

	it('leaves a use-only copy out of a batch that asks for its value', async () => {
		await snapshot([secretRow({ id: 'u', useOnly: true, key: await encrypt('hunter2') }), secretRow({ id: 'a', key: await encrypt('pw') })])
		const reply = await send({ kind: 'item.decrypt', ids: ['u', 'a'], fields: ['key'] })
		expect(reply).toEqual({ ok: true, items: { a: { key: 'pw' } } })
		expect(JSON.stringify(reply)).not.toContain('hunter2')
	})

	it('refuses while locked', async () => {
		await snapshot([secretRow({ id: 'a', key: await encrypt('hunter2') })])
		await lock(accountId)
		expect(await send({ kind: 'item.decrypt', ids: ['a'], fields: ['key'] })).toEqual({ ok: false, error: 'locked' })
	})

	it('reports ciphertext that does not open', async () => {
		vi.spyOn(console, 'error').mockImplementation(() => {})
		await snapshot([secretRow({ id: 'a', key: 'AAAA' })])
		expect(await send({ kind: 'item.decrypt', ids: ['a'], fields: ['key'] })).toEqual({ ok: false, error: 'decrypt_failed' })
	})
})

describe('last tab', () => {
	it('defaults to Vault, remembers a tab and forgets it on lock', async () => {
		expect(await readLastTab()).toBe('vault')
		await send({ kind: 'popup.lastTab.set', tab: 'settings' })
		expect(await readLastTab()).toBe('settings')
		await lock(accountId)
		expect(await readLastTab()).toBe('vault')
	})

	it('ignores a tab that does not exist', async () => {
		await send({ kind: 'popup.lastTab.set', tab: 'nope' as never })
		expect(await readLastTab()).toBe('vault')
	})
})

describe('settings', () => {
	it('reads and writes the theme while locked', async () => {
		await lock(accountId)
		expect(await send({ kind: 'settings.get' })).toEqual({ theme: 'system' })
		expect(await send({ kind: 'settings.set', patch: { theme: 'dark' } })).toEqual({ theme: 'dark' })
		expect(await send({ kind: 'settings.get' })).toEqual({ theme: 'dark' })
	})

	it('ignores an invalid theme', async () => {
		await send({ kind: 'settings.set', patch: { theme: 'light' } })
		expect(await send({ kind: 'settings.set', patch: { theme: 'blue' as never } })).toEqual({ theme: 'light' })
	})

	it('does not count a read as interaction', async () => {
		await send({ kind: 'settings.set', patch: { theme: 'light' } })
		const before = await browser.storage.session.get('lastInteractionAt')
		await new Promise((resolve) => setTimeout(resolve, 5))
		await send({ kind: 'settings.get' })
		expect(await browser.storage.session.get('lastInteractionAt')).toEqual(before)
	})
})

describe('vault writes', () => {
	it('answers with the snapshot\'s default type and the folder notice flag', async () => {
		await writeSnapshot(accountId, {
			suite: { id: suiteRow.id, unlockKeyEpoch: 1 }, secrets: [], folders: [], types: typeRows,
			syncedAt: new Date().toISOString(), listsSyncedAt: new Date().toISOString(), newestUpdatedAt: null, total: 0, defaultSecretType: 'note',
		})
		expect(await send({ kind: 'vault.snapshot' })).toMatchObject({ defaultSecretType: 'note', folderNoticeDismissed: false })
		expect(await send({ kind: 'folder.dismissNotice', accountId })).toBeNull()
		expect(await send({ kind: 'vault.snapshot' })).toMatchObject({ folderNoticeDismissed: true })
	})

	it('forgets the dismissed notice with the account', async () => {
		await send({ kind: 'folder.dismissNotice', accountId })
		await removeAccount(accountId)
		expect(await browser.storage.local.get(folderNoticeKey(accountId))).toEqual({})
	})

	it('routes a write to the server', async () => {
		fetchMock.mockResolvedValue(new Response(JSON.stringify({ message: 'Folder is not empty' }), { status: 409 }))
		expect(await send({ kind: 'folder.delete', accountId, id: 'f1', plan: { mode: 'plain' } })).toEqual({ ok: false, error: { code: 'conflict' } })
		expect(String(fetchMock.mock.calls[0]![0])).toBe('https://cloud.example.org/index.php/apps/keepiq/api/v1/folders/f1')
	})

	it('refuses a write meant for an account that is no longer active, without a request', async () => {
		expect(await send({ kind: 'item.delete', accountId: 'another', id: 's1' })).toEqual({ ok: false, error: { code: 'account_changed' } })
		expect(await send({ kind: 'folder.dismissNotice', accountId: 'another' })).toBeNull()
		expect(fetchMock).not.toHaveBeenCalled()
		expect(await browser.storage.local.get(folderNoticeKey(accountId))).toEqual({})
	})

	it('refuses a write that names no account', async () => {
		expect(await send({ kind: 'item.delete', id: 's1' } as never)).toEqual({ ok: false, error: { code: 'invalid' } })
		expect(fetchMock).not.toHaveBeenCalled()
	})

	it('answers item.editable from the item\'s write-context', async () => {
		fetchMock.mockResolvedValue(new Response(JSON.stringify({ sourceSecretId: 'src', effectiveGrade: 'read', ownerCertificate: null, sourceUpdatedAt: null })))
		expect(await send({ kind: 'item.editable', accountId, id: 's1' })).toEqual({ ok: true, value: { editable: false } })
		expect(String(fetchMock.mock.calls[0]![0])).toBe('https://cloud.example.org/index.php/apps/keepiq/api/v1/secrets/s1/write-context')
	})

	describe('item.update', () => {
		const json = (body: unknown, status = 200) => new Response(JSON.stringify(body), { status })
		const bob = { shareId: 'fs-bob', recipientCloudId: 'bob@cloud.partner.example' }
		/** Item `s1`, shared with a partner when `partner`, whose instance does not answer. */
		async function serve(partner: boolean) {
			const row = secretRow({ key: await encrypt('pw') })
			await snapshot([row])
			fetchMock.mockImplementation(async (input, init) => {
				const path = new URL(String(input)).pathname.replace('/index.php/apps/keepiq', '')
				const body = init?.body ? JSON.parse(String(init.body)) as { federated: unknown[]; suspend: unknown[] } : undefined
				if (path === '/api/v1/secrets/s1') return json(row)
				if (path === '/api/v1/secrets/s1/write-context') return json({ sourceSecretId: 's1', effectiveGrade: 'owner', ownerCertificate: envelope.certificatePem, sourceUpdatedAt: row.updatedAt })
				if (path === '/api/v1/secrets/s1/share-save') {
					return partner && body!.federated.length + body!.suspend.length === 0
						? json({ error: 'incomplete', copies: { required: [] }, federated: { required: [bob] } }, 409)
						: json({ secret: row, federated: { ownerOnly: false } })
				}
				if (path === '/api/v1/federation/recipient-certificate') return json({ message: 'partner_unreachable' }, 502)
				if (path === '/api/v1/suites') return json([suiteRow])
				if (path === '/api/v1/offline/manifest') return json({ suite: suiteRow, secrets: [row], folders: [folderRow()], types: typeRows, syncedAt: '' })
				return json({ message: 'Unavailable' }, 503)
			})
			vi.spyOn(console, 'error').mockImplementation(() => {})
			const opened = await send({ kind: 'item.fetch', accountId, id: 's1', purpose: 'edit' })
			return { row, record: opened.ok ? opened.value.recordId : undefined }
		}

		it.each([
			['its edit fetch\'s record, which it uses up', (record: string | undefined) => record, 0],
			['a record that is not a string as none, and still saves', () => 5, 1],
		])('passes a save %s', async (_, named, left) => {
			const { row, record } = await serve(false)
			expect(await send({ kind: 'item.update', accountId, id: 's1', patch: { key: 'new' }, baseUpdatedAt: row.updatedAt, recordId: named(record) } as never)).toMatchObject({ ok: true })
			expect(JSON.parse(await sessionValues.get(editOpenedKey(accountId)) ?? '[]')).toHaveLength(left)
		})

		it.each([
			['a list of share ids', ['fs-bob'], { ok: true }],
			['one share id that is not in a list', 'fs-bob', { ok: false, error: { code: 'partner_unreachable', partners: [{ shareId: 'fs-bob', cloudId: bob.recipientCloudId }] } }],
			['a list holding other than share ids', ['fs-bob', 5], { ok: false, error: { code: 'partner_unreachable' } }],
		])('passes the partners to save without as %s, dropping anything else', async (_, withoutUnreachable, reply) => {
			const { row, record } = await serve(true)
			expect(await send({ kind: 'item.update', accountId, id: 's1', patch: { key: 'new' }, baseUpdatedAt: row.updatedAt, recordId: record, withoutUnreachable } as never)).toMatchObject(reply)
		})
	})
})

describe('clipboard.copied', () => {
	it('schedules the clear with the default delay', async () => {
		await send({ kind: 'clipboard.copied' })
		expect(await browser.alarms.get(CLIPBOARD_ALARM)).toBeDefined()
	})

	it('schedules nothing when the delay is never', async () => {
		await browser.storage.local.set({ [settingsKey(accountId)]: { vaultTimeout: 15, vaultTimeoutAction: 'lock', clearClipboardMs: 0 } })
		await send({ kind: 'clipboard.copied' })
		expect(await browser.alarms.get(CLIPBOARD_ALARM)).toBeUndefined()
	})
})

describe('popup.popout', () => {
	it('opens the popup page in a window with the tab it came from', async () => {
		const create = vi.spyOn(browser.windows, 'create').mockResolvedValue({} as never)
		await send({ kind: 'popup.popout', tabId: 42 })
		expect(create).toHaveBeenCalledWith(expect.objectContaining({ type: 'popup', width: 380, height: 630 }))
		expect(create.mock.calls[0]![0]!.url).toMatch(/\/popup\.html\?popout=1&tabId=42$/)
	})

	it('gives a later close no grace when the window did not open', async () => {
		vi.spyOn(console, 'error').mockImplementation(() => {})
		vi.spyOn(browser.windows, 'create').mockRejectedValue(new Error('No window'))
		await writeSettings(accountId, { vaultTimeout: 'immediately', vaultTimeoutAction: 'lock' })
		onPopupOpened()
		expect(await send({ kind: 'popup.popout', tabId: 42 })).toBeUndefined()
		// With the grace still set, this close would wait on a timer that never fires.
		vi.useFakeTimers({ toFake: ['setTimeout'] })
		try {
			await onPopupClosed()
			expect(await hasKey(accountId)).toBe(false)
		} finally {
			vi.useRealTimers()
		}
	})
})

describe('generator', () => {
	const policy: GeneratorPolicy = { minLength: 16, requireUpper: true, requireLower: false, requireDigit: false, requireSymbol: false, allowPassphrase: false }
	const interaction = async () => (await browser.storage.session.get('lastInteractionAt')).lastInteractionAt

	it('answers the stored options, the cached policy and the account email, also while locked', async () => {
		await updateAccount(accountId, { email: 'alice@example.org' })
		await writePolicy(accountId, policy)
		expect(await send({ kind: 'generator.context', accountId })).toEqual({ options: defaultOptions(), policy, accountEmail: 'alice@example.org' })
		await lock(accountId)
		expect(await send({ kind: 'generator.context', accountId })).toMatchObject({ policy })
	})

	it('saves options as sanitize leaves them', async () => {
		const options = { ...defaultOptions(), tab: 'passphrase' as const, password: { ...defaultOptions().password, length: 500 } }
		expect(await send({ kind: 'generator.saveOptions', accountId, options })).toMatchObject({ tab: 'passphrase', password: { length: 128 } })
		expect(await send({ kind: 'generator.context', accountId })).toMatchObject({ options: { tab: 'passphrase', password: { length: 128 } } })
	})

	it('adds, lists and clears history', async () => {
		await send({ kind: 'generator.history.add', accountId, entry: { value: 'one', kind: 'password' } })
		expect(await send({ kind: 'generator.history.add', accountId, entry: { value: 'two', kind: 'username' } })).toMatchObject([{ value: 'two' }, { value: 'one' }])
		expect(await send({ kind: 'generator.history.list', accountId })).toHaveLength(2)
		expect(await send({ kind: 'generator.history.clear', accountId })).toBeNull()
		expect(await send({ kind: 'generator.history.list', accountId })).toEqual([])
	})

	it('ignores an entry that is not a generated value', async () => {
		await send({ kind: 'generator.history.add', accountId, entry: { value: '', kind: 'password' } })
		await send({ kind: 'generator.history.add', accountId, entry: { value: 'x', kind: 'pin' as never } })
		await send({ kind: 'generator.history.add', accountId, entry: undefined as never })
		expect(await send({ kind: 'generator.history.list', accountId })).toEqual([])
	})

	it('refuses an account that is not the active one, writing nothing', async () => {
		const other = 'another'
		expect(await send({ kind: 'generator.context', accountId: other })).toBeNull()
		expect(await send({ kind: 'generator.saveOptions', accountId: other, options: defaultOptions() })).toBeNull()
		expect(await send({ kind: 'generator.history.add', accountId: other, entry: { value: 'one', kind: 'password' } })).toEqual([])
		expect(await send({ kind: 'generator.history.list', accountId: other })).toEqual([])
		await send({ kind: 'generator.history.add', accountId, entry: { value: 'mine', kind: 'password' } })
		expect(await send({ kind: 'generator.history.clear', accountId: other })).toBeNull()
		expect(await send({ kind: 'generator.history.list', accountId })).toHaveLength(1)
		expect(Object.keys(await browser.storage.local.get(null)).filter((key) => key.startsWith('generator'))).toEqual([])
	})

	it('refuses a logged out account, so a stale pop-out writes nothing after the logout', async () => {
		await markLoggedOut(accountId)
		expect(await send({ kind: 'generator.context', accountId })).toBeNull()
		expect(await send({ kind: 'generator.saveOptions', accountId, options: defaultOptions() })).toBeNull()
		expect(await send({ kind: 'generator.history.add', accountId, entry: { value: 'one', kind: 'password' } })).toEqual([])
		expect(await browser.storage.session.get('generatorHistory.' + accountId)).toEqual({})
		expect(Object.keys(await browser.storage.local.get(null)).filter((key) => key.startsWith('generator'))).toEqual([])
	})

	it('does not count a message naming another account as interaction', async () => {
		await send({ kind: 'generator.history.add', accountId, entry: { value: 'one', kind: 'password' } })
		const before = await interaction()
		await new Promise((resolve) => setTimeout(resolve, 5))
		await send({ kind: 'generator.history.add', accountId: 'another', entry: { value: 'two', kind: 'password' } })
		await send({ kind: 'generator.saveOptions', accountId: 'another', options: defaultOptions() })
		expect(await interaction()).toEqual(before)
	})

	it('counts using the generator as interaction only while the active account is unlocked', async () => {
		await send({ kind: 'generator.history.add', accountId, entry: { value: 'one', kind: 'password' } })
		const unlocked = await interaction()
		await new Promise((resolve) => setTimeout(resolve, 5))
		await send({ kind: 'generator.context', accountId })
		expect(await interaction()).toEqual(unlocked)
		await send({ kind: 'generator.history.add', accountId, entry: { value: 'two', kind: 'password' } })
		expect(Number(await interaction())).toBeGreaterThan(Number(unlocked))

		// A locked account on the lock screen must not keep the other, unlocked one from timing out.
		const locked = (await addAccount({
			serverUrl: 'https://cloud.example.org', uid: 'bob', loginName: 'bob', displayName: 'Bob', email: null, avatarDataUrl: null, appPassword: 'pw', keepiqVersion: '0.3.12',
		}, { ...suiteRow })).id
		const before = await interaction()
		await new Promise((resolve) => setTimeout(resolve, 5))
		await send({ kind: 'generator.saveOptions', accountId: locked, options: defaultOptions() })
		await send({ kind: 'generator.history.add', accountId: locked, entry: { value: 'three', kind: 'password' } })
		expect(await interaction()).toEqual(before)
		expect(await hasKey(accountId)).toBe(true)
	})

	it('counts Copy as interaction only while the active account is unlocked', async () => {
		await send({ kind: 'clipboard.copied' })
		const unlocked = await interaction()
		expect(unlocked).toBeDefined()
		// Copy on a locked account's lock screen must not keep the other, unlocked one from timing out.
		await addAccount({
			serverUrl: 'https://cloud.example.org', uid: 'bob', loginName: 'bob', displayName: 'Bob', email: null, avatarDataUrl: null, appPassword: 'pw', keepiqVersion: '0.3.12',
		}, { ...suiteRow })
		await new Promise((resolve) => setTimeout(resolve, 5))
		await send({ kind: 'clipboard.copied' })
		expect(await interaction()).toEqual(unlocked)
		expect(await browser.alarms.get(CLIPBOARD_ALARM)).toBeDefined()
	})

	it('writes no history for an add that a logout overtakes, at any point', async () => {
		const hits: number[] = []
		for (let ticks = 0; ticks < 300; ticks++) {
			await updateAccount(accountId, { appPassword: 'pw', revoked: false })
			await putKey(accountId, toBase64(pemToPkcs8(envelope.privateKeyPem)))
			const add = send({ kind: 'generator.history.add', accountId, entry: { value: 'secret', kind: 'password' } })
			const logout = (async () => {
				for (let i = 0; i < ticks; i++) await Promise.resolve()
				await markLoggedOut(accountId)
			})()
			await Promise.all([add, logout])
			const key = 'generatorHistory.' + accountId
			if ((await browser.storage.session.get(key))[key] !== undefined) hits.push(ticks)
		}
		expect(hits).toEqual([])
	})
})

describe('send', () => {
	const interaction = async () => (await browser.storage.session.get('lastInteractionAt')).lastInteractionAt

	it('routes a send request to the server and counts it as interaction', async () => {
		await send({ kind: 'settings.get' })
		const before = await interaction()
		await new Promise((resolve) => setTimeout(resolve, 5))
		fetchMock.mockResolvedValue(new Response(JSON.stringify({ revoked: true })))
		expect(await send({ kind: 'send.delete', accountId, id: 'b9c1' })).toEqual({ ok: true, value: null })
		expect(String(fetchMock.mock.calls[0]![0])).toBe('https://cloud.example.org/index.php/apps/keepiq/api/v1/sends/b9c1')
		expect(Number(await interaction())).toBeGreaterThan(Number(before ?? 0))
	})

	it('routes send.list and send.create to the server', async () => {
		const row = { id: 'b9c1', token: 'f'.repeat(64), payloadType: 'text', hasPassword: false, maxViews: 1, viewCount: 0, remainingViews: 1, expiresAt: null, createdAt: '2026-10-22T10:00:00+00:00' }
		fetchMock.mockImplementation(async (_input, init) => new Response(JSON.stringify(init?.method === 'POST' ? row : [row]), { status: init?.method === 'POST' ? 201 : 200 }))
		expect(await send({ kind: 'send.list', accountId })).toEqual({ ok: true, value: { sends: [row], passwordProtection: true } })
		const created = await send({ kind: 'send.create', accountId, input: { payloadType: 'text', content: 'hello', maxViews: 1, ttlSeconds: 3600 } })
		expect(created).toMatchObject({ ok: true, value: { row, link: expect.stringMatching(/\/public\/send\/f{64}#k=[\w-]{43}$/) } })
		expect(fetchMock.mock.calls.map(([input, init]) => `${init?.method ?? 'GET'} ${String(input)}`)).toEqual([
			'GET https://cloud.example.org/index.php/apps/keepiq/api/v1/sends',
			'POST https://cloud.example.org/index.php/apps/keepiq/api/v1/sends',
		])
	})

	it('refuses a send request that names no account, or another, without a request', async () => {
		expect(await send({ kind: 'send.list' } as never)).toEqual({ ok: false, error: { code: 'invalid' } })
		expect(await send({ kind: 'send.list', accountId: 'another' })).toEqual({ ok: false, error: { code: 'account_changed' } })
		expect(fetchMock).not.toHaveBeenCalled()
	})
})
