<?php

/**
 * Keepiq Request Target
 *
 * The shell secret an application request fills and the suite it is sealed under.
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
 * The application, the unfilled shell secret its request fills, and the
 * application suite the submitted values will be encrypted under.
 *
 * @spec openspec/specs/secret-requests/spec.md
 */
final class RequestTarget {
	/**
	 * Constructor for RequestTarget.
	 *
	 * @param string $applicationId The application
	 * @param string $secretId The shell secret
	 * @param string $encryptionSuiteId The application suite
	 *
	 * @return void
	 */
	public function __construct(
		public readonly string $applicationId,
		public readonly string $secretId,
		public readonly string $encryptionSuiteId,
	) {
	}//end __construct()
}//end class
