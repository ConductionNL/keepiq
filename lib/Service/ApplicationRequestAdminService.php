<?php

/**
 * Doriath - Administrator-scoped reads and revokes over application requests
 *
 * A class of its own rather than two more methods on SecretRequestService, for
 * the same reason the controller is separate: these answer to a DIFFERENT
 * authority. Everything on SecretRequestService is scoped to the acting user —
 * `listByUser()` means "requests I created", `decline()` asserts the caller
 * created the request. Everything here is scoped to an administrator acting on
 * software they did not write. Putting the two beside each other would leave two
 * authorities one typo apart, and phpmd was already reporting that class as too
 * complex to keep absorbing decisions.
 *
 * What it deliberately does NOT duplicate: the emptiness predicate that decides
 * whether a placeholder Secret may be deleted. That lives on the Secret entity
 * (`Secret::holdsNoValues()`) precisely so this service and the user-scoped one
 * cannot disagree about what "unfilled" means — they disagreed once already, and
 * the cost was hard-deleted credentials.
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

use InvalidArgumentException;
use OCA\Doriath\Db\SecretMapper;
use OCA\Doriath\Db\SecretRequest;
use OCA\Doriath\Db\SecretRequestMapper;
use OCP\AppFramework\Db\DoesNotExistException;
use OCP\AppFramework\Db\MultipleObjectsReturnedException;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * What an application is asking humans for, and how an administrator ends it.
 */
class ApplicationRequestAdminService {
	/**
	 * Constructor for ApplicationRequestAdminService.
	 *
	 * @param SecretRequestMapper $mapper Reads the request rows
	 * @param SecretMapper $secretMapper Reads the Secret a request writes to
	 * @param SecretRequestOutbox $outbox The audit + notification outbox
	 * @param LoggerInterface $logger The logger
	 * @param ContainerInterface $container Resolves SecretService lazily
	 *
	 * @return void
	 *
	 * @spec exclude Constructor wiring only — no domain logic.
	 */
	public function __construct(
		private SecretRequestMapper $mapper,
		private SecretMapper $secretMapper,
		private SecretRequestOutbox $outbox,
		private LoggerInterface $logger,
		private ContainerInterface $container,
	) {
	}//end __construct()

	/**
	 * Every secret request an application created, for an administrator.
	 *
	 * Admin-scoped rather than registrar-scoped on purpose. An application's vault
	 * belongs to no single user, registration is a historical act rather than a
	 * continuing responsibility, and the registrar may have left the organisation.
	 * Tying audit visibility to it would make "who can see this" depend on who
	 * happened to click Register months ago.
	 *
	 * Every status is returned, not only pending: "what has this application been
	 * asking people for" is the audit question, and a list of only what is
	 * outstanding answers a narrower one.
	 *
	 * The caller passes its own authorization answer, matching how the other admin
	 * paths in this app work — the controller resolves `isAdmin` from the group
	 * manager, and the refusal is enforced HERE as well, so an endpoint cannot
	 * expose the listing by carrying the wrong annotation.
	 *
	 * @param string $applicationId The application whose requests to list
	 * @param bool $isAdmin Whether the caller is an administrator
	 *
	 * @return array<int,SecretRequest> Newest first
	 *
	 * @throws InvalidArgumentException 403 when the caller is not an administrator
	 *
	 * @spec openspec/specs/application-mgmt/spec.md#requirement-outstanding-application-requests-visible-to-administrators
	 */
	public function listForApplication(string $applicationId, bool $isAdmin): array {
		if ($isAdmin === false) {
			throw new InvalidArgumentException(message: 'Administrator privileges are required', code: 403);
		}

		if ($applicationId === '') {
			throw new InvalidArgumentException(message: 'applicationId is required', code: 400);
		}

		$rows = $this->mapper->findByApplication(applicationId: $applicationId);

		usort(
			$rows,
			static fn (SecretRequest $a, SecretRequest $b): int => ($b->getCreatedAt()?->getTimestamp() ?? 0)
				<=> ($a->getCreatedAt()?->getTimestamp() ?? 0)
		);

		return $rows;
	}//end listForApplication()

