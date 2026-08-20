<?php

/**
 * Doriath - Removal of a secret request's unfilled placeholder
 *
 * A request that created its own Secret leaves that Secret behind when the request
 * ends without being filled. Nothing else justifies a keyless Secret's existence,
 * so it goes with the request — and getting that wrong in either direction is
 * expensive: too timid and empty Secrets accumulate in vaults nobody is watching,
 * too eager and a hard delete takes a real credential and its version history with
 * it.
 *
 * Extracted from SecretRequestService because two callers need it (a user revoking
 * their own request, and the system expiring a lapsed one) and because adding the
 * second one in place pushed that class past phpmd's complexity threshold. The
 * class it came from is the request state machine; deciding whether a Secret may be
 * destroyed is a different question and now has a name.
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
use OCA\Doriath\Db\Secret;
use OCA\Doriath\Db\SecretMapper;
use OCA\Doriath\Db\SecretRequest;
use OCP\AppFramework\Db\DoesNotExistException;
use OCP\AppFramework\Db\MultipleObjectsReturnedException;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * Decides whether a request's placeholder Secret may be removed, and removes it.
 */
class SecretPlaceholderCleaner {
	/**
	 * Constructor for SecretPlaceholderCleaner.
	 *
	 * @param SecretMapper $secretMapper Reads the Secret a request targeted
	 * @param LoggerInterface $logger The logger
	 * @param ContainerInterface $container Resolves SecretService lazily, breaking the
	 *                                      cycle between it and the request service
	 *
	 * @return void
	 *
	 * @spec exclude Constructor wiring only — no domain logic.
	 */
	public function __construct(
		private SecretMapper $secretMapper,
		private LoggerInterface $logger,
		private ContainerInterface $container,
	) {
	}//end __construct()

	/**
	 * Remove the request's placeholder Secret, if it never held a value.
	 *
	 * One method for all three actors, because the lookup, the emptiness test and
	 * the fail-soft logging are identical. What genuinely differs is the
	 * AUTHORIZATION, and that is what the parameters carry — the caller states which
	 * vault it is entitled to delete from, and a Secret owned by anyone else is left
	 * alone:
	 *
	 * - `'user'` + a uid — a user revoking their own request.
	 * - `'application'` + an application id — an administrator revoking on behalf of
	 *   that application.
	 * - both null — the system expiring a lapsed request. There is no acting party to
	 *   match against, so the owner is taken from the SECRET and that vault's delete
	 *   path is used.
	 *
	 * Stating the expectation rather than inferring it is the point. The ownership
	 * check runs against the SECRET while the expectation comes from the CALLER, so
	 * a mismatched pair cannot destroy a third party's Secret — two checks against
	 * different data.
	 *
	 * The null case is what the previous code got wrong: expiry was routed through
	 * the user branch with the request's `created_by` passed as the owner. For an
	 * application request that value is `application:<id>`, never a user id, and the
	 * branch also required `owner_type === 'user'` — so it failed twice over and
	 * expired application placeholders were never cleaned up, which is the exact
	 * accumulation the expiry job exists to prevent. Reported by review on PR #282.
	 *
	 * Fail-soft throughout: an orphan empty Secret is untidy, while a throw here
	 * would abort a revoke that has already happened, or strand the remainder of an
	 * expiry sweep.
	 *
	 * @param SecretRequest $request The request whose placeholder may go
	 * @param string|null $expectedOwnerType 'user', 'application', or null for the system
	 * @param string|null $expectedOwnerId The owner the caller may delete from, or null
	 *
	 * @return void
	 *
	 * @spec openspec/specs/secret-requests/spec.md#requirement-optional-expiry
	 */
	public function removeIfUnfilled(
		SecretRequest $request,
		?string $expectedOwnerType,
		?string $expectedOwnerId,
	): void {
		// Both or neither. A half-stated expectation would silently widen into the
		// system case and delete outside the caller's vault.
		if (($expectedOwnerType === null) !== ($expectedOwnerId === null)) {
			throw new InvalidArgumentException(
				'An owner expectation needs both a type and an id, or neither'
			);
		}

		try {
			$secret = $this->secretMapper->findById($request->getSecretId());
		} catch (DoesNotExistException|MultipleObjectsReturnedException) {
			// Already gone, or ambiguous — nothing safe to delete either way.
			return;
		}

		// The predicate lives on the entity because it is a fact about the row and two
		// services need the same answer — this one, and ApplicationRequestAdminService
		// for an administrator revoking an application's request. A copy in each would
		// be two predicates answering one question, which is how this path came to
		// delete filled secrets in the first place.
		if ($secret->holdsNoValues() === false) {
			// Holds a value: a re-request target, a plain request's own Secret, or a
			// placeholder that has since been filled. Ending a request must never
			// cost the owner a working credential.
			return;
		}

		$ownerId = (string)$secret->getOwnerId();
		if ($ownerId === '') {
			return;
		}

		if ($expectedOwnerType !== null
			&& ($secret->getOwnerType() !== $expectedOwnerType || $ownerId !== $expectedOwnerId)
		) {
			// The Secret belongs to a vault this caller has no claim on.
			return;
		}

		try {
			$this->deleteOwned(secret: $secret, ownerId: $ownerId);
		} catch (Throwable $exception) {
			$this->logger->error(
				'Doriath: could not delete the placeholder for request '
				. $request->getId() . ': ' . $exception->getMessage(),
				['exception' => $exception]
			);
		}
	}//end removeIfUnfilled()

	/**
	 * Delete a Secret through the path its owning vault requires.
	 *
	 * @param Secret $secret The Secret to remove
	 * @param string $ownerId Its owner, taken from the Secret itself
	 *
	 * @return void
	 *
	 * @spec openspec/specs/secret-requests/spec.md#requirement-optional-expiry
	 */
	private function deleteOwned(Secret $secret, string $ownerId): void {
		$secretService = $this->container->get(SecretService::class);

		if ($secret->getOwnerType() === 'application') {
			$secretService->deleteByApplication(secretId: $secret->getId(), applicationId: $ownerId);

			return;
		}

		if ($secret->getOwnerType() === 'user') {
			$secretService->delete($secret->getId(), $ownerId);
		}
	}//end deleteOwned()
}//end class
