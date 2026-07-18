<?php

/**
 * Doriath Audit Event Types
 *
 * The single source of truth for dot-namespaced audit event-type strings
 * (add-secret-audit-trail §2.2) and the per-event-type metadata whitelist.
 * A string event_type (not an enum column) means new event families never
 * require a database migration (design D2); the whitelist (design D3) makes
 * the no-secret-material guarantee structural — AuditService validates every
 * recorded entry against the map for its event type.
 *
 * @category Event
 * @package  OCA\Doriath\Event\Audit
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

namespace OCA\Doriath\Event\Audit;

/**
 * Audit event-type constants and the metadata whitelist.
 */
final class AuditEventTypes
{
    // Secret lifecycle.
    public const SECRET_CREATED = 'secret.created';
    public const SECRET_UPDATED = 'secret.updated';
    public const SECRET_READ    = 'secret.read';
    public const SECRET_DELETED = 'secret.deleted';

    // Folder.
    public const FOLDER_DELETED_CASCADE = 'folder.deleted_cascade';

    // Sharing.
    public const SHARE_GRANTED   = 'share.granted';
    public const SHARE_REVOKED   = 'share.revoked';
    public const SHARE_DELEGATED = 'share.delegated';
    public const SHARE_DELEGATION_RECLAIMED = 'share.delegation_reclaimed';

    // Link share.
    public const LINK_SHARE_CREATED       = 'link_share.created';
    public const LINK_SHARE_ACCESSED      = 'link_share.accessed';
    public const LINK_SHARE_ACCESS_FAILED = 'link_share.access_failed';
    public const LINK_SHARE_REVOKED       = 'link_share.revoked';
    public const LINK_SHARE_AUTO_DELETED  = 'link_share.auto_deleted';

    // Secret request.
    public const REQUEST_CREATED      = 'request.created';
    public const REQUEST_FULFILLED    = 'request.fulfilled';
    public const REQUEST_RE_REQUESTED = 'request.re_requested';
    public const REQUEST_REVOKED      = 'request.revoked';

    // Suite.
    public const SUITE_REVOKED            = 'suite.revoked';
    public const SUITE_REINSTATED         = 'suite.reinstated';
    public const SUITE_RECOVERY_STARTED   = 'suite.recovery_started';
    public const SUITE_RECOVERY_COMPLETED = 'suite.recovery_completed';

    // Application.
    public const APPLICATION_REGISTERED       = 'application.registered';
    public const APPLICATION_APPROVED         = 'application.approved';
    public const APPLICATION_REJECTED         = 'application.rejected';
    public const APPLICATION_DELETED          = 'application.deleted';
    public const APPLICATION_TOKEN_ISSUED     = 'application.token_issued';
    public const APPLICATION_SECRET_RETRIEVED = 'application.secret_retrieved';

    // Emergency access (break-glass lifecycle — add-emergency-access).
    public const EMERGENCY_ACCESS_GRANTED     = 'emergency_access.granted';
    public const EMERGENCY_ACCESS_REQUESTED   = 'emergency_access.requested';
    public const EMERGENCY_ACCESS_DECLINED    = 'emergency_access.declined';
    public const EMERGENCY_ACCESS_APPROVED    = 'emergency_access.approved';
    public const EMERGENCY_ACCESS_ACCESSED    = 'emergency_access.accessed';
    public const EMERGENCY_ACCESS_REVOKED     = 'emergency_access.revoked';
    public const EMERGENCY_ACCESS_INVALIDATED = 'emergency_access.invalidated';

    // Export & deletion (consumed from secret-export-gdpr events when present).
    public const VAULT_EXPORTED        = 'vault.exported';
    public const VAULT_GDPR_EXPORTED   = 'vault.gdpr_exported';
    public const VAULT_ACCOUNT_DELETED = 'vault.account_deleted';

