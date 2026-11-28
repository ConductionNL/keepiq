<?php

/**
 * Keepiq UseOnlyUseRecorder
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
use OCA\Keepiq\Db\Secret;
use OCA\Keepiq\Db\SecretMapper;
use OCA\Keepiq\Event\Audit\AuditEventFactory;
use OCA\Keepiq\Event\Audit\AuditEventTypes;
use OCA\Keepiq\Exception\NotFoundException;
use OCA\Keepiq\Kernel\Contracts\UseOnlyUseRecorderInterface;
use OCP\AppFramework\Db\DoesNotExistException;
use OCP\EventDispatcher\IEventDispatcher;

/**
 * Records each fill of a use-only copy the extension reports, as a
 * `secret.used` audit event on the SOURCE secret, so the owner sees in its
 * activity tab who used the login and when
 * (sharing-use-only-and-expiring-shares D3, task 3.3).
 */
class UseOnlyUseRecorder implements UseOnlyUseRecorderInterface {

	/**
	 * Constructor for UseOnlyUseRecorder.
	 *
	 * @param SecretMapper      $secretMapper      The secret mapper
	 * @param ShareRowJudge $rowJudge Judged share rows (ShareRowJudge); every lookup of a share row goes through it
	 * @param IEventDispatcher  $eventDispatcher   The audit event dispatcher
	 * @param AuditEventFactory $auditEvents       The audit-event factory
	 *
	 * @return void
	 *
	 * @spec exclude Constructor wiring only.
	 */
	public function __construct(
		private SecretMapper $secretMapper,
		private ShareRowJudge $rowJudge,
		private IEventDispatcher $eventDispatcher,
		private AuditEventFactory $auditEvents = new AuditEventFactory(),
	) {
	}//end __construct()

	/**
	 * Record one use of a use-only copy by its holder.
	 *
	 * Refused, with the answer an unknown id gets, for a secret the caller
	 * does not hold, for a copy that is not use-only, and for a copy whose
	 * access ended.
	 *
	 * @param string $copyId The recipient copy the extension filled
	 * @param string $userId The caller
	 *
	 * @return void
	 *
	 * @throws NotFoundException When the caller holds no such use-only copy
	 *
	 * @spec openspec/specs/use-only-shares/spec.md#requirement-each-use-is-recorded
	 */
	public function recordUse(string $copyId, string $userId): void {
		$copy = $this->heldUseOnlyCopy(copyId: $copyId, userId: $userId);

		// Only now, for the caller's own live use-only row, is its share row judged.
		$sourceId = $this->usedSecretOf(copy: $copy);
		$this->secretMapper->markUsed($copyId, $userId, new DateTime());

		$sourceName = $this->usedSecretName(copy: $copy, sourceId: $sourceId);
		$this->eventDispatcher->dispatchTyped(
			$this->auditEvents->forUser(
				actorId: $userId,
				eventType: AuditEventTypes::SECRET_USED,
				objectType: 'secret',
				objectId: $sourceId,
				objectName: $sourceName,
				metadata: ['copyId' => $copyId],
			)
		);
	}//end recordUse()

	/**
	 * The caller's own live use-only copy, refused as an unknown id otherwise.
	 *
	 * @param string $copyId The recipient copy the extension filled
	 * @param string $userId The caller
	 *
	 * @return Secret
	 *
	 * @throws NotFoundException When the caller holds no such use-only copy
	 *
	 * @spec openspec/specs/use-only-shares/spec.md#requirement-each-use-is-recorded
	 */
	private function heldUseOnlyCopy(string $copyId, string $userId): Secret {
		try {
			$copy = $this->secretMapper->findById($copyId);
		} catch (DoesNotExistException) {
			throw new NotFoundException(message: 'Secret not found');
		}

		if ($this->isHeldBy(copy: $copy, userId: $userId) === false || $this->isLiveUseOnly(copy: $copy) === false) {
			throw new NotFoundException(message: 'Secret not found');
		}

		return $copy;
	}//end heldUseOnlyCopy()

	/**
	 * Whether the caller holds the copy in their own vault.
	 *
	 * @param Secret $copy   The copy
	 * @param string $userId The caller
	 *
	 * @return bool
	 */
	private function isHeldBy(Secret $copy, string $userId): bool {
		return $copy->getOwnerType() === 'user' && $copy->getOwnerId() === $userId;
	}//end isHeldBy()

	/**
	 * Whether the copy is use-only and its access has not ended.
	 *
	 * @param Secret $copy The copy
	 *
	 * @return bool
	 */
	private function isLiveUseOnly(Secret $copy): bool {
		$accessEnds = $copy->getAccessExpiresAt();
		return $copy->getUseOnly() === true && ($accessEnds === null || $accessEnds > new DateTime());
	}//end isLiveUseOnly()

	/**
	 * The name a use is recorded under: the source's, or the copy's own when
	 * the source is gone (the same plaintext metadata).
	 *
	 * @param Secret $copy     The copy
	 * @param string $sourceId The secret the use is recorded on
	 *
	 * @return string
	 */
	private function usedSecretName(Secret $copy, string $sourceId): string {
		try {
			return $this->secretMapper->findById($sourceId)->getName();
		} catch (DoesNotExistException) {
			return $copy->getName();
		}
	}//end usedSecretName()

	/**
	 * The secret a use of a copy is recorded on: its source, through a share
	 * row the judge trusts. A use of a secret a held-back row names is
	 * recorded on that secret itself.
	 *
	 * @param Secret $copy The copy
	 *
	 * @return string
	 *
	 * @throws NotFoundException When no share row, or more than one, names the copy
	 *
	 * @spec openspec/specs/user-sharing/spec.md#requirement-runtime-paths-follow-only-judged-share-rows
	 */
	private function usedSecretOf(Secret $copy): string {
		$verdict = $this->rowJudge->forCopy(copy: $copy);
		if ($verdict->state === ShareRowVerdict::NONE || $verdict->state === ShareRowVerdict::AMBIGUOUS) {
			throw new NotFoundException(message: 'Secret not found');
		}

		return ($verdict->trustedRow()?->getSourceSecretId() ?? $copy->getId());
	}//end usedSecretOf()
}//end class
