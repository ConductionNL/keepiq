import { readFileSync, writeFileSync } from 'node:fs'
import { describe, expect, it } from 'vitest'
import type { GeneratorPolicy } from './options'
import { generatePassword, type PasswordRequest } from './password'
import type { RandomInt } from './random'

const GOLDEN = new URL('./password.golden.json', import.meta.url)

/** A seeded linear congruential draw, so every run sees the same values. */
function seeded(seed: number): RandomInt {
	let state = seed >>> 0
	return (min, max) => {
		state = (Math.imul(state, 1664525) + 1013904223) >>> 0
		return min + (state % (max - min + 1))
	}
}

const policies: Record<string, GeneratorPolicy | null> = {
	none: null,
	floor: { minLength: 24, requireUpper: false, requireLower: false, requireDigit: false, requireSymbol: false, allowPassphrase: true },
	all: { minLength: 8, requireUpper: true, requireLower: true, requireDigit: true, requireSymbol: true, allowPassphrase: false },
	digitSymbol: { minLength: 12, requireUpper: false, requireLower: false, requireDigit: true, requireSymbol: true, allowPassphrase: true },
}
const exclusions: Record<string, string | undefined> = {
	none: undefined,
	letters: 'ABCDEFGHIJKLMNOPQRSTUVWXYZabcdefghijklmnopqrstuvwxyz',
	allButOne: 'ABCDEFGHIJKLMNOPQRSTUVWXYZabcdefghijklmnopqrstuvwxyz0123456789!@#$%^&*()-_=+[]{}|;:,.<>?',
	digitsSpecial: '0123456789!@#$%^&*()-_=+[]{}|;:,.<>?/',
}
const lengths: unknown[] = [undefined, 0, 7, 8, 16, 128, 129, 12.5, '20', Number.NaN]
const counts: unknown[] = [undefined, -1, 3, 2.7, 'x']
const flags = [undefined, false, true] as const

/**
 * A running FNV-1a digest (two 32-bit lanes) of the recorded lines. It only
 * detects change between runs, so it need not be a cryptographic hash.
 */
function digest() {
	let a = 0x811c9dc5
	let b = 0x01000193
	return {
		update(text: string) {
			for (let i = 0; i < text.length; i++) {
				const code = text.charCodeAt(i)
				a = Math.imul(a ^ code, 0x01000193) >>> 0
				b = Math.imul(b ^ code, 0x811c9dc5 | 1) >>> 0
			}
		},
		hex: () => a.toString(16).padStart(8, '0') + b.toString(16).padStart(8, '0'),
	}
}

/** Every request in the grid, as a stable label and the request. */
function* requests(): Generator<[string, PasswordRequest]> {
	let n = 0
	for (const length of lengths) {
		for (let mask = 0; mask < 81; mask++) {
			const pick = (shift: number) => flags[Math.floor(mask / 3 ** shift) % 3]
			for (const minDigits of counts) {
				const minSpecial = counts[n++ % counts.length]
				const request = {
					length, includeUppercase: pick(0), includeLowercase: pick(1), includeDigits: pick(2), includeSpecialCharacters: pick(3),
					minDigits, minSpecial, avoidAmbiguous: mask % 2 === 0,
				} as PasswordRequest
				yield [JSON.stringify(request, (_, v) => (v === undefined ? '(undefined)' : Number.isNaN(v) ? '(NaN)' : v)), request]
			}
		}
	}
}

function record(): Record<string, { cases: number; failures: Record<string, number>; digest: string }> {
	const out: Record<string, { cases: number; failures: Record<string, number>; digest: string }> = {}
	for (const [policyName, policy] of Object.entries(policies)) {
		for (const [excludedName, excluded] of Object.entries(exclusions)) {
			const hash = digest()
			const failures: Record<string, number> = {}
			let cases = 0
			for (const [label, request] of requests()) {
				const result = generatePassword({ ...request, excludedCharacters: excluded }, policy, seeded(cases))
				if (!result.ok) failures[result.failure] = (failures[result.failure] ?? 0) + 1
				hash.update(`${label}=${JSON.stringify(result)}\n`)
				cases++
			}
			out[`${policyName}/${excludedName}`] = { cases, failures, digest: hash.hex() }
		}
	}
	return out
}

describe('generatePassword golden record', () => {
	it('gives the recorded value or refusal for every request in the grid', () => {
		const got = record()
		if (process.env.KEEPIQ_RECORD_GOLDEN === '1') writeFileSync(GOLDEN, JSON.stringify(got, null, '\t') + '\n')
		expect(got).toEqual(JSON.parse(readFileSync(GOLDEN, 'utf8')))
	}, 30_000)
})
