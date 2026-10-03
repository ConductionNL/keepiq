<?php

/**
 * Keepiq Audit Event Types
 *
 * The single source of truth for dot-namespaced audit event-type strings
 * (add-secret-audit-trail §2.2) and the per-event-type metadata whitelist.
 * A string event_type (not an enum column) means new event families never
 * require a database migration (design D2); the whitelist (design D3) makes
 * the no-secret-material guarantee structural — AuditService validates every
 * recorded entry against the map for its event type.
 *
 * @category Event
 * @package  OCA\Keepiq\Event\Audit
 *
 * @author    Conduction Development Team <dev@conductio.nl>
 * @copyright 2024 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git-id>
 *
 * @link https://conduction.nl
 */

declare(strict_types=1);

namespace OCA\Keepiq\Event\Audit;

/**
 * Audit event-type constants and the metadata whitelist.
 */
final class AuditEventTypes {
	// Secret lifecycle.
	public const SECRET_CREATED = 'secret.created';
	public const SECRET_UPDATED = 'secret.updated';
	public const SECRET_READ = 'secret.read';
	public const SECRET_DELETED = 'secret.deleted';
	// Trash and archive (vault-trash-and-archive): ids and the item name only.
	public const SECRET_TRASHED = 'secret.trashed';
	public const SECRET_RESTORED = 'secret.restored';
	public const SECRET_PURGED = 'secret.purged';
	public const SECRET_ARCHIVED = 'secret.archived';
	public const SECRET_UNARCHIVED = 'secret.unarchived';
	// The holder of a use-only copy filled it (sharing-use-only-and-expiring-shares §3.3).
	public const SECRET_USED = 'secret.used';

	// Folder.
	public const FOLDER_DELETED_CASCADE = 'folder.deleted_cascade';

	// Sharing.
	public const SHARE_GRANTED = 'share.granted';
	public const SHARE_REVOKED = 'share.revoked';
	public const SHARE_DELEGATED = 'share.delegated';
	public const SHARE_DELEGATION_RECLAIMED = 'share.delegation_reclaimed';

	// Link share.
	public const LINK_SHARE_CREATED = 'link_share.created';
	public const LINK_SHARE_ACCESSED = 'link_share.accessed';
	public const LINK_SHARE_ACCESS_FAILED = 'link_share.access_failed';
	public const LINK_SHARE_REVOKED = 'link_share.revoked';
	public const LINK_SHARE_AUTO_DELETED = 'link_share.auto_deleted';

	// Secret request.
	public const REQUEST_CREATED = 'request.created';
	public const REQUEST_FULFILLED = 'request.fulfilled';
	public const REQUEST_RE_REQUESTED = 'request.re_requested';
	public const REQUEST_REVOKED = 'request.revoked';

	/**
	 * A request lapsed and the sweeper acted on it. Distinct from
	 * REQUEST_REVOKED because the actor is the system, not the requester —
	 * recording a person as the actor for something they did not do would
	 * corrupt the trail this capability exists to provide.
	 */
	public const REQUEST_EXPIRED = 'request.expired';

	// Suite.
	public const SUITE_REVOKED = 'suite.revoked';
	public const SUITE_REINSTATED = 'suite.reinstated';
	public const SUITE_RECOVERY_STARTED = 'suite.recovery_started';
	public const SUITE_RECOVERY_COMPLETED = 'suite.recovery_completed';
	// The owner aborted their own recovery before any record moved (keepiq#859).
	public const SUITE_RECOVERY_ABORTED = 'suite.recovery_aborted';
	// A compromise force-revoke ended an open migration (keepiq#870).
	public const SUITE_MIGRATION_TERMINATED = 'suite.migration_terminated';
	// An administrator force-revoke was refused (keepiq#870): an attack on the
	// containment path shows up as refusals, not as successes.
	public const SUITE_REVOKE_REFUSED = 'suite.revoke_refused';

	// Vault-key proof (keepiq#870). A refused proof is exactly what a
	// session-only attacker probing a guarded route produces. The proof,
	// the nonce and the signature are never recorded.
	public const KEY_PROOF_REFUSED = 'key_proof.refused';

	// Application.
	public const APPLICATION_REGISTERED = 'application.registered';
	public const APPLICATION_APPROVED = 'application.approved';
	public const APPLICATION_REJECTED = 'application.rejected';
	public const APPLICATION_DELETED = 'application.deleted';
	public const APPLICATION_TOKEN_ISSUED = 'application.token_issued';
	public const APPLICATION_SECRET_RETRIEVED = 'application.secret_retrieved';

