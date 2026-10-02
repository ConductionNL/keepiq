<?php

/**
 * Keepiq Two-Factor Gate
 *
 * The vault half of the two-factor policy (admin-vault-policies D3). When the
 * policy applies to a user and Nextcloud reports no enabled two-factor
 * provider for them other than backup codes, the vault must not open: the
 * server withholds the wrapped private key and refuses a first suite.
 * Withholding ciphertext holds for every client, the browser, the CLI and
 * the extension alike. Enforcing two-factor login itself stays with Nextcloud.
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

use OCP\Authentication\TwoFactorAuth\IRegistry;
use OCP\IGroupManager;
use OCP\IUser;
use OCP\IUserManager;

/**
 * Decides whether the two-factor policy blocks a user's unlock.
 */
class TwoFactorGate {
	/**
	 * The code every refusal and withheld suite carries.
	 */
	public const CODE = 'two_factor_required';

	/**
	 * Backup codes are a fallback, not a second factor of their own.
	 */
	private const IGNORED_PROVIDERS = ['backup_codes'];

	/**
	 * Constructor.
	 *
	 * @param VaultPolicyService $policies The vault policies
	 * @param IRegistry $registry Nextcloud's two-factor provider registry
	 * @param IUserManager $userManager Resolves the user
	 * @param IGroupManager|null $groupManager Group members, for the admin count
	 *
	 * @return void
	 *
	 * @spec exclude Constructor wiring only.
	 */
	public function __construct(
		private VaultPolicyService $policies,
		private IRegistry $registry,
		private IUserManager $userManager,
		private ?IGroupManager $groupManager = null,
	) {
	}//end __construct()

	/**
	 * Whether the policy applies and the user has no real second factor.
	 *
	 * Fails closed: an in-scope user id Nextcloud cannot resolve is blocked.
	 *
	 * @param string $userId The user
	 *
	 * @return bool
	 *
	 * @spec openspec/changes/admin-vault-policies/tasks.md#3.1
	 */
	public function blocks(string $userId): bool {
		if ($this->policies->appliesTo(policy: VaultPolicyService::REQUIRE_TWO_FACTOR, userId: $userId) === false) {
			return false;
		}

		$user = $this->userManager->get($userId);
		if ($user === null) {
			return true;
		}

		return $this->hasSecondFactor(user: $user) === false;
	}//end blocks()

	/**
	 * How many users a two-factor policy scoped to these groups would cover,
	 * and how many of them have no second factor and would lose vault access
	 * at once. The admin section shows it before saving (design risk D3).
	 *
	 * @param string[] $groupIds The scope; empty means every user who logged in
	 *
	 * @return array{inScope:int,withoutTwoFactor:int}
	 *
	 * @spec openspec/changes/admin-vault-policies/tasks.md#1.3
	 */
	public function gapReport(array $groupIds): array {
		$users = $this->usersInScope(groupIds: $groupIds);

		$without = 0;
		foreach ($users as $user) {
			if ($this->hasSecondFactor(user: $user) === false) {
				$without++;
			}
		}

		return ['inScope' => count($users), 'withoutTwoFactor' => $without];
	}//end gapReport()

	/**
	 * The users a scope covers, keyed by user id: the groups' members, or
	 * every user who has logged in when no group is named.
	 *
	 * @param string[] $groupIds The scope
	 *
	 * @return array<string,IUser>
	 */
	private function usersInScope(array $groupIds): array {
		$users = [];
		if ($groupIds === []) {
			$this->userManager->callForSeenUsers(
				static function (IUser $user) use (&$users): bool {
					$users[$user->getUID()] = $user;
					return true;
				}
			);

			return $users;
		}

		foreach ($groupIds as $groupId) {
			foreach ($this->groupManager?->get($groupId)?->getUsers() ?? [] as $user) {
				$users[$user->getUID()] = $user;
			}
		}

		return $users;
	}//end usersInScope()

	/**
	 * Whether a user has an enabled provider other than backup codes.
	 *
	 * @param IUser $user The user
	 *
	 * @return bool
	 */
	private function hasSecondFactor(IUser $user): bool {
		foreach ($this->registry->getProviderStates($user) as $providerId => $enabled) {
			if ($enabled === true && in_array($providerId, self::IGNORED_PROVIDERS, true) === false) {
				return true;
			}
		}

		return false;
	}//end hasSecondFactor()
}//end class
