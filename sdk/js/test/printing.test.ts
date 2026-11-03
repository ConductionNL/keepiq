// console.log and util.inspect of a client or a secret show no key, token or value.
import { readFileSync } from 'node:fs'
import { dirname, join } from 'node:path'
import { fileURLToPath } from 'node:url'
import { format, inspect } from 'node:util'
import { afterEach, beforeEach, describe, expect, it } from 'vitest'

import { Client } from '../src/index.js'
import { Stub } from './stub.js'

const TESTDATA = join(dirname(fileURLToPath(import.meta.url)), '..', '..', 'testdata')
const FIXTURE = JSON.parse(readFileSync(join(TESTDATA, 'machine_envelope.json'), 'utf8'))
const FIELDS = { key: 'value-k3y', login: 'login-n4me', additionalFields: '{"pin":"pin-9137"}' }
const VALUES = [FIELDS.key, FIELDS.login, FIELDS.additionalFields]
const PEM_BODY = FIXTURE.privateKeyPem.split('\n')[3]

const printed = (value: unknown): string[] => [
	inspect(value),
	inspect(value, { depth: Infinity, showHidden: true }),
	inspect([value]),
	inspect({ nested: { value } }, { depth: Infinity }),
	format('%o', value),
	format('%O', value),
	format('%s', value),
	String(value),
]

describe('printed output', () => {
	let stub: Stub
	let url: string

	beforeEach(async () => {
		stub = new Stub(FIXTURE)
		url = await stub.start()
	})
	afterEach(() => stub.stop())

	it('shows a client without its private key or token', async () => {
		const c = new Client(url, 'billing', FIXTURE.privateKeyPem)
		await c.getById('sec-cli-fixture')
		expect(stub.exchanges).toBe(1)
		expect(PEM_BODY.length).toBeGreaterThan(20)
		for (const text of [...printed(c), JSON.stringify(c), JSON.stringify(Object.values(c))]) {
			expect(text).not.toContain('tok-1')
			expect(text).not.toContain('PRIVATE KEY')
			expect(text).not.toContain(PEM_BODY)
		}
		expect(inspect(c)).toContain("applicationId: 'billing'")
	})

	const stored = async () => {
		const c = new Client(url, 'billing', FIXTURE.privateKeyPem)
		const created = await c.create({ name: 'db', ...FIELDS })
		return [created, await c.getById(created.id), ...(await c.list())]
	}

	it('shows a secret with its values redacted and its metadata intact', async () => {
		const secrets = await stored()
		expect(secrets.length).toBe(4)
		for (const s of secrets) {
			for (const text of printed(s)) {
				for (const value of [...VALUES, '9137']) expect(text).not.toContain(value)
			}
		}
		const text = inspect(secrets[1])
		expect(text).toContain("key: '[redacted]'")
		expect(text).toContain("login: '[redacted]'")
		expect(text).toContain("additionalFields: '[redacted]'")
		expect(text).toContain("id: 'sec-new-1'")
		expect(text).toContain("name: 'db'")
		expect(text).toContain('etag:')
		expect(text).toContain("lease: { id: 'lease-7'")
		const fixture = inspect(secrets.find((s) => s.id === 'sec-cli-fixture'))
		expect(fixture).toContain("folderPath: 'ci/database'")
		expect(fixture).not.toContain(FIXTURE.plaintext.login)
	})

	it('keeps the values in the properties, a spread copy and JSON', async () => {
		const s = (await stored())[1]
		expect([s.key, s.login, s.additionalFields]).toEqual(VALUES)
		const json = JSON.parse(JSON.stringify(s))
		expect([json.key, json.login, json.additionalFields]).toEqual(VALUES)
		const copy = { ...s }
		expect([copy.key, copy.login, copy.additionalFields]).toEqual(VALUES)
		expect(Object.keys(s)).toEqual(Object.keys(copy))
		expect(s).toEqual(copy)
		expect(inspect(copy)).toContain(FIELDS.login)
	})
})
