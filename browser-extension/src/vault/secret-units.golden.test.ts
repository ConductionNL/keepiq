import { readFileSync, writeFileSync } from 'node:fs'
import { resolve } from 'node:path'
import { describe, expect, it } from 'vitest'
import { generatePassphrase } from '../generator/passphrase'
import { parseTotpSeed } from '../totp/totp'
import { shownInFull } from './fields'

// Synthetic values only. From the package root, as vitest runs; `RECORD_GOLDEN=1` rewrites the record.
const GOLDEN = resolve(process.cwd(), 'src/vault/secret-units.golden.json')

const attempt = (run: () => unknown) => {
	try {
		return run()
	} catch (error) {
		return { threw: (error as Error).constructor.name, message: (error as Error).message }
	}
}

const SEED = 'GEZDGNBVGY3TQOJQ'
const SEEDS = [
	'', '   ', SEED, ` ${SEED.toLowerCase()} `, 'GEZD GNBV', '!!!!', 'otpauth://', 'OTPAUTH://TOTP/x?secret=' + SEED, 'otpauth://hotp/x?secret=' + SEED,
	'otpauth://totp/x', 'otpauth://totp/x?secret=', 'otpauth://totp/x?secret=!!!!', `otpauth://totp/?secret=${SEED}`, `otpauth://totp/acct?secret=${SEED.toLowerCase()}`,
	`otpauth://totp/Iss:acct?secret=${SEED}`, `otpauth://totp/Iss:acct:more?secret=${SEED}`, `otpauth://totp/Iss:?secret=${SEED}`, `otpauth://totp/:acct?secret=${SEED}`,
	`otpauth://totp/Iss%3Aacct?secret=${SEED}&issuer=Param`, `otpauth://totp/acct?secret=${SEED}&issuer=Param`, `otpauth://totp/acct?secret=${SEED}&issuer=`, `otpauth://totp/%E0%A4%A?secret=${SEED}`,
	`otpauth://totp/x?secret=${SEED}&algorithm=sha256`, `otpauth://totp/x?secret=${SEED}&algorithm=SHA512`, `otpauth://totp/x?secret=${SEED}&algorithm=MD5`, `otpauth://totp/x?secret=${SEED}&algorithm=`,
	`otpauth://totp/x?secret=${SEED}&digits=8`, `otpauth://totp/x?secret=${SEED}&digits=7`, `otpauth://totp/x?secret=${SEED}&digits=0`, `otpauth://totp/x?secret=${SEED}&digits=a`,
	`otpauth://totp/x?secret=${SEED}&period=60`, `otpauth://totp/x?secret=${SEED}&period=0`, `otpauth://totp/x?secret=${SEED}&period=-1`, `otpauth://totp/x?secret=${SEED}&period=b`,
	`otpauth://totp/x?secret=!!!!&algorithm=MD5`, `otpauth://user@totp/x?secret=${SEED}`,
]

const FIELDS = ['login', 'key', 'additionalFields'] as const
const TYPES = [undefined, 'login', 'api_key', 'note', 'totp', 'card', 'identity', 'passkey', 'other']
const PLAINTEXTS = [
	null, '', 'plain', '{', '[]', '{}', '"x"', '{"a":"b"}', '{"a":"b","a":"c"}', '{"a":{"b":1,"b":2}}', '{"a":[{"b":1},{"b":1}]}', '{"a":1.50}', '{"a":12345678901234567890}', '{"a":1,"b":[2,3]}',
	'{"cardholderName":"N","number":"4111","expMonth":"01","expYear":"2030","code":"1"}', '{"cardholderName":"N","cardholderName":"M"}', '{"expMonth":1}', '{"expMonth":1.0}', '{"unknown":"x"}', '{"number":null}',
	'{"title":"T","firstName":"F"}', '{"firstName":"F","toString":"x"}', '{"notes":"n"}', '{"notes":"n","custom":"c"}', '{"custom":"c"}', '{"a\\"b":"c","a\\"b":"d"}', '{"a":"1,2:3"}', '{"":"e"}', '[{"a":1,"a":2}]', '{"a":-1e3}', '{"a":true}',
	'{"number":"4111","expiry":"12/30"}', '{"number":4111}', '{"toString":"x"}', '{"cvv":"1","cvv":"2"}', '{"firstName":"F","bsn":"1"}', '{"firstName":5}', '{"number":"1","firstName":"F"}',
	'{"a":["b","b"]}', '{"a":["b","c","c"]}', '{"a":{"b":1},"a":2}', '{"a":{"x":1},"x":2}', '{"a":"a"}',
]

