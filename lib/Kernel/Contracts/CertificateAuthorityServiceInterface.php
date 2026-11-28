<?php

/**
 * Contract of CertificateAuthorityService
 *
 * The public surface of CertificateAuthorityService that other components call, so they depend
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

/**
 * Contract of CertificateAuthorityService.
 *
 * @category Kernel
 * @package  OCA\Keepiq\Kernel\Contracts
 * @author   Conduction b.v. <info@conduction.nl>
 * @license  EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 * @link     https://github.com/ConductionNL/keepiq
 *
 * @spec exclude Structural contract, no functional requirement.
 */
interface CertificateAuthorityServiceInterface {

	/**
	 * Bootstrap the CA: generate root + intermediate certificates.
	 *
	 * Idempotent in three ways:
	 *  - Skips entirely when both root and an active intermediate already exist (healthy).
	 *  - Recovers (creates only the missing intermediate) when a root exists but no
	 *    active intermediate does — this is the half-broken state left behind by the
	 *    pre-#40 Postgres SMALLINT/boolean mismatch that aborted the second insert
	 *    after the root was already persisted.
	 *  - Performs the full root + intermediate bootstrap when neither exists.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/retrofit-2026-05-25-doriath-coverage/tasks.md#task-1
	 */
	public function bootstrap(): void;

	/**
	 * Get the current CA status.
	 *
	 * The `issued` key is present only on the configured path — the
	 * `not_configured` early returns have no CA to count against.
	 *
	 * @return array{status: string, root: ?array, intermediate: ?array, issued?: array<string,int>}
	 *
	 * @spec openspec/changes/retrofit-2026-05-25-doriath-coverage/tasks.md#task-1
	 */
	public function getStatus(): array;

	/**
	 * Renew the intermediate certificate on schedule.
	 *
	 * The old intermediate is deactivated but NOT revoked: certificates it
	 * signed stay valid until they are re-signed. Called by the daily
	 * RenewIntermediateCertificate job.
	 *
	 * @return int Number of suites re-signed.
	 *
	 * @spec openspec/changes/retrofit-2026-05-25-doriath-coverage/tasks.md#task-1
	 */
	public function renewIntermediate(): int;

	/**
	 * Renew the intermediate certificate and revoke the old one immediately.
	 *
	 * The compromise path: the same rollover as renewIntermediate(), plus a
	 * revokedAt on the old intermediate. Called by the admin force-renew
	 * endpoint.
	 *
	 * @return int Number of suites re-signed.
	 *
	 * @spec openspec/changes/retrofit-2026-05-25-doriath-coverage/tasks.md#task-1
	 */
	public function renewIntermediateRevokingOld(): int;

	/**
	 * Renew the root certificate and generate a new intermediate.
	 *
	 * @return int Number of suites re-signed.
	 *
	 * @spec openspec/changes/retrofit-2026-05-25-doriath-coverage/tasks.md#task-1
	 */
	public function renewRoot(): int;

	/**
	 * Retry bootstrap (called from admin panel when CA is degraded).
	 *
	 * @return void
	 *
	 * @spec openspec/changes/retrofit-2026-05-25-doriath-coverage/tasks.md#task-1
	 */
	public function retryBootstrap(): void;

	/**
	 * Sign a public key PEM with the active intermediate, returning an X.509 certificate PEM.
	 *
	 * @param string $publicKeyPem The PEM-encoded public key
	 * @param string $commonName The common name for the certificate (e.g. user ID or app name)
	 * @param string|null $privateKeyPem The PEM-encoded matching private key, when available
	 *
	 * @return string
	 *
	 * @spec openspec/changes/retrofit-2026-05-25-doriath-coverage/tasks.md#task-1
	 */
	public function signPublicKey(
		string $publicKeyPem,
		string $commonName = 'Keepiq User',
		?string $privateKeyPem = null,
	): string;
}//end interface
