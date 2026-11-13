<?php

/**
 * Keepiq Certificate Authority Status Service
 *
 * Reports the health of the private CA and the counts of certificates it
 * has issued (certificate-lifecycle §2.6). This is a READ-ONLY view: it
 * derives `healthy` / `expiring_soon` / `action_required` /
 * `not_configured` from the stored root and intermediate, and counts
 * active suites plus stored certificate-type secrets. It emits counts
 * only — never an identifier, PEM, or key material — and it never
 * mutates the CA, which keeps it cleanly separable from
 * CertificateAuthorityService's bootstrap/renewal write paths.
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
use OCA\Keepiq\AppInfo\Application;
use OCA\Keepiq\Db\CACertificate;
use OCA\Keepiq\Db\CACertificateMapper;
use OCA\Keepiq\Db\EncryptionSuiteMapper;
use OCA\Keepiq\Db\SecretMapper;
use OCA\Keepiq\Db\SecretTypeMapper;
use OCP\AppFramework\Db\DoesNotExistException;
use OCP\IAppConfig;

/**
 * Read-only health and issued-certificate counts for the private CA.
 */
class CertificateAuthorityStatusService {
	/**
	 * The status of a CA that is degraded or has no root or intermediate.
	 *
	 * @var array{status: string, root: null, intermediate: null}
	 */
	private const NOT_CONFIGURED = [
		'status' => 'not_configured',
		'root' => null,
		'intermediate' => null,
	];

	/**
	 * Constructor for CertificateAuthorityStatusService.
	 *
	 * @param CACertificateMapper $caCertificateMapper The CA certificate mapper
	 * @param EncryptionSuiteMapper $suiteMapper The encryption suite mapper
	 * @param IAppConfig $appConfig The app config interface
	 * @param SecretMapper|null $secretMapper The secret mapper (issued-cert counts)
	 * @param SecretTypeMapper|null $secretTypeMapper The type mapper (issued-cert counts)
	 *
	 * @return void
	 *
	 * @spec exclude Constructor wiring only; no behaviour.
	 */
	public function __construct(
		private CACertificateMapper $caCertificateMapper,
		private EncryptionSuiteMapper $suiteMapper,
		private IAppConfig $appConfig,
		private ?SecretMapper $secretMapper = null,
		private ?SecretTypeMapper $secretTypeMapper = null,
	) {
	}//end __construct()

	/**
	 * Get the current CA status.
	 *
	 * The `issued` key is present only on the configured path — the
	 * `not_configured` early returns have no CA to count against.
	 *
	 * @return array{status: string, root: ?array, intermediate: ?array, issued?: array<string,int>}
	 *
	 * @spec openspec/changes/retrofit-2026-05-25-doriath-coverage/tasks.md#task-1
	 */
	public function getStatus(): array {
		$caStatus = $this->appConfig->getValueString(Application::APP_ID, 'ca_status', 'unknown');

		if ($caStatus === 'degraded') {
			return self::NOT_CONFIGURED;
		}

		try {
			$root = $this->caCertificateMapper->findRoot();
			$intermediate = $this->caCertificateMapper->findActiveIntermediate();
		} catch (DoesNotExistException) {
			return self::NOT_CONFIGURED;
		}

		return [
			'status' => $this->health(root: $root, intermediate: $intermediate),
			'root' => $root->jsonSerialize(),
			'intermediate' => $intermediate->jsonSerialize(),
			'issued' => $this->issuedCounts(),
		];
	}//end getStatus()

	/**
	 * The health of a configured CA.
	 *
	 * An intermediate within 30 days of expiry is expiring soon; a root within
	 * 90 days of expiry, or a revoked intermediate, needs action.
	 *
	 * @param CACertificate $root         The root certificate
	 * @param CACertificate $intermediate The active intermediate
	 *
	 * @return string `healthy`, `expiring_soon` or `action_required`
	 */
	private function health(CACertificate $root, CACertificate $intermediate): string {
		$now = new DateTime();
		$intermediateExpiry = $intermediate->getExpiresAt();
		$rootExpiry = $root->getExpiresAt();
		if (($rootExpiry !== null && $rootExpiry->diff($now)->days < 90) || $intermediate->getRevokedAt() !== null) {
			return 'action_required';
		}

		if ($intermediateExpiry !== null && $intermediateExpiry->diff($now)->days < 30) {
			return 'expiring_soon';
		}

		return 'healthy';
	}//end health()

	/**
	 * Issued-certificate counts (certificate-lifecycle §2.6): active
	 * user/application suites plus stored certificate-type secrets and
	 * how many of those expire within 30 days. Counts only — no
	 * identifiers, PEM, or key material.
	 *
	 * @return array<string,int>
	 */
	private function issuedCounts(): array {
		$counts = [
			'activeUserSuites' => $this->suiteMapper->countActiveByOwnerType('user'),
			'activeApplicationSuites' => $this->suiteMapper->countActiveByOwnerType('application'),
			'storedCertificates' => 0,
			'storedExpiringSoon' => 0,
		];

		if ($this->secretMapper === null || $this->secretTypeMapper === null) {
			return $counts;
		}

		try {
			$certTypeId = $this->secretTypeMapper->findByName('certificate')->getId();
			$counts['storedCertificates'] = $this->secretMapper->countByTypeId(typeId: $certTypeId);
			$counts['storedExpiringSoon'] = $this->secretMapper->countByTypeId(
				typeId: $certTypeId,
				expiresBefore: new DateTime('+30 days')
			);
		} catch (DoesNotExistException) {
			// Types not seeded yet — counts stay zero.
		}

		return $counts;
	}//end issuedCounts()
}//end class
