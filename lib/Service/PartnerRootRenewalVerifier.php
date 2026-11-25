<?php

/**
 * Keepiq Partner Root Renewal Verifier
 *
 * Every check of partner-root-renewal-announcement D3, on the announcement a partner's discovery document carries
 * now. The document is read the way it is when the partner is added; nothing from a notice or a lookup answer is used.
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

use DateTime;
use DateTimeZone;
use InvalidArgumentException;
use OCA\Keepiq\Db\FederationPartner;
use OCA\Keepiq\Exception\PartnerUnreachableException;
use OCP\AppFramework\Utility\ITimeFactory;

/**
 * Verifies a partner's announced root renewal against its pin.
 *
 * @spec openspec/changes/partner-root-renewal-announcement/specs/federated-sharing/spec.md#requirement-an-announced-renewal-is-offered-to-administrators-after-its-signature-checks-out
 */
class PartnerRootRenewalVerifier {
	/**
	 * Days after the renewal time during which an announced renewal can be accepted.
	 *
	 * @var int
	 */
	public const WINDOW_DAYS = 30;

	/**
	 * Constructor for PartnerRootRenewalVerifier.
	 *
	 * @param FederationPartnerService $partners Reads the partner's discovery document
	 * @param FederationRootService $root Fingerprints
	 * @param ITimeFactory $time The clock
	 * @param RootRenewalSignature $signatures Checks the previous root's signature
	 *
	 * @return void
	 *
	 * @spec exclude Constructor wiring only; no behaviour.
	 */
	public function __construct(
		private FederationPartnerService $partners,
		private FederationRootService $root,
		private ITimeFactory $time,
		private RootRenewalSignature $signatures = new RootRenewalSignature(),
	) {
	}//end __construct()

	/**
	 * The fingerprint of the new root an announcement names, or null when it names none. Used only to decide whether
	 * to run the checks.
	 *
	 * @param mixed $announcement A `rootRenewal`
	 *
	 * @return string|null
	 *
	 * @spec openspec/changes/partner-root-renewal-announcement/specs/federated-sharing/spec.md#requirement-an-announced-renewal-is-offered-to-administrators-after-its-signature-checks-out
	 */
	public function announcedFingerprint(mixed $announcement): ?string {
		$newRoot = null;
		if (is_array($announcement) === true) {
			$newRoot = ($announcement['newRoot'] ?? null);
		}

		if (is_string($newRoot) === false) {
			return null;
		}

		return $this->root->fingerprint(pem: $newRoot);
	}//end announcedFingerprint()

	/**
	 * Read the partner's discovery document and accept its announcement only when the previous root is the pinned
	 * one, its signature over the new root verifies, the new root is the one the document publishes, the new root is
	 * a CA certificate valid now, it differs from the pin, and the renewal time is not in the future and less than 30
	 * days ago.
	 *
	 * @param FederationPartner $partner The partner
	 *
	 * @return array{fingerprint:string,windowEndsAt:DateTime} The new root's fingerprint and the end of its window
	 *
	 * @throws PartnerUnreachableException When the discovery document could not be fetched
	 * @throws InvalidArgumentException Naming the first check that failed
	 *
	 * @spec openspec/changes/partner-root-renewal-announcement/specs/federated-sharing/spec.md#requirement-an-announced-renewal-is-offered-to-administrators-after-its-signature-checks-out
	 */
	public function verify(FederationPartner $partner): array {
		$read = $this->partners->announcedRenewal(url: $partner->getBaseUrl());
		$announcement = $this->announcementOf(renewal: $read['rootRenewal']);
		$now = $this->time->getDateTime();
		$pinned = $partner->getRootFingerprint();

		// Each root is parsed once, and every check below reads that one certificate.
		$previousRoot = $this->certificate(pem: $announcement['previousRoot'], refusal: 'previous_root_malformed');
		if (hash_equals($pinned, $previousRoot->fingerprint()) === false) {
			throw new InvalidArgumentException('previous_root_not_pinned');
		}

		$newRoot = $this->certificate(pem: $announcement['newRoot'], refusal: 'new_root_malformed');
		$signed = $this->signatures->verifies(newRoot: $newRoot, previousRoot: $previousRoot, signature: $announcement['signature']);
		if ($signed === false) {
			throw new InvalidArgumentException('signature_invalid');
		}

		$fingerprint = $newRoot->fingerprint();
		if (hash_equals($read['preview']['rootFingerprint'], $fingerprint) === false) {
			throw new InvalidArgumentException('not_the_published_root');
		}

		if ($newRoot->isCaValidAt(now: $now->getTimestamp()) === false) {
			throw new InvalidArgumentException('not_a_valid_ca');
		}

		if (hash_equals($pinned, $fingerprint) === true) {
			throw new InvalidArgumentException('already_pinned');
		}

		return ['fingerprint' => $fingerprint, 'windowEndsAt' => $this->windowEnd(renewedAt: $announcement['renewedAt'], now: $now)];
	}//end verify()

	/**
	 * An announced root, read strictly.
	 *
	 * @param string $pem The announced PEM
	 * @param string $refusal What to refuse with when it is not exactly one certificate
	 *
	 * @return CertificatePem
	 *
	 * @throws InvalidArgumentException `$refusal`
	 */
	private function certificate(string $pem, string $refusal): CertificatePem {
		try {
			return new CertificatePem(pem: $pem);
		} catch (InvalidArgumentException) {
			throw new InvalidArgumentException($refusal);
		}
	}//end certificate()

	/**
	 * The announcement's four fields, when it has them all as strings.
	 *
	 * @param mixed $renewal The `rootRenewal` of the discovery document
	 *
	 * @return array{previousRoot:string,newRoot:string,renewedAt:string,signature:string}
	 *
	 * @throws InvalidArgumentException `no_announcement`
	 */
	private function announcementOf(mixed $renewal): array {
		if (is_array($renewal) === false) {
			throw new InvalidArgumentException('no_announcement');
		}

		$fields = [];
		foreach (['previousRoot', 'newRoot', 'renewedAt', 'signature'] as $field) {
			if (is_string($renewal[$field] ?? null) === false) {
				throw new InvalidArgumentException('no_announcement');
			}

			$fields[$field] = $renewal[$field];
		}

		return $fields;
	}//end announcementOf()

	/**
	 * The end of the window, 30 days after a renewal time that is not in the future and less than 30 days ago.
	 *
	 * @param string $renewedAt The published renewal time, ISO 8601 UTC (`2026-11-25T10:00:00Z`)
	 * @param DateTime $now The current time
	 *
	 * @return DateTime
	 *
	 * @throws InvalidArgumentException `renewal_time_invalid`, `renewal_in_the_future` or `renewal_too_old`
	 */
	private function windowEnd(string $renewedAt, DateTime $now): DateTime {
		$time = date_create_from_format('!Y-m-d\TH:i:s\Z', $renewedAt, new DateTimeZone('UTC'));
		if ($time === false || $time->format('Y-m-d\TH:i:s\Z') !== $renewedAt) {
			throw new InvalidArgumentException('renewal_time_invalid');
		}

		if ($time > $now) {
			throw new InvalidArgumentException('renewal_in_the_future');
		}

		$endsAt = (clone $time)->modify('+' . self::WINDOW_DAYS . ' days');
		if ($endsAt <= $now) {
			throw new InvalidArgumentException('renewal_too_old');
		}

		return $endsAt;
	}//end windowEnd()
}//end class
