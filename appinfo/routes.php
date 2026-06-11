<?php

declare(strict_types=1);

return [
    'routes' => [
        // Dashboard + Settings.
        ['name' => 'dashboard#page', 'url' => '/', 'verb' => 'GET'],
        ['name' => 'settings#index', 'url' => '/api/settings', 'verb' => 'GET'],
        ['name' => 'settings#create', 'url' => '/api/settings', 'verb' => 'POST'],
        ['name' => 'settings#load',  'url' => '/api/settings/load', 'verb' => 'POST'],

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

        // User-to-user sharing — scaffold (implement-user-sharing).
        ['name' => 'share#index',   'url' => '/api/v1/secrets/{secretId}/shares', 'verb' => 'GET'],
        ['name' => 'share#create',  'url' => '/api/v1/secrets/{secretId}/shares', 'verb' => 'POST'],
        ['name' => 'share#destroy', 'url' => '/api/v1/shares/{id}',                'verb' => 'DELETE'],

        // Secret requests — scaffold (implement-secret-requests).
        ['name' => 'secretRequest#index',   'url' => '/api/v1/secret-requests',              'verb' => 'GET'],
        ['name' => 'secretRequest#create',  'url' => '/api/v1/secret-requests',              'verb' => 'POST'],
        ['name' => 'secretRequest#approve', 'url' => '/api/v1/secret-requests/{id}/approve', 'verb' => 'POST'],
        ['name' => 'secretRequest#decline', 'url' => '/api/v1/secret-requests/{id}/decline', 'verb' => 'POST'],
        ['name' => 'secretRequest#destroy', 'url' => '/api/v1/secret-requests/{id}',         'verb' => 'DELETE'],

        // Dashboard settings — scaffold (implement-dashboard-settings).
        ['name' => 'dashboardSettings#index',   'url' => '/api/v1/dashboard-settings',         'verb' => 'GET'],
        ['name' => 'dashboardSettings#show',    'url' => '/api/v1/dashboard-settings/{key}',   'verb' => 'GET'],
        ['name' => 'dashboardSettings#update',  'url' => '/api/v1/dashboard-settings',         'verb' => 'PUT'],
        ['name' => 'dashboardSettings#destroy', 'url' => '/api/v1/dashboard-settings',         'verb' => 'DELETE'],

        // Prometheus metrics endpoint.
        ['name' => 'metrics#index', 'url' => '/api/metrics', 'verb' => 'GET'],
        // Health check endpoint.
        ['name' => 'health#index', 'url' => '/api/health', 'verb' => 'GET'],

        // SPA catch-all — same controller as the index route; must use a distinct route name
        // (duplicate names replace the earlier route in Symfony, which breaks GET /).
        ['name' => 'dashboard#catchAll', 'url' => '/{path}', 'verb' => 'GET', 'requirements' => ['path' => '.+'], 'defaults' => ['path' => '']],
    ],
];
