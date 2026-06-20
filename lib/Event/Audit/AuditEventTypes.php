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

    // Export & deletion (consumed from secret-export-gdpr events when present).
    public const VAULT_EXPORTED        = 'vault.exported';
    public const VAULT_GDPR_EXPORTED   = 'vault.gdpr_exported';
    public const VAULT_ACCOUNT_DELETED = 'vault.account_deleted';

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
