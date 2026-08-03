<?php

/**
 * Doriath Certificate Lifecycle Service
 *
 * Certificate inventory, client-parsed metadata persistence, guided
 * renewal, and suite re-issue orchestration (certificate-lifecycle §2).
 * The core split (design D1): encrypted certificate-type secrets are
 * parsed CLIENT-side (the server holds only ciphertext, ADR-003) and
 * the browser submits non-secret display metadata; CA-issued suite /
 * application / CA certificates are parsed SERVER-side from the
 * cleartext PEM Doriath already stores. No private key or ciphertext is
 * ever emitted.
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
use OCA\Doriath\Db\CACertificateMapper;
use OCA\Doriath\Db\CertificateMetadata;
use OCA\Doriath\Db\CertificateMetadataMapper;
use OCA\Doriath\Db\EncryptionSuite;
use OCA\Doriath\Db\EncryptionSuiteMapper;
use OCA\Doriath\Db\Secret;
use OCA\Doriath\Db\SecretMapper;
use OCA\Doriath\Db\SecretTypeMapper;
use OCA\Doriath\Event\Audit\AuditEvent;
use OCA\Doriath\Event\Audit\AuditEventTypes;
use OCP\AppFramework\Db\DoesNotExistException;
use OCP\EventDispatcher\IEventDispatcher;
use Ramsey\Uuid\Uuid;
use RuntimeException;

/**
 * Business logic for the certificate lifecycle capability.
 *
 * @SuppressWarnings(PHPMD.CouplingBetweenObjects) The inventory merges
 *   three certificate domains by design — the seams are the mappers.
 */
class CertificateLifecycleService
{
    /**
     * Constructor for CertificateLifecycleService.
     *
     * @param CertificateMetadataMapper   $metadataMapper  The metadata mapper
     * @param SecretMapper                $secretMapper    The secret mapper
     * @param SecretTypeMapper            $typeMapper      The secret type mapper
     * @param EncryptionSuiteMapper       $suiteMapper     The suite mapper
     * @param CACertificateMapper         $caMapper        The CA certificate mapper
     * @param SecretService               $secretService   The secret service (expiry path)
     * @param CertificateAuthorityService $caService       The CA service (re-issue)
     * @param IEventDispatcher|null       $eventDispatcher The audit dispatcher
     *
     * @return void
     */
    public function __construct(
        private CertificateMetadataMapper $metadataMapper,
        private SecretMapper $secretMapper,
        private SecretTypeMapper $typeMapper,
        private EncryptionSuiteMapper $suiteMapper,
        private CACertificateMapper $caMapper,
        private SecretService $secretService,
        private CertificateAuthorityService $caService,
        private ?IEventDispatcher $eventDispatcher=null,
    ) {
    }//end __construct()

    /**
     * The certificate inventory (§2.1): the caller's certificate-type
     * secrets (client-parsed metadata) + the caller's active suite
     * certificate (server-parsed); admins additionally see all active
     * suites and the CA's own root/intermediate. Each row is tagged
     * with its metadata provenance. No PEM, private key, or ciphertext
     * is ever included.
     *
     * @param string $userId  The calling user
     * @param bool   $isAdmin Whether the caller is an admin
     *
     * @return array<string,array<int,array<string,mixed>>>
     *
     * @spec openspec/changes/certificate-lifecycle/specs/certificate-lifecycle/spec.md#requirement-certificate-inventory
     */
    public function inventory(string $userId, bool $isAdmin): array
    {
        $stored     = [];
        $certTypeId = $this->certificateTypeId();
        if ($certTypeId !== null) {
            $metadataBySecret = $this->metadataMapper->findByOwner($userId);
            foreach ($this->secretMapper->findByOwner(ownerType: 'user', ownerId: $userId, typeId: $certTypeId) as $secret) {
                $stored[] = $this->storedRow(secret: $secret, metadata: ($metadataBySecret[$secret->getId()] ?? null));
            }
        }

        $suites = [];
        if ($isAdmin === true) {
            foreach ($this->suiteMapper->findAllActive() as $suite) {
                $suites[] = $this->suiteRow(suite: $suite);
            }
        } else {
            try {
                $suites[] = $this->suiteRow(suite: $this->suiteMapper->findActiveByOwner('user', $userId));
            } catch (DoesNotExistException) {
                // No active suite yet — nothing to list.
            }
        }

        $caRows = [];
        if ($isAdmin === true) {
            try {
                $root         = $this->caMapper->findRoot();
                $intermediate = $this->caMapper->findActiveIntermediate();
                $caRows[]     = $this->caRow(kind: 'root', pem: $root->getCertificate());
                $caRows[]     = $this->caRow(kind: 'intermediate', pem: $intermediate->getCertificate());
            } catch (DoesNotExistException) {
                // CA not bootstrapped — no CA rows.
            }
        }

        return [
            'stored' => $stored,
            'suites' => $suites,
            'ca'     => $caRows,
        ];
    }//end inventory()

