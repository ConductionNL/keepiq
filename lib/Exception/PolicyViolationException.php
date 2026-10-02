<?php

/**
 * Keepiq Policy Violation Exception
 *
 * A write an organisation-wide vault policy refuses (admin-vault-policies).
 * It is a ForbiddenException, so every caller that already maps that to 403
 * keeps doing so, and it carries a machine-readable policy code the API
 * returns next to the message.
 *
 * @category Exception
 * @package  OCA\Keepiq\Exception
 *
 * @author    Conduction Development Team <dev@conductio.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git-id>
 *
 * @link https://conduction.nl
 */

declare(strict_types=1);

namespace OCA\Keepiq\Exception;

/**
 * Thrown when a vault policy refuses a write.
 */
class PolicyViolationException extends ForbiddenException {
	/**
	 * Constructor.
	 *
	 * @param string $policyCode The policy code, for example org_ownership_required
	 * @param string $message The human-readable reason
	 *
	 * @return void
	 *
	 * @spec openspec/changes/admin-vault-policies/tasks.md#4.1
	 */
	public function __construct(
		public readonly string $policyCode,
		string $message,
	) {
		parent::__construct(message: $message);
	}//end __construct()

	/**
	 * The policy code, for example org_ownership_required.
	 *
	 * @return string|null
	 *
	 * @spec openspec/changes/admin-vault-policies/tasks.md#4.1
	 */
	public function policyCode(): ?string {
		return $this->policyCode;
	}//end policyCode()
}//end class
