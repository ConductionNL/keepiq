<?php

/**
 * Contract of CertificateLifecycleService
 *
 * The public surface of CertificateLifecycleService that other components call, so they depend
 * on this contract instead of the implementation.
 *
 * @category  Kernel
 * @package   OCA\Keepiq\Kernel\Contracts
 * @author    Conduction b.v. <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 * @version   GIT: <git_id>
 * @link      https://github.com/ConductionNL/keepiq
 *
 * @spec openspec/specs/app-shell/spec.md
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Keepiq\Kernel\Contracts;

use InvalidArgumentException;
use OCA\Keepiq\Db\CertificateMetadata;
use OCP\AppFramework\Db\DoesNotExistException;

/**
 * Contract of CertificateLifecycleService.
 *
 * @category Kernel
 * @package  OCA\Keepiq\Kernel\Contracts
 * @author   Conduction b.v. <info@conduction.nl>
 * @license  EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 * @link     https://github.com/ConductionNL/keepiq
 *
 * @spec exclude Structural contract, no functional requirement.
 */
interface CertificateLifecycleServiceInterface {

	/**
	 * The certificate inventory (§2.1): the caller's certificate-type
	 * secrets (client-parsed metadata) + the caller's active suite
	 * certificate (server-parsed); admins additionally see all active
	 * suites and the CA's own root/intermediate. Each row is tagged
	 * with its metadata provenance. No PEM, private key, or ciphertext
	 * is ever included.
	 *
	 * @param string $userId The calling user
	 * @param bool $isAdmin Whether the caller is an admin
	 *
	 * @return array<string,array<int,array<string,mixed>>>
	 *
	 * @spec openspec/changes/certificate-lifecycle/specs/certificate-lifecycle/spec.md#requirement-certificate-inventory
	 */
	public function inventory(string $userId, bool $isAdmin): array;

	/**
	 * Server-side parse of a cleartext CA-issued PEM (§2.2). Only ever
	 * called for suite/application/CA certificates the server already
	 * stores in the clear — never for encrypted stored secrets.
	 *
	 * @param string $pem The PEM certificate
	 *
	 * @return array<string,mixed>|null Parsed display fields, or null
	 *
	 * @spec openspec/specs/certificate-lifecycle/spec.md#requirement-metadata-parsing-split-by-pem-readability
	 */
	public function parseCaCertificate(string $pem): ?array;

	/**
	 * Re-issue a suite/application certificate from the private CA
	 * (§2.4, D3): re-signs the EXISTING public key via the CA service;
	 * a result that cannot preserve the key is rejected and the
	 * existing certificate kept. Owner-scoped; admins may re-issue any
	 * suite. Emits certificate.reissued.
	 *
	 * @param string $suiteId The suite UUID
	 * @param string $userId The calling user
	 * @param bool $isAdmin Whether the caller is an admin
	 *
	 * @return array<string,mixed> The refreshed server-parsed suite row
	 *
	 * @throws DoesNotExistException When the suite does not exist
	 * @throws InvalidArgumentException When the caller may not re-issue it
	 * @throws \RuntimeException When the re-sign could not preserve the key
	 *
	 * @spec openspec/specs/certificate-lifecycle/spec.md
	 */
	public function reissueSuite(string $suiteId, string $userId, bool $isAdmin): array;

	/**
	 * The guided renewal checklist for an externally-issued stored
	 * certificate (§2.5, D4): Keepiq has no signing relationship with
	 * the issuing CA, so the honest flow is a checklist plus a normal
	 * client-side replace-value update. Emits certificate.renewal_marked.
	 *
	 * @param string $secretId The secret UUID
	 * @param string $userId The calling owner
	 *
	 * @return array<string,mixed>
	 *
	 * @throws DoesNotExistException When the secret does not exist
	 * @throws InvalidArgumentException When not owned or wrong type
	 *
	 * @spec openspec/specs/certificate-lifecycle/spec.md
	 */
	public function renewalChecklist(string $secretId, string $userId): array;

	/**
	 * Persist client-parsed metadata for an owned certificate-type
	 * secret and mirror not_after to the secret's expires_at via the
	 * rotation-expiry per-secret path — no ciphertext change, no
	 * key_updated_at reset (§2.3, D5: the submission is trusted but
	 * owner-scoped and type-checked).
	 *
	 * @param string $secretId The secret UUID
	 * @param string $userId The calling owner
	 * @param array<string,mixed> $fields subject/issuer/serial/fingerprintSha256/notBefore/notAfter
	 *
	 * @return CertificateMetadata
	 *
	 * @throws DoesNotExistException When the secret does not exist
	 * @throws InvalidArgumentException When not owned, wrong type, or unparseable dates
	 * @throws \OCA\Keepiq\Exception\StaleWriteException When the secret moved twice while its expiry was set
	 *
	 * @spec openspec/specs/certificate-lifecycle/spec.md
	 */
	public function submitMetadata(string $secretId, string $userId, array $fields): CertificateMetadata;
}//end interface
