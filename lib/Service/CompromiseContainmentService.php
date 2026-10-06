<?php

/**
 * Keepiq CompromiseContainmentService
 *
 * The compromise half of an administrator's force-revoke (ADR-005). It used to
 * be a listener on the revoke event; it is a service the controller calls so
 * that:
 *  - the blast radius is collected BEFORE any suite is revoked, because the
 *    revoke cascade deletes the ShareTargets and invalidates the emergency
 *    contacts it reads (keepiq#864);
 *  - every step contains its own failure and is counted, and the count reaches
 *    the administrator instead of only the server log (keepiq#863);
 *  - the account is contained, not only its secrets flagged: link shares and
 *    passkeys revoked (keepiq#858) and every session and app password ended
 *    (keepiq#860).
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

use OCA\Keepiq\Db\EmergencyContact;
use OCA\Keepiq\Db\EmergencyContactMapper;
use OCA\Keepiq\Db\EncryptionSuite;
use OCA\Keepiq\Db\Secret;
use OCA\Keepiq\Db\SecretMapper;
use OCA\Keepiq\Db\ShareTargetMapper;
use OCA\Keepiq\Listener\MarksCompromisedSecrets;
use OCP\Authentication\Token\IProvider;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * Collect and contain the blast radius of a compromise force-revoke.
 *
 * @SuppressWarnings(PHPMD.CouplingBetweenObjects) Containment is by definition
 *   the place where the suite, sharing, emergency-access, notification and
 *   session domains meet; each dependency is one thing the compromised key
 *   could reach.
 *
 * @spec openspec/specs/encryption-suites/spec.md#requirement-a-compromise-force-revoke-contains-the-account
 */
class CompromiseContainmentService {
	use MarksCompromisedSecrets;

	/**
	 * Constructor.
	 *
	 * @param SecretMapper           $secretMapper           Blast-radius lookup and stamp
	 * @param ShareTargetMapper      $shareTargetMapper      Resolves shared sources and outbound copies
	 * @param EmergencyContactMapper $contactMapper Finds grantors exposed through the revoked key
	 * @param NotificationService    $notificationService    Warns the affected users
	 * @param MigrationService       $migrationService       Revokes the owner's link shares and passkeys
	 * @param IProvider              $tokenProvider          Ends the owner's sessions and app passwords
	 * @param LoggerInterface        $logger                 The logger
	 * @param RotationPolicyService|null $rotationService    Raises the suite_compromise rotation flags
	 *
	 * @return void
	 */
	public function __construct(
		private SecretMapper $secretMapper,
		private ShareTargetMapper $shareTargetMapper,
		private EmergencyContactMapper $contactMapper,
		private NotificationService $notificationService,
		private MigrationService $migrationService,
		private IProvider $tokenProvider,
		private LoggerInterface $logger,
		private ?RotationPolicyService $rotationService = null,
	) {
	}//end __construct()

	/**
	 * Collect what the given suites' compromise exposes. Changes nothing.
	 *
	 * Call it before revoking any of the suites. A lookup that fails is counted
	 * on the result, never thrown, so one bad row cannot hide the rest.
	 *
	 * @param list<string> $suiteIds The suites about to be revoked as compromised
	 *
	 * @return CompromiseBlastRadius
	 *
	 * @spec openspec/specs/encryption-suites/spec.md#requirement-a-compromise-force-revoke-contains-the-account
	 */
	public function collect(array $suiteIds): CompromiseBlastRadius {
		$radius = new CompromiseBlastRadius();
		foreach ($suiteIds as $suiteId) {
			$this->collectSealed(radius: $radius, suiteId: $suiteId);
			$this->collectGrantors(radius: $radius, suiteId: $suiteId);
		}

		return $radius;
	}//end collect()

