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
        ['name' => 'encryption_suite#index',             'url' => '/api/v1/suites',                          'verb' => 'GET'],
        ['name' => 'encryption_suite#publicKey',         'url' => '/api/v1/suites/public-key/{userId}',      'verb' => 'GET'],
        ['name' => 'encryption_suite#show',              'url' => '/api/v1/suites/{id}',                     'verb' => 'GET'],
        ['name' => 'encryption_suite#create',            'url' => '/api/v1/suites',                          'verb' => 'POST'],
        ['name' => 'encryption_suite#updatePrivateKey',  'url' => '/api/v1/suites/{id}/private-key',         'verb' => 'PUT'],
        ['name' => 'encryption_suite#revoke',            'url' => '/api/v1/suites/{id}/revoke',              'verb' => 'POST'],
        ['name' => 'encryption_suite#reinstate',         'url' => '/api/v1/suites/{id}/reinstate',           'verb' => 'POST'],
        ['name' => 'encryption_suite#compromiseRecovery','url' => '/api/v1/suites/compromise-recovery',      'verb' => 'POST'],
        ['name' => 'encryption_suite#repair',           'url' => '/api/v1/suites/{id}/repair',              'verb' => 'POST'],
        ['name' => 'encryption_suite#confirmRepair',    'url' => '/api/v1/suites/{id}/repair/confirm',      'verb' => 'POST'],

        // CA management (admin-only).
        ['name' => 'c_a_certificate#getStatus',          'url' => '/api/v1/ca/status',                      'verb' => 'GET'],
        ['name' => 'c_a_certificate#retryBootstrap',     'url' => '/api/v1/ca/bootstrap-retry',              'verb' => 'POST'],
        ['name' => 'c_a_certificate#renewIntermediate',  'url' => '/api/v1/ca/renew-intermediate',           'verb' => 'POST'],
        ['name' => 'c_a_certificate#renewRoot',          'url' => '/api/v1/ca/renew-root',                   'verb' => 'POST'],

        // Migration tracking.
        ['name' => 'migration#getStatus',                'url' => '/api/v1/migrations/status',               'verb' => 'GET'],
        ['name' => 'migration#complete',                 'url' => '/api/v1/migrations/{id}/complete',        'verb' => 'POST'],

        // Secret CRUD.
        ['name' => 'secret#index',   'url' => '/api/v1/secrets',          'verb' => 'GET'],
        ['name' => 'secret#search',  'url' => '/api/v1/secrets/search',   'verb' => 'GET'],
        ['name' => 'secret#show',    'url' => '/api/v1/secrets/{id}',     'verb' => 'GET'],
        ['name' => 'secret#create',  'url' => '/api/v1/secrets',          'verb' => 'POST'],
        ['name' => 'secret#update',  'url' => '/api/v1/secrets/{id}',     'verb' => 'PUT'],
        ['name' => 'secret#destroy', 'url' => '/api/v1/secrets/{id}',     'verb' => 'DELETE'],
        ['name' => 'secret#migrate',          'url' => '/api/v1/secrets/{id}/migrate',       'verb' => 'PUT'],
        ['name' => 'secret#showForMigration', 'url' => '/api/v1/secrets/{id}/for-migration', 'verb' => 'GET'],

        // SecretType CRUD.
        ['name' => 'secret_type#index',   'url' => '/api/v1/secret-types',      'verb' => 'GET'],
        ['name' => 'secret_type#create',  'url' => '/api/v1/secret-types',      'verb' => 'POST'],
        ['name' => 'secret_type#update',  'url' => '/api/v1/secret-types/{id}', 'verb' => 'PUT'],
        ['name' => 'secret_type#destroy', 'url' => '/api/v1/secret-types/{id}', 'verb' => 'DELETE'],

        // Folder CRUD.
        ['name' => 'folder#index',    'url' => '/api/v1/folders',               'verb' => 'GET'],
        ['name' => 'folder#create',   'url' => '/api/v1/folders',               'verb' => 'POST'],
        ['name' => 'folder#update',   'url' => '/api/v1/folders/{id}',          'verb' => 'PUT'],
        ['name' => 'folder#destroy',  'url' => '/api/v1/folders/{id}',          'verb' => 'DELETE'],
        ['name' => 'folder#children', 'url' => '/api/v1/folders/{id}/children', 'verb' => 'GET'],

        // Sharing — user-level shares.
        ['name' => 'share#index',       'url' => '/api/v1/shares',                   'verb' => 'GET'],
        ['name' => 'share#create',      'url' => '/api/v1/shares',                   'verb' => 'POST'],
        ['name' => 'share#createBatch', 'url' => '/api/v1/shares/batch',             'verb' => 'POST'],
        ['name' => 'share#destroy',     'url' => '/api/v1/shares/{id}',              'verb' => 'DELETE'],
        ['name' => 'share#sync',        'url' => '/api/v1/shares/sync/{secretId}',   'verb' => 'PUT'],

        // Sharing — group-level shares.
        ['name' => 'group_share#index',   'url' => '/api/v1/group-shares',        'verb' => 'GET'],
        ['name' => 'group_share#create',  'url' => '/api/v1/group-shares',        'verb' => 'POST'],
        ['name' => 'group_share#destroy', 'url' => '/api/v1/group-shares/{id}',   'verb' => 'DELETE'],

        // Delegation.
        ['name' => 'delegation#index',   'url' => '/api/v1/delegations',                        'verb' => 'GET'],
        ['name' => 'delegation#create',  'url' => '/api/v1/delegations',                        'verb' => 'POST'],
        ['name' => 'delegation#reclaim', 'url' => '/api/v1/delegations/reclaim/{secretId}',     'verb' => 'POST'],

        // Prometheus metrics endpoint.
        ['name' => 'metrics#index', 'url' => '/api/metrics', 'verb' => 'GET'],
        // Health check endpoint.
        ['name' => 'health#index', 'url' => '/api/health', 'verb' => 'GET'],

        // SPA catch-all — same controller as the index route; must use a distinct route name
        // (duplicate names replace the earlier route in Symfony, which breaks GET /).
        ['name' => 'dashboard#catchAll', 'url' => '/{path}', 'verb' => 'GET', 'requirements' => ['path' => '.+'], 'defaults' => ['path' => '']],
    ],
];
