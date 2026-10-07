<?php

/**
 * Keepiq Vault Policy Service
 *
 * The three organisation-wide vault policies (admin-vault-policies D1): a
 * personal vault export ban, a two-factor login requirement before unlock,
 * and team folder ownership of work logins. Each is an app config switch with
 * an optional Nextcloud group scope (empty means every user); the ownership
 * policy also names the secret types it covers.
 *
 * This service reads, validates, writes and audits the keys, and answers
 * whether a policy applies to a user. The browser learns only the effective
 * answer for the session user, never the group lists.
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

use InvalidArgumentException;
use OCA\Keepiq\AppInfo\Application;
use OCA\Keepiq\Event\Audit\AuditEventFactory;
use OCA\Keepiq\Event\Audit\AuditEventTypes;
use OCP\EventDispatcher\IEventDispatcher;
use OCP\IAppConfig;
use OCP\IGroupManager;
use OCP\IUserSession;

/**
 * Reads, writes and evaluates the vault policies.
 */
class VaultPolicyService {
	/**
	 * Policy: block personal vault export.
	 */
	public const EXPORT_DISABLED = 'vault_export_disabled';

	/**
	 * Policy: require Nextcloud two-factor login before the vault unlocks.
	 */
	public const REQUIRE_TWO_FACTOR = 'vault_require_two_factor';

	/**
	 * Policy: keep work logins in team folders.
	 */
	public const ORG_OWNERSHIP = 'vault_org_ownership';

	/**
	 * The secret types the ownership policy covers.
	 */
	public const ORG_OWNERSHIP_TYPES = 'vault_org_ownership_types';

	/**
	 * The three policies; each has a `<policy>_groups` scope key.
	 *
	 * @var string[]
	 */
	public const POLICIES = [self::EXPORT_DISABLED, self::REQUIRE_TWO_FACTOR, self::ORG_OWNERSHIP];

	/**
	 * Default ownership types: the credential types a tender means by work logins.
	 *
	 * @var string[]
	 */
	public const DEFAULT_ORG_OWNERSHIP_TYPES = ['login', 'api_key', 'database'];

	/**
	 * A type name or group id may hold only these characters.
	 */
	private const NAME_PATTERN = '/^[A-Za-z0-9 _.@-]{1,64}$/';

	/**
	 * Constructor.
	 *
	 * @param IAppConfig $appConfig The app config
	 * @param IGroupManager $groupManager Group existence and membership
	 * @param IUserSession $userSession The audit actor
	 * @param IEventDispatcher|null $eventDispatcher The audit dispatcher
	 * @param AuditEventFactory $auditEvents The audit event factory
	 *
	 * @return void
	 *
	 * @spec exclude Constructor wiring only.
	 */
	public function __construct(
		private IAppConfig $appConfig,
		private IGroupManager $groupManager,
		private IUserSession $userSession,
		private ?IEventDispatcher $eventDispatcher = null,
		private AuditEventFactory $auditEvents = new AuditEventFactory(),
	) {
	}//end __construct()

	/**
	 * Every policy key with its stored or default value, for the admin page.
	 *
	 * @return array<string,mixed>
	 *
	 * @spec openspec/specs/vault-policies/spec.md#requirement-administrator-configures-vault-policies-per-group
	 */
	public function read(): array {
		$settings = [];
		foreach (self::POLICIES as $policy) {
			$settings[$policy] = $this->appConfig->getValueBool(Application::APP_ID, $policy, false);
			$settings[$policy . '_groups'] = $this->readList(key: $policy . '_groups', default: []);
		}

		$settings[self::ORG_OWNERSHIP_TYPES] = $this->ownershipTypes();

		return $settings;
	}//end read()

	/**
	 * Validate and store the policy keys present in an admin save, then
	 * audit one before and after snapshot of the touched keys.
	 *
	 * Validation runs over every touched key before anything is written, so
	 * a bad value leaves all policies as they were.
	 *
	 * @param array<string,mixed> $data The admin-settings input
	 *
	 * @return void
	 *
	 * @throws InvalidArgumentException On an unknown group or a malformed list
	 *
	 * @spec openspec/specs/vault-policies/spec.md#requirement-administrator-configures-vault-policies-per-group
	 */
	public function update(array $data): void {
		$keys = array_keys($this->read());
		$touched = array_values(array_intersect($keys, array_keys($data)));
		if ($touched === []) {
			return;
		}

		$writes = [];
		foreach ($touched as $key) {
			$writes[$key] = $this->validate(key: $key, value: $data[$key]);
		}

		$before = array_intersect_key($this->read(), array_flip($touched));
		foreach ($writes as $key => $value) {
			if (is_bool($value) === true) {
				$this->appConfig->setValueBool(Application::APP_ID, $key, $value);
				continue;
			}

			$this->appConfig->setValueString(Application::APP_ID, $key, (string)json_encode($value));
		}

		$after = array_intersect_key($this->read(), array_flip($touched));

		$this->eventDispatcher?->dispatchTyped(
			$this->auditEvents->forUser(
				actorId: ($this->userSession->getUser()?->getUID() ?? 'system'),
				eventType: AuditEventTypes::VAULT_POLICY_UPDATED,
				objectType: 'settings',
				objectId: 'vault_policy',
				objectName: '',
				metadata: [
					'before' => $before,
					'after' => $after,
				],
			)
		);
	}//end update()

