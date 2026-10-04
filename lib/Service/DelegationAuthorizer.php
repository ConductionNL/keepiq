<?php

/**
 * Keepiq Delegation Authorizer
 *
 * The authorization surface of the SecretDelegation lifecycle
 * (ownership-delegation spec.md, FEATURES.md V1 §17.1). One place answers
 * "may this delegation happen at all": the Secret must exist, the delegate
 * must be named, the admin path needs the People admin area, and either
 * path needs the recipient to already hold a share — a delegation promotes
 * an *existing* recipient copy, it never creates access.
 *
 * @category Service
 * @package  OCA\Keepiq\Service
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

namespace OCA\Keepiq\Service;

use InvalidArgumentException;
use OCA\Keepiq\Db\Secret;
use OCA\Keepiq\Db\SecretMapper;
use OCA\Keepiq\Db\ShareTargetMapper;
use OCA\Keepiq\Settings\PeopleAdminSettings;
use OCP\AppFramework\Db\DoesNotExistException;

/**
 * Authorization decisions for the SecretDelegation lifecycle.
 */
class DelegationAuthorizer {
	/**
	 * The legacy group that still counts as holding the People area until
	 * the alias is removed (admin-scoped-roles D4). Kept as a constant so
	 * existing references keep compiling; the rule itself lives in
	 * AdminAreaAuthorizer.
	 *
	 * @var string
	 */
	public const VAULT_ADMIN_GROUP = AdminAreaAuthorizer::LEGACY_PEOPLE_GROUP;

	/**
	 * Constructor for DelegationAuthorizer.
	 *
	 * @param SecretMapper $secretMapper The Secret mapper (owner lookup)
	 * @param ShareTargetMapper|null $shareTargetMapper Pre-existing-share lookup (admin path)
	 * @param AdminAreaAuthorizer|null $areas The People area check (admin path)
	 *
	 * @return void
	 *
	 * @spec exclude Constructor wiring only — no domain logic.
	 */
	public function __construct(
		private SecretMapper $secretMapper,
		private ?ShareTargetMapper $shareTargetMapper = null,
		private ?AdminAreaAuthorizer $areas = null,
	) {
	}//end __construct()

	/**
	 * Look up a Secret by ID, raising InvalidArgumentException on miss.
	 *
	 * @param string $secretId The Secret ID
	 *
	 * @return Secret
	 *
	 * @throws InvalidArgumentException When the Secret does not exist.
	 *
	 * @spec openspec/changes/implement-user-sharing/tasks.md#task-6.1
	 */
	public function requireSecret(string $secretId): Secret {
		try {
			return $this->secretMapper->findById($secretId);
		} catch (DoesNotExistException) {
			throw new InvalidArgumentException(message: 'Secret not found');
		}
	}//end requireSecret()

	/**
	 * Load the Secret both delegation paths act on, rejecting a blank
	 * delegate up front so the two entry points share one precondition.
	 *
	 * @param string $secretId The Secret ID
	 * @param string $delegatedTo The candidate delegate
	 *
	 * @return Secret
	 *
	 * @throws InvalidArgumentException When the Secret does not exist or
	 *                                  $delegatedTo is blank.
	 *
	 * @spec openspec/changes/implement-user-sharing/tasks.md#task-6.2
	 */
	public function requireDelegableSecret(string $secretId, string $delegatedTo): Secret {
		$secret = $this->requireSecret(secretId: $secretId);

		if ($delegatedTo === '') {
			throw new InvalidArgumentException(message: 'delegated_to is required');
		}

		return $secret;
	}//end requireDelegableSecret()

	/**
	 * Assert that $userId may use the admin handover path: an instance admin
	 * or a holder of the People and offboarding area (admin-scoped-roles D5).
	 *
	 * @param string $userId The candidate admin user ID
	 *
	 * @return void
	 *
	 * @throws InvalidArgumentException When the area check is wired but the
	 *                                  user does not hold the People area.
	 *
	 * @spec openspec/changes/archive/2026-10-04-admin-scoped-roles/tasks.md#2.4
	 */
	public function requireHandoverAdmin(string $userId): void {
		if ($this->areas === null) {
			// No area check wired: the admin path cannot be authorized.
			throw new InvalidArgumentException(
				message: 'Admin handover is not available in this context'
			);
		}

		if ($this->canHandover(userId: $userId) === false) {
			throw new InvalidArgumentException(
				message: 'Admin handover requires the People and offboarding admin area'
			);
		}
	}//end requireHandoverAdmin()

	/**
	 * Whether $userId may use the admin handover path at all.
	 *
	 * Exists so the UI can decide whether to OFFER the takeover without
	 * duplicating the rule: `requireHandoverAdmin()` above is written in
	 * terms of this predicate, so the button and the enforcement can never
	 * drift apart. It answers only the area question; the per-secret
	 * preconditions (not already the owner, already holds a share) stay with
	 * the delegation entry points, because they need the Secret.
	 *
	 * @param string $userId The candidate admin user ID
	 *
	 * @return bool
	 *
	 * @spec openspec/changes/archive/2026-10-04-admin-scoped-roles/tasks.md#2.4
	 */
	public function canHandover(string $userId): bool {
		if ($this->areas === null) {
			return false;
		}

		return $this->areas->holds(userId: $userId, areaClass: PeopleAdminSettings::class);
	}//end canHandover()

	/**
	 * Assert that $userId already holds a share of $secretId. No-op when
	 * the share-target mapper is not wired (preserves backward compat with
	 * existing constructors that pre-date the §17.1 hardening).
	 *
	 * @param string $secretId The source Secret ID
	 * @param string $userId The candidate recipient
	 *
	 * @return void
	 *
	 * @throws InvalidArgumentException When the share-target mapper is
	 *                                  wired but no share row exists for
	 *                                  (sourceSecret, user).
	 *
	 * @spec openspec/changes/implement-user-sharing/tasks.md#task-17.1
	 */
	public function requirePreExistingShare(string $secretId, string $userId): void {
		if ($this->shareTargetMapper === null) {
			return;
		}

		try {
			$this->shareTargetMapper->findBySourceSecretAndTargetUser(
				sourceSecretId: $secretId,
				targetUserId: $userId,
			);
		} catch (DoesNotExistException) {
			throw new InvalidArgumentException(
				message: 'Delegation requires the recipient to already hold a share of the secret'
			);
		}
	}//end requirePreExistingShare()
}//end class
