/**
 * The popup's Vault, Generator and Send tabs, at the worker boundary and in
 * their pure rules (clients-extension-generator-vault-send).
 *
 * The worker handlers run against a fake API and a fake key holder: values
 * are "encrypted" by prefixing, so a test can see that only ciphertext
 * reaches the API and that the popup only gets index fields from a list.
 *
 * @spec openspec/changes/clients-extension-generator-vault-send/specs/extension-vault/spec.md
 * @spec openspec/changes/clients-extension-generator-vault-send/specs/extension-send/spec.md
 * @spec openspec/changes/clients-extension-generator-vault-send/specs/extension-generator/spec.md
 */
import { describe, expect, it, vi } from 'vitest'
import { buildVaultHandlers } from '../../browser-extension/src/background/vault-handlers.js'
import {
	credentialPayload,
	expirySeconds,
	maxViewsFrom,
	sendRowLabel,
} from '../../browser-extension/src/lib/send-form.js'
import {
	buildIndex,
	filterIndex,
	folderChoices,
	presentTypes,
} from '../../browser-extension/src/lib/vault-index.js'
import { aesDecrypt, fromBase64Url } from '../../src/send/sendCrypto.js'

const ACCOUNT = { id: 'acc-1', url: 'https://cloud.example', user: 'ann' }

const ROWS = [
	{
		id: 's2',
		name: 'zebra bank',
		url: 'https://zebra.example',
		typeId: 't1',
		folderId: 'f1',
		key: 'enc:z',
		login: 'enc:ann',
	},
	{
		id: 's1',
		name: 'Alpha mail',
		url: 'https://mail.example',
		typeId: 't1',
		folderId: null,
		key: 'enc:a',
		login: 'enc:a@x',
	},
	{
		id: 's3',
		name: 'Server key',
		url: '',
		typeId: 't2',
		folderId: 'f2',
		key: 'enc:k',
		login: 'enc:',
	},
	{
		id: 's4',
		name: 'Old',
		url: '',
		typeId: 't1',
		trashedAt: '2026-10-01T00:00:00Z',
		key: 'enc:o',
		login: 'enc:',
	},
	{ id: 's5', name: 'Blocked', url: '', typeId: 't1', blocked: true },
]
const TYPES = [
	{ id: 't1', name: 'login' },
	{ id: 't2', name: 'api_key' },
]
const FOLDERS = [
	{ id: 'f1', name: 'Work', parentId: null },
	{ id: 'f2', name: 'Clients', parentId: 'f1' },
]

/**
 * A fake API and key holder, and the handlers built on them.
 *
 * @param {object} [overrides] Replace parts of the fake API.
 * @return {{api: object, vault: object, handlers: object}}
 */
function setup(overrides = {}) {
	const api = {
		listSecrets: vi.fn(async () => ROWS),
		listFolders: vi.fn(async () => FOLDERS),
		listTypes: vi.fn(async () => TYPES),
		getSecret: vi.fn(async (c, id) => ROWS.find((r) => r.id === id)),
		createSecret: vi.fn(async () => ({ id: 'new-1' })),
		updateSecret: vi.fn(async () => ({})),
		trashSecret: vi.fn(async () => null),
		fetchPolicy: vi.fn(async () => null),
		createSend: vi.fn(async () => ({ id: 'send-1', token: 'tok' })),
		listSends: vi.fn(async () => [
			{
				id: 'send-1',
				token: 'tok',
				payloadType: 'credential',
				createdAt: '2026-10-02T09:00:00Z',
				maxViews: 1,
				viewCount: 0,
			},
		]),
		revokeSend: vi.fn(async () => null),
		publicBase: () => 'https://cloud.example/index.php/apps/keepiq/public',
		...overrides,
	}
	const vault = {
		unlocked: true,
		isUnlocked: () => vault.unlocked,
		activeSuiteId: () => 'suite-1',
		encryptField: vi.fn(async (id, plain) => 'enc:' + plain),
		decryptField: vi.fn(async (id, ciphertext) => ciphertext.slice(4)),
		decryptSecret: vi.fn(async (id, row) => ({
			login: row.login.slice(4),
			secret: row.key.slice(4),
		})),
	}
	const handlers = buildVaultHandlers({
		api,
		vault,
		activeAccount: async () => ACCOUNT,
		policyRefusalFor: async (config, value) =>
			value === 'weak' ? 'Too weak' : null,
	})
	return { api, vault, handlers }
}

