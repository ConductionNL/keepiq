import { readFileSync, writeFileSync } from 'node:fs'
import { resolve } from 'node:path'
import { describe, expect, it } from 'vitest'
import type { VaultWriteError } from '@/src/messages'
import { setLocale, type Locale } from '@/src/testing/i18n'
import { writeErrorText } from './bar'

// From the package root, as vitest runs; `RECORD_GOLDEN=1` rewrites the record.
const GOLDEN = resolve(process.cwd(), 'entrypoints/notification/bar.errors.golden.json')

const CODES = [
	'locked', 'session_revoked', 'write_locked', 'forbidden', 'policy', 'invalid', 'conflict', 'not_found', 'blocked', 'unreadable',
	'offline', 'server_error', 'unknown', 'restricted', 'view_only', 'key_mismatch', 'account_changed', 'stale_source', 'incomplete',
	'bad_certificate', 'unverified', 'partner_unreachable', 'partner_lookup_limit', 'federation_unavailable', 'keepiq_outdated',
	'partner_clock', 'passkey_clone', 'argon2_unavailable', 'a_code_from_a_newer_background', 'toString', '__proto__', 'constructor',
]

const ERRORS: Record<string, VaultWriteError | undefined> = {
	'no answer': undefined,
	'code missing': {} as VaultWriteError,
	...Object.fromEntries(CODES.map((code) => [code, { code } as VaultWriteError])),
}

function texts(locale: Locale): Record<string, Record<string, string>> {
	setLocale(locale)
	return Object.fromEntries(Object.entries(ERRORS).map(([name, error]) => [name, {
		add: writeErrorText(error, 'add'),
		update: writeErrorText(error, 'update'),
	}]))
}

describe('notification bar writeErrorText golden', () => {
	if (process.env.RECORD_GOLDEN === '1') {
		it('records every code in both modes', () => {
			writeFileSync(GOLDEN, JSON.stringify({ en: texts('en'), nl: texts('nl') }, null, '\t') + '\n')
		})
		return
	}

	const golden = JSON.parse(readFileSync(GOLDEN, 'utf8')) as Record<Locale, Record<string, Record<string, string>>>

	for (const locale of ['en', 'nl'] as const) {
		it(`${locale}: every code in both modes`, () => {
			const got = texts(locale)
			for (const name of Object.keys(ERRORS)) expect(got[name], name).toEqual(golden[locale][name])
			expect(Object.keys(golden[locale])).toEqual(Object.keys(ERRORS))
		})
	}
})
