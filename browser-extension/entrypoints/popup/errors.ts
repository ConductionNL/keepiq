import { i18n } from '#i18n'
import { MAX_ACCOUNTS, type BlockReason, type ErrorCode, type NoticeCode } from '@/src/messages'

/** What the user reads for each error code; `host` names the server where it matters. */
export function errorText(code: ErrorCode, host: string): string {
	switch (code) {
		case 'insecure_url': return i18n.t('errors.insecureUrl')
		case 'invalid_url': return i18n.t('errors.invalidUrl')
		case 'permission_denied': return i18n.t('errors.permissionDenied', { host })
		case 'unreachable': return i18n.t('errors.unreachable', { host })
		case 'not_nextcloud': return i18n.t('errors.notNextcloud', { host })
		case 'server_error': return i18n.t('errors.serverError', { host })
		case 'unauthorized': return i18n.t('errors.unauthorized')
		case 'keepiq_missing': return i18n.t('errors.keepiqMissing', { host })
		case 'no_active_suite': return i18n.t('errors.noActiveSuite')
		case 'unlock_blocked': return i18n.t('errors.unlockBlocked')
		case 'duplicate': return i18n.t('errors.duplicate')
		case 'limit_reached': return i18n.t('errors.limitReached', { count: MAX_ACCOUNTS })
		case 'invalid_master_password': return i18n.t('errors.invalidMasterPassword')
		case 'offline_no_cache': return i18n.t('errors.offlineNoCache')
		case 'session_revoked': return i18n.t('errors.sessionRevoked')
		case 'write_locked': return i18n.t('errors.writeLocked')
		case 'not_responding': return i18n.t('errors.notResponding')
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

export function blockedText(reason: BlockReason): string {
	switch (reason) {
		case 'suite_missing': return i18n.t('blocked.suiteMissing')
		case 'suite_revoked': return i18n.t('blocked.suiteRevoked')
		case 'suite_compromised': return i18n.t('blocked.suiteCompromised')
		case 'migration_failed': return i18n.t('blocked.migrationFailed')
	}
}
