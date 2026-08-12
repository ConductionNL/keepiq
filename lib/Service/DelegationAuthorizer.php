<?php

/**
 * Doriath Delegation Authorizer
 *
 * The authorization surface of the SecretDelegation lifecycle
 * (ownership-delegation spec.md, FEATURES.md V1 §17.1). One place answers
 * "may this delegation happen at all": the Secret must exist, the delegate
 * must be named, the admin path needs vault_admin membership, and either
 * path needs the recipient to already hold a share — a delegation promotes
 * an *existing* recipient copy, it never creates access.
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
use OCA\Doriath\Db\ShareTargetMapper;
use OCP\AppFramework\Db\DoesNotExistException;
use OCP\IGroupManager;

/**
 * Authorization decisions for the SecretDelegation lifecycle.
 */
class DelegationAuthorizer {
	/**
	 * The Nextcloud group whose members can initiate admin-handover
	 * delegations (override the owner-consent requirement). Members of
	 * this group still MUST already hold a share of the target Secret —
	 * the admin path widens *who* can create the delegation, not what
	 * can be delegated without a pre-existing share.
	 *
	 * @var string
	 */
	public const VAULT_ADMIN_GROUP = 'vault_admin';

	/**
	 * Constructor for DelegationAuthorizer.
	 *
	 * @param SecretMapper $secretMapper The Secret mapper (owner lookup)
	 * @param ShareTargetMapper|null $shareTargetMapper Pre-existing-share lookup (admin path)
	 * @param IGroupManager|null $groupManager Group membership check (admin path)
	 *
	 * @return void
	 *
	 * @spec exclude Constructor wiring only — no domain logic.
	 */
	public function __construct(
		private SecretMapper $secretMapper,
		private ?ShareTargetMapper $shareTargetMapper = null,
		private ?IGroupManager $groupManager = null,
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
	 * Assert that $userId is a member of the vault_admin group.
	 *
	 * @param string $userId The candidate admin user ID
	 *
	 * @return void
	 *
	 * @throws InvalidArgumentException When the group manager is wired
	 *                                  but the user is not a vault admin.
	 *
	 * @spec openspec/changes/implement-user-sharing/tasks.md#task-17.1
	 */
	public function requireVaultAdmin(string $userId): void {
		if ($this->groupManager === null) {
			// No group manager wired — admin path cannot be authorized.
			throw new InvalidArgumentException(
				message: 'Admin handover is not available in this context'
			);
		}

		if ($this->isVaultAdmin(userId: $userId) === false) {
			throw new InvalidArgumentException(
				message: 'Admin handover requires membership in the vault_admin group'
			);
		}
	}//end requireVaultAdmin()

	/**
	 * Whether $userId may use the admin handover path at all.
	 *
	 * Exists so the UI can decide whether to OFFER the takeover without
	 * duplicating the membership rule: `requireVaultAdmin()` above is written
	 * in terms of this predicate, so the button and the enforcement can never
	 * drift apart. It answers only the group question — the per-secret
	 * preconditions (not already the owner, already holds a share) stay with
	 * the delegation entry points, because they need the Secret.
	 *
	 * @param string $userId The candidate admin user ID
	 *
	 * @return bool
	 *
	 * @spec openspec/specs/user-sharing/spec.md#requirement-ownership-delegation
	 */
	public function isVaultAdmin(string $userId): bool {
		if ($this->groupManager === null) {
			return false;
		}

		return $this->groupManager->isInGroup($userId, self::VAULT_ADMIN_GROUP);
	}//end isVaultAdmin()

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