describe('vault index', () => {
	it('leaves out trashed items, sorts by name and drops the blobs', () => {
		const index = buildIndex(ROWS, TYPES, FOLDERS)
		expect(index.map((e) => e.name)).toEqual([
			'Alpha mail',
			'Blocked',
			'Server key',
			'zebra bank',
		])
		for (const entry of index) {
			expect(entry).not.toHaveProperty('key')
			expect(entry).not.toHaveProperty('login')
		}
		expect(index.find((e) => e.id === 's5').blocked).toBe(true)
	})

	it('searches name and address, and filters by folder and type', () => {
		const index = buildIndex(ROWS, TYPES, FOLDERS)
		expect(filterIndex(index, { query: 'MAIL' }).map((e) => e.id)).toEqual([
			's1',
		])
		expect(
			filterIndex(index, { query: 'zebra.example' }).map((e) => e.id),
		).toEqual(['s2'])
		expect(filterIndex(index, { folderId: 'f2' }).map((e) => e.id)).toEqual([
			's3',
		])
		expect(filterIndex(index, { typeName: 'api_key' }).map((e) => e.id)).toEqual(
			['s3'],
		)
		expect(presentTypes(index)).toEqual(['api_key', 'login'])
	})

	it('shows folders as paths', () => {
		expect(folderChoices(FOLDERS)).toEqual([
			{ id: 'f1', label: 'Work' },
			{ id: 'f2', label: 'Work / Clients' },
		])
	})
})

