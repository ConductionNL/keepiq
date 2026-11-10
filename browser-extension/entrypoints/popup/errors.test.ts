import { describe, expect, it } from 'vitest'
import { i18n } from '#i18n'
import { MIN_KEEPIQ_VERSION } from '@/src/api/keepiq-version'
import { MAX_ACCOUNTS, type BlockReason, type ErrorCode, type VaultWriteError, type WriteErrorCode } from '@/src/messages'
import { LOCALES, setLocale } from '@/src/testing/i18n'
import { errorText, writeErrorText, type WriteContext } from './errors'

const t = i18n.t as (key: string, substitutions?: Record<string, string>) => string

const CONTEXTS = Object.keys({ item: 0, edit: 0, open: 0, folderName: 0, folderDelete: 0, send: 0, passkey: 0, delete: 0 } satisfies Record<WriteContext, 0>) as WriteContext[]

/** The catalog key a code reads as, or one per context where they differ with `other` for the rest. */
type Expected = string | (Partial<Record<WriteContext, string>> & { other: string })

// Typed per code, so a new WriteErrorCode does not compile until it has an expectation here.
const EXPECTED = {
	locked: 'errors.unknown',
	unknown: 'errors.unknown',
	session_revoked: 'errors.sessionRevoked',
	write_locked: 'writeErrors.writeLocked',
	forbidden: 'writeErrors.forbidden',
	policy: 'writeErrors.policy',
	account_changed: { delete: 'writeErrors.accountChangedDelete', other: 'writeErrors.accountChanged' },
	invalid: { send: 'writeErrors.sendInvalid', folderDelete: 'writeErrors.folderChanged', other: 'writeErrors.invalid' },
	conflict: {
		passkey: 'passkey.errors.conflict', edit: 'writeErrors.stale', open: 'writeErrors.unmatched', folderName: 'writeErrors.duplicateFolder',
		folderDelete: 'writeErrors.folderChanged', other: 'writeErrors.invalid',
	},
	not_found: { folderName: 'writeErrors.folderGone', folderDelete: 'writeErrors.folderGone', other: 'writeErrors.itemGone' },
	blocked: 'blocked.generic',
	unreadable: 'writeErrors.unreadable',
	restricted: 'writeErrors.restricted',
	view_only: 'item.viewOnly',
	key_mismatch: 'writeErrors.keyMismatch',
	offline: { send: 'writeErrors.sendOffline', other: 'writeErrors.offline' },
	server_error: 'writeErrors.serverError',
	keepiq_outdated: 'writeErrors.keepiqOutdated',
	stale_source: 'writeErrors.staleSource',
	incomplete: 'writeErrors.incomplete',
	bad_certificate: 'writeErrors.badCertificate',
	unverified: 'writeErrors.unverified',
	partner_unreachable: 'writeErrors.partnerUnreachable',
	partner_upgrade_required: { passkey: 'passkey.errors.partnerUpgradeRequiredUnnamed', other: 'writeErrors.partnerUpgradeRequiredUnnamed' },
	partner_lookup_limit: 'writeErrors.partnerLookupLimit',
	federation_unavailable: 'writeErrors.federationUnavailable',
	partner_clock: 'writeErrors.partnerClock',
	passkey_clone: 'item.passkeyNoClone',
	argon2_unavailable: 'writeErrors.argon2Unavailable',
	share_scan_pending: {
		open: 'writeErrors.shareScanPendingOpen', delete: 'writeErrors.shareScanPendingDelete', passkey: 'passkey.errors.shareScanPending', other: 'writeErrors.shareScanPending',
	},
} satisfies Record<WriteErrorCode, Expected>

const CODES = Object.keys(EXPECTED) as WriteErrorCode[]

function expectedText(code: WriteErrorCode, context: WriteContext): string {
	const expected: Expected = EXPECTED[code]
	const key = typeof expected === 'string' ? expected : expected[context] ?? expected.other
	// A bare code names no partners.
	return t(key, { cloudIds: '', version: MIN_KEEPIQ_VERSION })
}

const partners = (...cloudIds: string[]) => cloudIds.map((cloudId, index) => ({ shareId: `s${index}`, cloudId }))

describe.each(LOCALES)('writeErrorText in %s', (locale) => {
	it.each(CODES)('reads %s as its catalog text in every context', (code) => {
		setLocale(locale)
		for (const context of CONTEXTS) {
			const text = writeErrorText({ code }, context)
			expect(text.trim(), context).not.toBe('')
			expect(text, context).toBe(expectedText(code, context))
		}
	})
})