    // Secret version history (secret-version-history §6.3) — id + version
    // number only, never ciphertext or values.
    public const SECRET_VERSION_RESTORED = 'secret.version_restored';

    // Rotation & expiry (rotation-expiry-policies §5.2) — ids/reasons only.
    public const SECRET_EXPIRY_SET         = 'secret.expiry_set';
    public const SECRET_ROTATION_FLAGGED   = 'secret.rotation_flagged';
    public const SECRET_ROTATED            = 'secret.rotated';
    public const SECRET_ROTATION_DISMISSED = 'secret.rotation_dismissed';
    public const POLICY_EXPIRY_CHANGED     = 'policy.expiry_changed';

    // Org password policy (org-password-policies §3.1) — config values
    // only, never secret data.
    public const PASSWORD_POLICY_UPDATED = 'password_policy.updated';

    // Machine leases (machine-secret-leases §5.2) — ids + lifetimes only.
    public const LEASE_GRANTED = 'lease.granted';
    public const LEASE_RENEWED = 'lease.renewed';
    public const LEASE_REVOKED = 'lease.revoked';
    public const LEASE_EXPIRED = 'lease.expired';

    // Encrypted attachments (encrypted-attachments §5.1) — id/size only,
    // never filename, file key, or content.
    public const ATTACHMENT_UPLOADED   = 'attachment.uploaded';
    public const ATTACHMENT_DOWNLOADED = 'attachment.downloaded';
    public const ATTACHMENT_DELETED    = 'attachment.deleted';