	/**
	 * An application created a secret request in its own vault, session-lessly.
	 *
	 * A distinct type rather than REQUEST_CREATED with an application actor:
	 * `application.*` events are what an operator filters on to see everything a
	 * machine identity did, and folding this into the user-facing request type
	 * would hide it from that view.
	 *
	 * @var string
	 */
	public const APPLICATION_SECRET_REQUEST_CREATED = 'application.secret_request_created';

	// Emergency access (break-glass lifecycle — add-emergency-access).
	public const EMERGENCY_ACCESS_GRANTED = 'emergency_access.granted';
	public const EMERGENCY_ACCESS_REQUESTED = 'emergency_access.requested';
	public const EMERGENCY_ACCESS_DECLINED = 'emergency_access.declined';
	public const EMERGENCY_ACCESS_APPROVED = 'emergency_access.approved';
	public const EMERGENCY_ACCESS_ACCESSED = 'emergency_access.accessed';
	public const EMERGENCY_ACCESS_REVOKED = 'emergency_access.revoked';
	public const EMERGENCY_ACCESS_INVALIDATED = 'emergency_access.invalidated';
	// An existing granted contact carried to the new key during a rotation.
	// Kept apart from GRANTED so a carry can't be mistaken for a fresh
	// designation, which is what a planted contact would be (#804 review).
	public const EMERGENCY_ACCESS_CARRIED = 'emergency_access.carried';

	// Export & deletion (consumed from secret-export-gdpr events when present).
	public const VAULT_EXPORTED = 'vault.exported';
	public const VAULT_GDPR_EXPORTED = 'vault.gdpr_exported';
	public const VAULT_ACCOUNT_DELETED = 'vault.account_deleted';

	// Secret version history (secret-version-history §6.3) — id + version
	// number only, never ciphertext or values.
	public const SECRET_VERSION_RESTORED = 'secret.version_restored';

	// Rotation & expiry (rotation-expiry-policies §5.2) — ids/reasons only.
	public const SECRET_EXPIRY_SET = 'secret.expiry_set';
	public const SECRET_ROTATION_FLAGGED = 'secret.rotation_flagged';
	public const SECRET_ROTATED = 'secret.rotated';
	public const SECRET_ROTATION_DISMISSED = 'secret.rotation_dismissed';
	public const POLICY_EXPIRY_CHANGED = 'policy.expiry_changed';

	// Org password policy (org-password-policies §3.1) — config values
	// only, never secret data.
	public const PASSWORD_POLICY_UPDATED = 'password_policy.updated';
	// Vault policies (admin-vault-policies §1.1): before and after snapshot.
	public const VAULT_POLICY_UPDATED = 'vault_policy.updated';
	// Scheduled vault backups (admin-scheduled-vault-backups §2.3).
	public const BACKUP_CREATED = 'backup.created';
	public const BACKUP_FAILED = 'backup.failed';
	public const BACKUP_RESTORED = 'backup.restored';

	// Compliance reporting (compliance-reporting §5.1) — identifiers +
	// export format only, never an aggregate body.
	public const COMPLIANCE_REPORT_GENERATED = 'compliance.report_generated';
	public const COMPLIANCE_REPORT_EXPORTED = 'compliance.report_exported';

	// Machine leases (machine-secret-leases §5.2) — ids + lifetimes only.
	public const LEASE_GRANTED = 'lease.granted';
	public const LEASE_RENEWED = 'lease.renewed';
	public const LEASE_REVOKED = 'lease.revoked';
	public const LEASE_EXPIRED = 'lease.expired';

	// Encrypted attachments (encrypted-attachments §5.1) — id/size only,
	// never filename, file key, or content.
	public const ATTACHMENT_UPLOADED = 'attachment.uploaded';
	public const ATTACHMENT_DOWNLOADED = 'attachment.downloaded';
	public const ATTACHMENT_DELETED = 'attachment.deleted';

	// Team folder sharing (team-folder-sharing §4.2).
	public const TEAM_FOLDER_SHARED = 'team_folder.shared';
	public const TEAM_FOLDER_UNSHARED = 'team_folder.unshared';
	public const TEAM_FOLDER_MEMBER_ADDED = 'team_folder.member_added';
	public const TEAM_FOLDER_MEMBER_REMOVED = 'team_folder.member_removed';
	public const TEAM_FOLDER_OFFBOARDED = 'team_folder.offboarded';
	// Automatic member confirmation (admin-auto-confirm-members D6).
	public const TEAM_FOLDER_MEMBERS_CONFIRMED = 'team_folder.members_confirmed';
	// Folder permission grades (folder-permission-grades §3.3).
	public const TEAM_FOLDER_GRADE_CHANGED = 'team_folder.grade_changed';

