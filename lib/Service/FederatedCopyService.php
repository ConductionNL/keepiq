<?php

/**
 * Keepiq Federated Copy Service
 *
 * Keeps the recipient's read-only copy of a federated share
 * (sharing-federated-recipients D4), from the ciphertext FederatedSharePuller
 * fetched. The copy is a `Secret` owned by the recipient, encrypted to their
 * active suite by the sender's browser, marked read-only and with the
 * sender's cloud id.
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
use OCA\Keepiq\Db\EncryptionSuiteMapper;
use OCA\Keepiq\Db\FederatedInbound;
use OCA\Keepiq\Db\Secret;
use OCA\Keepiq\Db\SecretMapper;
use OCP\AppFramework\Db\DoesNotExistException;
use Ramsey\Uuid\Uuid;
use RuntimeException;

/**
 * The recipient's read-only copy of a federated share.
 *
 * @spec openspec/specs/federated-sharing/spec.md#scenario-bob-accepts-a-shared-login
 */
class FederatedCopyService {
	/**
	 * Constructor for FederatedCopyService.
	 *
	 * @param FederatedSharePuller $puller Fetches the ciphertext from the sender
	 * @param SecretMapper $secretMapper The copies
	 * @param EncryptionSuiteMapper $suiteMapper The recipient's active suite
	 * @param SecretTypeService $typeService The copy's type
	 *
	 * @return void
	 *
	 * @spec exclude Constructor wiring only; no behaviour.
	 */
	public function __construct(
		private FederatedSharePuller $puller,
		private SecretMapper $secretMapper,
		private EncryptionSuiteMapper $suiteMapper,
		private SecretTypeService $typeService,
	) {
	}//end __construct()

	/**
	 * Pull the ciphertext and store it as a new read-only copy.
	 *
	 * @param FederatedInbound $row The accepted share
	 *
	 * @return Secret The stored copy
	 *
	 * @throws RuntimeException `pull_failed` or `no_suite`
	 *
	 * @spec openspec/specs/federated-sharing/spec.md#scenario-bob-accepts-a-shared-login
	 */
	public function pullNew(FederatedInbound $row): Secret {
		$answer = $this->puller->pull(row: $row);
		$userId = $row->getRecipientUid();

		try {
			$suite = $this->suiteMapper->findActiveByOwner(ownerType: 'user', ownerId: $userId);
		} catch (DoesNotExistException) {
			throw new RuntimeException('no_suite');
		}

		$now = new DateTime();
		$copy = new Secret();
		$copy->setId(Uuid::uuid4()->toString());
		$copy->setTypeId($this->typeFor(typeId: $answer['typeId'], userId: $userId));
		$copy->setFolderId(null);
		$copy->setEncryptionSuiteId($suite->getId());
		$copy->setOwnerType('user');
		$copy->setOwnerId($userId);
		$copy->setReadOnly(true);
		$copy->setFederatedSource($row->getSenderCloudId());
		$copy->setCreatedAt($now);
		$this->fill(copy: $copy, answer: $answer, now: $now);

		return $this->secretMapper->insert($copy);
	}//end pullNew()


	/**
	 * Pull again after the sender changed the secret, and replace the copy
	 * whole (task 4.1).
	 *
	 * @param FederatedInbound $row The accepted share
	 *
	 * @return Secret The updated copy
	 *
	 * @throws RuntimeException `pull_failed`, or `copy_missing` when the copy is gone
	 *
	 * @spec openspec/specs/federated-sharing/spec.md#scenario-a-password-change-reaches-bob
	 */
	public function refresh(FederatedInbound $row): Secret {
		$copy = $this->copyOf(row: $row);
		$answer = $this->puller->pull(row: $row);
		$this->fill(copy: $copy, answer: $answer, now: new DateTime());

		return $this->secretMapper->update($copy);
	}//end refresh()

	/**
	 * Delete the copy after the sender revoked the share (task 4.2). A copy
	 * that is already gone is fine.
	 *
	 * @param FederatedInbound $row The share
	 *
	 * @return void
	 *
	 * @spec openspec/specs/federated-sharing/spec.md#scenario-revocation-removes-bobs-copy
	 */
	public function remove(FederatedInbound $row): void {
		try {
			$this->secretMapper->delete($this->copyOf(row: $row));
		} catch (RuntimeException) {
			// Already gone.
		}
	}//end remove()

	/**
	 * The share's copy: the recipient's own read-only secret from that sender.
	 *
	 * @param FederatedInbound $row The share
	 *
	 * @return Secret
	 *
	 * @throws RuntimeException `copy_missing`
	 */
	private function copyOf(FederatedInbound $row): Secret {
		try {
			$copy = $this->secretMapper->findById((string)$row->getSecretId());
		} catch (DoesNotExistException) {
			throw new RuntimeException('copy_missing');
		}

		if ($copy->getOwnerId() !== $row->getRecipientUid() || $copy->getReadOnly() !== true) {
			throw new RuntimeException('copy_missing');
		}

		return $copy;
	}//end copyOf()

	/**
	 * Write a pulled answer into a copy.
	 *
	 * @param Secret $copy The copy
	 * @param array{name:string,url:?string,key:string,login:?string,additionalFields:?string} $answer The pulled answer
	 * @param DateTime $now The time of the pull
	 *
	 * @return void
	 */
	private function fill(Secret $copy, array $answer, DateTime $now): void {
		$copy->setName($answer['name']);
		$copy->setUrl($answer['url']);
		$copy->setKey($answer['key']);
		$copy->setLogin($answer['login']);
		$copy->setAdditionalFields($answer['additionalFields']);
		$copy->setUpdatedAt($now);
		$copy->setKeyUpdatedAt($now);
	}//end fill()

	/**
	 * The copy's type: the sender's when it is one this user can see (the
	 * system types share their ids across instances), else the default.
	 *
	 * @param string|null $typeId The sender's type id
	 * @param string $userId The recipient
	 *
	 * @return string
	 */
	private function typeFor(?string $typeId, string $userId): string {
		try {
			return $this->typeService->resolveTypeForSecret($typeId, $userId);
		} catch (InvalidArgumentException) {
			return $this->typeService->resolveTypeForSecret(null, $userId);
		}
	}//end typeFor()
}//end class
