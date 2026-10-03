<?php

/**
 * Keepiq Secret Sharing Revoker
 *
 * Ends everybody else's access to one secret: link shares, open secret
 * requests, user shares, group shares and delegations. Used when a secret
 * moves to the trash (vault-trash-and-archive D2).
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

use OCA\Keepiq\Db\GroupShareMapper;
use OCA\Keepiq\Db\SecretDelegationMapper;

/**
 * Revokes the whole sharing graph of a secret.
 */
class SecretSharingRevoker {
	/**
	 * Constructor for SecretSharingRevoker.
	 *
	 * @param LinkShareService $linkShareService Link shares of a secret
	 * @param SecretRequestService $secretRequestService Open secret requests of a secret
	 * @param ShareService $shareService User shares of a secret
	 * @param GroupShareMapper $groupShareMapper Group shares of a secret
	 * @param SecretDelegationMapper $delegationMapper Delegations of a secret
	 *
	 * @return void
	 *
	 * @spec exclude Constructor wiring only.
	 */
	public function __construct(
		private LinkShareService $linkShareService,
		private SecretRequestService $secretRequestService,
		private ShareService $shareService,
		private GroupShareMapper $groupShareMapper,
		private SecretDelegationMapper $delegationMapper,
	) {
	}//end __construct()

	/**
	 * Revoke every share, request and delegation of a secret.
	 *
	 * @param string $secretId The secret ID
	 *
	 * @return void
	 *
	 * @spec openspec/specs/vault-trash-and-archive/spec.md#requirement-deleting-a-secret-moves-it-to-the-trash
	 */
	public function revokeAll(string $secretId): void {
		$this->linkShareService->deleteBySecretId($secretId);
		$this->secretRequestService->deleteAllForSecret($secretId);
		$this->shareService->deleteAllForSecret($secretId);
		$this->groupShareMapper->deleteBySecret($secretId);
		$this->delegationMapper->deleteBySecret($secretId);
	}//end revokeAll()
}//end class
