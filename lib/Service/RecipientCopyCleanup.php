<?php

/**
 * Keepiq Recipient Copy Cleanup
 *
 * What goes with a recipient's copy of a shared secret when a revoke or the source's trash or purge deletes it for good:
 * the partner shares its holder made of it end before the copy goes, and the attachment grants it holds go after.
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

/**
 * Ends a deleted copy's partner shares and removes its attachment grants.
 *
 * @spec openspec/specs/user-sharing/spec.md#requirement-revoke-share
 */
class RecipientCopyCleanup {
	/**
	 * Constructor for RecipientCopyCleanup.
	 *
	 * @param AttachmentService $attachments The attachment grants a copy holds
	 * @param PartnerShareRevoker $partnerShares Ends the partner shares of a copy that is deleted
	 *
	 * @return void
	 *
	 * @spec exclude Constructor wiring only; no behaviour.
	 */
	public function __construct(
		private AttachmentService $attachments,
		private PartnerShareRevoker $partnerShares,
	) {
	}//end __construct()

	/**
	 * End the partner shares the copy's holder made of it, each SHARE_UNSHARED left to the retry job. Runs before the copy goes.
	 *
	 * @param string $copyId The copy being deleted
	 *
	 * @return void
	 *
	 * @spec openspec/specs/federated-sharing/spec.md#scenario-a-received-copy-deleted-for-good-ends-its-partner-shares
	 */
	public function endPartnerShares(string $copyId): void {
		$this->partnerShares->queueForDeletedCopy(copyId: $copyId);
	}//end endPartnerShares()

	/**
	 * Remove the attachment grants a deleted copy holds, also when the copy was gone already.
	 *
	 * @param string $copyId The deleted copy
	 *
	 * @return void
	 *
	 * @spec openspec/specs/user-sharing/spec.md#requirement-revoke-share
	 */
	public function deleteAttachmentGrants(string $copyId): void {
		$this->attachments->deleteGrantsForSecretCopy($copyId);
	}//end deleteAttachmentGrants()
}//end class
