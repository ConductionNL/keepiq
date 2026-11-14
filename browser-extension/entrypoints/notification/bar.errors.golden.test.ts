import { readFileSync, writeFileSync } from 'node:fs'
import { resolve } from 'node:path'
import { describe, expect, it } from 'vitest'
import type { VaultWriteError, WriteErrorCode } from '@/src/messages'
import { setLocale, type Locale } from '@/src/testing/i18n'
import { writeErrorText } from './bar'

// From the package root, as vitest runs; `KEEPIQ_RECORD_GOLDEN=1` rewrites the record.
const GOLDEN = resolve(process.cwd(), 'entrypoints/notification/bar.errors.golden.json')

// Typed per code, so a new WriteErrorCode does not compile until it is recorded here.
const KNOWN = {
	locked: 0, session_revoked: 0, write_locked: 0, forbidden: 0, policy: 0, invalid: 0, conflict: 0, not_found: 0, blocked: 0, unreadable: 0,
	offline: 0, server_error: 0, unknown: 0, restricted: 0, view_only: 0, key_mismatch: 0, account_changed: 0, stale_source: 0,
	share_scan_pending: 0, incomplete: 0, bad_certificate: 0, unverified: 0, partner_unreachable: 0, partner_upgrade_required: 0,
	partner_lookup_limit: 0, federation_unavailable: 0, keepiq_outdated: 0, partner_clock: 0, passkey_clone: 0, argon2_unavailable: 0,
} satisfies Record<WriteErrorCode, 0>

const CODES = [...Object.keys(KNOWN), 'a_code_from_a_newer_background', 'toString', '__proto__', 'constructor']

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
	if (process.env.KEEPIQ_RECORD_GOLDEN === '1') {
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
