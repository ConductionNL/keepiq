<?php

/**
 * Keepiq Federation Root Service
 *
 * This instance's side of partner pinning (sharing-federated-recipients D1,
 * D3): the SHA-256 fingerprint of the Keepiq root certificate that a
 * partner's administrator pins, and the CA chain sent with a certificate so
 * the owner's browser can check it ends at that root.
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

use DateTimeZone;
use InvalidArgumentException;
use OCA\Keepiq\Db\CACertificateMapper;
use OCA\Keepiq\Kernel\Contracts\FederationRootServiceInterface;
use OCP\AppFramework\Db\DoesNotExistException;
use OCP\OCM\Events\OCMEndpointRequestEvent;

/**
 * The local root fingerprint and CA chain for federation.
 *
 * @spec openspec/specs/federated-sharing/spec.md#requirement-administrators-approve-and-pin-partner-instances
 */
class FederationRootService implements FederationRootServiceInterface {
	/**
	 * Constructor for FederationRootService.
	 *
	 * @param CACertificateMapper $caMapper The CA certificate mapper
	 *
	 * @return void
	 *
	 * @spec exclude Constructor wiring only; no behaviour.
	 */
	public function __construct(
		private CACertificateMapper $caMapper,
	) {
	}//end __construct()

	/**
	 * Whether this Nextcloud can federate: the OCM endpoint event arrived in 33.
	 *
	 * @return bool
	 *
	 * @spec openspec/specs/federated-sharing/spec.md#requirement-administrators-approve-and-pin-partner-instances
	 */
	public function isSupported(): bool {
		return class_exists(OCMEndpointRequestEvent::class);
	}//end isSupported()

	/**
	 * Lowercase hex SHA-256 over a PEM certificate's DER bytes.
	 *
	 * @param string $pem The PEM certificate
	 *
	 * @return string|null Null unless the PEM is exactly one certificate, as CertificatePem reads it
	 *
	 * @spec openspec/specs/federated-sharing/spec.md#requirement-administrators-approve-and-pin-partner-instances
	 */
	public function fingerprint(string $pem): ?string {
		try {
			return (new CertificatePem(pem: $pem))->fingerprint();
		} catch (InvalidArgumentException) {
			return null;
		}
	}//end fingerprint()

	/**
	 * The fingerprint of this instance's Keepiq root, or null before the CA exists.
	 *
	 * @return string|null
	 *
	 * @spec openspec/specs/federated-sharing/spec.md#requirement-administrators-approve-and-pin-partner-instances
	 */
	public function localRootFingerprint(): ?string {
		try {
			return $this->fingerprint(pem: $this->caMapper->findRoot()->getCertificate());
		} catch (DoesNotExistException) {
			return null;
		}
	}//end localRootFingerprint()

	/**
	 * The announcement of this instance's last root renewal: the new (current) root, the root it replaced, when it
	 * was renewed, and the replaced root's signature over the new root's DER. Null for a root that was never renewed
	 * or was renewed without a signature.
	 *
	 * @return array{previousRoot:string,newRoot:string,renewedAt:string,signature:string}|null
	 *
	 * @spec openspec/changes/partner-root-renewal-announcement/specs/federated-sharing/spec.md#requirement-a-renewed-root-is-announced-to-partners
	 */
	public function localRootRenewal(): ?array {
		try {
			$root = $this->caMapper->findRoot();
		} catch (DoesNotExistException) {
			return null;
		}

		$signature = $root->getPredecessorSignature();
		$renewedAt = $root->getCreatedAt();
		if ($signature === null || $signature === '' || $renewedAt === null) {
			return null;
		}

		$previous = $this->caMapper->findPredecessorOf(rootId: $root->getId());
		if ($previous === null) {
			return null;
		}

		return [
			'previousRoot' => $previous->getCertificate(),
			'newRoot' => $root->getCertificate(),
			'renewedAt' => (clone $renewedAt)->setTimezone(new DateTimeZone('UTC'))->format('Y-m-d\TH:i:s\Z'),
			'signature' => $signature,
		];
	}//end localRootRenewal()

	/**
	 * The CA chain above a user certificate: the active intermediate, then the root.
	 *
	 * @return array<int,string> PEM certificates, empty before the CA exists
	 *
	 * @spec openspec/specs/federated-sharing/spec.md#requirement-certificate-lookup-is-signed-allowlisted-and-verified-in-the-browser
	 */
	public function localChain(): array {
		try {
			return [
				$this->caMapper->findActiveIntermediate()->getCertificate(),
				$this->caMapper->findRoot()->getCertificate(),
			];
		} catch (DoesNotExistException) {
			return [];
		}
	}//end localChain()
}//end class