	/**
	 * Revoke an application's secret request as an administrator.
	 *
	 * The point of the whole change: a pending fill link is a bearer credential in
	 * a URL, and it needs an off switch that does not depend on the application
	 * cooperating. `SecretRequestService::decline()` cannot serve here — it asserts
	 * the caller CREATED the request, and an administrator never did.
	 *
	 * Scoping is structural rather than trusted: the request must actually be this
	 * application's. Without that check, an endpoint addressed by request id would
	 * revoke ANY request — including a user's own — through an application route.
	 *
	 * @param string $requestId The request to revoke
	 * @param string $applicationId The application it must belong to
	 * @param string $adminUserId The administrator performing the revoke
	 * @param bool $isAdmin Whether the caller is an administrator
	 *
	 * @return SecretRequest The revoked request
	 *
	 * @throws InvalidArgumentException 403 not an admin, 404 not that application's, 400 not pending
	 *
	 * @spec openspec/specs/application-mgmt/spec.md#requirement-outstanding-application-requests-visible-to-administrators
	 */
	public function revokeForApplication(
		string $requestId,
		string $applicationId,
		string $adminUserId,
		bool $isAdmin,
	): SecretRequest {
		if ($isAdmin === false) {
			throw new InvalidArgumentException(message: 'Administrator privileges are required', code: 403);
		}

		$entity = $this->mapper->findById($requestId);

		// 404 rather than 403: an administrator asking the wrong application about a
		// request should not learn that the id exists somewhere else.
		if ($entity->belongsToApplication(applicationId: $applicationId) === false) {
			throw new InvalidArgumentException(message: 'Request not found for this application', code: 404);
		}

		if ($entity->getStatus() !== SecretRequest::STATUS_PENDING) {
			throw new InvalidArgumentException(message: 'Request is not pending', code: 400);
		}

		$entity->setStatus(SecretRequest::STATUS_DECLINED);
		$updated = $this->mapper->update($entity);

		$this->logger->info(
			'Doriath: administrator ' . $adminUserId . ' revoked application request ' . $requestId,
			['app' => 'doriath']
		);

		// After the status flip, deliberately: if the cleanup fails the request is
		// already revoked and the worst case is an orphan empty Secret. The other
		// order risks deleting a Secret while its request stays pending.
		$this->deletePlaceholderIfUnfilled(request: $updated, applicationId: $applicationId);

		$this->outbox->recordRevoked(userId: $adminUserId, requestId: $requestId);

		return $updated;
	}//end revokeForApplication()

	/**
	 * Delete an application-owned placeholder that never held a value.
	 *
	 * Uses `Secret::holdsNoValues()` rather than its own test, sharing the
	 * predicate with the user-scoped revoke: the cost of being too eager is
	 * identical here, a hard delete taking a credential and its version history
	 * with it.
	 *
	 * Ownership is re-checked against the Secret, not inferred from the request:
	 * two checks against different data, so a mismatched pair cannot destroy a
	 * third party's Secret.
	 *
	 * Fail-soft, because an orphan empty Secret is untidy while a revoke the
	 * administrator believes failed sends them back to retry against a circulating
	 * link that is in fact already dead.
	 *
	 * @param SecretRequest $request The revoked request
	 * @param string $applicationId The owning application
	 *
	 * @return void
	 *
	 * @spec openspec/specs/application-mgmt/spec.md#requirement-outstanding-application-requests-visible-to-administrators
	 */
	private function deletePlaceholderIfUnfilled(SecretRequest $request, string $applicationId): void {
		try {
			$secret = $this->secretMapper->findById($request->getSecretId());
		} catch (DoesNotExistException|MultipleObjectsReturnedException) {
			return;
		}

		if ($secret->holdsNoValues() === false) {
			return;
		}

		if ($secret->getOwnerType() !== 'application' || $secret->getOwnerId() !== $applicationId) {
			return;
		}

		try {
			$this->container->get(SecretService::class)->deleteByApplication(
				secretId: $secret->getId(),
				applicationId: $applicationId
			);
		} catch (Throwable $exception) {
			$this->logger->error(
				'Doriath: could not delete the application placeholder for revoked request '
				. $request->getId() . ': ' . $exception->getMessage(),
				['exception' => $exception]
			);
		}
	}//end deletePlaceholderIfUnfilled()
}//end class