	/**
	 * Stamp, flag and warn over a collected blast radius, then contain the account.
	 *
	 * Runs after the suites are revoked. Every step is contained on its own and
	 * counted: a failure on one secret or one notification never stops the
	 * rest, and the caller reports the count to the administrator (keepiq#863).
	 *
	 * @param CompromiseBlastRadius $radius    Collected before the revoke
	 * @param EncryptionSuite       $suite     The suite the administrator revoked
	 * @param string                $revokedBy The acting administrator
	 *
	 * @return array{stamped: int, notified: int, failed: int}
	 *
	 * @spec openspec/specs/encryption-suites/spec.md#requirement-a-compromise-force-revoke-contains-the-account
	 */
	public function contain(CompromiseBlastRadius $radius, EncryptionSuite $suite, string $revokedBy): array {
		$tally = ['stamped' => 0, 'notified' => 0, 'failed' => $radius->getFailures()];

		$this->stampSealed(radius: $radius, tally: $tally);
		$this->warnOwners(radius: $radius, suite: $suite, revokedBy: $revokedBy, tally: $tally);
		$this->warnRecipients(radius: $radius, tally: $tally);
		$this->warnGrantors(radius: $radius, tally: $tally);

		if ($suite->getOwnerType() === 'user') {
			$this->containAccount(ownerId: (string)$suite->getOwnerId(), tally: $tally);
		}

		if ($tally['failed'] > 0) {
			$this->logger->error(
				'Keepiq: compromise containment for suite ' . $suite->getId() . ' is incomplete',
				['app' => 'keepiq', 'stamped' => $tally['stamped'], 'failed' => $tally['failed']]
			);
		}

		return $tally;
	}//end contain()

	/**
	 * Tell a user that an administrator's revoke deleted their emergency contacts.
	 *
	 * Revocation deletes the contacts outright, so the owner has nothing left to
	 * look at afterwards; this notice is the only way they learn they have to
	 * designate them again (keepiq#876). A count only, never identities.
	 *
	 * @param EncryptionSuite $suite The revoked suite
	 * @param int             $count The usable contacts the revoke deleted
	 *
	 * @return bool True when a notice went out
	 *
	 * @spec openspec/specs/encryption-suites/spec.md#requirement-administrator-force-revocation
	 */
	public function notifyEmergencyAccessCleared(EncryptionSuite $suite, int $count): bool {
		if ($count <= 0 || $suite->getOwnerType() !== 'user') {
			return false;
		}

		try {
			return $this->notificationService->notify(
				subject: 'emergency_access_cleared',
				recipientId: (string)$suite->getOwnerId(),
				params: ['count' => $count],
				objectType: 'suite',
				objectId: (string)$suite->getId(),
			);
		} catch (Throwable $exception) {
			$this->logger->warning(
				'Keepiq: could not tell ' . $suite->getOwnerId() . ' their emergency access was cleared: '
				. $exception->getMessage(),
				['app' => 'keepiq']
			);
			return false;
		}
	}//end notifyEmergencyAccessCleared()

	/**
	 * Collect the secrets sealed under one suite and the copies shared out of it.
	 *
	 * @param CompromiseBlastRadius $radius  The radius being collected
	 * @param string                $suiteId The suite
	 *
	 * @return void
	 */
	private function collectSealed(CompromiseBlastRadius $radius, string $suiteId): void {
		try {
			$secrets = $this->secretMapper->findByEncryptionSuiteId($suiteId);
		} catch (Throwable $exception) {
			$radius->addFailure();
			$this->logger->error(
				'Keepiq: could not list the secrets sealed under suite ' . $suiteId . ': ' . $exception->getMessage(),
				['app' => 'keepiq']
			);
			return;
		}

		foreach ($secrets as $secret) {
			$failed = false;
			$target = $this->resolveTarget(secret: $secret, failed: $failed);
			if ($failed === true) {
				$radius->addFailure();
			}

			$radius->addSealed(secret: $secret, target: $target);
			// After a failed lookup $secret may be a copy, and its outbound
			// lookup would fail on the same cause: one failure, counted once.
			if ($target === $secret && $failed === false) {
				$this->collectOutbound(radius: $radius, secret: $secret);
			}
		}
	}//end collectSealed()

	/**
	 * Collect the copies other users hold of one of the revoked user's secrets.
	 *
	 * Those recipients are the only people left who can act on the value, and
	 * nothing else tells them (keepiq#872).
	 *
	 * @param CompromiseBlastRadius $radius The radius being collected
	 * @param Secret                $secret The revoked user's own secret
	 *
	 * @return void
	 */
	private function collectOutbound(CompromiseBlastRadius $radius, Secret $secret): void {
		try {
			foreach ($this->shareTargetMapper->findBySourceSecret($secret->getId()) as $shareTarget) {
				$recipientId = $shareTarget->getTargetUserId();
				if ($recipientId === '' || $recipientId === $secret->getOwnerId()) {
					continue;
				}

				$copy = $this->secretMapper->findById($shareTarget->getSecretId());
				$radius->addOutbound(recipientId: $recipientId, copy: $copy);
			}
		} catch (Throwable $exception) {
			$radius->addFailure();
			$this->logger->warning(
				'Keepiq: could not list the copies of secret ' . $secret->getId() . ': ' . $exception->getMessage(),
				['app' => 'keepiq']
			);
		}
	}//end collectOutbound()

