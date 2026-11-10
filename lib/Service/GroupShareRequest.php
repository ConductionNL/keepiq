<?php

/**
 * Keepiq Group Share Request
 *
 * One request to share a secret with a Nextcloud group.
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
 * The secret, the group, the initiator and the restriction every member's
 * copy gets.
 *
 * @spec openspec/specs/sharing-group/spec.md#requirement-share-with-a-group
 */
final class GroupShareRequest {
	/**
	 * Constructor for GroupShareRequest.
	 *
	 * @param string $secretId The source Secret ID (or a delegate's own copy)
	 * @param string $groupId The Nextcloud group ID
	 * @param string $userId The initiator (must be owner or delegate)
	 * @param ShareRestriction|null $restriction Use-only and end date for every member
	 *
	 * @return void
	 */
	public function __construct(
		public readonly string $secretId,
		public readonly string $groupId,
		public readonly string $userId,
		public readonly ?ShareRestriction $restriction = null,
	) {
	}//end __construct()
}//end class