    /**
     * Server-side parse of a cleartext CA-issued PEM (§2.2). Only ever
     * called for suite/application/CA certificates the server already
     * stores in the clear — never for encrypted stored secrets.
     *
     * @param string $pem The PEM certificate
     *
     * @return array<string,mixed>|null Parsed display fields, or null
     */
    public function parseCaCertificate(string $pem): ?array
    {
        $parsed = @openssl_x509_parse(certificate: $pem);
        if (is_array($parsed) === false) {
            return null;
        }

        $fingerprint = openssl_x509_fingerprint(certificate: $pem, digest_algo: 'sha256');

        $notBefore = null;
        if (isset($parsed['validFrom_time_t']) === true) {
            $notBefore = (new DateTime())->setTimestamp((int) $parsed['validFrom_time_t']);
        }

        $notAfter = null;
        if (isset($parsed['validTo_time_t']) === true) {
            $notAfter = (new DateTime())->setTimestamp((int) $parsed['validTo_time_t']);
        }

        $serial = '';
        if (isset($parsed['serialNumberHex']) === true) {
            $serial = (string) $parsed['serialNumberHex'];
        }

        $fingerprintValue = null;
        if ($fingerprint !== false) {
            $fingerprintValue = 'sha256:'.$fingerprint;
        }

        return [
            'subject'           => $this->dnToString(dnParts: (array) ($parsed['subject'] ?? [])),
            'issuer'            => $this->dnToString(dnParts: (array) ($parsed['issuer'] ?? [])),
            'serial'            => $serial,
            'fingerprintSha256' => $fingerprintValue,
            'notBefore'         => $notBefore?->format('c'),
            'notAfter'          => $notAfter?->format('c'),
        ];
    }//end parseCaCertificate()

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
        } else {
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
     * The guided renewal checklist for an externally-issued stored
     * certificate (§2.5, D4): Doriath has no signing relationship with
     * the issuing CA, so the honest flow is a checklist plus a normal
     * client-side replace-value update. Emits certificate.renewal_marked.
     *
     * @param string $secretId The secret UUID
     * @param string $userId   The calling owner
     *
     * @return array<string,mixed>
     *
     * @throws DoesNotExistException    When the secret does not exist
     * @throws InvalidArgumentException When not owned or wrong type
     */
    public function renewalChecklist(string $secretId, string $userId): array
    {
        $secret = $this->secretMapper->findById($secretId);
        if ($secret->getOwnerType() !== 'user' || $secret->getOwnerId() !== $userId) {
            throw new InvalidArgumentException('Only the owner may request a renewal checklist');
        }

        $certTypeId = $this->certificateTypeId();
        if ($certTypeId === null || $secret->getTypeId() !== $certTypeId) {
            throw new InvalidArgumentException('Secret is not a certificate-type secret');
        }

        $this->eventDispatcher?->dispatchTyped(
            AuditEvent::forUser(
                actorId: $userId,
                eventType: AuditEventTypes::CERTIFICATE_RENEWAL_MARKED,
                objectType: 'secret',
                objectId: $secretId,
                objectName: $secret->getName(),
                metadata: [],
            )
        );

        return [
            'renewable' => false,
            'reason'    => 'externally_issued',
            'steps'     => [
                'Request a renewed certificate from the issuing CA (Doriath has no signing relationship with it and cannot renew on your behalf).',
                'Verify the renewed certificate chains to the expected CA and covers the same subject.',
                'Note the new expiry (notAfter) date.',
                'Update this secret with the new certificate value — the update re-encrypts in your browser and Doriath never sees the plaintext.',
                'Re-parse the certificate so the inventory metadata and expiry reminder follow the new notAfter.',
            ],
        ];
    }//end renewalChecklist()

    /**
     * Re-issue a suite/application certificate from the private CA
     * (§2.4, D3): re-signs the EXISTING public key via the CA service;
     * a result that cannot preserve the key is rejected and the
     * existing certificate kept. Owner-scoped; admins may re-issue any
     * suite. Emits certificate.reissued.
     *
     * @param string $suiteId The suite UUID
     * @param string $userId  The calling user
     * @param bool   $isAdmin Whether the caller is an admin
     *
     * @return array<string,mixed> The refreshed server-parsed suite row
     *
     * @throws DoesNotExistException    When the suite does not exist
     * @throws InvalidArgumentException When the caller may not re-issue it
     * @throws \RuntimeException        When the re-sign could not preserve the key
     */
    public function reissueSuite(string $suiteId, string $userId, bool $isAdmin): array
    {
        $suite  = $this->suiteMapper->findById($suiteId);
        $ownsIt = ($suite->getOwnerType() === 'user' && $suite->getOwnerId() === $userId);
        if ($ownsIt === false && $isAdmin === false) {
            throw new InvalidArgumentException('Only the suite owner or an admin may re-issue');
        }

        if ($this->caService->reissueSuiteCertificate(suite: $suite) === false) {
            throw new RuntimeException('Re-issue could not preserve the existing public key; certificate unchanged');
        }

        $this->eventDispatcher?->dispatchTyped(
            AuditEvent::forUser(
                actorId: $userId,
                eventType: AuditEventTypes::CERTIFICATE_REISSUED,
                objectType: 'encryption_suite',
                objectId: $suiteId,
                objectName: '',
                metadata: ['suiteId' => $suiteId],
            )
        );

        return $this->suiteRow(suite: $suite);
    }//end reissueSuite()

    /**
     * An inventory row for a stored certificate-type secret — metadata
     * is whatever the owner's browser submitted; never derived here.
     *
     * @param Secret                   $secret   The secret row
     * @param CertificateMetadata|null $metadata The client-parsed metadata
     *
     * @return array<string,mixed>
     */
    private function storedRow(Secret $secret, ?CertificateMetadata $metadata): array
    {
        return [
            'kind'           => 'stored_secret',
            'id'             => $secret->getId(),
            'name'           => $secret->getName(),
            'metadataSource' => 'client_parsed',
            'metadata'       => $metadata?->jsonSerialize(),
            'expiresAt'      => $secret->getExpiresAt()?->format('c'),
        ];
    }//end storedRow()

    /**
     * An inventory row for a CA-issued suite certificate (server-parsed
     * from the cleartext PEM; the PEM itself is not emitted).
     *
     * @param EncryptionSuite $suite The suite
     *
     * @return array<string,mixed>
     */
    private function suiteRow(EncryptionSuite $suite): array
    {
        $metadata = null;
        $pem      = $suite->getCertificate();
        if ($pem !== null && $pem !== '') {
            $metadata = $this->parseCaCertificate(pem: $pem);
        }

        return [
            'kind'           => 'suite',
            'id'             => $suite->getId(),
            'ownerType'      => $suite->getOwnerType(),
            'ownerId'        => $suite->getOwnerId(),
            'status'         => $suite->getStatus(),
            'metadataSource' => 'server_parsed',
            'metadata'       => $metadata,
        ];
    }//end suiteRow()

    /**
     * An inventory row for a CA root/intermediate certificate.
     *
     * @param string $kind 'root' or 'intermediate'
     * @param string $pem  The cleartext PEM
     *
     * @return array<string,mixed>
     */
    private function caRow(string $kind, string $pem): array
    {
        return [
            'kind'           => 'ca',
            'caRole'         => $kind,
            'metadataSource' => 'server_parsed',
            'metadata'       => $this->parseCaCertificate(pem: $pem),
        ];
    }//end caRow()

    /**
     * The system certificate type id, or null before seeding.
     *
     * @return string|null
     */
    private function certificateTypeId(): ?string
    {
        try {
            return $this->typeMapper->findByName('certificate')->getId();
        } catch (DoesNotExistException) {
            return null;
        }
    }//end certificateTypeId()

    /**
     * Render an X.509 DN array as a readable string.
     *
     * @param array<string,mixed> $dnParts The parsed DN parts
     *
     * @return string
     */
    private function dnToString(array $dnParts): string
    {
        $parts = [];
        foreach ($dnParts as $dnKey => $dnValue) {
            if (is_array($dnValue) === true) {
                $dnValue = implode('+', array_map('strval', $dnValue));
            }

            $parts[] = $dnKey.'='.$dnValue;
        }

        return implode(', ', $parts);
    }//end dnToString()

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