	/**
	 * Collect the grantors whose approved emergency envelope the suite can open.
	 *
	 * An approved envelope escrows the grantor's private key, sealed to the
	 * grantee's suite. With that suite compromised the grantor's whole vault is
	 * exposed (keepiq#872).
	 *
	 * @param CompromiseBlastRadius $radius  The radius being collected
	 * @param string                $suiteId The grantee's suite
	 *
	 * @return void
	 */
	private function collectGrantors(CompromiseBlastRadius $radius, string $suiteId): void {
		try {
			foreach ($this->contactMapper->findByGranteeSuite($suiteId) as $contact) {
				if ($contact->getState() !== EmergencyContact::STATE_APPROVED) {
					continue;
				}

				$radius->addGrantor(grantorId: $contact->getGrantorUserId(), granteeId: $contact->getGranteeUserId());
			}
		} catch (Throwable $exception) {
			$radius->addFailure();
			$this->logger->error(
				'Keepiq: could not list the emergency grants of suite ' . $suiteId . ': ' . $exception->getMessage(),
				['app' => 'keepiq']
			);
		}
	}//end collectGrantors()

	/**
	 * Stamp and flag every sealed secret and, for a shared copy, its source.
	 *
	 * @param CompromiseBlastRadius                         $radius The collected radius
	 * @param array{stamped: int, notified: int, failed: int} $tally  Running counts
	 *
	 * @return void
	 */
	private function stampSealed(CompromiseBlastRadius $radius, array &$tally): void {
		$done = [];
		foreach ($radius->getSealed() as $entry) {
			foreach ([$entry['secret'], $entry['target']] as $secret) {
				if (isset($done[$secret->getId()]) === true) {
					continue;
				}

				$done[$secret->getId()] = true;
				if ($this->stampAndFlag(secret: $secret) === true) {
					$tally['stamped']++;
					continue;
				}

				$tally['failed']++;
			}
		}
	}//end stampSealed()

	/**
	 * Warn each owner once, naming the first secret and counting the others.
	 *
	 * One notification per owner keeps a large vault from flooding the inbox,
	 * but it must say how many secrets are affected, or one name reads as
	 * "only this one" (keepiq#875).
	 *
	 * @param CompromiseBlastRadius                         $radius    The collected radius
	 * @param EncryptionSuite                               $suite     The revoked suite
	 * @param string                                        $revokedBy The acting administrator
	 * @param array{stamped: int, notified: int, failed: int} $tally     Running counts
	 *
	 * @return void
	 */
	private function warnOwners(CompromiseBlastRadius $radius, EncryptionSuite $suite, string $revokedBy, array &$tally): void {
		$byOwner = [];
		foreach ($radius->getSealed() as $entry) {
			$target = $entry['target'];
			$ownerId = (string)$target->getOwnerId();
			if ($ownerId === '') {
				continue;
			}

			$byOwner[$ownerId]['first'] ??= $target;
			$byOwner[$ownerId]['ids'][$target->getId()] = true;
		}

		foreach ($byOwner as $ownerId => $group) {
			$this->send(
				subject: 'secret_compromised',
				recipientId: (string)$ownerId,
				first: $group['first'],
				count: count($group['ids']),
				extra: ['suiteId' => $suite->getId(), 'revokedBy' => $revokedBy],
				tally: $tally
			);
		}
	}//end warnOwners()

