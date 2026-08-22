import { translate as t } from '@nextcloud/l10n'

/**
 * Human-readable, translatable labels for audit event types
 * (add-secret-audit-trail §5.6). Keyed by the dot-namespaced event_type the
 * backend records. English source strings are the i18n keys.
 *
 * @param {string} eventType The dot-namespaced event type.
 * @return {string} A human-readable, localized label.
 * @spec openspec/changes/add-secret-audit-trail/tasks.md#task-5.6
 */
export function auditEventLabel(eventType) {
	const labels = {
		'secret.created': t('keepiq', 'Secret created'),
		'secret.updated': t('keepiq', 'Secret updated'),
		'secret.read': t('keepiq', 'Secret read'),
		'secret.deleted': t('keepiq', 'Secret deleted'),
		'folder.deleted_cascade': t('keepiq', 'Folder deleted with contents'),
		'share.granted': t('keepiq', 'Share granted'),
		'share.revoked': t('keepiq', 'Share revoked'),
		'share.delegated': t('keepiq', 'Ownership delegated'),
		'share.delegation_reclaimed': t('keepiq', 'Delegation reclaimed'),
		'link_share.created': t('keepiq', 'Link share created'),
		'link_share.accessed': t('keepiq', 'Link share accessed'),
		'link_share.access_failed': t('keepiq', 'Link share access failed'),
		'link_share.revoked': t('keepiq', 'Link share revoked'),
		'link_share.auto_deleted': t('keepiq', 'Link share auto-deleted'),
		'request.created': t('keepiq', 'Secret request created'),
		'request.fulfilled': t('keepiq', 'Secret request fulfilled'),
		'request.re_requested': t('keepiq', 'Secret request re-requested'),
		'request.revoked': t('keepiq', 'Secret request revoked'),
		'suite.revoked': t('keepiq', 'Encryption suite revoked'),
		'suite.reinstated': t('keepiq', 'Encryption suite reinstated'),
		'suite.recovery_started': t('keepiq', 'Compromise recovery started'),
		'suite.recovery_completed': t('keepiq', 'Compromise recovery completed'),
		'application.registered': t('keepiq', 'Application registered'),
		'application.approved': t('keepiq', 'Application approved'),
		'application.rejected': t('keepiq', 'Application rejected'),
		'application.deleted': t('keepiq', 'Application deleted'),
		'application.token_issued': t('keepiq', 'Application token issued'),
		'application.secret_retrieved': t('keepiq', 'Application secret retrieved'),
		'vault.exported': t('keepiq', 'Vault exported'),
		'vault.gdpr_exported': t('keepiq', 'GDPR data exported'),
		'vault.account_deleted': t('keepiq', 'Account data deleted'),
	}
	return labels[eventType] || eventType
}

/**
 * The full list of event types, for the admin filter dropdown.
 *
 * @return {Array<{id: string, label: string}>} Options sorted by label.
 * @spec openspec/changes/add-secret-audit-trail/tasks.md#task-5.6
 */
export function auditEventOptions() {
	const types = [
		'secret.created',
		'secret.updated',
		'secret.read',
		'secret.deleted',
		'folder.deleted_cascade',
		'share.granted',
		'share.revoked',
		'share.delegated',
		'share.delegation_reclaimed',
		'link_share.created',
		'link_share.accessed',
		'link_share.access_failed',
		'link_share.revoked',
		'link_share.auto_deleted',
		'request.created',
		'request.fulfilled',
		'request.re_requested',
		'request.revoked',
		'suite.revoked',
		'suite.reinstated',
		'suite.recovery_started',
		'suite.recovery_completed',
		'application.registered',
		'application.approved',
		'application.rejected',
		'application.deleted',
		'application.token_issued',
		'application.secret_retrieved',
		'vault.exported',
		'vault.gdpr_exported',
		'vault.account_deleted',
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
 * @spec openspec/changes/add-secret-audit-trail/tasks.md#task-5.6
 */
export function auditActorLabel(entry) {
	if (entry.actorType === 'link_visitor') {
		return t('keepiq', 'Anonymous visitor')
	}
	if (entry.actorType === 'system') {
		return t('keepiq', 'System')
	}
	if (entry.actorId === 'deleted-account' || entry.actorId === null) {
		return t('keepiq', 'Deleted account')
	}
	return entry.actorId
}