describe('vault handlers', () => {
	it('lists index fields only, and refuses a locked vault', async () => {
		const { handlers, vault } = setup()
		const list = await handlers['vault-list']({})
		expect(list.items).toHaveLength(4)
		expect(JSON.stringify(list.items)).not.toContain('enc:')
		vault.unlocked = false
		await expect(handlers['vault-list']({})).rejects.toThrow('vault is locked')
	})

	it('fetches one item fresh and decrypts it, extra fields included, but never a blocked one', async () => {
		const { handlers, api } = setup({
			getSecret: vi.fn(async (c, id) =>
				id === 's2'
					? {
							...ROWS[0],
							additionalFields: 'enc:{"pin":"1234","notes":"hi"}',
							createdAt: '2026-10-01T10:00:00+00:00',
						}
					: {
							id: 's5',
							name: 'Blocked',
							blocked: true,
							blockedReason: 'Suite revoked',
						},
			),
		})
		const item = await handlers['vault-item']({ id: 's2' })
		expect(api.getSecret).toHaveBeenCalledWith(ACCOUNT, 's2')
		expect(item).toMatchObject({
			login: 'ann',
			secret: 'z',
			name: 'zebra bank',
			typeName: 'login',
			additionalFields: { pin: '1234', notes: 'hi' },
			createdAt: '2026-10-01T10:00:00+00:00',
		})
		const blocked = await handlers['vault-item']({ id: 's5' })
		expect(blocked).toMatchObject({
			blocked: true,
			blockedReason: 'Suite revoked',
		})
		expect(blocked).not.toHaveProperty('secret')
	})

	it('creates with every part encrypted, in the chosen folder', async () => {
		const { handlers, api } = setup()
		await handlers['vault-save']({
			typeId: 't1',
			changes: {
				name: ' New site ',
				url: 'https://new.example',
				login: 'me',
				key: 'S3cret!',
				folderId: 'f1',
				additionalFields: { pin: '9' },
			},
		})
		expect(api.createSecret).toHaveBeenCalledWith(ACCOUNT, {
			name: 'New site',
			url: 'https://new.example',
			folderId: 'f1',
			key: 'enc:S3cret!',
			login: 'enc:me',
			additionalFields: 'enc:{"pin":"9"}',
			encryptionSuiteId: 'suite-1',
			typeId: 't1',
		})
	})

	it('updates only the parts that changed', async () => {
		const { handlers, api } = setup()
		await handlers['vault-save']({
			id: 's1',
			changes: { url: 'https://x.example' },
		})
		expect(api.updateSecret).toHaveBeenLastCalledWith(ACCOUNT, 's1', {
			url: 'https://x.example',
		})
		await handlers['vault-save']({ id: 's1', changes: { key: 'n3w' } })
		expect(api.updateSecret).toHaveBeenLastCalledWith(ACCOUNT, 's1', {
			key: 'enc:n3w',
			encryptionSuiteId: 'suite-1',
		})
	})

	it('refuses a nameless item and a value the org policy refuses', async () => {
		const { handlers, api } = setup()
		await expect(
			handlers['vault-save']({ changes: { name: '  ', key: 'x' } }),
		).rejects.toThrow('Give the item a name')
		await expect(
			handlers['vault-save']({ changes: { name: 'A', key: 'weak' } }),
		).rejects.toThrow('Too weak')
		expect(api.createSecret).not.toHaveBeenCalled()
	})

	it('moves an item by changing only its folder', async () => {
		const { handlers, api } = setup()
		await handlers['vault-move']({ id: 's2', folderId: 'f2' })
		expect(api.updateSecret).toHaveBeenCalledWith(ACCOUNT, 's2', {
			folderId: 'f2',
		})
	})

	it('says what went wrong on a refused write', async () => {
		const failing = (status, body) =>
			vi.fn(async () => {
				throw Object.assign(new Error('x'), { status, body })
			})
		let { handlers } = setup({ updateSecret: failing(423) })
		await expect(handlers['vault-move']({ id: 's2' })).rejects.toThrow(
			'temporarily locked for a key migration',
		)
		;({ handlers } = setup({
			updateSecret: failing(400, '{"message":"Folder not found"}'),
		}))
		await expect(
			handlers['vault-save']({ id: 's2', changes: { url: 'u' } }),
		).rejects.toThrow('Folder not found')
		;({ handlers } = setup({
			updateSecret: vi.fn(async () => {
				throw new TypeError('Failed to fetch')
			}),
		}))
		await expect(
			handlers['vault-save']({ id: 's2', changes: { url: 'u' } }),
		).rejects.toThrow('Could not reach the server')
	})

	it('moves an item to the trash', async () => {
		const { handlers, api } = setup()
		await handlers['vault-trash']({ id: 's2' })
		expect(api.trashSecret).toHaveBeenCalledWith(ACCOUNT, 's2')
	})
})

describe('generator handlers', () => {
	it('suggests a 20-character password for a field, under the org policy', async () => {
		const { handlers } = setup({
			fetchPolicy: async () => ({
				policy_enabled: true,
				generator_min_length: 24,
				generator_require_digit: true,
			}),
		})
		const { value } = await handlers['generate-for-field']({})
		expect(value).toHaveLength(24)
		expect(value).toMatch(/[0-9]/)
	})

	it('still suggests one when the server does not answer', async () => {
		const { handlers } = setup({
			fetchPolicy: async () => {
				throw new Error('offline')
			},
		})
		expect((await handlers['generate-for-field']({})).value).toHaveLength(20)
	})
})