describe('writeErrorText', () => {
	it('reads every code as an item write when no context is given', () => {
		for (const code of CODES) expect(writeErrorText({ code })).toBe(writeErrorText({ code }, 'item'))
	})

	it.each([['no answer', undefined], ['a null error', null], ['an error without a code', {}]])('reads %s from the background as not responding', (_, error) => {
		for (const context of CONTEXTS) expect(writeErrorText(error as VaultWriteError | undefined, context)).toBe(t('errors.notResponding'))
	})

	it('reads a code from a newer background as unknown', () => {
		for (const context of CONTEXTS) expect(writeErrorText({ code: 'from_a_newer_background' } as unknown as VaultWriteError, context)).toBe(t('errors.unknown'))
	})

	it.each(['constructor', 'toString', '__proto__'])('reads a code named like an object property, %s, as unknown', (code) => {
		for (const context of CONTEXTS) expect(writeErrorText({ code } as unknown as VaultWriteError, context)).toBe(t('errors.unknown'))
	})

	it('names the Keepiq version the user\'s server needs', () => {
		expect(writeErrorText({ code: 'keepiq_outdated' })).toContain(MIN_KEEPIQ_VERSION)
	})

	it.each(Object.entries({
		suite_missing: 'blocked.suiteMissing', suite_revoked: 'blocked.suiteRevoked', suite_compromised: 'blocked.suiteCompromised', migration_failed: 'blocked.migrationFailed',
	} satisfies Record<BlockReason, string>))('says a row is blocked for %s when the server gave that reason', (reason, key) => {
		expect(t(key)).not.toBe('')
		expect(writeErrorText({ code: 'blocked', reason: reason as BlockReason })).toBe(t(key))
	})

	it('names the partners that could not be reached', () => {
		expect(writeErrorText({ code: 'partner_unreachable', partners: partners('bob@other.example', 'carol@third.example') })).toContain('bob@other.example and carol@third.example')
	})

	it.each([
		['en', 'bob@other.example, carol@third.example, and dave@fourth.example'],
		['nl', 'bob@other.example, carol@third.example en dave@fourth.example'],
	] as const)('joins the partners in the words of %s', (locale, list) => {
		setLocale(locale)
		expect(writeErrorText({ code: 'partner_unreachable', partners: partners('bob@other.example', 'carol@third.example', 'dave@fourth.example') })).toContain(`(${list})`)
	})

	it('uses the passkey wording for a conflict in the passkey window only', () => {
		expect(writeErrorText({ code: 'conflict' }, 'passkey')).toBe(t('passkey.errors.conflict'))
		for (const context of CONTEXTS.filter((context) => context !== 'passkey')) expect(writeErrorText({ code: 'conflict' }, context)).not.toBe(t('passkey.errors.conflict'))
	})

	describe('partner_upgrade_required', () => {
		const named = { code: 'partner_upgrade_required', partners: partners('bob@other.example', 'carol@third.example') } satisfies VaultWriteError
		const cloudIds = 'bob@other.example and carol@third.example'

		it('names the partners, in the passkey window with its own wording', () => {
			expect(writeErrorText(named)).toBe(t('writeErrors.partnerUpgradeRequired', { cloudIds }))
			expect(writeErrorText(named, 'passkey')).toBe(t('passkey.errors.partnerUpgradeRequired', { cloudIds }))
		})

		it('adds the version the partners need, after the named or the unnamed text', () => {
			for (const context of ['item', 'passkey'] as const) {
				for (const error of [named, { code: 'partner_upgrade_required' } as const]) {
					const withVersion = writeErrorText({ ...error, requiredVersion: '0.4.0' }, context)
					expect(withVersion).toBe(`${writeErrorText(error, context)} ${t('writeErrors.partnerUpgradeVersion', { version: '0.4.0' })}`)
					expect(withVersion).toContain('0.4.0')
				}
			}
		})

		it('adds no version when none was named', () => {
			expect(writeErrorText({ ...named, requiredVersion: '' })).toBe(writeErrorText(named))
		})
	})
})

/** The catalog key each code reads as, and whether it names the host. Typed per code, so a new ErrorCode does not compile until it has an expectation here. */
const ERROR_KEYS = {
	insecure_url: ['errors.insecureUrl', false],
	invalid_url: ['errors.invalidUrl', false],
	permission_denied: ['errors.permissionDenied', true],
	unreachable: ['errors.unreachable', true],
	not_nextcloud: ['errors.notNextcloud', true],
	server_error: ['errors.serverError', true],
	unauthorized: ['errors.unauthorized', false],
	keepiq_missing: ['errors.keepiqMissing', true],
	keepiq_outdated: ['errors.keepiqOutdated', true],
	no_active_suite: ['errors.noActiveSuite', false],
	key_unreadable: ['errors.keyUnreadable', false],
	unlock_blocked: ['errors.unlockBlocked', false],
	duplicate: ['errors.duplicate', false],
	limit_reached: ['errors.limitReached', false],
	invalid_master_password: ['errors.invalidMasterPassword', false],
	offline_no_cache: ['errors.offlineNoCache', false],
	session_revoked: ['errors.sessionRevoked', false],
	write_locked: ['errors.writeLocked', false],
	not_responding: ['errors.notResponding', false],
	invalid_pin: ['errors.wrongPin', false],
	pin_forgotten: ['errors.pinForgotten', false],
	pin_key_changed: ['errors.pinKeyChanged', false],
	unknown: ['errors.unknown', false],
} satisfies Record<ErrorCode, readonly [string, boolean]>

describe.each(LOCALES)('errorText in %s', (locale) => {
	it.each(Object.keys(ERROR_KEYS) as ErrorCode[])('reads %s as its catalog text', (code) => {
		setLocale(locale)
		const host = 'cloud.example.org'
		const text = errorText(code, host)
		expect(text.trim()).not.toBe('')
		expect(text).toBe(t(ERROR_KEYS[code][0], { host, version: MIN_KEEPIQ_VERSION, count: String(MAX_ACCOUNTS) }))
		if (ERROR_KEYS[code][1]) expect(text).toContain(host)
	})
})

describe('errorText', () => {
	it('counts the PIN attempts left only when the background sent them', () => {
		for (const attemptsLeft of [0, 1, 4]) expect(errorText('invalid_pin', 'h', attemptsLeft)).toBe((i18n.t as (key: string, count: number) => string)('errors.invalidPin', attemptsLeft))
		expect(errorText('invalid_pin', 'h')).toBe(t('errors.wrongPin'))
	})

	it('ignores attempts sent with any other code', () => {
		expect(errorText('unreachable', 'h', 2)).toBe(errorText('unreachable', 'h'))
	})

	it.each(['from_a_newer_background', 'constructor', 'toString', '__proto__'])('reads %s as unknown', (code) => {
		expect(errorText(code as ErrorCode, 'h')).toBe(t('errors.unknown'))
	})
})
