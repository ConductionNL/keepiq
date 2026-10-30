import { i18n } from '#i18n'
import { MIN_KEEPIQ_VERSION } from '@/src/api/keepiq-version'
import { MAX_ACCOUNTS, type BlockReason, type ErrorCode, type NoticeCode, type VaultWriteError } from '@/src/messages'
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

/** What was being written, since a 400 or 409 means something different for each. `open` is the fetch an edit or clone starts from; `send` is creating one. */
export type WriteContext = 'item' | 'edit' | 'open' | 'folderName' | 'folderDelete' | 'send'

/** `undefined` is the background not answering. */
export function writeErrorText(error: VaultWriteError | undefined, context: WriteContext = 'item'): string {
	const folder = context === 'folderName' || context === 'folderDelete'
	switch (error?.code) {
		case undefined: return i18n.t('errors.notResponding')
		case 'write_locked': return i18n.t('writeErrors.writeLocked')
		case 'forbidden': return i18n.t('writeErrors.forbidden')
		case 'policy': return i18n.t('writeErrors.policy')
		case 'account_changed': return i18n.t('writeErrors.accountChanged')
		case 'invalid':
			if (context === 'send') return i18n.t('writeErrors.sendInvalid')
			return context === 'folderDelete' ? i18n.t('writeErrors.folderChanged') : i18n.t('writeErrors.invalid')
		case 'conflict':
			if (context === 'edit') return i18n.t('writeErrors.stale')
			if (context === 'open') return i18n.t('writeErrors.unmatched')
			if (context === 'folderName') return i18n.t('writeErrors.duplicateFolder')
			return context === 'folderDelete' ? i18n.t('writeErrors.folderChanged') : i18n.t('writeErrors.invalid')
		case 'not_found': return folder ? i18n.t('writeErrors.folderGone') : i18n.t('writeErrors.itemGone')
		case 'blocked': return blockedText(error.reason)
		case 'unreadable': return i18n.t('writeErrors.unreadable')
		case 'restricted': return i18n.t('writeErrors.restricted')
		case 'view_only': return i18n.t('item.viewOnly')
		case 'key_mismatch': return i18n.t('writeErrors.keyMismatch')
		case 'offline': return context === 'send' ? i18n.t('writeErrors.sendOffline') : i18n.t('writeErrors.offline')
		case 'server_error': return i18n.t('writeErrors.serverError')
		case 'session_revoked': return i18n.t('errors.sessionRevoked')
		case 'stale_source': return i18n.t('writeErrors.staleSource')
		case 'incomplete': return i18n.t('writeErrors.incomplete')
		case 'bad_certificate': return i18n.t('writeErrors.badCertificate')
		case 'unverified': return i18n.t('writeErrors.unverified')
		case 'partner_unreachable': return i18n.t('writeErrors.partnerUnreachable', { cloudIds: cloudIdList((error.partners ?? []).map((partner) => partner.cloudId)) })
		case 'partner_lookup_limit': return i18n.t('writeErrors.partnerLookupLimit')
		case 'federation_unavailable': return i18n.t('writeErrors.federationUnavailable')
		case 'keepiq_outdated': return i18n.t('writeErrors.keepiqOutdated', { version: MIN_KEEPIQ_VERSION })
		case 'partner_clock': return i18n.t('writeErrors.partnerClock')
		case 'passkey_clone': return i18n.t('item.passkeyNoClone')
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
