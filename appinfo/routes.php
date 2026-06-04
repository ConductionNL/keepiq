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

        // Link sharing — authenticated CRUD (secret owner).
        ['name' => 'linkShare#index',   'url' => '/api/v1/secrets/{secretId}/link-shares', 'verb' => 'GET'],
        ['name' => 'linkShare#create',  'url' => '/api/v1/secrets/{secretId}/link-shares', 'verb' => 'POST'],
        ['name' => 'linkShare#destroy', 'url' => '/api/v1/link-shares/{id}',                'verb' => 'DELETE'],

        // Link sharing — public access (no Nextcloud auth; two-phase protocol).
        ['name' => 'linkShareAccess#show',    'url' => '/api/v1/public/link-shares/{token}',         'verb' => 'GET'],
        ['name' => 'linkShareAccess#confirm', 'url' => '/api/v1/public/link-shares/{token}/confirm', 'verb' => 'POST'],

        // Application management — Nextcloud session auth.
        // Static/verb routes are listed BEFORE the {id} wildcard.
        ['name' => 'application#index',    'url' => '/api/v1/applications',             'verb' => 'GET'],
        ['name' => 'application#register', 'url' => '/api/v1/applications',             'verb' => 'POST'],
        ['name' => 'application#pending',  'url' => '/api/v1/applications/pending',     'verb' => 'GET'],
        ['name' => 'application#approve',  'url' => '/api/v1/applications/{id}/approve','verb' => 'POST'],
        ['name' => 'application#reject',   'url' => '/api/v1/applications/{id}/reject', 'verb' => 'POST'],
        ['name' => 'application#show',     'url' => '/api/v1/applications/{id}',        'verb' => 'GET'],
        ['name' => 'application#destroy',  'url' => '/api/v1/applications/{id}',        'verb' => 'DELETE'],

        // Application API — RFC 7523 JWT Bearer (public token + Bearer-guarded secrets).
        ['name' => 'applicationToken#exchange', 'url' => '/api/v1/token',            'verb' => 'POST'],
        ['name' => 'applicationSecrets#index',  'url' => '/api/v1/app/secrets',      'verb' => 'GET'],
        ['name' => 'applicationSecrets#show',   'url' => '/api/v1/app/secrets/{id}', 'verb' => 'GET'],

        // Prometheus metrics endpoint.
        ['name' => 'metrics#index', 'url' => '/api/metrics', 'verb' => 'GET'],
        // Health check endpoint.
        ['name' => 'health#index', 'url' => '/api/health', 'verb' => 'GET'],

        // SPA catch-all — same controller as the index route; must use a distinct route name
        // (duplicate names replace the earlier route in Symfony, which breaks GET /).
        ['name' => 'dashboard#catchAll', 'url' => '/{path}', 'verb' => 'GET', 'requirements' => ['path' => '.+'], 'defaults' => ['path' => '']],
    ],
];