const WORDS = Array.from({ length: 40 }, (_, n) => `word${String.fromCharCode(97 + n % 26)}${n}`)
/** The same draws on every run, and every draw is recorded. */
function drawn() {
	let state = 12345
	const calls: number[][] = []
	const rand = (min: number, max: number) => {
		calls.push([min, max])
		state = (state * 1103515245 + 12345) % 2147483648
		return min + state % (max - min + 1)
	}
	return { rand, calls }
}
const POLICY = { minLength: 0, requireUpper: false, requireLower: false, requireDigit: false, requireSymbol: false, allowPassphrase: true }
const PHRASES: Array<[Record<string, unknown>, Record<string, unknown> | null]> = [
	[{}, null], [{ words: 3 }, null], [{ words: '4' }, null], [{ words: 2 }, null], [{ words: 1 }, null], [{ words: 0 }, null], [{ words: 21 }, null], [{ words: 12 }, null], [{ words: 13 }, null], [{ words: 4.5 }, null], [{ words: 3.5 }, null], [{ words: 'x' }, null],
	[{ separator: '_' }, null], [{ separator: '' }, null], [{ capitalise: true }, null], [{ capitalise: 'yes' }, null], [{ includeNumber: true }, null], [{ includeNumber: 1 }, null], [{ capitalise: true, includeNumber: true, words: 5, separator: '.' }, null],
	[{ separator: 'a' }, { ...POLICY, requireSymbol: true }], [{}, { ...POLICY, minLength: 37 }],
	[{}, POLICY], [{}, { ...POLICY, allowPassphrase: false }], [{ words: 0 }, { ...POLICY, allowPassphrase: false }], [{}, { ...POLICY, requireUpper: true }], [{}, { ...POLICY, requireDigit: true }], [{}, { ...POLICY, requireSymbol: true }],
	[{ separator: '!' }, { ...POLICY, requireSymbol: true }], [{ separator: 'a!' }, { ...POLICY, requireSymbol: true }], [{}, { ...POLICY, minLength: 80 }], [{ words: 4, separator: '' }, { ...POLICY, minLength: 200 }], [{ capitalise: true, includeNumber: true }, { ...POLICY, requireUpper: true, requireDigit: true, requireSymbol: true, minLength: 40 }],
]

const record = () => ({
	parseTotpSeed: SEEDS.map((seed) => [seed, attempt(() => parseTotpSeed(seed))]),
	shownInFull: PLAINTEXTS.flatMap((plaintext) => TYPES.flatMap((type) => FIELDS.map((field) => [field, plaintext, type ?? '(none)', attempt(() => shownInFull(field, plaintext, type))]))),
	generatePassphrase: PHRASES.map(([options, policy]) => {
		const { rand, calls } = drawn()
		return [options, policy, attempt(() => generatePassphrase(options as never, policy as never, WORDS, rand)), calls]
	}),
})

describe('secret units golden', () => {
	const got = record()
	if (process.env.RECORD_GOLDEN === '1') writeFileSync(GOLDEN, JSON.stringify(got, null, '\t') + '\n')
	const want = JSON.parse(readFileSync(GOLDEN, 'utf8')) as typeof got
	for (const name of Object.keys(want) as Array<keyof typeof got>) {
		it(`${name} matches the record`, () => {
			const rows = JSON.parse(JSON.stringify(got[name])) as unknown[]
			expect(rows.length).toBe(want[name].length)
			rows.forEach((row, index) => expect(row, String(index)).toEqual(want[name][index]))
		})
	}
})
