<?php

/**
 * Keepiq Secret Request Arguments
 *
 * The arguments every new secret request needs before anything is looked up
 * or written.
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

use InvalidArgumentException;

/**
 * Refuses a new secret request that misses one of its arguments.
 *
 * @spec openspec/specs/secret-requests/spec.md#requirement-create-secret-request
 */
final class SecretRequestArguments {
	/**
	 * Refuse the first missing argument, in this order: the Secret, the
	 * recipient's suite, the requested fields and the requester.
	 *
	 * @param string          $secretId          The Secret the request writes to
	 * @param string          $encryptionSuiteId The recipient's active suite ID
	 * @param RequestedFields $requestedFields   The fields to be filled in
	 * @param string          $userId            The Nextcloud user creating the request
	 *
	 * @return void
	 *
	 * @throws InvalidArgumentException When an argument is empty
	 *
	 * @spec openspec/specs/secret-requests/spec.md#requirement-create-secret-request
	 */
	public function requireComplete(
		string $secretId,
		string $encryptionSuiteId,
		RequestedFields $requestedFields,
		string $userId,
	): void {
		if ($secretId === '') {
			throw new InvalidArgumentException(message: 'secretId is required');
		}

		if ($encryptionSuiteId === '') {
			throw new InvalidArgumentException(message: 'encryptionSuiteId is required');
		}

		if ($requestedFields->names === []) {
			throw new InvalidArgumentException(message: 'requestedFields cannot be empty');
		}

		if ($userId === '') {
			throw new InvalidArgumentException(message: 'userId is required');
		}
	}//end requireComplete()
}//end class