	// SIEM audit export (siem-audit-export §5.1) — sink config lifecycle
	// only; endpoints/secrets never appear in metadata.
	public const SIEM_SINK_CREATED = 'siem.sink_created';
	public const SIEM_SINK_UPDATED = 'siem.sink_updated';
	public const SIEM_SINK_DELETED = 'siem.sink_deleted';
	public const SIEM_SINK_TESTED = 'siem.sink_tested';

	// An AI agent called a Keepiq MCP read tool (hermiq-ai-tooling): the tool
	// name and a result count, never an entry name, subject or value.
	public const MCP_TOOL_INVOKED = 'mcp.tool_invoked';

	// Certificate lifecycle (certificate-lifecycle §5) — identifiers
	// only; no PEM, key, or secret value is ever recorded.
	public const CERTIFICATE_REISSUED = 'certificate.reissued';
	public const CERTIFICATE_RENEWAL_MARKED = 'certificate.renewal_marked';

	// Honey credentials (honey-credentials §D6) — the distinguished
	// high-severity tripwire marker; channel only, never secret data.
	public const HONEY_ACCESSED = 'honey.accessed';

	// New device approval (crypto-new-device-approval D5), identifiers only.
	public const DEVICE_APPROVAL_REQUESTED = 'device_approval.requested';
	public const DEVICE_APPROVAL_APPROVED = 'device_approval.approved';
	public const DEVICE_APPROVAL_DENIED = 'device_approval.denied';
	public const DEVICE_APPROVAL_EXPIRED = 'device_approval.expired';
	public const DEVICE_APPROVAL_PICKED_UP = 'device_approval.picked_up';

	// Organisation account recovery (crypto-organisation-account-recovery 5.2),
	// identifiers only: never an envelope, a wrapped copy or a sealed result.
	public const RECOVERY_SETTINGS_CHANGED = 'recovery.settings_changed';
	public const RECOVERY_KEY_CREATED = 'recovery.key_created';
	public const RECOVERY_KEY_RETIRED = 'recovery.key_retired';
	public const RECOVERY_ENROLLED = 'recovery.enrolled';
	public const RECOVERY_WITHDRAWN = 'recovery.withdrawn';
	public const RECOVERY_REQUESTED = 'recovery.requested';
	public const RECOVERY_APPROVED = 'recovery.approved';
	public const RECOVERY_DECLINED = 'recovery.declined';
	public const RECOVERY_HANDED_OFF = 'recovery.handed_off';
	public const RECOVERY_COMPLETED = 'recovery.completed';
	public const RECOVERY_EXPIRED = 'recovery.expired';

	/**
	 * Metadata keys that MUST NEVER appear in any audit entry, in any position.
	 * Recording any of these is rejected with an exception — defense in depth so
	 * a future dispatch site cannot accidentally leak secret material (design D3).
	 *
	 * @var string[]
	 */
	public const FORBIDDEN_KEYS = [
		'key',
		'login',
		'password',
		'value',
		'additionalFields',
		'ciphertext',
		'payload',
	];

