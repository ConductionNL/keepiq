// Tests for @conduction/keepiq-sdk against the shared vectors in sdk/testdata
// and a stub Keepiq. KEEPIQ_WRITE_VECTORS=1 rewrites encrypted_by_js.json.
import { generateKeyPairSync } from 'node:crypto'
import { existsSync, readFileSync, writeFileSync } from 'node:fs'
import { dirname, join } from 'node:path'
import { fileURLToPath } from 'node:url'
import { afterEach, beforeEach, describe, expect, it } from 'vitest'

import { AmbiguousNameError, Client, KeyMismatchError, NotFoundError, NotModifiedError, UnauthorizedError } from '../src/index.js'
import { decryptField, encryptField, importKeySet, unwrapPrivateKey } from '../src/crypto.js'
import { Stub } from './stub.js'

const TESTDATA = join(dirname(fileURLToPath(import.meta.url)), '..', '..', 'testdata')
const load = (name: string) => JSON.parse(readFileSync(join(TESTDATA, name), 'utf8'))
const FIXTURE = load('machine_envelope.json')

describe('Client', () => {
	let stub: Stub
	let url: string
	const client = (opts = {}) => new Client(url, 'billing', FIXTURE.privateKeyPem, opts)

	beforeEach(async () => {
		stub = new Stub(FIXTURE)
		url = await stub.start()
	})
	afterEach(() => stub.stop())

	it('reads by name and decrypts the server envelope in process, caching the token', async () => {
		const c = client()
		const s = await c.getByName('ci-fixture-db-password')
		expect(s.key).toBe(FIXTURE.plaintext.key)
		expect(s.login).toBe(FIXTURE.plaintext.login)
		expect(s.additionalFields).toBe(FIXTURE.plaintext.additionalFields)
		expect(s.folderPath).toBe('ci/database')
		expect(s.lease).toEqual({ id: 'lease-7', expires: '2026-10-02T13:00:00+00:00' })
		await c.getById('sec-cli-fixture')
		expect(stub.exchanges).toBe(1)
	})

	it('reports not modified on an unchanged second read', async () => {
		const c = client()
		expect((await c.getById('sec-cli-fixture')).etag).toBeTruthy()
		await expect(c.getById('sec-cli-fixture')).rejects.toBeInstanceOf(NotModifiedError)
	})

	it('raises an ambiguous-name error with both candidates', async () => {
		const twin = stub.newEnvelope('sec-twin', { name: 'ci-fixture-db-password' })
		twin.secret.folderPath = 'other'
		stub.envelopes.set('sec-twin', twin)
		const err = await client().getByName('ci-fixture-db-password').catch((e) => e)
		expect(err).toBeInstanceOf(AmbiguousNameError)
		expect(Object.fromEntries(err.candidates.map((c: { id: string, folderPath: string }) => [c.id, c.folderPath])))
			.toEqual({ 'sec-cli-fixture': 'ci/database', 'sec-twin': 'other' })
	})

	it('raises not found', async () => {
		await expect(client().getByName('nope')).rejects.toBeInstanceOf(NotFoundError)
	})

	it('raises unauthorized for a key the server does not know', async () => {
		const { privateKey } = generateKeyPairSync('rsa', { modulusLength: 2048 })
		const pem = privateKey.export({ type: 'pkcs1', format: 'pem' }) as string
		await expect(new Client(url, 'billing', pem).getById('sec-cli-fixture')).rejects.toBeInstanceOf(UnauthorizedError)
	})

	it('renews a revoked token once', async () => {
		const c = client()
		await c.getById('sec-cli-fixture')
		stub.revokeNext = true
		await c.list()
		expect(stub.exchanges).toBe(2)
	})

	it('sends only ciphertext on create and update, and reads back the new value', async () => {
		const c = client()
		const created = await c.create({ name: 'stripe-key', key: 'sk_live_first', login: 'billing-bot' })
		expect(created.key).toBe('sk_live_first')
		await c.update(created.id, { key: 'YOUR_TOKEN_HERE' })
		for (const body of stub.bodies) {
			for (const plain of ['sk_live_first', 'billing-bot', 'YOUR_TOKEN_HERE', 'PRIVATE KEY']) {
				expect(body).not.toContain(plain)
			}
		}
		const got = await c.getById(created.id)
		expect(got.key).toBe('YOUR_TOKEN_HERE')
		expect(got.login).toBe('billing-bot')
		await expect(c.create({ name: 'x', password: 'y' } as never)).rejects.toThrow(/unknown field/)
	})

	it('filters the list on updatedSince', async () => {
		stub.envelopes.set('sec-new', stub.newEnvelope('sec-new', { name: 'n' }))
		const c = client()
		expect(await c.list()).toHaveLength(2)
		expect((await c.list(new Date('2026-10-01T00:00:00Z'))).map((s) => s.id)).toEqual(['sec-new'])
	})

	it('refuses an envelope for another certificate before decrypting', async () => {
		stub.envelopes.get('sec-cli-fixture')!.encryption.certificateFingerprint = 'sha256:00'
		await expect(client({ certificatePem: FIXTURE.certificatePem }).getById('sec-cli-fixture')).rejects.toBeInstanceOf(KeyMismatchError)
	})

	it('accepts the matching certificate', async () => {
		const s = await client({ certificatePem: FIXTURE.certificatePem }).getById('sec-cli-fixture')
		expect(s.key).toBe(FIXTURE.plaintext.key)
	})
})

