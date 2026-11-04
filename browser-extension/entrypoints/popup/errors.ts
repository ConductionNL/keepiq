import { i18n } from '#i18n'
import { MIN_KEEPIQ_VERSION } from '@/src/api/keepiq-version'
import { MAX_ACCOUNTS, type BlockReason, type ErrorCode, type NoticeCode, type VaultWriteError, type WriteErrorCode } from '@/src/messages'
import type { FieldError } from '@/src/vault/fields'
import { language } from './i18n'

/** What the user reads for each error code; `host` names the server where it matters, `attemptsLeft` comes with `invalid_pin`. */
export function errorText(code: ErrorCode, host: string, attemptsLeft?: number): string {
	switch (code) {
		case 'insecure_url': return i18n.t('errors.insecureUrl')
		case 'invalid_url': return i18n.t('errors.invalidUrl')
		case 'permission_denied': return i18n.t('errors.permissionDenied', { host })
		case 'unreachable': return i18n.t('errors.unreachable', { host })
		case 'not_nextcloud': return i18n.t('errors.notNextcloud', { host })
		case 'server_error': return i18n.t('errors.serverError', { host })
		case 'unauthorized': return i18n.t('errors.unauthorized')
		case 'keepiq_missing': return i18n.t('errors.keepiqMissing', { host })
		case 'keepiq_outdated': return i18n.t('errors.keepiqOutdated', { host, version: MIN_KEEPIQ_VERSION })
		case 'no_active_suite': return i18n.t('errors.noActiveSuite')
		case 'key_unreadable': return i18n.t('errors.keyUnreadable')
		case 'unlock_blocked': return i18n.t('errors.unlockBlocked')
		case 'duplicate': return i18n.t('errors.duplicate')
		case 'limit_reached': return i18n.t('errors.limitReached', { count: MAX_ACCOUNTS })
		case 'invalid_master_password': return i18n.t('errors.invalidMasterPassword')
		case 'offline_no_cache': return i18n.t('errors.offlineNoCache')
		case 'session_revoked': return i18n.t('errors.sessionRevoked')
		case 'write_locked': return i18n.t('errors.writeLocked')
		case 'not_responding': return i18n.t('errors.notResponding')
		case 'invalid_pin': return attemptsLeft === undefined ? i18n.t('errors.wrongPin') : i18n.t('errors.invalidPin', attemptsLeft)
		case 'pin_forgotten': return i18n.t('errors.pinForgotten')
		case 'pin_key_changed': return i18n.t('errors.pinKeyChanged')
		// A newer background can send a code this popup does not know yet.
		case 'unknown':
		default: return i18n.t('errors.unknown')
	}
}

export function noticeText(notice: NoticeCode): string {
	switch (notice) {
		case 'session_revoked': return i18n.t('errors.sessionRevoked')
		case 'key_changed': return i18n.t('notices.keyChanged')
	}
}

/**
 * What was being written, since a 400 or 409 means something different for each. `open` is the fetch an edit or clone starts from; `send` is creating one;
 * `passkey` is the passkey window's save or counter save; `delete` is an item's move to the trash.
 */
export type WriteContext = 'item' | 'edit' | 'open' | 'folderName' | 'folderDelete' | 'send' | 'passkey' | 'delete'

/** `undefined`, or an error without a code, is the background not answering. */
export function writeErrorText(error: VaultWriteError | undefined, context: WriteContext = 'item'): string {
	if (error?.code === undefined) return i18n.t('errors.notResponding')
	switch (error.code) {
		case 'write_locked': return i18n.t('writeErrors.writeLocked')
		case 'forbidden': return i18n.t('writeErrors.forbidden')
		case 'policy': return i18n.t('writeErrors.policy')
		case 'account_changed': return i18n.t('writeErrors.accountChanged')
		case 'invalid': return invalidText(context)
		case 'conflict': return conflictText(context)
		case 'not_found': return context === 'folderName' || context === 'folderDelete' ? i18n.t('writeErrors.folderGone') : i18n.t('writeErrors.itemGone')
		case 'blocked': return blockedText(error.reason)
		case 'unreadable': return i18n.t('writeErrors.unreadable')
		case 'passkey_clone': return i18n.t('item.passkeyNoClone')
		case 'key_mismatch': return i18n.t('writeErrors.keyMismatch')
		case 'offline': return context === 'send' ? i18n.t('writeErrors.sendOffline') : i18n.t('writeErrors.offline')
		default: return serverWriteErrorText(error, context)
	}
}

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

/** The codes about the user's own server. */
function serverWriteErrorText(error: VaultWriteError, context: WriteContext): string {
	switch (error.code) {
		case 'server_error': return i18n.t('writeErrors.serverError')
		case 'session_revoked': return i18n.t('errors.sessionRevoked')
		case 'keepiq_outdated': return i18n.t('writeErrors.keepiqOutdated', { version: MIN_KEEPIQ_VERSION })
		default: return sharingWriteErrorText(error, context)
	}
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

/** The codes about sharing: shared copies, partner organisations and their certificates. A `Map`, so a code named like an object property misses. */
const SHARING_WRITE_ERRORS = new Map<WriteErrorCode, (error: VaultWriteError, context: WriteContext) => string>([
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
	['share_scan_pending', (_, context) => {
		if (context === 'open') return i18n.t('writeErrors.shareScanPendingOpen')
		return context === 'delete' ? i18n.t('writeErrors.shareScanPendingDelete') : i18n.t('writeErrors.shareScanPending')
	}],
])

function sharingWriteErrorText(error: VaultWriteError, context: WriteContext): string {
	const text = SHARING_WRITE_ERRORS.get(error.code)
	return text ? text(error, context) : localWriteErrorText(error)
}

/** The codes about this browser and its clock, and the fallback for codes this popup does not know. */
function localWriteErrorText(error: VaultWriteError): string {
	switch (error.code) {
		case 'partner_clock': return i18n.t('writeErrors.partnerClock')
		case 'argon2_unavailable': return i18n.t('writeErrors.argon2Unavailable')
		case 'locked':
		case 'unknown':
		default: return i18n.t('errors.unknown')
	}
}

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
