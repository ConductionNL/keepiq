<?php

/**
 * Doriath Certificate Metadata Service
 *
 * Everything about certificate-TYPE stored secrets: identifying them
 * (the system `certificate` type id) and persisting the owner's
 * CLIENT-parsed display metadata for them (certificate-lifecycle §2.3,
 * design D5). The server never decrypts a stored secret, so the only
 * metadata it can hold for one is what the owner's browser submits;
 * that submission is trusted in substance but owner-scoped and
 * type-checked here, and its not_after is mirrored into the
 * rotation-expiry per-secret expiry so the existing reminder job picks
 * it up. Server-side PEM parsing of CA-issued certificates is a
 * different path entirely and lives in CertificateLifecycleService.
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

use DateTime;
use InvalidArgumentException;
use OCA\Doriath\Db\CertificateMetadata;
use OCA\Doriath\Db\CertificateMetadataMapper;
use OCA\Doriath\Db\SecretMapper;
use OCA\Doriath\Db\SecretTypeMapper;
use OCP\AppFramework\Db\DoesNotExistException;
use Ramsey\Uuid\Uuid;

/**
 * Client-parsed metadata for certificate-type stored secrets.
 */
class CertificateMetadataService
{
    /**
     * Constructor for CertificateMetadataService.
     *
     * @param CertificateMetadataMapper $metadataMapper The metadata mapper
     * @param SecretMapper              $secretMapper   The secret mapper
     * @param SecretTypeMapper          $typeMapper     The secret type mapper
     * @param SecretService             $secretService  The secret service (expiry path)
     *
     * @return void
     *
     * @spec exclude Constructor wiring only; no behaviour.
     */
    public function __construct(
        private CertificateMetadataMapper $metadataMapper,
        private SecretMapper $secretMapper,
        private SecretTypeMapper $typeMapper,
        private SecretService $secretService,
    ) {
    }//end __construct()

    /**
     * The system certificate type id, or null before seeding.
     *
     * @return string|null
     *
     * @spec openspec/specs/certificate-lifecycle/spec.md
     */
    public function certificateTypeId(): ?string
    {
        try {
            return $this->typeMapper->findByName('certificate')->getId();
        } catch (DoesNotExistException) {
            return null;
        }
    }//end certificateTypeId()

    /**
     * Persist client-parsed metadata for an owned certificate-type
     * secret and mirror not_after to the secret's expires_at via the
     * rotation-expiry per-secret path — no ciphertext change, no
     * key_updated_at reset (§2.3, D5: the submission is trusted but
     * owner-scoped and type-checked).
     *
     * @param string              $secretId The secret UUID
     * @param string              $userId   The calling owner
     * @param array<string,mixed> $fields   subject/issuer/serial/fingerprintSha256/notBefore/notAfter
     *
     * @return CertificateMetadata
     *
     * @throws DoesNotExistException    When the secret does not exist
     * @throws InvalidArgumentException When not owned, wrong type, or unparseable dates
     *
     * @spec openspec/specs/certificate-lifecycle/spec.md
     */
    public function submitMetadata(string $secretId, string $userId, array $fields): CertificateMetadata
    {
        $secret = $this->secretMapper->findById($secretId);
        if ($secret->getOwnerType() !== 'user' || $secret->getOwnerId() !== $userId) {
            throw new InvalidArgumentException('Only the owner may submit certificate metadata');
        }

        $certTypeId = $this->certificateTypeId();
        if ($certTypeId === null || $secret->getTypeId() !== $certTypeId) {
            throw new InvalidArgumentException('Secret is not a certificate-type secret');
        }

        $notBefore = $this->parseClientDate(value: $fields['notBefore'] ?? null);
        $notAfter  = $this->parseClientDate(value: $fields['notAfter'] ?? null);

        $isNew = false;
        try {
            $row = $this->metadataMapper->findBySecretId($secretId);
        } catch (DoesNotExistException) {
            $isNew = true;
            $row   = new CertificateMetadata();
            $row->setId(Uuid::uuid4()->toString());
            $row->setSecretId($secretId);
        }

        $row->setOwnerId($userId);
        $row->setSubject($this->optionalString(value: $fields['subject'] ?? null));
        $row->setIssuer($this->optionalString(value: $fields['issuer'] ?? null));
        $row->setSerial($this->optionalString(value: $fields['serial'] ?? null));
        $row->setFingerprintSha256($this->optionalString(value: $fields['fingerprintSha256'] ?? null));
        $row->setNotBefore($notBefore);
        $row->setNotAfter($notAfter);
        $row->setParsedAt(new DateTime());

        if ($isNew === true) {
            $row = $this->metadataMapper->insert($row);
        }

        if ($isNew === false) {
            $row = $this->metadataMapper->update($row);
        }

        // Mirror notAfter into the rotation-expiry per-secret expiry so the
        // existing ScanExpiringSecretsJob reminds on it. setExpiry touches
        // neither ciphertext nor key_updated_at and audits SECRET_EXPIRY_SET.
        if ($notAfter !== null) {
            $this->secretService->setExpiry(id: $secretId, expiresAt: $notAfter, userId: $userId);
        }

        return $row;
    }//end submitMetadata()

    /**
     * Parse a client-submitted ISO date, rejecting garbage (D5: the
     * value is trusted in substance but must at least be a date).
     *
     * @param mixed $value The submitted value
     *
     * @return DateTime|null
     *
     * @throws InvalidArgumentException When set but unparseable
     */
    private function parseClientDate(mixed $value): ?DateTime
    {
        if ($value === null || $value === '') {
            return null;
        }

        try {
            return new DateTime((string) $value);
        } catch (\Exception) {
            throw new InvalidArgumentException('Unparseable certificate date: '.(string) $value);
        }
    }//end parseClientDate()

    /**
     * Trimmed string or null.
     *
     * @param mixed $value The submitted value
     *
     * @return string|null
     */
    private function optionalString(mixed $value): ?string
    {
        if (is_string($value) === false || trim($value) === '') {
            return null;
        }

        return trim($value);
    }//end optionalString()
}//end class
