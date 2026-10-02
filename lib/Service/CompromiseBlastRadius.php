<?php

/**
 * Keepiq CompromiseBlastRadius
 *
 * What a compromise force-revoke has to warn about, collected BEFORE any suite
 * is revoked. The revoke cascade deletes the ShareTargets and invalidates the
 * emergency contacts this lookup reads, so it has to run first (keepiq#864).
 *
 * @category Service
 * @package  OCA\Keepiq\Service
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

namespace OCA\Keepiq\Service;

use OCA\Keepiq\Db\Secret;

/**
 * The secrets, shared copies and emergency grantors one containment touches.
 *
 * @spec openspec/specs/encryption-suites/spec.md#requirement-a-compromise-force-revoke-contains-the-account
 */
final class CompromiseBlastRadius {
	/**
	 * Every secret sealed under a revoked suite, with the secret a warning
	 * about it points at (the SOURCE for a shared copy, otherwise itself).
	 *
	 * @var list<array{secret: Secret, target: Secret}>
	 */
	private array $sealed = [];

	/**
	 * Copies of the revoked user's own secrets held by other users.
	 *
	 * @var list<array{recipientId: string, copy: Secret}>
	 */
	private array $outbound = [];

	/**
	 * Grantors whose approved emergency envelope is sealed to a revoked suite.
	 *
	 * @var list<array{grantorId: string, granteeId: string}>
	 */
	private array $grantors = [];

	/**
	 * Lookups that failed while collecting; each is a gap in the warning.
	 *
	 * @var int
	 */
	private int $failures = 0;

	/**
	 * Record a secret sealed under a revoked suite.
	 *
	 * @param Secret $secret The secret sealed under the suite
	 * @param Secret $target The secret its warning points at
	 *
	 * @return void
	 *
	 * @spec openspec/specs/encryption-suites/spec.md#requirement-a-compromise-force-revoke-contains-the-account
	 */
	public function addSealed(Secret $secret, Secret $target): void {
		$this->sealed[] = ['secret' => $secret, 'target' => $target];
	}//end addSealed()

	/**
	 * Record a copy of the revoked user's secret held by someone else.
	 *
	 * @param string $recipientId The user holding the copy
	 * @param Secret $copy        The copy
	 *
	 * @return void
	 *
	 * @spec openspec/specs/encryption-suites/spec.md#requirement-a-compromise-force-revoke-contains-the-account
	 */
	public function addOutbound(string $recipientId, Secret $copy): void {
		$this->outbound[] = ['recipientId' => $recipientId, 'copy' => $copy];
	}//end addOutbound()

	/**
	 * Record a grantor whose approved emergency envelope a revoked suite opens.
	 *
	 * @param string $grantorId The vault owner who designated the contact
	 * @param string $granteeId The revoked user, the contact
	 *
	 * @return void
	 *
	 * @spec openspec/specs/encryption-suites/spec.md#requirement-a-compromise-force-revoke-contains-the-account
	 */
	public function addGrantor(string $grantorId, string $granteeId): void {
		$this->grantors[] = ['grantorId' => $grantorId, 'granteeId' => $granteeId];
	}//end addGrantor()

	/**
	 * Count a lookup that failed while collecting.
	 *
	 * @return void
	 *
	 * @spec openspec/specs/encryption-suites/spec.md#requirement-a-compromise-force-revoke-contains-the-account
	 */
	public function addFailure(): void {
		$this->failures++;
	}//end addFailure()

	/**
	 * The sealed secrets and their warning targets.
	 *
	 * @return list<array{secret: Secret, target: Secret}>
	 *
	 * @spec openspec/specs/encryption-suites/spec.md#requirement-a-compromise-force-revoke-contains-the-account
	 */
	public function getSealed(): array {
		return $this->sealed;
	}//end getSealed()

	/**
	 * The outbound copies and their holders.
	 *
	 * @return list<array{recipientId: string, copy: Secret}>
	 *
	 * @spec openspec/specs/encryption-suites/spec.md#requirement-a-compromise-force-revoke-contains-the-account
	 */
	public function getOutbound(): array {
		return $this->outbound;
	}//end getOutbound()

	/**
	 * The exposed emergency grantors.
	 *
	 * @return list<array{grantorId: string, granteeId: string}>
	 *
	 * @spec openspec/specs/encryption-suites/spec.md#requirement-a-compromise-force-revoke-contains-the-account
	 */
	public function getGrantors(): array {
		return $this->grantors;
	}//end getGrantors()

	/**
	 * How many lookups failed while collecting.
	 *
	 * @return int
	 *
	 * @spec openspec/specs/encryption-suites/spec.md#requirement-a-compromise-force-revoke-contains-the-account
	 */
	public function getFailures(): int {
		return $this->failures;
	}//end getFailures()
}//end class