describe('send', () => {
	it('maps the expiry presets and bounds Custom to 720 hours', () => {
		expect(expirySeconds('3d')).toEqual({ ttlSeconds: 259200 })
		expect(expirySeconds('custom', 48)).toEqual({ ttlSeconds: 172800 })
		expect(expirySeconds('custom', 721)).toEqual({
			error: 'At most 720 hours (30 days)',
		})
		expect(maxViewsFrom(0).error).toBeTruthy()
		expect(maxViewsFrom(101).error).toBeTruthy()
		expect(maxViewsFrom(5)).toEqual({ maxViews: 5 })
	})

	it('serialises a credential as exactly two lines', () => {
		expect(credentialPayload('ann', 'p@ss')).toBe(
			'Username: ann\nPassword: p@ss',
		)
	})

	it('labels a row by kind and creation time', () => {
		expect(
			sendRowLabel(
				{ payloadType: 'credential', createdAt: '2026-10-02T09:00:00Z' },
				'en-GB',
			),
		).toMatch(/^Credential send, 2 Oct 2026/)
		expect(sendRowLabel({ payloadType: 'text', createdAt: 'nope' })).toBe(
			'Text send',
		)
	})

	it('sends only ciphertext, and the link alone decrypts it', async () => {
		const { handlers, api } = setup()
		const { link } = await handlers['send-create']({
			payloadType: 'credential',
			username: 'ann',
			password: 'p@ss',
			maxViews: 2,
			expiry: '1d',
		})
		const body = api.createSend.mock.calls[0][1]
		expect(body).toMatchObject({
			payloadType: 'credential',
			maxViews: 2,
			ttlSeconds: 86400,
			hasPassword: false,
		})
		expect(JSON.stringify(body)).not.toContain('p@ss')
		expect(link).toMatch(
			/^https:\/\/cloud\.example\/index\.php\/apps\/keepiq\/public\/send\/tok#k=/,
		)

		const key = await crypto.subtle.importKey(
			'raw',
			fromBase64Url(link.split('#k=')[1]),
			{ name: 'AES-GCM' },
			false,
			['decrypt'],
		)
		const plain = new TextDecoder().decode(
			await aesDecrypt(key, body.encryptedPayload),
		)
		expect(plain).toBe('Username: ann\nPassword: p@ss')
	})

	it('refuses an empty send and bad bounds before any request', async () => {
		const { handlers, api } = setup()
		await expect(
			handlers['send-create']({
				payloadType: 'text',
				text: ' ',
				maxViews: 1,
				expiry: '1h',
			}),
		).rejects.toThrow('nothing to send')
		await expect(
			handlers['send-create']({
				payloadType: 'text',
				text: 'x',
				maxViews: 1,
				expiry: 'custom',
				customHours: 721,
			}),
		).rejects.toThrow('720 hours')
		expect(api.createSend).not.toHaveBeenCalled()
	})

	it('wraps the key under a password: the link carries no key and the server no password', async () => {
		const { handlers, api } = setup()
		const { link, hasPassword } = await handlers['send-create']({
			payloadType: 'text',
			text: 'wifi: hunter2',
			maxViews: 1,
			expiry: '1h',
			sendPassword: 'correct horse',
		})
		const body = api.createSend.mock.calls[0][1]
		expect(hasPassword).toBe(true)
		expect(body).toMatchObject({ hasPassword: true })
		expect(body.wrappedKey).toBeTruthy()
		expect(body.argon2idSalt).toBeTruthy()
		expect(link).not.toContain('#k=')
		expect(JSON.stringify(body)).not.toContain('correct horse')
		expect(JSON.stringify(body)).not.toContain('hunter2')
	})

	it('lists and ends sends', async () => {
		const { handlers, api } = setup()
		const { sends } = await handlers['send-list']({})
		expect(sends[0]).toMatchObject({ id: 'send-1', payloadType: 'credential' })
		expect(sends[0]).not.toHaveProperty('token')
		await handlers['send-revoke']({ id: 'send-1' })
		expect(api.revokeSend).toHaveBeenCalledWith(ACCOUNT, 'send-1')
	})
})
