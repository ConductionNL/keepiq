<?php

/**
 * Keepiq Partner Root Renewal Service
 *
 * The receiving side of an announced root renewal (partner-root-renewal-announcement D3 to D7). A renewal notice
 * from a listed partner, or an announcement in its certificate lookup answer, only starts a check: the partner's
 * discovery document is read again the way it is when the partner is added, and the renewal is offered to the
 * administrators only when every check passes. Accepting goes through the administrator re-pin. An offered renewal
 * stays acceptable for 30 days after the renewal time the partner publishes, with reminders before that ends.
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
use OCA\Keepiq\Db\FederationPartner;
use OCA\Keepiq\Exception\PartnerUnreachableException;
use OCA\Keepiq\Kernel\AppIdentity;
use OCP\AppFramework\Db\DoesNotExistException;
use OCP\Share\Exceptions\ShareNotFound;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * Starts the checks of partner root renewals, and accepts them.
 *
 * @spec openspec/changes/partner-root-renewal-announcement/specs/federated-sharing/spec.md#requirement-an-announced-renewal-is-offered-to-administrators-after-its-signature-checks-out
 */
class PartnerRootRenewalService {
	/**
	 * Partners checked in this process, so a batch of lookups starts one check per partner.
	 *
	 * @var array<string,true>
	 */
	private array $checked = [];

	/**
	 * Constructor for PartnerRootRenewalService.
	 *
	 * @param PartnerRootRenewalNoticeSigner $notices Names and authenticates the partner a notice comes from
	 * @param PartnerRootRenewalOffers $offers Checks, stores and withdraws the offered renewal
	 * @param FederationPartnerService $partners Re-pins an accepted renewal
	 * @param LoggerInterface $logger Logs every notice ignored and every check that fails
	 *
	 * @return void
	 *
	 * @spec exclude Constructor wiring only; no behaviour.
	 */
	public function __construct(
		private PartnerRootRenewalNoticeSigner $notices,
		private PartnerRootRenewalOffers $offers,
		private FederationPartnerService $partners,
		private LoggerInterface $logger,
	) {
	}//end __construct()

	/**
	 * Whose signature a renewal notice must carry: the listed partner its sender names, in any direction.
	 *
	 * @param array<array-key,mixed> $payload The notice
	 *
	 * @return string The partner's OCM address, or '' when the sender is no listed partner
	 *
	 * @spec openspec/changes/partner-root-renewal-announcement/specs/federated-sharing/spec.md#requirement-an-announced-renewal-is-offered-to-administrators-after-its-signature-checks-out
	 */
	public function noticeIdentity(array $payload): string {
		$identity = $this->notices->identity(payload: $payload);
		if ($identity === '') {
			$this->logIgnored(payload: $payload);
		}

		return $identity;
	}//end noticeIdentity()

	/**
	 * Take a renewal notice: only a notice signed by the listed partner it names starts the check, and nothing in it
	 * is used beyond that.
	 *
	 * @param array<array-key,mixed> $payload The notice
	 *
	 * @return void
	 *
	 * @throws ShareNotFound When the notice is not signed by a listed partner; it is ignored and logged
	 * @throws PartnerUnreachableException When the partner's discovery document could not be fetched, so the
	 *   notice is refused and the sender retries it
	 *
	 * @spec openspec/changes/partner-root-renewal-announcement/specs/federated-sharing/spec.md#requirement-an-announced-renewal-is-offered-to-administrators-after-its-signature-checks-out
	 */
	public function noticeReceived(array $payload): void {
		$partner = $this->notices->signedPartner(payload: $payload);
		if ($partner === null) {
			$this->logIgnored(payload: $payload);
			throw new ShareNotFound();
		}

		$this->check(partner: $partner);
	}//end noticeReceived()

	/**
	 * Start the check when an outbound partner's lookup answer announces a renewal to a root that is neither pinned
	 * nor already offered.
	 *
	 * @param FederationPartner $partner The partner that answered
	 * @param mixed $announcement The answer's `rootRenewal`
	 *
	 * @return void
	 *
	 * @spec openspec/changes/partner-root-renewal-announcement/specs/federated-sharing/spec.md#requirement-an-announced-renewal-is-offered-to-administrators-after-its-signature-checks-out
	 */
	public function announcedInLookup(FederationPartner $partner, mixed $announcement): void {
		if ($this->offers->announcesNewRoot(partner: $partner, announcement: $announcement) === false) {
			return;
		}

		try {
			$this->check(partner: $partner);
		} catch (Throwable $exception) {
			// The lookup goes on whatever the check found.
			$this->logger->warning(
				'Keepiq: checking the root renewal of partner {partner} failed: {error}',
				['partner' => $partner->getBaseUrl(), 'error' => $exception->getMessage(), 'app' => AppIdentity::APP_ID]
			);
		}
	}//end announcedInLookup()

