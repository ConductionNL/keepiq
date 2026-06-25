<?php

/**
 * Doriath DeletionReport
 *
 * A small value object accumulating per-entity counts produced by the
 * account-deletion cascade (secret-export-gdpr D4). It is returned to the
 * in-app deletion caller and folded into the AccountDataDeletedEvent payload.
 * It holds counts only — never any secret material or personal data.
 *
 * @category Service
 * @package  OCA\Doriath\Service
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

namespace OCA\Doriath\Service;

use JsonSerializable;

/**
 * Per-entity counts from an account-deletion cascade run.
 */
class DeletionReport implements JsonSerializable
{

    /**
     * Number of own secrets hard-deleted.
     *
     * @var integer
     */
    public int $secretsDeleted = 0;

    /**
     * Number of delegated secrets whose ownership transferred to the delegate.
     *
     * @var integer
     */
    public int $secretsTransferred = 0;

    /**
     * Number of recipient copies detached and tombstoned.
     *
     * @var integer
     */
    public int $sharesDetached = 0;

    /**
     * Number of received share copies (and their links) hard-deleted.
     *
     * @var integer
     */
    public int $sharesRemoved = 0;

    /**
     * Number of folders deleted.
     *
     * @var integer
     */
    public int $foldersDeleted = 0;

    /**
     * Number of link shares deleted.
     *
     * @var integer
     */
    public int $linkSharesDeleted = 0;

    /**
     * Number of secret requests deleted.
     *
     * @var integer
     */
    public int $requestsDeleted = 0;

    /**
     * Number of encryption suites deleted.
     *
     * @var integer
     */
    public int $suitesDeleted = 0;

    /**
     * Whether user settings/preferences were removed.
     *
     * @var boolean
     */
    public bool $settingsDeleted = false;

    /**
     * Serialize the report for the API response and the audit event.
     *
     * @return array<string,mixed>
     *
     * @spec openspec/changes/secret-export-gdpr/specs/gdpr-compliance/spec.md
     */
    public function jsonSerialize(): array
    {
        return [
            'secretsDeleted'     => $this->secretsDeleted,
            'secretsTransferred' => $this->secretsTransferred,
            'sharesDetached'     => $this->sharesDetached,
            'sharesRemoved'      => $this->sharesRemoved,
            'foldersDeleted'     => $this->foldersDeleted,
            'linkSharesDeleted'  => $this->linkSharesDeleted,
            'requestsDeleted'    => $this->requestsDeleted,
            'suitesDeleted'      => $this->suitesDeleted,
            'settingsDeleted'    => $this->settingsDeleted,
        ];
    }//end jsonSerialize()
}//end class