	/**
	 * Whether a policy is on and its group scope covers the user.
	 *
	 * @param string $policy One of POLICIES
	 * @param string $userId The user
	 *
	 * @return bool
	 *
	 * @spec openspec/specs/vault-policies/spec.md#requirement-administrator-configures-vault-policies-per-group
	 */
	public function appliesTo(string $policy, string $userId): bool {
		if (in_array($policy, self::POLICIES, true) === false
			|| $this->appConfig->getValueBool(Application::APP_ID, $policy, false) === false
		) {
			return false;
		}

		$groups = $this->readList(key: $policy . '_groups', default: []);
		if ($groups === []) {
			return true;
		}

		foreach ($groups as $groupId) {
			if ($this->groupManager->isInGroup($userId, $groupId) === true) {
				return true;
			}
		}

		return false;
	}//end appliesTo()

	/**
	 * The effective policies for one user, as the browser may see them:
	 * whether each applies, and the covered types. Never the group lists.
	 *
	 * @param string $userId The session user
	 *
	 * @return array<string,mixed>
	 *
	 * @spec openspec/specs/vault-policies/spec.md#requirement-administrator-configures-vault-policies-per-group
	 */
	public function effectiveFor(string $userId): array {
		$effective = [];
		foreach (self::POLICIES as $policy) {
			$effective[$policy] = $this->appliesTo(policy: $policy, userId: $userId);
		}

		$effective[self::ORG_OWNERSHIP_TYPES] = $this->ownershipTypes();

		return $effective;
	}//end effectiveFor()

	/**
	 * The secret type names the ownership policy covers.
	 *
	 * @return string[]
	 *
	 * @spec openspec/specs/vault-policies/spec.md#requirement-work-logins-are-kept-in-team-folders
	 */
	public function ownershipTypes(): array {
		return $this->readList(key: self::ORG_OWNERSHIP_TYPES, default: self::DEFAULT_ORG_OWNERSHIP_TYPES);
	}//end ownershipTypes()

	/**
	 * Validate one touched key: a policy switch, the type list, or a group list.
	 *
	 * @param string $key The key
	 * @param mixed $value The submitted value
	 *
	 * @return bool|string[] The value to store
	 *
	 * @throws InvalidArgumentException On a bad value
	 */
	private function validate(string $key, mixed $value): bool|array {
		if (in_array($key, self::POLICIES, true) === true) {
			return $this->validSwitch(key: $key, value: $value);
		}

		$names = $this->validNames(key: $key, value: $value);
		if ($key === self::ORG_OWNERSHIP_TYPES) {
			if ($names === []) {
				throw new InvalidArgumentException($key . ' must name at least one type');
			}

			return $names;
		}

		return $this->existingGroups(key: $key, groupIds: $names);
	}//end validate()

	/**
	 * A policy switch.
	 *
	 * @param string $key The key
	 * @param mixed $value The submitted value
	 *
	 * @return bool
	 *
	 * @throws InvalidArgumentException When it is not a boolean
	 */
	private function validSwitch(string $key, mixed $value): bool {
		$bool = filter_var($value, FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE);
		if ($bool === null) {
			throw new InvalidArgumentException($key . ' must be true or false');
		}

		return $bool;
	}//end validSwitch()

	/**
	 * A list of distinct, well-formed names.
	 *
	 * @param string $key The key
	 * @param mixed $value The submitted value
	 *
	 * @return string[]
	 *
	 * @throws InvalidArgumentException When it is not a list of valid names
	 */
	private function validNames(string $key, mixed $value): array {
		if (is_array($value) === false) {
			throw new InvalidArgumentException($key . ' must be a list');
		}

		$names = [];
		foreach ($value as $name) {
			if (is_string($name) === false || preg_match(self::NAME_PATTERN, $name) !== 1) {
				throw new InvalidArgumentException($key . ' holds an invalid name');
			}

			$names[$name] = true;
		}

		return array_keys($names);
	}//end validNames()

	/**
	 * Group ids that exist in Nextcloud.
	 *
	 * @param string $key The key
	 * @param string[] $groupIds The group ids
	 *
	 * @return string[]
	 *
	 * @throws InvalidArgumentException When a group does not exist
	 */
	private function existingGroups(string $key, array $groupIds): array {
		foreach ($groupIds as $groupId) {
			if ($this->groupManager->groupExists($groupId) === false) {
				throw new InvalidArgumentException($key . ' names an unknown group: ' . $groupId);
			}
		}

		return $groupIds;
	}//end existingGroups()

	/**
	 * Read a JSON list key.
	 *
	 * @param string $key The key
	 * @param string[] $default The default list
	 *
	 * @return string[]
	 */
	private function readList(string $key, array $default): array {
		$decoded = json_decode(
			$this->appConfig->getValueString(Application::APP_ID, $key, (string)json_encode($default)),
			true
		);
		if (is_array($decoded) === false) {
			return $default;
		}

		return array_values(array_filter($decoded, 'is_string'));
	}//end readList()
}//end class
