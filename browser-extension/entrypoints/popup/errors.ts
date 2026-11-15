import { i18n } from '#i18n'
import { MIN_KEEPIQ_VERSION } from '@/src/api/keepiq-version'
import { MAX_ACCOUNTS, type BlockReason, type ErrorCode, type NoticeCode, type VaultWriteError, type WriteErrorCode } from '@/src/messages'
import type { FieldError } from '@/src/vault/fields'
import { language } from './i18n'

/** How one unlock error code reads; `host` names the server where it matters, `attemptsLeft` comes with `invalid_pin`. */
type ErrorWording = (host: string, attemptsLeft?: number) => string

const pinText = (attemptsLeft: number | undefined) => (attemptsLeft === undefined ? i18n.t('errors.wrongPin') : i18n.t('errors.invalidPin', attemptsLeft))

/** A `Map`, so a code named like an object property misses and reads as unknown. */
const ERROR_TEXTS = new Map<ErrorCode, ErrorWording>([
	['insecure_url', () => i18n.t('errors.insecureUrl')],
	['invalid_url', () => i18n.t('errors.invalidUrl')],
	['permission_denied', (host) => i18n.t('errors.permissionDenied', { host })],
	['unreachable', (host) => i18n.t('errors.unreachable', { host })],
	['not_nextcloud', (host) => i18n.t('errors.notNextcloud', { host })],
	['server_error', (host) => i18n.t('errors.serverError', { host })],
	['unauthorized', () => i18n.t('errors.unauthorized')],
	['keepiq_missing', (host) => i18n.t('errors.keepiqMissing', { host })],
	['keepiq_outdated', (host) => i18n.t('errors.keepiqOutdated', { host, version: MIN_KEEPIQ_VERSION })],
	['no_active_suite', () => i18n.t('errors.noActiveSuite')],
	['key_unreadable', () => i18n.t('errors.keyUnreadable')],
	['unlock_blocked', () => i18n.t('errors.unlockBlocked')],
	['duplicate', () => i18n.t('errors.duplicate')],
	['limit_reached', () => i18n.t('errors.limitReached', { count: MAX_ACCOUNTS })],
	['invalid_master_password', () => i18n.t('errors.invalidMasterPassword')],
	['offline_no_cache', () => i18n.t('errors.offlineNoCache')],
	['session_revoked', () => i18n.t('errors.sessionRevoked')],
	['write_locked', () => i18n.t('errors.writeLocked')],
	['not_responding', () => i18n.t('errors.notResponding')],
	['invalid_pin', (_, attemptsLeft) => pinText(attemptsLeft)],
	['pin_forgotten', () => i18n.t('errors.pinForgotten')],
	['pin_key_changed', () => i18n.t('errors.pinKeyChanged')],
	['passkey_failed', () => i18n.t('errors.passkeyFailed')],
])

/** What the user reads for each error code; `host` names the server where it matters, `attemptsLeft` comes with `invalid_pin`. */
export function errorText(code: ErrorCode, host: string, attemptsLeft?: number): string {
	const text = ERROR_TEXTS.get(code)
	// `unknown`, and a code from a newer background this popup does not know yet.
	return text ? text(host, attemptsLeft) : i18n.t('errors.unknown')
}

export function noticeText(notice: NoticeCode): string {
	switch (notice) {
		case 'session_revoked': return i18n.t('errors.sessionRevoked')
		case 'key_changed': return i18n.t('notices.keyChanged')
		case 'file_access': return i18n.t('notices.fileAccess')
	}
}

/**
 * What was being written, since a 400 or 409 means something different for each. `open` is the fetch an edit or clone starts from; `send` is creating one;
 * `passkey` is the passkey window's save or counter save; `delete` is an item's move to the trash.
 */
export type WriteContext = 'item' | 'edit' | 'open' | 'folderName' | 'folderDelete' | 'send' | 'passkey' | 'delete'

/** How one write error code reads, given what was being written. */
type WriteErrorWording = (error: VaultWriteError, context: WriteContext) => string

/** `undefined`, or an error without a code, is the background not answering. */
export function writeErrorText(error: VaultWriteError | undefined, context: WriteContext = 'item'): string {
	if (error?.code === undefined) return i18n.t('errors.notResponding')
	const text = WRITE_ERRORS.get(error.code) ?? SHARING_WRITE_ERRORS.get(error.code)
	// `locked`, `unknown`, and a code this popup does not know.
	return text ? text(error, context) : i18n.t('errors.unknown')
}

const accountChangedText = (context: WriteContext) => (context === 'delete' ? i18n.t('writeErrors.accountChangedDelete') : i18n.t('writeErrors.accountChanged'))

function invalidText(context: WriteContext): string {
	if (context === 'send') return i18n.t('writeErrors.sendInvalid')
	return context === 'folderDelete' ? i18n.t('writeErrors.folderChanged') : i18n.t('writeErrors.invalid')
}

/** A conflict is a stale edit, an unknown version when opening, or a name clash for a folder. */
function conflictText(context: WriteContext): string {
	// A fetch the cache does not hold, or a login that changed again after the save's one retry.
	if (context === 'passkey') return i18n.t('passkey.errors.conflict')
	if (context === 'edit') return i18n.t('writeErrors.stale')
	if (context === 'open') return i18n.t('writeErrors.unmatched')
	if (context === 'folderName') return i18n.t('writeErrors.duplicateFolder')
	return context === 'folderDelete' ? i18n.t('writeErrors.folderChanged') : i18n.t('writeErrors.invalid')
}

