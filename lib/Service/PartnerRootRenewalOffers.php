<?php

/**
 * Keepiq Partner Root Renewal Offers
 *
 * The renewal offered on a partner row (partner-root-renewal-announcement D3 to D6): the check of the partner's
 * announcement and what it does to the offer, storing a verified renewal and telling the administrators, and the
 * hourly reminders and withdrawal when the 30 days end.
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

use DateTimeInterface;
use InvalidArgumentException;
use OCA\Keepiq\Db\FederationPartner;
use OCA\Keepiq\Db\FederationPartnerMapper;
use OCA\Keepiq\Exception\PartnerUnreachableException;
use OCP\AppFramework\Db\DoesNotExistException;
use OCP\AppFramework\Utility\ITimeFactory;

/**
 * Checks, stores, reminds about and withdraws a partner's offered root renewal.
 *
 * @spec openspec/changes/partner-root-renewal-announcement/specs/federated-sharing/spec.md#requirement-an-announced-renewal-is-offered-to-administrators-after-its-signature-checks-out
 */
class PartnerRootRenewalOffers {
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
	 * Constructor for PartnerRootRenewalOffers.
	 *
	 * @param FederationPartnerMapper $partnerMapper The partner table
	 * @param PartnerRootRenewalVerifier $verifier Runs every check on the partner's announcement
	 * @param PartnerRootRenewalNotifier $admins Notifies the administrators
	 * @param ITimeFactory $time The clock
	 *
	 * @return void
	 *
	 * @spec exclude Constructor wiring only; no behaviour.
	 */
	public function __construct(
		private FederationPartnerMapper $partnerMapper,
		private PartnerRootRenewalVerifier $verifier,
		private PartnerRootRenewalNotifier $admins,
		private ITimeFactory $time,
	) {
	}//end __construct()

	/**
	 * Whether a lookup answer's announcement names a root that is neither pinned nor already offered. Only the
	 * announced root's fingerprint is read, to decide that.
	 *
	 * @param FederationPartner $partner The partner that answered
	 * @param mixed $announcement The answer's `rootRenewal`
	 *
	 * @return bool
	 *
	 * @spec openspec/changes/partner-root-renewal-announcement/specs/federated-sharing/spec.md#requirement-an-announced-renewal-is-offered-to-administrators-after-its-signature-checks-out
	 */
	public function announcesNewRoot(FederationPartner $partner, mixed $announcement): bool {
		$fingerprint = $this->verifier->announcedFingerprint(announcement: $announcement);
		$pending = (string)$partner->getPendingRootFingerprint();

		return $fingerprint !== null && hash_equals($partner->getRootFingerprint(), $fingerprint) === false
			&& hash_equals($pending, $fingerprint) === false;
	}//end announcesNewRoot()

	/**
	 * Run every check on the partner's announcement.
	 *
	 * @param FederationPartner $partner The partner
	 *
	 * @return array{fingerprint:string,windowEndsAt:\DateTime} The verified renewal
	 *
	 * @throws PartnerUnreachableException When the partner's discovery document could not be fetched
	 * @throws InvalidArgumentException Naming the first check that failed
	 *
	 * @spec openspec/changes/partner-root-renewal-announcement/specs/federated-sharing/spec.md#requirement-an-announced-renewal-is-offered-to-administrators-after-its-signature-checks-out
	 */
	public function verify(FederationPartner $partner): array {
		return $this->verifier->verify(partner: $partner);
	}//end verify()

	/**
	 * Withdraw the renewal the partner offers, if any.
	 *
	 * @param FederationPartner $partner The partner
	 *
	 * @return void
	 *
	 * @spec openspec/changes/partner-root-renewal-announcement/specs/federated-sharing/spec.md#requirement-an-announced-renewal-is-offered-to-administrators-after-its-signature-checks-out
	 */
	public function withdraw(FederationPartner $partner): void {
		if ($partner->getPendingRootFingerprint() !== null) {
			$partner->withdrawRenewal();
			$this->partnerMapper->update(entity: $partner);
		}
	}//end withdraw()

	/**
	 * The partner, when the renewal it offers now is the one with this fingerprint.
	 *
	 * @param string $id The partner UUID
	 * @param string $fingerprint The fingerprint the administrator accepts
	 *
	 * @return FederationPartner
	 *
	 * @throws DoesNotExistException When the partner does not exist
	 * @throws InvalidArgumentException When no renewal with this fingerprint is offered, or its window ended
	 *
	 * @spec openspec/changes/partner-root-renewal-announcement/specs/federated-sharing/spec.md#scenario-an-administrator-accepts-a-verified-renewal
	 */
	public function offeredBy(string $id, string $fingerprint): FederationPartner {
		$partner = $this->partnerMapper->findById(id: $id);
		$offered = $partner->offeredRenewal(now: $this->time->getDateTime());
		if ($offered === null || hash_equals($offered['fingerprint'], strtolower(trim($fingerprint))) === false) {
			throw new InvalidArgumentException('No renewed certificate with this fingerprint is offered for this partner');
		}

		return $partner;
	}//end offeredBy()

	/**
	 * Store a verified renewal as the partner's offer and notify the administrators. The row is read again first:
	 * when it was re-pinned since the check read it, or the same renewal is offered already, nothing is stored and no
	 * one is notified. The offer counts as the reminder its days left fall in.
	 *
	 * @param FederationPartner $partner The partner as the check read it
	 * @param array{fingerprint:string,windowEndsAt:\DateTime} $verified The renewal verified against its pin
	 *
	 * @return void
	 *
	 * @spec openspec/changes/partner-root-renewal-announcement/specs/federated-sharing/spec.md#requirement-an-announced-renewal-is-offered-to-administrators-after-its-signature-checks-out
	 */
	public function offer(FederationPartner $partner, array $verified): void {
		try {
			$current = $this->partnerMapper->findById(id: $partner->getId());
		} catch (DoesNotExistException) {
			return;
		}

		$endsAt = $current->getPendingRootWindowEndsAt();
		if (hash_equals($partner->getRootFingerprint(), $current->getRootFingerprint()) === false
			|| ($current->getPendingRootFingerprint() === $verified['fingerprint']
			&& $endsAt?->getTimestamp() === $verified['windowEndsAt']->getTimestamp())
		) {
			return;
		}

		$now = $this->time->getDateTime();
		$daysLeft = (int)ceil(($verified['windowEndsAt']->getTimestamp() - $now->getTimestamp()) / 86400);
		$current->offerRenewal(fingerprint: $verified['fingerprint'], verifiedAt: $now, windowEndsAt: $verified['windowEndsAt']);
		$current->setPendingRootReminded($this->thresholdFor(daysLeft: $daysLeft));
		$this->partnerMapper->update(entity: $current);

		$this->admins->notifyAdmins(subject: self::SUBJECT_OFFERED, partner: $current, daysLeft: $daysLeft);
	}//end offer()

	/**
	 * The hourly pass: withdraw every offered renewal whose window ended, and remind the administrators 7, 5, 3 and 1
	 * day before a window ends, each reminder once.
	 *
	 * @param DateTimeInterface $now The current time
	 *
	 * @return void
	 *
	 * @spec openspec/changes/partner-root-renewal-announcement/specs/federated-sharing/spec.md#requirement-an-offered-renewal-expires-after-30-days-with-reminders
	 */
	public function remindAndExpire(DateTimeInterface $now): void {
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
}//end class
