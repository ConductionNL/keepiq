<?php

/**
 * Contract of FederatedCertificateService
 *
 * The public surface of FederatedCertificateService that other components call, so they depend
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
use RuntimeException;

/**
 * Contract of FederatedCertificateService.
 *
 * @category Kernel
 * @package  OCA\Keepiq\Kernel\Contracts
 * @author   Conduction b.v. <info@conduction.nl>
 * @license  EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 * @link     https://github.com/ConductionNL/keepiq
 *
 * @spec exclude Structural contract, no functional requirement.
 */
interface FederatedCertificateServiceInterface {

	/**
	 * The OCM capability Keepiq advertises and answers under.
	 *
	 * @var string
	 */
	public const OCM_CAPABILITY = 'keepiq';

	/**
	 * Answer a partner's certificate lookup, or null for the unknown answer.
	 *
	 * @param string|null $signer The verified signer (OCMEndpointRequestEvent::getRemote()), null when unsigned
	 * @param array<array-key,mixed> $payload The request payload, `{cloudId}`
	 *
	 * @return array{cloudId:string,certificate:string,chain:array<int,string>,valueFields:string[]}|null
	 *
	 * @spec openspec/specs/federated-sharing/spec.md#requirement-certificate-lookup-is-signed-allowlisted-and-verified-in-the-browser
	 */
	public function answer(?string $signer, array $payload): ?array;

	/**
	 * Ask an outbound partner for a recipient's certificate.
	 *
	 * The answer names the value fields beyond `key`, `login` and `additionalFields` the partner stores,
	 * so the browser leaves out a `totp` or `passkeys` value it cannot hold.
	 *
	 * @param string $cloudId The recipient's cloud id, `bob@cloud.partner.example`
	 * @param string $userId The owner asking; their cloud id goes along as `sender`
	 *
	 * @return array{cloudId:string,certificate:string,chain:array<int,string>,partnerRootFingerprint:string,valueFields:list<string>}
	 *
	 * @throws InvalidArgumentException `not_a_partner` or `unknown_recipient`
	 * @throws RuntimeException `federation_unavailable`, `partner_unreachable` or `bad_chain`
	 *
	 * @spec openspec/specs/federated-sharing/spec.md#requirement-certificate-lookup-is-signed-allowlisted-and-verified-in-the-browser
	 */
	public function lookup(string $cloudId, string $userId): array;

	/**
	 * Whether users here can share with another organisation at all: this
	 * Nextcloud supports federation and at least one partner allows
	 * outbound shares. With none, the share dialog offers nothing.
	 *
	 * @return bool
	 *
	 * @spec openspec/specs/federated-sharing/spec.md#scenario-no-partner-no-federation
	 */
	public function outboundAvailable(): bool;
}//end interface