const partnerCloudIds = (error: VaultWriteError) => (error.partners ?? []).map((partner) => partner.cloudId)

/** Names the partners when the server listed them, and the release they need when it named one. */
function partnerUpgradeText(error: VaultWriteError, context: WriteContext): string {
	const cloudIds = partnerCloudIds(error)
	let text: string
	if (context === 'passkey') text = cloudIds.length > 0 ? i18n.t('passkey.errors.partnerUpgradeRequired', { cloudIds: cloudIdList(cloudIds) }) : i18n.t('passkey.errors.partnerUpgradeRequiredUnnamed')
	else text = cloudIds.length > 0 ? i18n.t('writeErrors.partnerUpgradeRequired', { cloudIds: cloudIdList(cloudIds) }) : i18n.t('writeErrors.partnerUpgradeRequiredUnnamed')
	return error.requiredVersion ? `${text} ${i18n.t('writeErrors.partnerUpgradeVersion', { version: error.requiredVersion })}` : text
}

function shareScanPendingText(_: VaultWriteError, context: WriteContext): string {
	if (context === 'open') return i18n.t('writeErrors.shareScanPendingOpen')
	if (context === 'delete') return i18n.t('writeErrors.shareScanPendingDelete')
	return context === 'passkey' ? i18n.t('passkey.errors.shareScanPending') : i18n.t('writeErrors.shareScanPending')
}

/** The codes about sharing: shared copies, partner organisations and their certificates. A `Map`, so a code named like an object property misses. */
const SHARING_WRITE_ERRORS = new Map<WriteErrorCode, WriteErrorWording>([
	['restricted', () => i18n.t('writeErrors.restricted')],
	['view_only', () => i18n.t('item.viewOnly')],
	['stale_source', () => i18n.t('writeErrors.staleSource')],
	['incomplete', () => i18n.t('writeErrors.incomplete')],
	['bad_certificate', () => i18n.t('writeErrors.badCertificate')],
	['unverified', () => i18n.t('writeErrors.unverified')],
	['partner_unreachable', (error) => i18n.t('writeErrors.partnerUnreachable', { cloudIds: cloudIdList(partnerCloudIds(error)) })],
	['partner_upgrade_required', partnerUpgradeText],
	['partner_lookup_limit', () => i18n.t('writeErrors.partnerLookupLimit')],
	['federation_unavailable', () => i18n.t('writeErrors.federationUnavailable')],
	['share_scan_pending', shareScanPendingText],
])

const notFoundText = (context: WriteContext) => (context === 'folderName' || context === 'folderDelete' ? i18n.t('writeErrors.folderGone') : i18n.t('writeErrors.itemGone'))

const offlineText = (context: WriteContext) => (context === 'send' ? i18n.t('writeErrors.sendOffline') : i18n.t('writeErrors.offline'))

/**
 * The codes about the write itself, the user's own server, and this browser and its clock. A `Map`, so a code named like an object property misses.
 */
const WRITE_ERRORS = new Map<WriteErrorCode, WriteErrorWording>([
	['write_locked', () => i18n.t('writeErrors.writeLocked')],
	['forbidden', () => i18n.t('writeErrors.forbidden')],
	['policy', () => i18n.t('writeErrors.policy')],
	['account_changed', (_, context) => accountChangedText(context)],
	['invalid', (_, context) => invalidText(context)],
	['conflict', (_, context) => conflictText(context)],
	['not_found', (_, context) => notFoundText(context)],
	['blocked', (error) => blockedText(error.reason)],
	['unreadable', () => i18n.t('writeErrors.unreadable')],
	['passkey_clone', () => i18n.t('item.passkeyNoClone')],
	['key_mismatch', () => i18n.t('writeErrors.keyMismatch')],
	['offline', (_, context) => offlineText(context)],
	['server_error', () => i18n.t('writeErrors.serverError')],
	['session_revoked', () => i18n.t('errors.sessionRevoked')],
	['keepiq_outdated', () => i18n.t('writeErrors.keepiqOutdated', { version: MIN_KEEPIQ_VERSION })],
	['partner_clock', () => i18n.t('writeErrors.partnerClock')],
	['argon2_unavailable', () => i18n.t('writeErrors.argon2Unavailable')],
])

/** Partner recipients named in a sentence of the catalog's language. */
export const cloudIdList = (cloudIds: string[]) => new Intl.ListFormat(language(), { type: 'conjunction' }).format(cloudIds)

export function fieldErrorText(error: FieldError): string {
	switch (error.code) {
		case 'required': return i18n.t('fieldErrors.required')
		case 'tooLong': return i18n.t('fieldErrors.tooLong', { max: error.max.toLocaleString(language()) })
		case 'tooLongBytes': return i18n.t('fieldErrors.tooLongBytes', { max: String(error.max) })
		case 'reserved': return i18n.t('fieldErrors.reserved')
		case 'duplicate': return i18n.t('fieldErrors.duplicate')
		case 'invalidTotp': return i18n.t('fieldErrors.invalidTotp')
	}
}

/** `undefined` is a row the server blocked without a reason we know. */
export function blockedText(reason: BlockReason | undefined): string {
	switch (reason) {
		case undefined: return i18n.t('blocked.generic')
		case 'suite_missing': return i18n.t('blocked.suiteMissing')
		case 'suite_revoked': return i18n.t('blocked.suiteRevoked')
		case 'suite_compromised': return i18n.t('blocked.suiteCompromised')
		case 'migration_failed': return i18n.t('blocked.migrationFailed')
	}
}
