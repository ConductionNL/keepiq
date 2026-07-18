<?php

declare(strict_types=1);

/*
 * Doriath route table.
 *
 * The canonical AppHost plumbing routes (dashboard page + SPA catch-all,
 * settings index/create/load, per-user preferences, and the observability
 * endpoints health#index / metrics#index) are provided by
 * \OCA\OpenRegister\AppHost\Routes::standard(). The /api/health and
 * /api/metrics URLs are unchanged; their controllers are aliased to the
 * AppHost generic controllers by Bootstrap::register() in Application.php.
 *
 * Every Doriath domain route is appended via $extra below — it is inserted
 * before the SPA catch-all so it keeps priority over the /{path} fallback.
 * This file references no OCA\OpenRegister symbol other than the pure array
 * builder Routes::standard(), so it is safe to require even when OpenRegister
 * is disabled.
 */

return \OCA\OpenRegister\AppHost\Routes::standard([
    // Dashboard summary (domain aggregator — DashboardController::summary()).
    ['name' => 'dashboard#summary', 'url' => '/api/dashboard/summary', 'verb' => 'GET'],

    // Admin + user settings split (implement-dashboard-settings §2.4).
    ['name' => 'settings#getAdminSettings',    'url' => '/api/settings/admin', 'verb' => 'GET'],
    ['name' => 'settings#updateAdminSettings', 'url' => '/api/settings/admin', 'verb' => 'PUT'],
    ['name' => 'settings#getUserSettings',     'url' => '/api/settings/user',  'verb' => 'GET'],
    ['name' => 'settings#updateUserSettings',  'url' => '/api/settings/user',  'verb' => 'PUT'],

    // EncryptionSuite CRUD.
    ['name' => 'encryptionSuite#index',             'url' => '/api/v1/suites',                          'verb' => 'GET'],
    ['name' => 'encryptionSuite#show',              'url' => '/api/v1/suites/{id}',                     'verb' => 'GET'],
    ['name' => 'encryptionSuite#create',            'url' => '/api/v1/suites',                          'verb' => 'POST'],
    ['name' => 'encryptionSuite#updatePrivateKey',  'url' => '/api/v1/suites/{id}/private-key',         'verb' => 'PUT'],
    ['name' => 'encryptionSuite#revoke',            'url' => '/api/v1/suites/{id}/revoke',              'verb' => 'POST'],
    ['name' => 'encryptionSuite#reinstate',         'url' => '/api/v1/suites/{id}/reinstate',           'verb' => 'POST'],
    ['name' => 'encryptionSuite#compromiseRecovery','url' => '/api/v1/suites/compromise-recovery',      'verb' => 'POST'],

    // CA management (admin-only).
    ['name' => 'cACertificate#getStatus',          'url' => '/api/v1/ca/status',                      'verb' => 'GET'],
    ['name' => 'cACertificate#retryBootstrap',     'url' => '/api/v1/ca/bootstrap-retry',              'verb' => 'POST'],
    ['name' => 'cACertificate#renewIntermediate',  'url' => '/api/v1/ca/renew-intermediate',           'verb' => 'POST'],
    ['name' => 'cACertificate#renewRoot',          'url' => '/api/v1/ca/renew-root',                   'verb' => 'POST'],

    // Migration tracking.
    ['name' => 'migration#getStatus',                'url' => '/api/v1/migrations/status',               'verb' => 'GET'],
    ['name' => 'migration#complete',                 'url' => '/api/v1/migrations/{id}/complete',        'verb' => 'POST'],

    // Key generator endpoint (stateless, authenticated).
    ['name' => 'keyGenerator#generate', 'url' => '/api/v1/generate-key', 'verb' => 'POST'],

    // Secret types CRUD (specific paths before the {id} secrets wildcard).
    ['name' => 'secretType#index',   'url' => '/api/v1/secret-types',      'verb' => 'GET'],
    ['name' => 'secretType#create',  'url' => '/api/v1/secret-types',      'verb' => 'POST'],
    ['name' => 'secretType#update',  'url' => '/api/v1/secret-types/{id}', 'verb' => 'PUT'],
    ['name' => 'secretType#destroy', 'url' => '/api/v1/secret-types/{id}', 'verb' => 'DELETE'],

    // Folder CRUD + children (children route before the {id} wildcard).
    ['name' => 'folder#index',    'url' => '/api/v1/folders',             'verb' => 'GET'],
    ['name' => 'folder#create',   'url' => '/api/v1/folders',             'verb' => 'POST'],
    ['name' => 'folder#children', 'url' => '/api/v1/folders/{id}/children', 'verb' => 'GET'],
    ['name' => 'folder#update',   'url' => '/api/v1/folders/{id}',        'verb' => 'PUT'],
    ['name' => 'folder#destroy',  'url' => '/api/v1/folders/{id}',        'verb' => 'DELETE'],

    // Secret CRUD. The nested link-shares route below is more specific and
    // is registered immediately after, so it still resolves correctly.
    ['name' => 'secret#index',   'url' => '/api/v1/secrets',      'verb' => 'GET'],
    ['name' => 'secret#create',  'url' => '/api/v1/secrets',      'verb' => 'POST'],
    // Batch import commit (secret-import D7). Accepts arrays of already
    // client-encrypted items; owner-scoped to the session user. The literal
    // /import-batch path is registered before the {id} wildcard so a POST
    // here never collides with the secret routes, and well before the SPA
    // catch-all wildcard.
    ['name' => 'import#batchCreate', 'url' => '/api/v1/secrets/import-batch', 'verb' => 'POST'],
    ['name' => 'secret#show',    'url' => '/api/v1/secrets/{id}', 'verb' => 'GET'],
    ['name' => 'secret#update',  'url' => '/api/v1/secrets/{id}', 'verb' => 'PUT'],
    ['name' => 'secret#destroy', 'url' => '/api/v1/secrets/{id}', 'verb' => 'DELETE'],

    // Link sharing — authenticated CRUD (secret owner).
    ['name' => 'linkShare#index',   'url' => '/api/v1/secrets/{secretId}/link-shares', 'verb' => 'GET'],
    ['name' => 'linkShare#create',  'url' => '/api/v1/secrets/{secretId}/link-shares', 'verb' => 'POST'],
    ['name' => 'linkShare#destroy', 'url' => '/api/v1/link-shares/{id}',                'verb' => 'DELETE'],

    // Link sharing — public access (no Nextcloud auth; two-phase protocol).
    ['name' => 'linkShareAccess#show',    'url' => '/api/v1/public/link-shares/{token}',         'verb' => 'GET'],
    ['name' => 'linkShareAccess#confirm', 'url' => '/api/v1/public/link-shares/{token}/confirm', 'verb' => 'POST'],

    // Emergency access — break-glass lifecycle (add-emergency-access §2.3).
    ['name' => 'emergencyAccess#index',              'url' => '/api/v1/emergency-access/contacts',                'verb' => 'GET'],
    ['name' => 'emergencyAccess#incoming',           'url' => '/api/v1/emergency-access/incoming',                'verb' => 'GET'],
    ['name' => 'emergencyAccess#granteeCertificate', 'url' => '/api/v1/emergency-access/grantee-certificate',     'verb' => 'GET'],
    ['name' => 'emergencyAccess#create',             'url' => '/api/v1/emergency-access/contacts',                'verb' => 'POST'],
    ['name' => 'emergencyAccess#destroy',            'url' => '/api/v1/emergency-access/contacts/{id}',           'verb' => 'DELETE'],
    ['name' => 'emergencyAccess#request',            'url' => '/api/v1/emergency-access/contacts/{id}/request',   'verb' => 'POST'],
    ['name' => 'emergencyAccess#decline',            'url' => '/api/v1/emergency-access/contacts/{id}/decline',   'verb' => 'POST'],
    ['name' => 'emergencyAccess#envelope',           'url' => '/api/v1/emergency-access/contacts/{id}/envelope',  'verb' => 'GET'],

    // User-to-user sharing — implement-user-sharing §9.
    ['name' => 'share#index',       'url' => '/api/v1/secrets/{secretId}/shares',       'verb' => 'GET'],
    ['name' => 'share#create',      'url' => '/api/v1/secrets/{secretId}/shares',       'verb' => 'POST'],
    ['name' => 'share#createBatch', 'url' => '/api/v1/secrets/{secretId}/shares/batch', 'verb' => 'POST'],
    ['name' => 'share#sync',        'url' => '/api/v1/secrets/{secretId}/sync',         'verb' => 'PUT'],
    ['name' => 'share#destroy',     'url' => '/api/v1/shares/{id}',                     'verb' => 'DELETE'],

    // Group sharing — implement-user-sharing §9.2.
    ['name' => 'groupShare#index',            'url' => '/api/v1/secrets/{secretId}/group-shares',           'verb' => 'GET'],
    ['name' => 'groupShare#create',           'url' => '/api/v1/secrets/{secretId}/group-shares',           'verb' => 'POST'],
    ['name' => 'groupShare#destroy',          'url' => '/api/v1/group-shares/{id}',                         'verb' => 'DELETE'],
    ['name' => 'groupShare#approveNewMember', 'url' => '/api/v1/group-shares/{id}/approve-new-member',      'verb' => 'POST'],
    ['name' => 'groupShare#denyNewMember',    'url' => '/api/v1/group-shares/{id}/deny-new-member',         'verb' => 'POST'],

    // Share requests — implement-user-sharing §9.3.
    ['name' => 'shareRequest#create',  'url' => '/api/v1/share-requests',           'verb' => 'POST'],
    ['name' => 'shareRequest#approve', 'url' => '/api/v1/share-requests/approve',   'verb' => 'POST'],
    ['name' => 'shareRequest#deny',    'url' => '/api/v1/share-requests/deny',      'verb' => 'POST'],

    // Delegations — implement-user-sharing §9.4.
    ['name' => 'delegation#index',   'url' => '/api/v1/secrets/{secretId}/delegations',         'verb' => 'GET'],
    ['name' => 'delegation#create',  'url' => '/api/v1/secrets/{secretId}/delegations',         'verb' => 'POST'],
    ['name' => 'delegation#reclaim', 'url' => '/api/v1/secrets/{secretId}/delegations/reclaim', 'verb' => 'POST'],

    // Secret requests — scaffold (implement-secret-requests).
    ['name' => 'secretRequest#index',   'url' => '/api/v1/secret-requests',              'verb' => 'GET'],
    ['name' => 'secretRequest#listBySecret', 'url' => '/api/v1/secrets/{secretId}/requests', 'verb' => 'GET'],
    ['name' => 'secretRequest#create',  'url' => '/api/v1/secret-requests',              'verb' => 'POST'],
    ['name' => 'secretRequest#approve', 'url' => '/api/v1/secret-requests/{id}/approve', 'verb' => 'POST'],
    ['name' => 'secretRequest#decline', 'url' => '/api/v1/secret-requests/{id}/decline', 'verb' => 'POST'],
    ['name' => 'secretRequest#destroy', 'url' => '/api/v1/secret-requests/{id}',         'verb' => 'DELETE'],

    // Secret requests — public fill-in flow (implement-secret-requests §4.2).
    // Token-based recipient endpoints; no Nextcloud auth.
    ['name' => 'secretRequestFill#show', 'url' => '/api/v1/public/secret-requests/{token}',      'verb' => 'GET'],
    ['name' => 'secretRequestFill#fill', 'url' => '/api/v1/public/secret-requests/{token}/fill', 'verb' => 'POST'],

    // Dashboard settings — scaffold (implement-dashboard-settings).
    ['name' => 'dashboardSettings#index',   'url' => '/api/v1/dashboard-settings',         'verb' => 'GET'],
    ['name' => 'dashboardSettings#show',    'url' => '/api/v1/dashboard-settings/{key}',   'verb' => 'GET'],
    ['name' => 'dashboardSettings#update',  'url' => '/api/v1/dashboard-settings',         'verb' => 'PUT'],
    ['name' => 'dashboardSettings#destroy', 'url' => '/api/v1/dashboard-settings',         'verb' => 'DELETE'],

    // Applications — scaffold (implement-application-mgmt). Specific
    // /pending route comes before the {id} wildcard.
    ['name' => 'application#pending', 'url' => '/api/v1/applications/pending',          'verb' => 'GET'],
    ['name' => 'application#index',   'url' => '/api/v1/applications',                  'verb' => 'GET'],
    ['name' => 'application#create',  'url' => '/api/v1/applications',                  'verb' => 'POST'],
    ['name' => 'application#approve', 'url' => '/api/v1/applications/{id}/approve',     'verb' => 'POST'],
    ['name' => 'application#reject',  'url' => '/api/v1/applications/{id}/reject',      'verb' => 'POST'],
    ['name' => 'application#certificate', 'url' => '/api/v1/applications/{id}/certificate', 'verb' => 'GET'],
    ['name' => 'application#show',    'url' => '/api/v1/applications/{id}',              'verb' => 'GET'],
    ['name' => 'application#destroy', 'url' => '/api/v1/applications/{id}',              'verb' => 'DELETE'],

    // Machine secret-store API discovery document (public, no auth —
    // reveals only endpoint shapes; openconnector-secret-store-api §1.1).
    ['name' => 'discovery#document', 'url' => '/api/v1/app/.well-known/doriath', 'verb' => 'GET'],

    // JWT-Bearer token exchange (public; signature-verified).
    ['name' => 'applicationToken#exchange', 'url' => '/api/v1/token', 'verb' => 'POST'],

    // Bearer-authenticated application secrets API (openconnector-secret-store-api).
    // JwtAuthMiddleware enforces the Authorization header before the controller runs.
    // The by-name route precedes {id} so its extra path segment resolves first.
    ['name' => 'applicationSecrets#index',  'url' => '/api/v1/app/secrets',                 'verb' => 'GET'],
    ['name' => 'applicationSecrets#create', 'url' => '/api/v1/app/secrets',                 'verb' => 'POST'],
    ['name' => 'applicationSecrets#byName', 'url' => '/api/v1/app/secrets/by-name/{name}',  'verb' => 'GET',
        'requirements' => ['name' => '.+']],
    ['name' => 'applicationSecrets#show',   'url' => '/api/v1/app/secrets/{id}',            'verb' => 'GET'],
    ['name' => 'applicationSecrets#update', 'url' => '/api/v1/app/secrets/{id}',            'verb' => 'PUT'],

    // Version history (secret-version-history §6.2). List rides under the
    // secret; show/restore address the version id. Per-object authorization
    // lives in SecretVersionService method bodies.
    ['name' => 'secretVersion#index',   'url' => '/api/v1/secrets/{secretId}/versions',  'verb' => 'GET'],
    ['name' => 'secretVersion#show',    'url' => '/api/v1/versions/{id}',                'verb' => 'GET'],
    ['name' => 'secretVersion#restore', 'url' => '/api/v1/versions/{id}/restore',        'verb' => 'POST'],

    // Rotation & expiry (rotation-expiry-policies §5.1).
    ['name' => 'rotation#getExpiry',     'url' => '/api/v1/secrets/{id}/expiry',        'verb' => 'GET'],
    ['name' => 'rotation#setExpiry',     'url' => '/api/v1/secrets/{id}/expiry',        'verb' => 'PUT'],
    ['name' => 'rotation#policies',      'url' => '/api/v1/expiry-policies',            'verb' => 'GET'],
    ['name' => 'rotation#upsertPolicy',  'url' => '/api/v1/expiry-policies',            'verb' => 'POST'],
    ['name' => 'rotation#destroyPolicy', 'url' => '/api/v1/expiry-policies/{id}',       'verb' => 'DELETE'],
    ['name' => 'rotation#flags',         'url' => '/api/v1/rotation-flags',             'verb' => 'GET'],
    ['name' => 'rotation#flagBatch',     'url' => '/api/v1/rotation-flags',             'verb' => 'POST'],
    ['name' => 'rotation#markRotated',   'url' => '/api/v1/rotation-flags/{id}/rotated', 'verb' => 'POST'],
    ['name' => 'rotation#dismissFlag',   'url' => '/api/v1/rotation-flags/{id}/dismiss', 'verb' => 'POST'],

    // Attachments (encrypted-attachments §4.2). Upload/list ride under the
    // owning secret; download/grants/delete address the attachment id.
    // Per-object authorization lives in AttachmentService method bodies.
    ['name' => 'attachment#create',   'url' => '/api/v1/secrets/{secretId}/attachments', 'verb' => 'POST'],
    ['name' => 'attachment#index',    'url' => '/api/v1/secrets/{secretId}/attachments', 'verb' => 'GET'],
    ['name' => 'attachment#download', 'url' => '/api/v1/attachments/{id}/blob',          'verb' => 'GET'],
    ['name' => 'attachment#addGrant', 'url' => '/api/v1/attachments/{id}/grants',        'verb' => 'POST'],
    ['name' => 'attachment#destroy',  'url' => '/api/v1/attachments/{id}',               'verb' => 'DELETE'],

    // Team folder sharing (team-folder-sharing §4.1). Static segments
    // (offboard) precede the {id} routes so they resolve first. Per-object
    // owner/admin guards live in TeamFolderService method bodies.
    ['name' => 'teamFolder#index',          'url' => '/api/v1/team-folders',                            'verb' => 'GET'],
    ['name' => 'teamFolder#create',         'url' => '/api/v1/team-folders',                            'verb' => 'POST'],
    ['name' => 'teamFolder#offboard',       'url' => '/api/v1/team-folders/offboard',                   'verb' => 'POST'],
    ['name' => 'teamFolder#members',        'url' => '/api/v1/team-folders/{id}/members',               'verb' => 'GET'],
    ['name' => 'teamFolder#addMember',      'url' => '/api/v1/team-folders/{id}/members',               'verb' => 'POST'],
    ['name' => 'teamFolder#removeMember',   'url' => '/api/v1/team-folders/{id}/members/{memberId}',    'verb' => 'DELETE'],
    ['name' => 'teamFolder#reconcile',      'url' => '/api/v1/team-folders/{id}/reconcile',             'verb' => 'GET'],
    ['name' => 'teamFolder#registerShares', 'url' => '/api/v1/team-folders/{id}/shares',                'verb' => 'POST'],
    ['name' => 'teamFolder#approveJoin',    'url' => '/api/v1/team-folders/{id}/approve-join',          'verb' => 'POST'],
    ['name' => 'teamFolder#destroy',        'url' => '/api/v1/team-folders/{id}',                       'verb' => 'DELETE'],

    // Audit trail (add-secret-audit-trail §4.1). Specific /secret/{id} and
    // /me routes come before the admin instance-wide /audit collection.
    ['name' => 'audit#secret', 'url' => '/api/v1/audit/secret/{id}', 'verb' => 'GET'],
    ['name' => 'audit#me',     'url' => '/api/v1/audit/me',          'verb' => 'GET'],
    ['name' => 'audit#index',  'url' => '/api/v1/audit',             'verb' => 'GET'],

    // Password-health breach-check proxy (password-health §1.5). Prefix-only
    // k-anonymity forward to HIBP; double-gated (admin setting + user opt-in).
    ['name' => 'breachProxy#range', 'url' => '/api/v1/breach-check/range/{prefix}', 'verb' => 'GET'],

    // GDPR data-subject endpoints (secret-export-gdpr D3/D4). All self-scoped
    // to the session user — no user selector. Master-password re-auth on the
    // delete is enforced client-side (ADR-003); the typed confirmation phrase
    // is the server-checkable gate.
    ['name' => 'gdpr#metadata',          'url' => '/api/v1/gdpr/metadata',     'verb' => 'GET'],
    ['name' => 'gdpr#deleteAccountData', 'url' => '/api/v1/gdpr/account-data', 'verb' => 'DELETE'],

    // Export-event emission (secret-export-gdpr D5). The client reports a
    // completed export before offering the local download; this dispatches
    // SecretExportedEvent for the session user. No secret material is sent.
    ['name' => 'export#events', 'url' => '/api/v1/export/events', 'verb' => 'POST'],
]);
