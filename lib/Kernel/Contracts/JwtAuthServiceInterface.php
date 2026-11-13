<?php

/**
 * Contract of JwtAuthService
 *
 * The public surface of JwtAuthService that other components call, so they depend
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

use OCA\Keepiq\Db\Application;
use RuntimeException;

/**
 * Contract of JwtAuthService.
 *
 * @category Kernel
 * @package  OCA\Keepiq\Kernel\Contracts
 * @author   Conduction b.v. <info@conduction.nl>
 * @license  EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 * @link     https://github.com/ConductionNL/keepiq
 *
 * @spec exclude Structural contract, no functional requirement.
 */
interface JwtAuthServiceInterface {

	/**
	 * Lifetime of issued access tokens in seconds (5 minutes per spec
	 * §4.3).
	 *
	 * @var int
	 */
	public const ACCESS_TOKEN_TTL = 300;

	/**
	 * Exchange a JWT bearer assertion for a short-lived opaque access
	 * token.
	 *
	 * The assertion MUST be a Compact-Serialized JWS signed RS256
	 * (preferred) or ES256 with the application's registered private
	 * key. Required claims: iss (application id), aud="doriath",
	 * exp (>now), iat (<=now+CLOCK_SKEW), jti (unique within TTL).
	 *
	 * @param string $assertion The JWS compact serialization
	 *
	 * @return array{access_token:string,token_type:string,expires_in:int}
	 *
	 * @throws RuntimeException When the assertion is malformed, has
	 *                          invalid claims, fails signature
	 *                          verification, or replays a known jti.
	 *
	 * @spec openspec/changes/add-secret-audit-trail/tasks.md#task-3.7
	 */
	public function exchangeAssertion(string $assertion): array;

	/**
	 * Validate an opaque access token and resolve the bound application.
	 *
	 * @param string $accessToken The opaque access token from the
	 *                            `Authorization: Bearer <token>` header.
	 *
	 * @return Application|null The bound application, or null when the
	 *                          token is unknown, expired, or the
	 *                          application is no longer active.
	 *
	 * @spec openspec/specs/secret-store-api/spec.md#requirement-strict-own-vault-scoping
	 */
	public function validateAccessToken(string $accessToken): ?Application;
}//end interface