	/**
	 * Per-event-type whitelist of permitted metadata keys. Unknown keys are
	 * dropped by AuditService::record; only the listed keys survive.
	 *
	 * A constant rather than a static accessor: the table is a compile-time
	 * literal, and constant access is a value read at the call site instead
	 * of a static method call the caller cannot substitute
	 * (rulesets/cleancode.xml/StaticAccess).
	 *
	 * @var array<string,string[]>
	 */
	public const WHITELIST = [
		self::SECRET_CREATED => ['typeId', 'folderId'],
		self::SECRET_UPDATED => ['changedFields'],
		self::SECRET_READ => [],
		self::SECRET_DELETED => [],
		self::SECRET_TRASHED => [],
		self::SECRET_RESTORED => [],
		self::SECRET_PURGED => ['reason'],
		self::SECRET_ARCHIVED => [],
		self::SECRET_UNARCHIVED => [],
		self::SECRET_USED => ['copyId'],
		self::FOLDER_DELETED_CASCADE => ['secretCount', 'subfolderCount'],
		self::SHARE_GRANTED => ['recipientType', 'recipientId'],
		self::SHARE_REVOKED => ['recipientType', 'recipientId'],
		self::SHARE_DELEGATED => ['delegatedTo', 'isPermanent'],
		self::SHARE_DELEGATION_RECLAIMED => ['delegatedTo'],
		self::LINK_SHARE_CREATED => ['hasPassword', 'expiresAt'],
		self::LINK_SHARE_ACCESSED => [],
		self::LINK_SHARE_ACCESS_FAILED => ['reason'],
		self::LINK_SHARE_REVOKED => [],
		self::LINK_SHARE_AUTO_DELETED => ['reason'],
		self::REQUEST_CREATED => ['recipientType', 'recipientId'],
		self::REQUEST_FULFILLED => [],
		self::REQUEST_RE_REQUESTED => [],
		self::REQUEST_REVOKED => [],
		// No metadata: the request id is the object, and WHY it expired is the
		// event type itself. Adding expires_at here would put a timestamp in the
		// trail that the request row already carries.
		self::REQUEST_EXPIRED => [],
		self::SUITE_REVOKED => ['reason', 'markCompromised', 'emergencyContactsDestroyed'],
		self::SUITE_REINSTATED => [],
		self::SUITE_RECOVERY_STARTED => ['migrationId', 'newSuiteId'],
		self::SUITE_RECOVERY_COMPLETED => ['reSuitedCount'],
		self::SUITE_RECOVERY_ABORTED => ['migrationId', 'newSuiteId'],
		self::SUITE_MIGRATION_TERMINATED => ['migrationId', 'oldSuiteId', 'newSuiteId'],
		self::SUITE_REVOKE_REFUSED => ['reasonCode', 'markCompromised'],
		// The guarded route, its purpose and why the proof was refused; never
		// the proof, the nonce or the signature.
		self::KEY_PROOF_REFUSED => ['route', 'purpose', 'reason'],
		self::APPLICATION_REGISTERED => [],
		self::APPLICATION_APPROVED => [],
		self::APPLICATION_REJECTED => ['reason'],
		self::APPLICATION_DELETED => [],
		self::APPLICATION_TOKEN_ISSUED => [],
		self::APPLICATION_SECRET_RETRIEVED => [],
		self::APPLICATION_SECRET_REQUEST_CREATED => ['secretId', 'requestedFieldCount'],
		self::VAULT_EXPORTED => ['mode', 'scope', 'secretCount'],
		self::VAULT_GDPR_EXPORTED => ['mode', 'scope', 'secretCount'],
		self::VAULT_ACCOUNT_DELETED => ['trigger', 'secretCount', 'shareCount', 'requestCount', 'suiteCount'],
		// Emergency access — only non-sensitive relationship references; the
		// recovery envelope and any key material are NEVER recorded (design D8).
		self::EMERGENCY_ACCESS_GRANTED => ['grantorUserId', 'granteeUserId', 'accessLevel', 'waitPeriodDays'],
		self::EMERGENCY_ACCESS_REQUESTED => ['grantorUserId', 'granteeUserId', 'waitPeriodDays'],
		self::EMERGENCY_ACCESS_DECLINED => ['grantorUserId', 'granteeUserId'],
		self::EMERGENCY_ACCESS_APPROVED => ['grantorUserId', 'granteeUserId'],
		self::EMERGENCY_ACCESS_ACCESSED => ['grantorUserId', 'granteeUserId'],
		self::EMERGENCY_ACCESS_REVOKED => ['grantorUserId', 'granteeUserId'],
		self::EMERGENCY_ACCESS_INVALIDATED => ['grantorUserId', 'granteeUserId', 'reason'],
		self::EMERGENCY_ACCESS_CARRIED => ['grantorUserId', 'granteeUserId', 'fromSuiteId', 'toSuiteId'],
		self::SECRET_VERSION_RESTORED => ['versionNumber'],
		// Rotation & expiry — ids/reasons only (§5.2).
		self::SECRET_EXPIRY_SET => ['expiresAt'],
		self::SECRET_ROTATION_FLAGGED => ['reason'],
		self::SECRET_ROTATED => ['reason'],
		self::SECRET_ROTATION_DISMISSED => ['reason'],
		self::POLICY_EXPIRY_CHANGED => ['scope', 'scopeId'],
		// Org password policy — before/after config values (§3.1).
		self::PASSWORD_POLICY_UPDATED => ['before', 'after'],
		self::VAULT_POLICY_UPDATED => ['before', 'after'],
		// Backups: archive name, flags, sizes and counts only (§2.3).
		self::BACKUP_CREATED => ['archive', 'encrypted', 'bytes'],
		self::BACKUP_FAILED => ['error'],
		self::BACKUP_RESTORED => ['archive', 'createdAt', 'tables', 'rows', 'blobs'],
		// Compliance reporting — identifiers + format only (§5.1).
		self::COMPLIANCE_REPORT_GENERATED => ['reportId'],
		self::COMPLIANCE_REPORT_EXPORTED => ['reportId', 'format'],
		// Machine leases — ids + lifetimes only (§5.2).
		self::LEASE_GRANTED => ['leaseId', 'secretId', 'expiresAt', 'ttl'],
		self::LEASE_RENEWED => ['leaseId', 'secretId', 'expiresAt', 'renewedCount'],
		self::LEASE_REVOKED => ['leaseId', 'secretId', 'expiresAt'],
		self::LEASE_EXPIRED => ['leaseId', 'secretId', 'expiresAt'],
		// Encrypted attachments — id/size only (§5.1).
		self::ATTACHMENT_UPLOADED => ['secretId', 'sizeBytes'],
		self::ATTACHMENT_DOWNLOADED => ['secretId', 'sizeBytes'],
		self::ATTACHMENT_DELETED => ['secretId', 'sizeBytes'],
		// Team folder sharing — identifiers only, never key material (§4.2).
		self::TEAM_FOLDER_SHARED => ['folderId'],
		self::TEAM_FOLDER_UNSHARED => ['folderId', 'revokedCount'],
		self::TEAM_FOLDER_MEMBER_ADDED => ['memberType', 'memberId'],
		self::TEAM_FOLDER_MEMBER_REMOVED => ['memberType', 'memberId', 'revokedCount'],
		self::TEAM_FOLDER_OFFBOARDED => [
			'leavingUserId',
			'successorUserId',
			'revokedCount',
			'transferredCount',
			// Member offboarding (admin-member-overview-and-offboarding §1.3): counts and group ids only.
			'membershipsRemovedCount',
			'coveringGroupIds',
		],
		// Grade changes — identifiers + the new grade only (§3.3).
		self::TEAM_FOLDER_GRADE_CHANGED => ['memberType', 'memberId', 'grade'],
		// Automatic confirmation: counts only, the actor is the confirmer.
		self::TEAM_FOLDER_MEMBERS_CONFIRMED => ['confirmedCount', 'memberCount'],
		// SIEM sinks — sink id/type/outcome only (§5.1).
		self::SIEM_SINK_CREATED => ['sinkId', 'type'],
		self::SIEM_SINK_UPDATED => ['sinkId', 'type'],
		self::SIEM_SINK_DELETED => ['sinkId'],
		self::SIEM_SINK_TESTED => ['sinkId', 'outcome'],
		self::MCP_TOOL_INVOKED => ['tool', 'resultCount'],
		// Certificate lifecycle — identifiers only, never PEM/key.
		self::CERTIFICATE_REISSUED => ['suiteId'],
		self::CERTIFICATE_RENEWAL_MARKED => [],
		// Honey tripwire — the access channel only (§D6).
		self::HONEY_ACCESSED => ['channel'],
		self::DEVICE_APPROVAL_REQUESTED => ['clientKind'],
		self::DEVICE_APPROVAL_APPROVED => [],
		self::DEVICE_APPROVAL_DENIED => [],
		self::DEVICE_APPROVAL_EXPIRED => [],
		self::DEVICE_APPROVAL_PICKED_UP => [],
		self::RECOVERY_SETTINGS_CHANGED => ['policy', 'threshold', 'officerCount'],
		self::RECOVERY_KEY_CREATED => [],
		self::RECOVERY_KEY_RETIRED => [],
		self::RECOVERY_ENROLLED => ['suiteId'],
		self::RECOVERY_WITHDRAWN => [],
		self::RECOVERY_REQUESTED => ['userId'],
		self::RECOVERY_APPROVED => ['userId', 'approvals', 'threshold'],
		self::RECOVERY_DECLINED => ['userId'],
		self::RECOVERY_HANDED_OFF => ['userId'],
		self::RECOVERY_COMPLETED => ['handledBy'],
		self::RECOVERY_EXPIRED => ['userId'],
	];

	/**
	 * The whitelisted metadata keys whose VALUES reference a user id and must
	 * be scrubbed on account-deletion anonymization (design D6).
	 *
	 * @var string[]
	 */
	public const USER_REFERENCING_METADATA_KEYS = [
		'recipientId',
		'delegatedTo',
		'grantorUserId',
		'granteeUserId',
		'memberId',
		'leavingUserId',
		'successorUserId',
	];

	/**
	 * Whether an event type is known to the whitelist.
	 *
	 * @param string $eventType The event type
	 *
	 * @return bool
	 */
	public static function isKnown(string $eventType): bool {
		return array_key_exists($eventType, self::WHITELIST);
	}//end isKnown()
}//end class
