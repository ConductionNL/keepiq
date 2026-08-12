<?php

/**
 * Doriath Honey Credential Service
 *
 * Decoy tripwire logic (honey-credentials §2): flag/unflag decoy
 * secrets, raise deduplicated alerts on access, page owner + admins
 * (ungated), and record the distinguished honey.accessed audit event.
 * Deception is detection, not prevention — an alert never blocks or
 * delays the observed access, and the flag is never serialized into
 * any secret response shape.
 *
 * @category Service
 * @package  OCA\Doriath\Service
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

namespace OCA\Doriath\Service;

use DateInterval;
use DateTime;
use InvalidArgumentException;
use OCA\Doriath\Db\HoneyAlert;
use OCA\Doriath\Db\HoneyAlertMapper;
use OCA\Doriath\Db\HoneyFlag;
use OCA\Doriath\Db\HoneyFlagMapper;
use OCA\Doriath\Db\SecretMapper;
use OCP\AppFramework\Db\DoesNotExistException;
use Ramsey\Uuid\Uuid;

/**
 * Management of honey (decoy) credentials: flags and their alert log.
 *
 * The detection path — raising, paging and auditing a tripwire hit — lives
 * in HoneyTripwireService; this class owns the flag lifecycle and the
 * owner/admin-guarded views onto the alerts it produced.
 */
class HoneyCredentialService {
	/**
	 * Constructor for HoneyCredentialService.
	 *
	 * @param HoneyFlagMapper $flagMapper The flag mapper
	 * @param HoneyAlertMapper $alertMapper The alert mapper
	 * @param SecretMapper $secretMapper The secret mapper (owner guard)
	 *
	 * @return void
	 */
	public function __construct(
		private HoneyFlagMapper $flagMapper,
		private HoneyAlertMapper $alertMapper,
		private SecretMapper $secretMapper,
	) {
	}//end __construct()

	/**
	 * Flag a secret as a decoy (owner or admin, §2.1). Upsert — a
	 * repeated flag call updates the note.
	 *
	 * @param string $secretId The secret UUID
	 * @param string $actorId The calling user
	 * @param bool $isAdmin Whether the caller is an admin
	 * @param string|null $note Optional placement note
	 *
	 * @return HoneyFlag
	 *
	 * @throws DoesNotExistException When the secret does not exist
	 * @throws InvalidArgumentException When the caller may not flag it
	 */
	public function flag(string $secretId, string $actorId, bool $isAdmin, ?string $note = null): HoneyFlag {
		$secret = $this->secretMapper->findById($secretId);
		$ownsIt = ($secret->getOwnerType() === 'user' && $secret->getOwnerId() === $actorId);
		if ($ownsIt === false && $isAdmin === false) {
			throw new InvalidArgumentException('Only the owner or an admin may flag a honey secret');
		}

		try {
			$flag = $this->flagMapper->findBySecretId($secretId);
			$flag->setNote($note);

			return $this->flagMapper->update($flag);
		} catch (DoesNotExistException) {
			$flag = new HoneyFlag();
			$flag->setId(Uuid::uuid4()->toString());
			$flag->setSecretId($secretId);
			$flag->setOwnerId($secret->getOwnerId());
			$flag->setNote($note);
			$flag->setCreatedBy($actorId);
			$flag->setCreatedAt(new DateTime());

			return $this->flagMapper->insert($flag);
		}
	}//end flag()

	/**
	 * Remove the decoy flag (owner or admin). Alert rows are KEPT — the
	 * forensic trail survives unflagging.
	 *
	 * @param string $secretId The secret UUID
	 * @param string $actorId The calling user
	 * @param bool $isAdmin Whether the caller is an admin
	 *
	 * @return void
	 *
	 * @throws DoesNotExistException When the secret is not flagged
	 * @throws InvalidArgumentException When the caller may not unflag it
	 */
	public function unflag(string $secretId, string $actorId, bool $isAdmin): void {
		$flag = $this->flagMapper->findBySecretId($secretId);
		if ($flag->getOwnerId() !== $actorId && $isAdmin === false) {
			throw new InvalidArgumentException('Only the owner or an admin may unflag a honey secret');
		}

		$this->flagMapper->delete($flag);
	}//end unflag()

