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
 * @spec openspec/changes/sharing-federated-recipients/specs/federated-sharing/spec.md#scenario-bob-accepts-a-shared-login
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
	 * @spec openspec/changes/sharing-federated-recipients/specs/federated-sharing/spec.md#scenario-bob-accepts-a-shared-login
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