	/**
	 * Accept the offered renewal: the offer must still be open and name this fingerprint, the partner's discovery
	 * document is read again and every check repeated, and the pin is then stored through the re-pin, with its audit
	 * entry.
	 *
	 * @param string $id The partner UUID
	 * @param string $fingerprint The offered fingerprint the administrator accepts
	 * @param string $adminId The administrator
	 *
	 * @return array{partner:FederationPartner,changed:bool}
	 *
	 * @throws DoesNotExistException When the partner does not exist
	 * @throws PartnerUnreachableException When the partner's discovery document could not be fetched; the offer stays
	 * @throws InvalidArgumentException When nothing is offered for that fingerprint, or the partner no longer
	 *   announces it; a newer valid announcement then replaces the offer, and anything else withdraws it
	 *
	 * @spec openspec/changes/partner-root-renewal-announcement/specs/federated-sharing/spec.md#scenario-an-administrator-accepts-a-verified-renewal
	 * @spec openspec/changes/partner-root-renewal-announcement/specs/federated-sharing/spec.md#scenario-the-announcement-changes-before-it-is-accepted
	 */
	public function accept(string $id, string $fingerprint, string $adminId): array {
		$partner = $this->offers->offeredBy(id: $id, fingerprint: $fingerprint);
		$offered = (string)$partner->getPendingRootFingerprint();
		$verified = $this->verifyOrWithdraw(partner: $partner);
		if ($verified === null) {
			throw new InvalidArgumentException('The partner no longer announces this renewed certificate');
		}

		if (hash_equals($offered, $verified['fingerprint']) === false) {
			$this->logRefusal(partner: $partner, reason: 'another_renewal');
			$this->offers->offer(partner: $partner, verified: $verified);
			throw new InvalidArgumentException('The partner no longer announces this renewed certificate');
		}

		return $this->partners->repin(id: $id, confirmedFingerprint: $offered, adminId: $adminId);
	}//end accept()

	/**
	 * Run the check once per partner and process, and offer the renewal when it passes.
	 *
	 * @param FederationPartner $partner The partner
	 *
	 * @return void
	 *
	 * @throws PartnerUnreachableException When the partner's discovery document could not be fetched
	 */
	private function check(FederationPartner $partner): void {
		if (isset($this->checked[$partner->getId()]) === true) {
			return;
		}

		$this->checked[$partner->getId()] = true;
		$verified = $this->verifyOrWithdraw(partner: $partner);
		if ($verified !== null) {
			$this->offers->offer(partner: $partner, verified: $verified);
		}
	}//end check()

	/**
	 * Run every check on the partner's announcement; a failed check is logged and withdraws any offered renewal.
	 *
	 * @param FederationPartner $partner The partner
	 *
	 * @return array{fingerprint:string,windowEndsAt:\DateTime}|null The verified renewal, null when a check failed
	 *
	 * @throws PartnerUnreachableException When the partner's discovery document could not be fetched
	 */
	private function verifyOrWithdraw(FederationPartner $partner): ?array {
		try {
			return $this->offers->verify(partner: $partner);
		} catch (PartnerUnreachableException $exception) {
			$this->logRefusal(partner: $partner, reason: $exception->getMessage());
			throw $exception;
		} catch (InvalidArgumentException $exception) {
			$this->logRefusal(partner: $partner, reason: $exception->getMessage());
		}

		$this->offers->withdraw(partner: $partner);

		return null;
	}//end verifyOrWithdraw()

	/**
	 * Log a renewal notice that no listed partner signed.
	 *
	 * @param array<array-key,mixed> $payload The notice
	 *
	 * @return void
	 */
	private function logIgnored(array $payload): void {
		$sender = ($payload['sender'] ?? '');
		if (is_string($sender) === false) {
			$sender = '';
		}

		$this->logger->warning(
			'Keepiq: ignored a root renewal notice that no listed partner signed (sender {sender})',
			['sender' => $sender, 'app' => AppIdentity::APP_ID]
		);
	}//end logIgnored()

	/**
	 * Log an announcement that did not pass a check; nothing is stored and no one is notified.
	 *
	 * @param FederationPartner $partner The partner
	 * @param string $reason The check that failed
	 *
	 * @return void
	 */
	private function logRefusal(FederationPartner $partner, string $reason): void {
		$this->logger->warning(
			'Keepiq: the root renewal announced by partner {partner} was not offered: {reason}',
			['partner' => $partner->getBaseUrl(), 'reason' => $reason, 'app' => AppIdentity::APP_ID]
		);
	}//end logRefusal()
}//end class
