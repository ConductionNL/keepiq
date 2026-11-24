<?php

/**
 * Keepiq Audit Event Types
 *
 * The single source of truth for dot-namespaced audit event-type strings
 * (add-secret-audit-trail §2.2). A string event_type (not an enum column)
 * means new event families never require a database migration (design D2).
 * The per-event-type metadata whitelist (design D3) lives in
 * AuditMetadataPolicy.
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
 * Audit event-type constants; the metadata policy is AuditMetadataPolicy.
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
	// The scan quarantined a planted share row (GHSA-m5p9-6pw7-pf3g), and an
	// administrator released or dropped one with occ keepiq:share-quarantine.
	public const SHARE_QUARANTINED = 'share.quarantined';
	public const SHARE_QUARANTINE_RELEASED = 'share.quarantine_released';
	public const SHARE_QUARANTINE_DROPPED = 'share.quarantine_dropped';

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

	// The containment of a compromise force-revoke ran (keepiq#1189). An
	// incomplete one otherwise only reached the HTTP response.
	public const SUITE_COMPROMISE_CONTAINED = 'suite.compromise_contained';

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

	// Privileged logins (capability-requirements-from-the-matrix 3.1): the
	// window, the address and the request, never secret material.
	public const PRIVILEGED_ACCESS_MARKED = 'privileged_access.marked';
	public const PRIVILEGED_ACCESS_UNMARKED = 'privileged_access.unmarked';
	public const PRIVILEGED_ACCESS_REQUESTED = 'privileged_access.requested';
	public const PRIVILEGED_ACCESS_APPROVED = 'privileged_access.approved';
	public const PRIVILEGED_ACCESS_DENIED = 'privileged_access.denied';
	public const PRIVILEGED_ACCESS_USED = 'privileged_access.used';
	public const PRIVILEGED_ACCESS_REFUSED = 'privileged_access.refused';

	// Browser-extension passkeys (extension-passkey-enrolment D3), identifiers only.
	public const PASSKEY_REVOKED_WITH_APP_PASSWORD =
		'passkey.revoked_with_app_password';
	public const PASSKEY_ENROLLED = 'passkey.enrolled';
	public const PASSKEY_UNLOCKED = 'passkey.unlocked';
	public const PASSKEY_REFUSED = 'passkey.refused';
	public const PASSKEY_SUSPENDED = 'passkey.suspended';

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

	// Federated sharing (sharing-federated-recipients 4.3), both sides,
	// identifiers only: never ciphertext, the shared secret or its hash.
	public const FEDERATED_SHARE_SENT = 'federated_share.sent';
	public const FEDERATED_SHARE_UPDATED = 'federated_share.updated';
	public const FEDERATED_SHARE_REVOKED = 'federated_share.revoked';
	public const FEDERATED_SHARE_SUSPENDED = 'federated_share.suspended';
	public const FEDERATED_SHARE_FAILED = 'federated_share.failed';
	public const FEDERATED_SHARE_RECEIVED = 'federated_share.received';
	public const FEDERATED_SHARE_ACCEPTED = 'federated_share.accepted';
	public const FEDERATED_SHARE_DECLINED = 'federated_share.declined';
	public const FEDERATED_COPY_UPDATED = 'federated_share.copy_updated';
	public const FEDERATED_COPY_REMOVED = 'federated_share.copy_removed';
	// The owner's side of a recipient deleting their copy (task 4.4).
	public const FEDERATED_SHARE_RECIPIENT_DECLINED = 'federated_share.recipient_declined';
	// The owner's side of a recipient restoring a declined copy from the trash.
	public const FEDERATED_SHARE_RECIPIENT_RESUMED = 'federated_share.recipient_resumed';
	// A partner's lookup answer advertised other value fields than recorded (keepiq#1325).
	public const FEDERATION_PARTNER_VALUE_FIELDS_CHANGED = 'federation_partner.value_fields_changed';
	// An administrator pinned the root fingerprint a partner publishes now; the actor is that administrator.
	public const FEDERATION_PARTNER_REPINNED = 'federation_partner.repinned';
}//end class
