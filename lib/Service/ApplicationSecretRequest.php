<?php

/**
 * Keepiq Application Secret Request
 *
 * One secret request an application opens in its own vault.
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

use DateTime;

/**
 * The application whose vault receives the value, the fields it asks for,
 * and the optional name, folder and end date of the request.
 *
 * @spec openspec/specs/secret-requests/spec.md
 */
final class ApplicationSecretRequest {
	/**
	 * Constructor for ApplicationSecretRequest.
	 *
	 * @param string $applicationId The application whose vault receives the value
	 * @param string[] $requestedFields The fields the requester asks for
	 * @param string|null $name The name of the shell secret, when given
	 * @param string|null $folderId The folder of the shell secret, when given
	 * @param DateTime|null $expiresAt When the request lapses, when given
	 *
	 * @return void
	 */
	public function __construct(
		public readonly string $applicationId,
		public readonly array $requestedFields,
		public readonly ?string $name = null,
		public readonly ?string $folderId = null,
		public readonly ?DateTime $expiresAt = null,
	) {
	}//end __construct()
}//end class
