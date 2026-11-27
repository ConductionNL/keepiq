<?php

/**
 * Keepiq Audit Metadata Policy
 *
 * Which metadata keys an audit entry may carry: the per-event-type whitelist
 * (add-secret-audit-trail design D3), the keys that may never appear, and the
 * keys whose values name a user. AuditService validates every recorded entry
 * against it; the event-type strings themselves live in AuditEventTypes.
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
 * The metadata keys an audit entry may, and may never, carry.
 *
 * @spec openspec/specs/secret-audit-trail/spec.md#requirement-no-secret-material-in-audit-entries
 */
final class AuditMetadataPolicy {
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
		'totp',
		'passkeys',
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
		AuditEventTypes::SECRET_CREATED => ['typeId', 'folderId'],
		AuditEventTypes::SECRET_UPDATED => ['changedFields', 'skippedUserIds'],
		AuditEventTypes::SECRET_READ => [],
		AuditEventTypes::SECRET_DELETED => [],
		AuditEventTypes::SECRET_TRASHED => [],
		AuditEventTypes::SECRET_RESTORED => [],
		AuditEventTypes::SECRET_PURGED => ['reason'],
		AuditEventTypes::SECRET_ARCHIVED => [],
		AuditEventTypes::SECRET_UNARCHIVED => [],
		AuditEventTypes::SECRET_USED => ['copyId'],
		AuditEventTypes::FOLDER_DELETED_CASCADE => ['secretCount', 'subfolderCount'],
		AuditEventTypes::SHARE_GRANTED => ['recipientType', 'recipientId'],
		AuditEventTypes::SHARE_REVOKED => ['recipientType', 'recipientId'],
		AuditEventTypes::SHARE_DELEGATED => ['delegatedTo', 'isPermanent'],
		AuditEventTypes::SHARE_DELEGATION_RECLAIMED => ['delegatedTo'],
		AuditEventTypes::SHARE_QUARANTINED => ['sourceSecretId', 'recipientId', 'copyId', 'reason', 'copyUseOnly', 'copyAccessEndsAt'],
		AuditEventTypes::SHARE_QUARANTINE_RELEASED => ['sourceSecretId', 'recipientId', 'copyId', 'reason', 'forced'],
		AuditEventTypes::SHARE_QUARANTINE_DROPPED => ['sourceSecretId', 'recipientId', 'copyId', 'reason', 'restrictionsCleared'],
		AuditEventTypes::LINK_SHARE_CREATED => ['hasPassword', 'expiresAt'],
		AuditEventTypes::LINK_SHARE_ACCESSED => [],
		AuditEventTypes::LINK_SHARE_ACCESS_FAILED => ['reason'],
		AuditEventTypes::LINK_SHARE_REVOKED => [],
		AuditEventTypes::LINK_SHARE_AUTO_DELETED => ['reason'],
		AuditEventTypes::REQUEST_CREATED => ['recipientType', 'recipientId'],
		AuditEventTypes::REQUEST_FULFILLED => [],
		AuditEventTypes::REQUEST_RE_REQUESTED => [],
		AuditEventTypes::REQUEST_REVOKED => [],
		// No metadata: the request id is the object, and WHY it expired is the
		// event type itself. Adding expires_at here would put a timestamp in the
		// trail that the request row already carries.
		AuditEventTypes::REQUEST_EXPIRED => [],
		AuditEventTypes::SUITE_REVOKED => ['reason', 'markCompromised', 'emergencyContactsDestroyed'],
		AuditEventTypes::SUITE_REINSTATED => [],
		AuditEventTypes::SUITE_RECOVERY_STARTED => ['migrationId', 'newSuiteId'],
		AuditEventTypes::SUITE_RECOVERY_COMPLETED => ['reSuitedCount'],
		AuditEventTypes::SUITE_RECOVERY_ABORTED => ['migrationId', 'newSuiteId'],
		AuditEventTypes::SUITE_MIGRATION_TERMINATED => ['migrationId', 'oldSuiteId', 'newSuiteId'],
		AuditEventTypes::SUITE_REVOKE_REFUSED => ['reasonCode', 'markCompromised'],
		AuditEventTypes::SUITE_COMPROMISE_CONTAINED => ['stamped', 'notified', 'failed', 'incomplete', 'migrationEndFailed'],
		// The guarded route, its purpose and why the proof was refused; never
		// the proof, the nonce or the signature.
		AuditEventTypes::KEY_PROOF_REFUSED => ['route', 'purpose', 'reason'],
		AuditEventTypes::APPLICATION_REGISTERED => [],
		AuditEventTypes::APPLICATION_APPROVED => [],
		AuditEventTypes::APPLICATION_REJECTED => ['reason'],
		AuditEventTypes::APPLICATION_DELETED => [],
		AuditEventTypes::APPLICATION_TOKEN_ISSUED => [],
		AuditEventTypes::APPLICATION_SECRET_RETRIEVED => [],
		AuditEventTypes::APPLICATION_SECRET_REQUEST_CREATED => ['secretId', 'requestedFieldCount'],
		AuditEventTypes::VAULT_EXPORTED => ['mode', 'scope', 'secretCount'],
		AuditEventTypes::VAULT_GDPR_EXPORTED => ['mode', 'scope', 'secretCount'],
		AuditEventTypes::VAULT_ACCOUNT_DELETED => ['trigger', 'secretCount', 'shareCount', 'requestCount', 'suiteCount'],
		// Emergency access — only non-sensitive relationship references; the
		// recovery envelope and any key material are NEVER recorded (design D8).
		AuditEventTypes::EMERGENCY_ACCESS_GRANTED => ['grantorUserId', 'granteeUserId', 'accessLevel', 'waitPeriodDays'],
		AuditEventTypes::EMERGENCY_ACCESS_REQUESTED => ['grantorUserId', 'granteeUserId', 'waitPeriodDays'],
		AuditEventTypes::EMERGENCY_ACCESS_DECLINED => ['grantorUserId', 'granteeUserId'],
		AuditEventTypes::EMERGENCY_ACCESS_APPROVED => ['grantorUserId', 'granteeUserId'],
		AuditEventTypes::EMERGENCY_ACCESS_ACCESSED => ['grantorUserId', 'granteeUserId'],
		AuditEventTypes::EMERGENCY_ACCESS_REVOKED => ['grantorUserId', 'granteeUserId'],
		AuditEventTypes::EMERGENCY_ACCESS_INVALIDATED => ['grantorUserId', 'granteeUserId', 'reason'],
		AuditEventTypes::EMERGENCY_ACCESS_CARRIED => ['grantorUserId', 'granteeUserId', 'fromSuiteId', 'toSuiteId'],
		// `via: share-save` marks a version number the client asserted.
		AuditEventTypes::SECRET_VERSION_RESTORED => ['versionNumber', 'via'],
		// Rotation & expiry — ids/reasons only (§5.2).
		AuditEventTypes::SECRET_EXPIRY_SET => ['expiresAt'],
		AuditEventTypes::SECRET_ROTATION_FLAGGED => ['reason'],
		AuditEventTypes::SECRET_ROTATED => ['reason'],
		AuditEventTypes::SECRET_ROTATION_DISMISSED => ['reason'],
		AuditEventTypes::POLICY_EXPIRY_CHANGED => ['scope', 'scopeId'],
		// Org password policy — before/after config values (§3.1).
		AuditEventTypes::PASSWORD_POLICY_UPDATED => ['before', 'after'],
		AuditEventTypes::VAULT_POLICY_UPDATED => ['before', 'after'],
		// Backups: archive name, flags, sizes and counts only (§2.3).
		AuditEventTypes::BACKUP_CREATED => ['archive', 'encrypted', 'bytes'],
		AuditEventTypes::BACKUP_FAILED => ['error'],
		AuditEventTypes::BACKUP_RESTORED => ['archive', 'createdAt', 'tables', 'rows', 'blobs'],
		// Compliance reporting — identifiers + format only (§5.1).
		AuditEventTypes::COMPLIANCE_REPORT_GENERATED => ['reportId'],
		AuditEventTypes::COMPLIANCE_REPORT_EXPORTED => ['reportId', 'format'],
		// Machine leases — ids + lifetimes only (§5.2).
		AuditEventTypes::LEASE_GRANTED => ['leaseId', 'secretId', 'expiresAt', 'ttl'],
		AuditEventTypes::LEASE_RENEWED => ['leaseId', 'secretId', 'expiresAt', 'renewedCount'],
		AuditEventTypes::LEASE_REVOKED => ['leaseId', 'secretId', 'expiresAt'],
		AuditEventTypes::LEASE_EXPIRED => ['leaseId', 'secretId', 'expiresAt'],
		// Encrypted attachments — id/size only (§5.1).
		AuditEventTypes::ATTACHMENT_UPLOADED => ['secretId', 'sizeBytes'],
		AuditEventTypes::ATTACHMENT_DOWNLOADED => ['secretId', 'sizeBytes'],
		AuditEventTypes::ATTACHMENT_DELETED => ['secretId', 'sizeBytes'],
		// Team folder sharing — identifiers only, never key material (§4.2).
		AuditEventTypes::TEAM_FOLDER_SHARED => ['folderId'],
		AuditEventTypes::TEAM_FOLDER_UNSHARED => ['folderId', 'revokedCount'],
		AuditEventTypes::TEAM_FOLDER_MEMBER_ADDED => ['memberType', 'memberId'],
		AuditEventTypes::TEAM_FOLDER_MEMBER_REMOVED => ['memberType', 'memberId', 'revokedCount'],
		AuditEventTypes::TEAM_FOLDER_OFFBOARDED => [
			'leavingUserId',
			'successorUserId',
			'revokedCount',
			'transferredCount',
			'linkSharesTransferredCount',
			// Member offboarding (admin-member-overview-and-offboarding §1.3): counts and group ids only.
			'membershipsRemovedCount',
			'coveringGroupIds',
		],
		// Grade changes — identifiers + the new grade only (§3.3).
		AuditEventTypes::TEAM_FOLDER_GRADE_CHANGED => ['memberType', 'memberId', 'grade'],
		// Automatic confirmation: counts only, the actor is the confirmer.
		AuditEventTypes::TEAM_FOLDER_MEMBERS_CONFIRMED => ['confirmedCount', 'memberCount'],
		// SIEM sinks — sink id/type/outcome only (§5.1).
		AuditEventTypes::SIEM_SINK_CREATED => ['sinkId', 'type'],
		AuditEventTypes::SIEM_SINK_UPDATED => ['sinkId', 'type'],
		AuditEventTypes::SIEM_SINK_DELETED => ['sinkId'],
		AuditEventTypes::SIEM_SINK_TESTED => ['sinkId', 'outcome'],
		AuditEventTypes::MCP_TOOL_INVOKED => ['tool', 'resultCount'],
		// Certificate lifecycle — identifiers only, never PEM/key.
		AuditEventTypes::CERTIFICATE_REISSUED => ['suiteId'],
		AuditEventTypes::CERTIFICATE_RENEWAL_MARKED => [],
		// Honey tripwire — the access channel only (§D6).
		AuditEventTypes::HONEY_ACCESSED => ['channel'],
		AuditEventTypes::DEVICE_APPROVAL_REQUESTED => ['clientKind'],
		AuditEventTypes::DEVICE_APPROVAL_APPROVED => [],
		AuditEventTypes::DEVICE_APPROVAL_DENIED => [],
		AuditEventTypes::DEVICE_APPROVAL_EXPIRED => [],
		AuditEventTypes::DEVICE_APPROVAL_PICKED_UP => [],
		AuditEventTypes::PRIVILEGED_ACCESS_MARKED => [],
		AuditEventTypes::PRIVILEGED_ACCESS_UNMARKED => [],
		AuditEventTypes::PRIVILEGED_ACCESS_REQUESTED => ['requestId', 'startsAt', 'endsAt'],
		AuditEventTypes::PRIVILEGED_ACCESS_APPROVED => ['requestId', 'requesterId', 'startsAt', 'endsAt', 'ranges'],
		AuditEventTypes::PRIVILEGED_ACCESS_DENIED => ['requestId', 'requesterId'],
		AuditEventTypes::PRIVILEGED_ACCESS_USED => ['requestId', 'address', 'channel'],
		AuditEventTypes::PRIVILEGED_ACCESS_REFUSED => ['address', 'channel'],
		AuditEventTypes::PASSKEY_REVOKED_WITH_APP_PASSWORD => ['credentialCount'],
		AuditEventTypes::PASSKEY_ENROLLED => ['clientKind'],
		AuditEventTypes::PASSKEY_UNLOCKED => ['clientKind'],
		AuditEventTypes::PASSKEY_REFUSED => ['clientKind', 'ceremony'],
		AuditEventTypes::PASSKEY_SUSPENDED => ['clientKind'],
		AuditEventTypes::RECOVERY_SETTINGS_CHANGED => ['policy', 'threshold', 'officerCount'],
		AuditEventTypes::RECOVERY_KEY_CREATED => [],
		AuditEventTypes::RECOVERY_KEY_RETIRED => [],
		AuditEventTypes::RECOVERY_ENROLLED => ['suiteId'],
		AuditEventTypes::RECOVERY_WITHDRAWN => [],
		AuditEventTypes::RECOVERY_REQUESTED => ['userId'],
		AuditEventTypes::RECOVERY_APPROVED => ['userId', 'approvals', 'threshold'],
		AuditEventTypes::RECOVERY_DECLINED => ['userId'],
		AuditEventTypes::RECOVERY_HANDED_OFF => ['userId'],
		AuditEventTypes::RECOVERY_COMPLETED => ['handledBy'],
		AuditEventTypes::RECOVERY_EXPIRED => ['userId'],
		// The other side by cloud id and partner, the share by id; nothing else.
		AuditEventTypes::FEDERATED_SHARE_SENT => ['federatedShareId', 'recipientCloudId', 'partnerId'],
		AuditEventTypes::FEDERATED_SHARE_UPDATED => ['federatedShareId', 'recipientCloudId', 'partnerId'],
		AuditEventTypes::FEDERATED_SHARE_REVOKED => ['federatedShareId', 'recipientCloudId', 'partnerId'],
		AuditEventTypes::FEDERATED_SHARE_SUSPENDED => ['federatedShareId', 'recipientCloudId', 'partnerId', 'reason'],
		AuditEventTypes::FEDERATED_SHARE_RESUMED => ['federatedShareId', 'recipientCloudId', 'partnerId'],
		AuditEventTypes::FEDERATED_SHARE_FAILED => ['federatedShareId', 'recipientCloudId', 'partnerId', 'notification', 'reason'],
		AuditEventTypes::FEDERATED_SHARE_RECEIVED => ['inboundShareId', 'senderCloudId', 'partnerId'],
		AuditEventTypes::FEDERATED_SHARE_ACCEPTED => ['inboundShareId', 'senderCloudId', 'partnerId', 'copyId'],
		AuditEventTypes::FEDERATED_SHARE_DECLINED => ['inboundShareId', 'senderCloudId', 'partnerId', 'copyId'],
		AuditEventTypes::FEDERATED_COPY_UPDATED => ['inboundShareId', 'senderCloudId', 'partnerId', 'copyId'],
		AuditEventTypes::FEDERATED_COPY_REMOVED => ['inboundShareId', 'senderCloudId', 'partnerId', 'copyId'],
		AuditEventTypes::FEDERATED_SHARE_RECIPIENT_DECLINED => ['federatedShareId', 'recipientCloudId', 'partnerId', 'kept', 'was'],
		AuditEventTypes::FEDERATED_SHARE_RECIPIENT_RESUMED => ['federatedShareId', 'recipientCloudId', 'partnerId'],
		// Field names only, never a value.
		AuditEventTypes::FEDERATION_PARTNER_VALUE_FIELDS_CHANGED => ['partnerId', 'from', 'to'],
		// Root fingerprints are public: the partner publishes them in its discovery document.
		AuditEventTypes::FEDERATION_PARTNER_REPINNED => ['partnerId', 'oldFingerprint', 'newFingerprint'],
	];

	/**
	 * The whitelisted metadata keys whose VALUES reference a user id and must
	 * be scrubbed on account-deletion anonymization (design D6). A value is
	 * one user id or a list of them.
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
		'skippedUserIds',
	];

	/**
	 * Whether an event type is known to the whitelist.
	 *
	 * @param string $eventType The event type
	 *
	 * @return bool
	 *
	 * @spec openspec/specs/secret-audit-trail/spec.md#requirement-no-secret-material-in-audit-entries
	 */
	public static function isKnown(string $eventType): bool {
		return array_key_exists($eventType, self::WHITELIST);
	}//end isKnown()
}//end class
