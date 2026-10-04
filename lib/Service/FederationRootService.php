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

use OCA\Keepiq\Db\CACertificateMapper;
use OCP\AppFramework\Db\DoesNotExistException;
use OCP\OCM\Events\OCMEndpointRequestEvent;

/**
 * The local root fingerprint and CA chain for federation.
 *
 * @spec openspec/specs/federated-sharing/spec.md#requirement-administrators-approve-and-pin-partner-instances
 */
class FederationRootService {
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
	 * @return string|null Null when the PEM holds no certificate
	 *
	 * @spec openspec/specs/federated-sharing/spec.md#requirement-administrators-approve-and-pin-partner-instances
	 */
	public function fingerprint(string $pem): ?string {
		if (preg_match('/-----BEGIN CERTIFICATE-----(.+?)-----END CERTIFICATE-----/s', $pem, $match) !== 1) {
			return null;
		}

		$der = base64_decode(preg_replace('/\s+/', '', $match[1]) ?? '', true);
		if ($der === false || $der === '') {
			return null;
		}

		return hash('sha256', $der);
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
