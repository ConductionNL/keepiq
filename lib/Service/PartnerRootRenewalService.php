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

use DateTime;
use InvalidArgumentException;
use OCA\Keepiq\Db\FederationPartner;
use OCA\Keepiq\Db\FederationPartnerMapper;
use OCA\Keepiq\Exception\PartnerUnreachableException;
use OCA\Keepiq\Kernel\AppIdentity;
use OCP\AppFramework\Db\DoesNotExistException;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\Federation\ICloudIdManager;
use OCP\OCM\IOCMDiscoveryService;
use OCP\Share\Exceptions\ShareNotFound;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * Starts the checks, offers, reminds about and accepts partner root renewals.
 *
 * @spec openspec/changes/partner-root-renewal-announcement/specs/federated-sharing/spec.md#requirement-an-announced-renewal-is-offered-to-administrators-after-its-signature-checks-out
 *
 * @SuppressWarnings(PHPMD.CouplingBetweenObjects) One step of D3 to D6 each: the partner table, the notice's OCM
 *   signature and sender, the check, the clock, the re-pin and the admin notifications.
 */
class PartnerRootRenewalService {
	/**
	 * Days before the window ends on which administrators are reminded, each once.
	 *
	 * @var int[]
	 */
	public const REMINDER_DAYS = [7, 5, 3, 1];

	/**
	 * The notification subject of a newly offered renewal.
	 *
	 * @var string
	 */
	public const SUBJECT_OFFERED = 'partner_root_renewal';

	/**
	 * The notification subject of a reminder.
	 *
	 * @var string
	 */
	public const SUBJECT_REMINDER = 'partner_root_renewal_reminder';

	/**
	 * Partners checked in this process, so a batch of lookups starts one check per partner.
	 *
	 * @var array<string,true>
	 */
	private array $checked = [];

	/**
	 * Constructor for PartnerRootRenewalService.
	 *
	 * @param FederationPartnerService $partners Re-pins, and names hosts as OCM signers do
	 * @param FederationPartnerMapper $partnerMapper The partner table
	 * @param PartnerRootRenewalVerifier $verifier Runs every check on the partner's announcement
	 * @param PartnerRootRenewalNotifier $admins Notifies the administrators
	 * @param IOCMDiscoveryService $ocmDiscovery Verifies a notice's signature
	 * @param ICloudIdManager $cloudIdManager Reads the sender a notice names
	 * @param ITimeFactory $time The clock
	 * @param LoggerInterface $logger Logs every notice ignored and every check that fails
	 *
	 * @return void
	 *
	 * @spec exclude Constructor wiring only; no behaviour.
	 */
	public function __construct(
		private FederationPartnerService $partners,
		private FederationPartnerMapper $partnerMapper,
		private PartnerRootRenewalVerifier $verifier,
		private PartnerRootRenewalNotifier $admins,
		private IOCMDiscoveryService $ocmDiscovery,
		private ICloudIdManager $cloudIdManager,
		private ITimeFactory $time,
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
		$partner = $this->partnerNamedBy(payload: $payload);
		if ($partner === null) {
			$this->logIgnored(payload: $payload);
			return '';
		}

		return $this->identityOf(partner: $partner);
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
		$partner = $this->partnerNamedBy(payload: $payload);
		$signer = null;
		if ($partner !== null) {
			try {
				$signer = $this->ocmDiscovery->getIncomingSignedRequest($this->identityOf(partner: $partner))?->getOrigin();
			} catch (Throwable) {
				$signer = null;
			}
		}

		if ($partner === null || $signer === null || $this->partners->hostOf(url: $signer) !== $partner->getHost()) {
			$this->logIgnored(payload: $payload);
			throw new ShareNotFound();
		}

		$this->check(partner: $partner);
	}//end noticeReceived()

