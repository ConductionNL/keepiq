<?php

/**
 * Doriath SecretExportedEvent
 *
 * Dispatched when a user completes a vault export (encrypted backup or
 * plaintext CSV). Because export runs client-side under the always-E2E model
 * (ADR-003), the browser reports the completed export to the server, which
 * emits this event for the session user only (secret-export-gdpr D5).
 *
 * The payload carries the export mode, scope, and secret count — NEVER secret
 * names, values, or ciphertext. The audit-trail change consumes this event via
 * its AuditListener (whitelist: mode, scope, secretCount).
 *
 * @category Event
 * @package  OCA\Doriath\Event
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

namespace OCA\Doriath\Event;

use OCP\EventDispatcher\Event;

/**
 * Fired when a vault export completes.
 */
class SecretExportedEvent extends Event
{
    /**
     * Constructor for SecretExportedEvent.
     *
     * @param string $userId      The session user that performed the export
     * @param string $mode        The export mode (encrypted-backup|plaintext-csv)
     * @param string $scope       The export scope (vault|folders)
     * @param int    $secretCount The number of secrets exported
     *
     * @return void
     */
    public function __construct(
        private string $userId,
        private string $mode,
        private string $scope,
        private int $secretCount,
    ) {
        parent::__construct();
    }//end __construct()

    /**
     * Get the acting user ID.
     *
     * @return string
     */
    public function getUserId(): string
    {
        return $this->userId;
    }//end getUserId()

    /**
     * Get the export mode.
     *
     * @return string
     */
    public function getMode(): string
    {
        return $this->mode;
    }//end getMode()

    /**
     * Get the export scope.
     *
     * @return string
     */
    public function getScope(): string
    {
        return $this->scope;
    }//end getScope()

    /**
     * Get the exported secret count.
     *
     * @return int
     */
    public function getSecretCount(): int
    {
        return $this->secretCount;
    }//end getSecretCount()

    /**
     * Get the audit metadata payload — counts and modes only, never secret
     * material. Keys match the AuditEventTypes whitelist for vault.exported.
     *
     * @return array<string,mixed>
     */
    public function getMetadata(): array
    {
        return [
            'mode'        => $this->mode,
            'scope'       => $this->scope,
            'secretCount' => $this->secretCount,
        ];
    }//end getMetadata()
}//end class