	/**
	 * The flag of a secret for the owner/admin detail view, or null.
	 *
	 * @param string $secretId The secret UUID
	 * @param string $actorId The calling user
	 * @param bool $isAdmin Whether the caller is an admin
	 *
	 * @return HoneyFlag|null
	 *
	 * @throws InvalidArgumentException When the caller may not see it
	 */
	public function getFlag(string $secretId, string $actorId, bool $isAdmin): ?HoneyFlag {
		try {
			$flag = $this->flagMapper->findBySecretId($secretId);
		} catch (DoesNotExistException) {
			return null;
		}

		if ($flag->getOwnerId() !== $actorId && $isAdmin === false) {
			throw new InvalidArgumentException('Only the owner or an admin may inspect a honey flag');
		}

		return $flag;
	}//end getFlag()

	/**
	 * List alerts: owner sees own decoys' alerts; admins instance-wide.
	 *
	 * @param string $actorId The calling user
	 * @param bool $isAdmin Whether the caller is an admin
	 *
	 * @return HoneyAlert[]
	 */
	public function listAlerts(string $actorId, bool $isAdmin): array {
		if ($isAdmin === true) {
			return $this->alertMapper->findAll();
		}

		$flagIds = array_map(
			static fn (HoneyFlag $flag): string => $flag->getId(),
			$this->flagMapper->findByOwner($actorId)
		);

		return $this->alertMapper->findByFlagIds(flagIds: $flagIds);
	}//end listAlerts()

	/**
	 * Acknowledge an alert (owner of the decoy or admin).
	 *
	 * @param string $alertId The alert UUID
	 * @param string $actorId The calling user
	 * @param bool $isAdmin Whether the caller is an admin
	 *
	 * @return HoneyAlert
	 *
	 * @throws DoesNotExistException When the alert is missing
	 * @throws InvalidArgumentException When the caller may not act on it
	 */
	public function acknowledge(string $alertId, string $actorId, bool $isAdmin): HoneyAlert {
		$alert = $this->guardedAlert(alertId: $alertId, actorId: $actorId, isAdmin: $isAdmin);
		$alert->setAcknowledgedAt(new DateTime());
		$alert->setAcknowledgedBy($actorId);

		return $this->alertMapper->update($alert);
	}//end acknowledge()

	/**
	 * Snooze future paging for the alert's accessor (owner or admin).
	 * A snoozed accessor stops paging but every access is still audited.
	 *
	 * @param string $alertId The alert UUID
	 * @param string $actorId The calling user
	 * @param bool $isAdmin Whether the caller is an admin
	 * @param int $hours Snooze duration in hours
	 *
	 * @return HoneyAlert
	 *
	 * @throws DoesNotExistException When the alert is missing
	 * @throws InvalidArgumentException When the caller may not act on it
	 */
	public function snooze(string $alertId, string $actorId, bool $isAdmin, int $hours = 24): HoneyAlert {
		$alert = $this->guardedAlert(alertId: $alertId, actorId: $actorId, isAdmin: $isAdmin);
		$alert->setSnoozedUntil((new DateTime())->add(new DateInterval('PT' . max(1, $hours) . 'H')));

		return $this->alertMapper->update($alert);
	}//end snooze()

	/**
	 * Load an alert and enforce the owner-or-admin guard.
	 *
	 * @param string $alertId The alert UUID
	 * @param string $actorId The calling user
	 * @param bool $isAdmin Whether the caller is an admin
	 *
	 * @return HoneyAlert
	 *
	 * @throws DoesNotExistException When the alert is missing
	 * @throws InvalidArgumentException When the caller may not act on it
	 */
	private function guardedAlert(string $alertId, string $actorId, bool $isAdmin): HoneyAlert {
		$alert = $this->alertMapper->findById($alertId);
		if ($isAdmin === true) {
			return $alert;
		}

		try {
			$flag = $this->flagMapper->findBySecretId($alert->getSecretId());
			if ($flag->getOwnerId() === $actorId) {
				return $alert;
			}
		} catch (DoesNotExistException) {
			// Flag removed — only admins may act on orphaned alerts.
		}

		throw new InvalidArgumentException('Only the decoy owner or an admin may act on this alert');
	}//end guardedAlert()
}//end class
