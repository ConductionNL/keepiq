<?php

// SPDX-License-Identifier: EUPL-1.2
// Copyright (C) 2026 Conduction B.V.

/**
 * Doriath application routes
 *
 * Maps URL patterns to controller actions for the Doriath Nextcloud app.
 *
 * @category Routes
 * @package  OCA\AppTemplate
 *
 * @author    Conduction Development Team <dev@conductio.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://conduction.nl
 */

declare(strict_types=1);

return [
    'routes' => [
        // ------------------------------------------------------------------ //
        // Dashboard                                                           //
        // ------------------------------------------------------------------ //
        ['name' => 'dashboard#page',    'url' => '/',                       'verb' => 'GET'],
        ['name' => 'dashboard#summary', 'url' => '/api/dashboard/summary',  'verb' => 'GET'],

        // ------------------------------------------------------------------ //
        // Settings — admin (admin-only, no @NoAdminRequired)                 //
        // ------------------------------------------------------------------ //
        ['name' => 'settings#getAdminSettings',    'url' => '/api/settings/admin', 'verb' => 'GET'],
        ['name' => 'settings#updateAdminSettings', 'url' => '/api/settings/admin', 'verb' => 'PUT'],

        // ------------------------------------------------------------------ //
        // Settings — user (@NoAdminRequired)                                 //
        // ------------------------------------------------------------------ //
        ['name' => 'settings#getUserSettings',    'url' => '/api/settings/user', 'verb' => 'GET'],
        ['name' => 'settings#updateUserSettings', 'url' => '/api/settings/user', 'verb' => 'PUT'],

        // ------------------------------------------------------------------ //
        // Legacy settings endpoints (kept for backward-compatibility)        //
        // ------------------------------------------------------------------ //
        ['name' => 'settings#index',  'url' => '/api/settings',       'verb' => 'GET'],
        ['name' => 'settings#create', 'url' => '/api/settings',       'verb' => 'POST'],
        ['name' => 'settings#load',   'url' => '/api/settings/load',  'verb' => 'POST'],

        // ------------------------------------------------------------------ //
        // Observability                                                       //
        // ------------------------------------------------------------------ //
        ['name' => 'metrics#index', 'url' => '/api/metrics', 'verb' => 'GET'],
        ['name' => 'health#index',  'url' => '/api/health',  'verb' => 'GET'],

        // ------------------------------------------------------------------ //
        // SPA catch-all — serves the Vue app for any frontend route          //
        // ------------------------------------------------------------------ //
        [
            'name'         => 'dashboard#page',
            'url'          => '/{path}',
            'verb'         => 'GET',
            'requirements' => ['path' => '.+'],
            'defaults'     => ['path' => ''],
        ],
    ],
];
