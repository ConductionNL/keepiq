<?php

/**
 * Contract of AudiencePolicy
 *
 * The public surface of AudiencePolicy that other components call, so they depend
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

use OCA\Keepiq\Kernel\AppIdentity;

/**
 * Contract of AudiencePolicy.
 *
 * @category Kernel
 * @package  OCA\Keepiq\Kernel\Contracts
 * @author   Conduction b.v. <info@conduction.nl>
 * @license  EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 * @link     https://github.com/ConductionNL/keepiq
 *
 * @spec exclude Structural contract, no functional requirement.
 */
interface AudiencePolicyInterface {

	/**
	 * The audience claim this instance advertises and prefers.
	 *
	 * Assertions are ACCEPTED on any value in ACCEPTED_AUDIENCES; this is the
	 * one published in the `.well-known` discovery document, so a
	 * self-configuring consumer converges on it without being told.
	 *
	 * @var string
	 */
	public const CANONICAL_AUDIENCE = 'keepiq';

	/**
	 * The pre-rename audience, still accepted and now deprecated.
	 *
	 * Not an app id but a published authentication parameter: every
	 * application registered before the rename signs `aud=doriath` into its
	 * RS256 assertion with a private key this server does not hold and cannot
	 * re-sign. Rejecting it outright would be a fleet-wide credential outage
	 * that no repair step can heal, because the fix lives in each consumer's
	 * configuration.
	 *
	 * Accepting both instead is ADDITIVE, so it costs nothing and no consumer
	 * needs a change window. The shim is removed before the first stable
	 * release — see Application::PRE_STABLE_COMPAT_REMOVED_IN — not deferred
	 * to a future apiVersion: nothing stable has shipped, so there is no
	 * released contract a version bump would protect.
	 *
	 * @var string
	 */
	public const DEPRECATED_AUDIENCE = 'doriath';

	/**
	 * The app version in which DEPRECATED_AUDIENCE stops being accepted.
	 *
	 * @var string
	 */
	public const DEPRECATED_AUDIENCE_REMOVED_IN = AppIdentity::PRE_STABLE_COMPAT_REMOVED_IN;

	/**
	 * Every audience value an assertion may carry to reach this instance.
	 *
	 * RFC 7519 section 4.1.3 requires the recipient to identify itself with a
	 * value in the claim — both of these name this app, so accepting the pair
	 * does not widen the confused-deputy guard.
	 *
	 * @var string[]
	 */
	public const ACCEPTED_AUDIENCES = [
		self::CANONICAL_AUDIENCE,
		self::DEPRECATED_AUDIENCE,
	];
}//end interface