    // Team folder sharing (team-folder-sharing §4.2).
    public const TEAM_FOLDER_SHARED         = 'team_folder.shared';
    public const TEAM_FOLDER_UNSHARED       = 'team_folder.unshared';
    public const TEAM_FOLDER_MEMBER_ADDED   = 'team_folder.member_added';
    public const TEAM_FOLDER_MEMBER_REMOVED = 'team_folder.member_removed';
    public const TEAM_FOLDER_OFFBOARDED     = 'team_folder.offboarded';
    // Folder permission grades (folder-permission-grades §3.3).
    public const TEAM_FOLDER_GRADE_CHANGED = 'team_folder.grade_changed';

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
     * @return array<string,string[]>
     */
    public static function whitelist(): array
    {
        return [
            self::SECRET_CREATED               => ['typeId', 'folderId'],
            self::SECRET_UPDATED               => ['changedFields'],
            self::SECRET_READ                  => [],
            self::SECRET_DELETED               => [],
            self::FOLDER_DELETED_CASCADE       => ['secretCount', 'subfolderCount'],
            self::SHARE_GRANTED                => ['recipientType', 'recipientId'],
            self::SHARE_REVOKED                => ['recipientType', 'recipientId'],
            self::SHARE_DELEGATED              => ['delegatedTo', 'isPermanent'],
            self::SHARE_DELEGATION_RECLAIMED   => ['delegatedTo'],
            self::LINK_SHARE_CREATED           => ['hasPassword', 'expiresAt'],
            self::LINK_SHARE_ACCESSED          => [],
            self::LINK_SHARE_ACCESS_FAILED     => ['reason'],
            self::LINK_SHARE_REVOKED           => [],
            self::LINK_SHARE_AUTO_DELETED      => ['reason'],
            self::REQUEST_CREATED              => ['recipientType', 'recipientId'],
            self::REQUEST_FULFILLED            => [],
            self::REQUEST_RE_REQUESTED         => [],
            self::REQUEST_REVOKED              => [],
            self::SUITE_REVOKED                => ['reason'],
            self::SUITE_REINSTATED             => [],
            self::SUITE_RECOVERY_STARTED       => [],
            self::SUITE_RECOVERY_COMPLETED     => ['reSuitedCount'],
            self::APPLICATION_REGISTERED       => [],
            self::APPLICATION_APPROVED         => [],
            self::APPLICATION_REJECTED         => ['reason'],
            self::APPLICATION_DELETED          => [],
            self::APPLICATION_TOKEN_ISSUED     => [],
            self::APPLICATION_SECRET_RETRIEVED => [],
            self::VAULT_EXPORTED               => ['mode', 'scope', 'secretCount'],
            self::VAULT_GDPR_EXPORTED          => ['mode', 'scope', 'secretCount'],
            self::VAULT_ACCOUNT_DELETED        => ['trigger', 'secretCount', 'shareCount', 'requestCount', 'suiteCount'],
            // Emergency access — only non-sensitive relationship references; the
            // recovery envelope and any key material are NEVER recorded (design D8).
            self::EMERGENCY_ACCESS_GRANTED     => ['grantorUserId', 'granteeUserId', 'accessLevel', 'waitPeriodDays'],
            self::EMERGENCY_ACCESS_REQUESTED   => ['grantorUserId', 'granteeUserId', 'waitPeriodDays'],
            self::EMERGENCY_ACCESS_DECLINED    => ['grantorUserId', 'granteeUserId'],
            self::EMERGENCY_ACCESS_APPROVED    => ['grantorUserId', 'granteeUserId'],
            self::EMERGENCY_ACCESS_ACCESSED    => ['grantorUserId', 'granteeUserId'],
            self::EMERGENCY_ACCESS_REVOKED     => ['grantorUserId', 'granteeUserId'],
            self::EMERGENCY_ACCESS_INVALIDATED => ['grantorUserId', 'granteeUserId', 'reason'],
            self::SECRET_VERSION_RESTORED      => ['versionNumber'],
            // Rotation & expiry — ids/reasons only (§5.2).
            self::SECRET_EXPIRY_SET            => ['expiresAt'],
            self::SECRET_ROTATION_FLAGGED      => ['reason'],
            self::SECRET_ROTATED               => ['reason'],
            self::SECRET_ROTATION_DISMISSED    => ['reason'],
            self::POLICY_EXPIRY_CHANGED        => ['scope', 'scopeId'],
            // Org password policy — before/after config values (§3.1).
            self::PASSWORD_POLICY_UPDATED      => ['before', 'after'],
            // Machine leases — ids + lifetimes only (§5.2).
            self::LEASE_GRANTED                => ['leaseId', 'secretId', 'expiresAt', 'ttl'],
            self::LEASE_RENEWED                => ['leaseId', 'secretId', 'expiresAt', 'renewedCount'],
            self::LEASE_REVOKED                => ['leaseId', 'secretId', 'expiresAt'],
            self::LEASE_EXPIRED                => ['leaseId', 'secretId', 'expiresAt'],
            // Encrypted attachments — id/size only (§5.1).
            self::ATTACHMENT_UPLOADED          => ['secretId', 'sizeBytes'],
            self::ATTACHMENT_DOWNLOADED        => ['secretId', 'sizeBytes'],
            self::ATTACHMENT_DELETED           => ['secretId', 'sizeBytes'],
            // Team folder sharing — identifiers only, never key material (§4.2).
            self::TEAM_FOLDER_SHARED           => ['folderId'],
            self::TEAM_FOLDER_UNSHARED         => ['folderId', 'revokedCount'],
            self::TEAM_FOLDER_MEMBER_ADDED     => ['memberType', 'memberId'],
            self::TEAM_FOLDER_MEMBER_REMOVED   => ['memberType', 'memberId', 'revokedCount'],
            self::TEAM_FOLDER_OFFBOARDED       => ['leavingUserId', 'successorUserId', 'revokedCount', 'transferredCount'],
            // Grade changes — identifiers + the new grade only (§3.3).
            self::TEAM_FOLDER_GRADE_CHANGED    => ['memberType', 'memberId', 'grade'],
        ];
    }//end whitelist()

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
    public static function isKnown(string $eventType): bool
    {
        return array_key_exists($eventType, self::whitelist());
    }//end isKnown()
}//end class
