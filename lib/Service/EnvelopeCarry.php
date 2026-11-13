<?php

/**
 * Keepiq Envelope Carry
 *
 * One emergency contact carried onto a migration's new suite.
 *
 * @category Service
 * @package  OCA\Keepiq\Service
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

namespace OCA\Keepiq\Service;

/**
 * The grantor, the migration's old and new suite, the contact and the fresh
 * recovery envelope with the grantee suite it was sealed to.
 *
 * @spec openspec/changes/migrate-emergency-access-on-rotation/specs/encryption-suites/spec.md#requirement-migration-covers-every-suite-bound-store
 */
final class EnvelopeCarry {
	/**
	 * Constructor for EnvelopeCarry.
	 *
	 * @param string $ownerId The grantor (migration owner)
	 * @param string $oldSuiteId The migration's old suite
	 * @param string $newSuiteId The migration's new suite
	 * @param string $contactId The emergency contact
	 * @param string $recoveryEnvelope The fresh envelope escrowing the new private key
	 * @param string $sealedSuiteId The grantee suite the envelope was sealed to
	 *
	 * @return void
	 */
	public function __construct(
		public readonly string $ownerId,
		public readonly string $oldSuiteId,
		public readonly string $newSuiteId,
		public readonly string $contactId,
		public readonly string $recoveryEnvelope,
		public readonly string $sealedSuiteId,
	) {
	}//end __construct()
}//end class