	/**
	 * Start the check when an outbound partner's lookup answer announces a renewal to a root that is neither pinned
	 * nor already offered. Only the announced root's fingerprint is read, to decide that.
	 *
	 * @param FederationPartner $partner The partner that answered
	 * @param mixed $announcement The answer's `rootRenewal`
	 *
	 * @return void
	 *
	 * @spec openspec/changes/partner-root-renewal-announcement/specs/federated-sharing/spec.md#requirement-an-announced-renewal-is-offered-to-administrators-after-its-signature-checks-out
	 */
	public function announcedInLookup(FederationPartner $partner, mixed $announcement): void {
		$fingerprint = $this->verifier->announcedFingerprint(announcement: $announcement);
		$pending = (string)$partner->getPendingRootFingerprint();
		if ($fingerprint === null || hash_equals($partner->getRootFingerprint(), $fingerprint) === true
			|| hash_equals($pending, $fingerprint) === true
		) {
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
		$partner = $this->partnerMapper->findById(id: $id);
		$offered = $partner->offeredRenewal(now: $this->time->getDateTime());
		if ($offered === null || hash_equals($offered['fingerprint'], strtolower(trim($fingerprint))) === false) {
			throw new InvalidArgumentException('No renewed certificate with this fingerprint is offered for this partner');
		}

		$verified = $this->verifyOrWithdraw(partner: $partner);
		if ($verified === null) {
			throw new InvalidArgumentException('The partner no longer announces this renewed certificate');
		}

		if (hash_equals($offered['fingerprint'], $verified['fingerprint']) === false) {
			$this->logRefusal(partner: $partner, reason: 'another_renewal');
			$this->offer(partner: $partner, verified: $verified);
			throw new InvalidArgumentException('The partner no longer announces this renewed certificate');
		}

		return $this->partners->repin(id: $id, confirmedFingerprint: $offered['fingerprint'], adminId: $adminId);
	}//end accept()

	/**
	 * The hourly pass: withdraw every offered renewal whose window ended, and remind the administrators 7, 5, 3 and 1
	 * day before a window ends, each reminder once.
	 *
	 * @param DateTime $now The current time
	 *
	 * @return void
	 *
	 * @spec openspec/changes/partner-root-renewal-announcement/specs/federated-sharing/spec.md#requirement-an-offered-renewal-expires-after-30-days-with-reminders
	 */
	public function remindAndExpire(DateTime $now): void {
		foreach ($this->partnerMapper->findWithOfferedRenewal() as $partner) {
			$endsAt = $partner->getPendingRootWindowEndsAt();
			if ($endsAt === null || $endsAt <= $now) {
				$partner->withdrawRenewal();
				$this->partnerMapper->update(entity: $partner);
				continue;
			}

			$daysLeft = (int)ceil(($endsAt->getTimestamp() - $now->getTimestamp()) / 86400);
			$threshold = $this->thresholdFor(daysLeft: $daysLeft);
			$reminded = $partner->getPendingRootReminded();
			if ($threshold === null || ($reminded !== null && $reminded <= $threshold)) {
				continue;
			}

			$this->admins->notifyAdmins(subject: self::SUBJECT_REMINDER, partner: $partner, daysLeft: $daysLeft);
			$partner->setPendingRootReminded($threshold);
			$this->partnerMapper->update(entity: $partner);
		}
	}//end remindAndExpire()

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
			$this->offer(partner: $partner, verified: $verified);
		}
	}//end check()

	/**
	 * Run every check on the partner's announcement. A failed check withdraws any offered renewal; only a document
	 * that could not be fetched leaves it, since that may pass.
	 *
	 * @param FederationPartner $partner The partner
	 *
	 * @return array{fingerprint:string,windowEndsAt:DateTime}|null The verified renewal, null when a check failed
	 *
	 * @throws PartnerUnreachableException When the partner's discovery document could not be fetched
	 */
	private function verifyOrWithdraw(FederationPartner $partner): ?array {
		try {
			return $this->verifier->verify(partner: $partner);
		} catch (PartnerUnreachableException $exception) {
			$this->logRefusal(partner: $partner, reason: $exception->getMessage());
			throw $exception;
		} catch (InvalidArgumentException $exception) {
			$this->logRefusal(partner: $partner, reason: $exception->getMessage());
		}

		if ($partner->getPendingRootFingerprint() !== null) {
			$partner->withdrawRenewal();
			$this->partnerMapper->update(entity: $partner);
		}

		return null;
	}//end verifyOrWithdraw()

	/**
	 * Store a verified renewal as the partner's offer and notify the administrators, unless the same renewal is
	 * offered already. The offer counts as the reminder its days left fall in.
	 *
	 * @param FederationPartner $partner The partner
	 * @param array{fingerprint:string,windowEndsAt:DateTime} $verified The verified renewal
	 *
	 * @return void
	 */
	private function offer(FederationPartner $partner, array $verified): void {
		$endsAt = $partner->getPendingRootWindowEndsAt();
		if ($partner->getPendingRootFingerprint() === $verified['fingerprint']
			&& $endsAt?->getTimestamp() === $verified['windowEndsAt']->getTimestamp()
		) {
			return;
		}

		$now = $this->time->getDateTime();
		$daysLeft = (int)ceil(($verified['windowEndsAt']->getTimestamp() - $now->getTimestamp()) / 86400);
		$partner->offerRenewal(fingerprint: $verified['fingerprint'], verifiedAt: $now, windowEndsAt: $verified['windowEndsAt']);
		$partner->setPendingRootReminded($this->thresholdFor(daysLeft: $daysLeft));
		$this->partnerMapper->update(entity: $partner);

		$this->admins->notifyAdmins(subject: self::SUBJECT_OFFERED, partner: $partner, daysLeft: $daysLeft);
	}//end offer()

	/**
	 * The reminder a number of days left falls in: (5, 7] is 7, (3, 5] is 5, (1, 3] is 3 and (0, 1] is 1.
	 *
	 * @param int $daysLeft Whole days left, rounded up
	 *
	 * @return int|null Null outside them
	 */
	private function thresholdFor(int $daysLeft): ?int {
		$threshold = null;
		foreach (self::REMINDER_DAYS as $days) {
			if ($daysLeft <= $days && $daysLeft > 0) {
				$threshold = $days;
			}
		}

		return $threshold;
	}//end thresholdFor()

	/**
	 * The listed partner, in any direction, whose host the notice's `sender` names.
	 *
	 * @param array<array-key,mixed> $payload The notice
	 *
	 * @return FederationPartner|null
	 */
	private function partnerNamedBy(array $payload): ?FederationPartner {
		$sender = ($payload['sender'] ?? null);
		if (is_string($sender) === false || $this->cloudIdManager->isValidCloudId($sender) === false) {
			return null;
		}

		try {
			$host = $this->partners->hostOf(url: $this->cloudIdManager->resolveCloudId($sender)->getRemote());
			return $this->partnerMapper->findByHost(host: (string)$host);
		} catch (InvalidArgumentException | DoesNotExistException) {
			return null;
		}
	}//end partnerNamedBy()

	/**
	 * The OCM address a partner's notice is signed as.
	 *
	 * @param FederationPartner $partner The partner
	 *
	 * @return string
	 */
	private function identityOf(FederationPartner $partner): string {
		return AppIdentity::APP_ID . '@' . $partner->getHost();
	}//end identityOf()

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