	/**
	 * Warn each holder of a copy of the revoked user's secrets, once.
	 *
	 * @param CompromiseBlastRadius                         $radius The collected radius
	 * @param array{stamped: int, notified: int, failed: int} $tally  Running counts
	 *
	 * @return void
	 */
	private function warnRecipients(CompromiseBlastRadius $radius, array &$tally): void {
		$byRecipient = [];
		foreach ($radius->getOutbound() as $entry) {
			$byRecipient[$entry['recipientId']]['first'] ??= $entry['copy'];
			$byRecipient[$entry['recipientId']]['ids'][$entry['copy']->getId()] = true;
		}

		foreach ($byRecipient as $recipientId => $group) {
			$this->send(
				subject: 'shared_secret_compromised',
				recipientId: (string)$recipientId,
				first: $group['first'],
				count: count($group['ids']),
				extra: [],
				tally: $tally
			);
		}
	}//end warnRecipients()

	/**
	 * Warn each grantor whose vault the compromised grantee could open.
	 *
	 * @param CompromiseBlastRadius                         $radius The collected radius
	 * @param array{stamped: int, notified: int, failed: int} $tally  Running counts
	 *
	 * @return void
	 */
	private function warnGrantors(CompromiseBlastRadius $radius, array &$tally): void {
		$warned = [];
		foreach ($radius->getGrantors() as $grant) {
			if (isset($warned[$grant['grantorId']]) === true) {
				continue;
			}

			$warned[$grant['grantorId']] = true;
			try {
				$sent = $this->notificationService->notify(
					subject: 'emergency_grantee_compromised',
					recipientId: $grant['grantorId'],
					params: ['granteeUserId' => $grant['granteeId']],
					objectType: 'emergency_contact',
					objectId: $grant['granteeId'],
				);
				$tally['notified'] += (int)$sent;
			} catch (Throwable $exception) {
				$tally['failed']++;
				$this->logger->warning(
					'Keepiq: could not warn grantor ' . $grant['grantorId'] . ': ' . $exception->getMessage(),
					['app' => 'keepiq']
				);
			}
		}//end foreach
	}//end warnGrantors()

	/**
	 * Send one aggregated secret warning, containing its own failure.
	 *
	 * @param string                                        $subject     The notification subject
	 * @param string                                        $recipientId Who is warned
	 * @param Secret                                        $first       The secret the notice names and links
	 * @param int                                           $count       How many secrets the notice covers
	 * @param array<string,string>                          $extra       Extra subject parameters
	 * @param array{stamped: int, notified: int, failed: int} $tally       Running counts
	 *
	 * @return void
	 */
	private function send(string $subject, string $recipientId, Secret $first, int $count, array $extra, array &$tally): void {
		try {
			$sent = $this->notificationService->notify(
				subject: $subject,
				recipientId: $recipientId,
				params: $extra + [
					'secret_id' => $first->getId(),
					'secret_name' => $first->getName(),
					'other_count' => $count - 1,
				],
				objectType: 'secret',
				objectId: $first->getId(),
			);
			$tally['notified'] += (int)$sent;
		} catch (Throwable $exception) {
			$tally['failed']++;
			$this->logger->warning(
				'Keepiq: could not send ' . $subject . ' to ' . $recipientId . ': ' . $exception->getMessage(),
				['app' => 'keepiq']
			);
		}
	}//end send()

	/**
	 * Contain the account itself: link shares, passkeys, sessions.
	 *
	 * The same key-material cleanup the owner's compromise recovery runs
	 * (keepiq#858), plus ending every session and app password. Without the
	 * last step a stolen session could enrol a new suite the moment the old one
	 * is revoked and become the user's identity for every new share
	 * (keepiq#860).
	 *
	 * @param string                                        $ownerId The revoked user
	 * @param array{stamped: int, notified: int, failed: int} $tally   Running counts
	 *
	 * @return void
	 */
	private function containAccount(string $ownerId, array &$tally): void {
		try {
			$this->migrationService->revokeKeyMaterialOfOwner(ownerId: $ownerId);
		} catch (Throwable $exception) {
			$tally['failed']++;
			$this->logger->error(
				'Keepiq: could not revoke the link shares and passkeys of ' . $ownerId . ': ' . $exception->getMessage(),
				['app' => 'keepiq']
			);
		}

		try {
			$this->tokenProvider->invalidateTokensOfUser($ownerId, null);
		} catch (Throwable $exception) {
			$tally['failed']++;
			$this->logger->error(
				'Keepiq: could not end the sessions of ' . $ownerId . ': ' . $exception->getMessage(),
				['app' => 'keepiq']
			);
		}
	}//end containAccount()
}//end class
