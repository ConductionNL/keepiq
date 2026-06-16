<?php

/**
 * Doriath GdprExportPerformedEvent
 *
 * Dispatched when a user produces a GDPR Art. 15 personal-data export package.
 * The server half (metadata) is produced server-side; the vault half is
 * assembled client-side when the vault is unlocked. The browser reports whether
 * the vault half was included, and the server emits this event for the session
 * user (secret-export-gdpr D5).
 *
 * The payload carries only whether the vault was included — NEVER secret names,
 * values, or ciphertext. The audit-trail change consumes this via its
 * AuditListener (vault.gdpr_exported whitelist: mode, scope, secretCount).
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
 * Fired when a GDPR data export package is produced.
 */
class GdprExportPerformedEvent extends Event
{
    /**
     * Constructor for GdprExportPerformedEvent.
     *
     * @param string $userId        The session user that produced the export
     * @param bool   $includesVault Whether the decrypted vault half was included
     *
     * @return void
     */
    public function __construct(
        private string $userId,
        private bool $includesVault,
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
     * Whether the decrypted vault half was included.
     *
     * @return bool
     */
    public function includesVault(): bool
    {
        return $this->includesVault;
    }//end includesVault()

    /**
     * Get the audit metadata payload — no secret material. The 'scope' key maps
     * the vault-inclusion flag onto the whitelisted vault.gdpr_exported keys.
     *
     * @return array<string,mixed>
     */
    public function getMetadata(): array
    {
        return [
            'mode'  => 'gdpr-package',
            'scope' => ($this->includesVault === true ? 'metadata-and-vault' : 'metadata-only'),
        ];
    }//end getMetadata()
}//end class
