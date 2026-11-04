import { readFileSync, writeFileSync } from 'node:fs'
import { resolve } from 'node:path'
import { describe, expect, it } from 'vitest'
import type { VaultWriteError } from '@/src/messages'
import { setLocale, type Locale } from '@/src/testing/i18n'
import { writeErrorText, type WriteContext } from './errors'

// From the package root, as vitest runs; `RECORD_GOLDEN=1` rewrites the record.
const GOLDEN = resolve(process.cwd(), 'entrypoints/popup/errors.golden.json')

const CODES = [
	'locked', 'session_revoked', 'write_locked', 'forbidden', 'policy', 'invalid', 'conflict', 'not_found', 'blocked', 'unreadable',
	'offline', 'server_error', 'unknown', 'restricted', 'view_only', 'key_mismatch', 'account_changed', 'stale_source', 'incomplete',
	'bad_certificate', 'unverified', 'partner_unreachable', 'partner_lookup_limit', 'federation_unavailable', 'keepiq_outdated',
	'partner_clock', 'passkey_clone', 'argon2_unavailable', 'a_code_from_a_newer_background',
]
const CONTEXTS: Array<WriteContext | undefined> = [undefined, 'item', 'edit', 'open', 'folderName', 'folderDelete', 'send']

const ERRORS: Record<string, VaultWriteError | undefined> = {
	'no answer': undefined,
	...Object.fromEntries(CODES.map((code) => [code, { code } as VaultWriteError])),
	...Object.fromEntries(['suite_missing', 'suite_revoked', 'suite_compromised', 'migration_failed'].map((reason) => [`blocked ${reason}`, { code: 'blocked', reason } as VaultWriteError])),
	'partners none': { code: 'partner_unreachable', partners: [] },
	'partners one': { code: 'partner_unreachable', partners: [{ shareId: 's1', cloudId: 'bob@other.example' }] },
	'partners three': { code: 'partner_unreachable', partners: ['a', 'b', 'c'].map((name) => ({ shareId: name, cloudId: `${name}@other.example` })) },
}

function texts(locale: Locale): Record<string, Record<string, string>> {
	setLocale(locale)
	return Object.fromEntries(Object.entries(ERRORS).map(([name, error]) => [name, Object.fromEntries(CONTEXTS.map((context) => [
		context ?? '(default)',
		context === undefined ? writeErrorText(error) : writeErrorText(error, context),
	]))]))
}

describe('writeErrorText golden', () => {
	if (process.env.RECORD_GOLDEN === '1') {
		it('records every code in every context', () => {
			writeFileSync(GOLDEN, JSON.stringify({ en: texts('en'), nl: texts('nl') }, null, '\t') + '\n')
		})
		return
	}

	const golden = JSON.parse(readFileSync(GOLDEN, 'utf8')) as Record<Locale, Record<string, Record<string, string>>>

	for (const locale of ['en', 'nl'] as const) {
		for (const name of Object.keys(ERRORS)) {
			it(`${locale}: ${name}`, () => {
				expect(texts(locale)[name]).toEqual(golden[locale][name])
			})
		}
	}

	it('covers every recorded case', () => {
		expect(Object.keys(golden.en).sort()).toEqual(Object.keys(ERRORS).sort())
	})
})
