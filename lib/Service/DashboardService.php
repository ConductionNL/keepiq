<?php

// SPDX-License-Identifier: EUPL-1.2
// Copyright (C) 2026 Conduction B.V.

/**
 * Doriath Dashboard Service
 *
 * Aggregates vault summary statistics from existing mappers for the dashboard.
 *
 * @category Service
 * @package  OCA\AppTemplate\Service
 *
 * @author    Conduction Development Team <dev@conductio.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://conduction.nl
 */

declare(strict_types=1);

namespace OCA\AppTemplate\Service;

use OCP\IConfig;

/**
 * Aggregates vault summary statistics for the dashboard.
 */
class DashboardService
{
    /**
     * Constructor.
     *
     * @param SettingsService $settingsService The settings service.
     * @param IConfig         $config          The user config service.
     *
     * @return void
     */
    public function __construct(
        private SettingsService $settingsService,
        private IConfig $config,
    ) {
    }//end __construct()

    /**
     * Fetch a summary of vault statistics for the given user.
     *
     * Aggregates counts from existing mappers. When those mappers are
     * integrated they should be injected here; until then the method
     * returns safe zero-defaults so the dashboard still renders.
     *
     * @param string $userId  The current user identifier.
     * @param bool   $isAdmin Whether the current user is an admin.
     *
     * @return array{
     *     totalSecrets: int,
     *     sharedSecrets: int,
     *     totalFolders: int,
     *     compromisedSecrets: int,
     *     migrationPending: int,
     *     migrationFailed: int,
     *     pendingApps: int,
     *     caHealthy: bool
     * }
     */
    public function fetchSummary(string $userId, bool $isAdmin): array
    {
        // TODO (V1): inject SecretMapper, FolderMapper, ApplicationMapper and
        // query real counts once those entities land (#1, #2, #3, #5).
        $summary = [
            'totalSecrets'       => 0,
            'sharedSecrets'      => 0,
            'totalFolders'       => 0,
            'compromisedSecrets' => 0,
            'migrationPending'   => 0,
            'migrationFailed'    => 0,
            'pendingApps'        => 0,
            'caHealthy'          => true,
        ];

        if ($isAdmin === true) {
            $adminSettings    = $this->settingsService->getAdminSettings();
            $summary['caHealthy'] = ($adminSettings['caStatus'] ?? 'unknown') === 'healthy';
        }

        return $summary;
    }//end fetchSummary()
}//end class