describe('shared vectors', () => {
	it('decrypts the PHP serializer envelope', async () => {
		const keys = await importKeySet(FIXTURE.privateKeyPem)
		for (const [name, plain] of Object.entries(FIXTURE.plaintext)) {
			expect(await decryptField(FIXTURE.envelope.ciphertext[name], keys.decrypt)).toBe(plain)
		}
	})

	it('decrypts the browser vector', async () => {
		let env = JSON.parse(readFileSync(join(TESTDATA, 'webcrypto_envelope.json'), 'utf8'))
		if (typeof env === 'string') env = JSON.parse(env)
		const keys = await importKeySet(await unwrapPrivateKey(env.blobB64, env.masterPw))
		expect(await decryptField(env.fieldB64, keys.decrypt)).toBe(env.plaintext)
	})

	it('decrypts every library vector present', async () => {
		const keys = await importKeySet(FIXTURE.privateKeyPem)
		for (const lang of ['go', 'python', 'js']) {
			if (!existsSync(join(TESTDATA, `encrypted_by_${lang}.json`))) continue
			const vec = load(`encrypted_by_${lang}.json`)
			for (const [name, plain] of Object.entries(vec.plaintext)) {
				expect(await decryptField(vec.ciphertext[name], keys.decrypt), `${lang} ${name}`).toBe(plain)
			}
		}
	})

	it('writes and checks the TypeScript vector', async () => {
		const keys = await importKeySet(FIXTURE.privateKeyPem)
		const path = join(TESTDATA, 'encrypted_by_js.json')
		if (process.env.KEEPIQ_WRITE_VECTORS === '1') {
			const plaintext = load('vector_plaintexts.json').plaintext as Record<string, string>
			const ciphertext: Record<string, string> = {}
			for (const [k, v] of Object.entries(plaintext)) ciphertext[k] = await encryptField(v, keys.encrypt)
			writeFileSync(path, JSON.stringify({
				_comment: 'TEST ONLY. Values encrypted by TypeScript (sdk/js) to the machine_envelope.json test key. Decrypted by every library and by DecryptService in tests/Unit/Service/SdkEncryptedVectorsTest.php.',
				plaintext,
				ciphertext,
			}, null, 4) + '\n')
		}
		expect(existsSync(path), 'write it with KEEPIQ_WRITE_VECTORS=1').toBe(true)
		const vec = load('encrypted_by_js.json')
		expect(vec.plaintext).toEqual(load('vector_plaintexts.json').plaintext)
		for (const [name, plain] of Object.entries(vec.plaintext)) {
			expect(await decryptField(vec.ciphertext[name], keys.decrypt)).toBe(plain)
		}
	})

	it('chunks like the server: 446 bytes per 512-byte block', async () => {
		const keys = await importKeySet(FIXTURE.privateKeyPem)
		for (const value of ['s3cr3t', 'x'.repeat(1000), '', 'é 🔑']) {
			const raw = Buffer.from(await encryptField(value, keys.encrypt), 'base64')
			const chunks = Math.max(1, Math.ceil(Buffer.byteLength(value) / 446))
			expect(raw.readUInt32BE(0)).toBe(chunks)
			expect(raw.length).toBe(4 + chunks * 512)
			expect(await decryptField(raw.toString('base64'), keys.decrypt)).toBe(value)
		}
	})
})
