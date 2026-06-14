import { translate as t } from '@nextcloud/l10n'

/**
 * Human-readable, translatable labels for audit event types
 * (add-secret-audit-trail §5.6). Keyed by the dot-namespaced event_type the
 * backend records. English source strings are the i18n keys.
 *
 * @param {string} eventType The dot-namespaced event type.
 * @return {string} A human-readable, localized label.
 */
export function auditEventLabel(eventType) {
	const labels = {
		'secret.created': t('doriath', 'Secret created'),
		'secret.updated': t('doriath', 'Secret updated'),
		'secret.read': t('doriath', 'Secret read'),
		'secret.deleted': t('doriath', 'Secret deleted'),
		'folder.deleted_cascade': t('doriath', 'Folder deleted with contents'),
		'share.granted': t('doriath', 'Share granted'),
		'share.revoked': t('doriath', 'Share revoked'),
		'share.delegated': t('doriath', 'Ownership delegated'),
		'share.delegation_reclaimed': t('doriath', 'Delegation reclaimed'),
		'link_share.created': t('doriath', 'Link share created'),
		'link_share.accessed': t('doriath', 'Link share accessed'),
		'link_share.access_failed': t('doriath', 'Link share access failed'),
		'link_share.revoked': t('doriath', 'Link share revoked'),
		'link_share.auto_deleted': t('doriath', 'Link share auto-deleted'),
		'request.created': t('doriath', 'Secret request created'),
		'request.fulfilled': t('doriath', 'Secret request fulfilled'),
		'request.re_requested': t('doriath', 'Secret request re-requested'),
		'request.revoked': t('doriath', 'Secret request revoked'),
		'suite.revoked': t('doriath', 'Encryption suite revoked'),
		'suite.reinstated': t('doriath', 'Encryption suite reinstated'),
		'suite.recovery_started': t('doriath', 'Compromise recovery started'),
		'suite.recovery_completed': t('doriath', 'Compromise recovery completed'),
		'application.registered': t('doriath', 'Application registered'),
		'application.approved': t('doriath', 'Application approved'),
		'application.rejected': t('doriath', 'Application rejected'),
		'application.deleted': t('doriath', 'Application deleted'),
		'application.token_issued': t('doriath', 'Application token issued'),
		'application.secret_retrieved': t('doriath', 'Application secret retrieved'),
		'vault.exported': t('doriath', 'Vault exported'),
		'vault.gdpr_exported': t('doriath', 'GDPR data exported'),
		'vault.account_deleted': t('doriath', 'Account data deleted'),
	}
	return labels[eventType] || eventType
}

/**
 * The full list of event types, for the admin filter dropdown.
 *
 * @return {Array<{id: string, label: string}>} Options sorted by label.
 */
export function auditEventOptions() {
	const types = [
		'secret.created', 'secret.updated', 'secret.read', 'secret.deleted',
		'folder.deleted_cascade',
		'share.granted', 'share.revoked', 'share.delegated', 'share.delegation_reclaimed',
		'link_share.created', 'link_share.accessed', 'link_share.access_failed',
		'link_share.revoked', 'link_share.auto_deleted',
		'request.created', 'request.fulfilled', 'request.re_requested', 'request.revoked',
		'suite.revoked', 'suite.reinstated', 'suite.recovery_started', 'suite.recovery_completed',
		'application.registered', 'application.approved', 'application.rejected',
		'application.deleted', 'application.token_issued', 'application.secret_retrieved',
		'vault.exported', 'vault.gdpr_exported', 'vault.account_deleted',
	]
	return types
		.map((id) => ({ id, label: auditEventLabel(id) }))
		.sort((a, b) => a.label.localeCompare(b.label))
}

/**
 * Render an actor for display, honoring the deleted-account marker.
 *
 * @param {object} entry An audit entry (actorType / actorId).
 * @return {string} A human-readable actor label.
 */
export function auditActorLabel(entry) {
	if (entry.actorType === 'link_visitor') {
		return t('doriath', 'Anonymous visitor')
	}
	if (entry.actorType === 'system') {
		return t('doriath', 'System')
	}
	if (entry.actorId === 'deleted-account' || entry.actorId === null) {
		return t('doriath', 'Deleted account')
	}
	return entry.actorId
}
