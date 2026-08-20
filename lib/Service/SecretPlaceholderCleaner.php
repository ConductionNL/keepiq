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
	 * One method for both actors, because the lookup, the emptiness test and the
	 * fail-soft logging are identical. What genuinely differs is the AUTHORIZATION,
	 * and that is the parameter:
	 *
	 * - `$actingUserId` given — a user revoking their own request. The Secret must be
	 *   theirs: user-owned, and owned by them. That is an authorization boundary, not
	 *   a tidiness check, and it must not be relaxed.
	 * - `$actingUserId` null — the system expiring a lapsed request. There is no
	 *   acting user to match against, so the owner is taken from the SECRET and the
	 *   matching vault's delete path is used.
	 *
	 * The second case is what the previous code got wrong: expiry was routed through
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
	 * @param string|null $actingUserId The revoking user, or null when the system acted
	 *
	 * @return void
	 *
	 * @spec openspec/specs/secret-requests/spec.md#requirement-optional-expiry
	 */
	public function removeIfUnfilled(SecretRequest $request, ?string $actingUserId): void {
		try {
			$secret = $this->secretMapper->findById($request->getSecretId());
		} catch (DoesNotExistException|MultipleObjectsReturnedException) {
			// Already gone, or ambiguous — nothing safe to delete either way.
			return;
		}

		if ($this->holdsNoValues(secret: $secret) === false) {
			// Holds a value: a re-request target, a plain request's own Secret, or a
			// placeholder that has since been filled. Ending a request must never
			// cost the owner a working credential.
			return;
		}

		$ownerId = (string)$secret->getOwnerId();
		if ($ownerId === '') {
			return;
		}

		if ($actingUserId !== null
			&& ($secret->getOwnerType() !== 'user' || $ownerId !== $actingUserId)
		) {
			// A user may only remove a placeholder in their own vault.
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
	 * Whether the Secret holds no value in any of its value columns.
	 *
	 * Deliberately wider than a `key`-only test. Nothing requires `key` among a
	 * request's fields, so a request can ask for a login or a custom member alone;
	 * filling that leaves `key` empty on a Secret that now holds ciphertext. Testing
	 * `key` by itself is how this path once hard-deleted filled secrets.
	 *
	 * `url` counts too: it is requestable plaintext and a fresh placeholder never
	 * has one, so its presence means somebody put it there.
	 *
	 * @param Secret $secret The Secret to inspect
	 *
	 * @return bool True when every value column is empty
	 *
	 * @spec openspec/specs/secrets/spec.md#requirement-unfilled-request-placeholder
	 */
	private function holdsNoValues(Secret $secret): bool {
		return (string)$secret->getKey() === ''
			&& (string)$secret->getLogin() === ''
			&& (string)$secret->getAdditionalFields() === ''
			&& (string)$secret->getUrl() === '';
	}//end holdsNoValues()

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
