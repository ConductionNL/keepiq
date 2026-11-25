<?php

/**
 * Keepiq Federation Partner Changes
 *
 * The administrator's partner changes that reach beyond the allowlist: removing a partner suspends the shares to it,
 * and a re-pin either pins what the partner publishes now or accepts its offered root renewal.
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

use OCA\Keepiq\Db\FederationPartner;
use OCA\Keepiq\Kernel\Contracts\FederatedShareServiceInterface;
use OCA\Keepiq\Kernel\Contracts\FederationPartnerServiceInterface;

/**
 * Removes and re-pins partners, with what follows from that elsewhere.
 *
 * @spec openspec/specs/federated-sharing/spec.md#requirement-administrators-approve-and-pin-partner-instances
 */
class FederationPartnerChanges {
	/**
	 * Constructor for FederationPartnerChanges.
	 *
	 * @param FederationPartnerServiceInterface $partners The partner allowlist
	 * @param FederatedShareServiceInterface $federatedShares Suspends shares to a removed partner
	 * @param PartnerRootRenewalService $renewals Accepts an offered root renewal
	 *
	 * @return void
	 *
	 * @spec exclude Constructor wiring only; no behaviour.
	 */
	public function __construct(
		private FederationPartnerServiceInterface $partners,
		private FederatedShareServiceInterface $federatedShares,
		private PartnerRootRenewalService $renewals,
	) {
	}//end __construct()

	/**
	 * Remove a partner. Shares to that organisation stop being served, and their owners see them suspended
	 * (sharing-federated-recipients task 4.2).
	 *
	 * @param string $id The partner UUID
	 *
	 * @return void
	 *
	 * @throws \OCP\AppFramework\Db\DoesNotExistException When the partner does not exist; nothing is suspended
	 *
	 * @spec openspec/specs/federated-sharing/spec.md#requirement-administrators-approve-and-pin-partner-instances
	 */
	public function remove(string $id): void {
		$this->partners->remove(id: $id);
		$this->federatedShares->suspendForPartner(partnerId: $id);
	}//end remove()

	/**
	 * Pin the root fingerprint the partner publishes now, or, with `$acceptRenewal`, accept the offered renewal with
	 * that fingerprint after its checks are repeated.
	 *
	 * @param string $id The partner UUID
	 * @param string $fingerprint The fingerprint the administrator compared, or the offered one they accept
	 * @param bool $acceptRenewal Whether this accepts the offered renewal
	 * @param string $adminId The administrator
	 *
	 * @return array{partner:FederationPartner,changed:bool}
	 *
	 * @throws \OCP\AppFramework\Db\DoesNotExistException When the partner does not exist
	 * @throws \InvalidArgumentException When the fingerprint is refused
	 *
	 * @spec openspec/changes/partner-share-temporary-refusals/specs/federated-sharing/spec.md#requirement-administrators-approve-and-pin-partner-instances
	 * @spec openspec/changes/partner-root-renewal-announcement/specs/federated-sharing/spec.md#scenario-an-administrator-accepts-a-verified-renewal
	 */
	public function repin(string $id, string $fingerprint, bool $acceptRenewal, string $adminId): array {
		if ($acceptRenewal === true) {
			return $this->renewals->accept(id: $id, fingerprint: $fingerprint, adminId: $adminId);
		}

		return $this->partners->repin(id: $id, confirmedFingerprint: $fingerprint, adminId: $adminId);
	}//end repin()
}//end class
