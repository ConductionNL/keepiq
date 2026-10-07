<?php

/**
 * Keepiq admin area authorizer
 *
 * One answer to "does this user hold this Keepiq admin area"
 * (admin-scoped-roles D2). Endpoint guards ask Nextcloud's middleware through
 * `#[AuthorizedAdminSetting(<Area>::class)]`; checks inside services and the
 * flags that decide whether the UI offers an admin action ask this class,
 * which reads the same delegations through `IManager::getAllowedAdminSettings()`.
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

use OCA\Keepiq\Settings\AdminAreaSettings;
use OCA\Keepiq\Settings\AdminSettings;
use OCA\Keepiq\Settings\ApplicationAdminSettings;
use OCA\Keepiq\Settings\AuditAdminSettings;
use OCA\Keepiq\Settings\PeopleAdminSettings;
use OCA\Keepiq\Settings\PolicyAdminSettings;
use OCP\IGroupManager;
use OCP\IUserManager;
use OCP\Settings\IManager;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * Decides whether a user holds a Keepiq admin area.
 */
class AdminAreaAuthorizer {
	/**
	 * The area classes by name, so a caller can name an area without
	 * depending on its settings class.
	 *
	 * @var string
	 */
	public const GENERAL = AdminSettings::class;
	public const POLICIES = PolicyAdminSettings::class;
	public const APPLICATIONS = ApplicationAdminSettings::class;
	public const PEOPLE = PeopleAdminSettings::class;
	public const AUDIT = AuditAdminSettings::class;

	/**
	 * The five area classes, keyed by area key.
	 *
	 * @var array<string,class-string<AdminAreaSettings>>
	 */
	public const AREAS = [
		'general' => AdminSettings::class,
		'policies' => PolicyAdminSettings::class,
		'applications' => ApplicationAdminSettings::class,
		'people' => PeopleAdminSettings::class,
		'audit' => AuditAdminSettings::class,
	];

	/**
	 * Constructor.
	 *
	 * @param IGroupManager $groupManager The instance admin check
	 * @param IUserManager $userManager Resolves the user for the settings manager
	 * @param IManager $settingsManager The settings a user may see, delegations included
	 * @param LoggerInterface $logger Records a failed delegation lookup
	 *
	 * @return void
	 *
	 * @spec exclude Constructor wiring only.
	 */
	public function __construct(
		private readonly IGroupManager $groupManager,
		private readonly IUserManager $userManager,
		private readonly IManager $settingsManager,
		private readonly LoggerInterface $logger,
	) {
	}//end __construct()

	/**
	 * Whether $userId holds the area of $areaClass: an instance admin holds
	 * every area, and a delegated user the areas delegated to one of their
	 * groups. No group name grants an area by itself (#1043 removed the
	 * `vault_admin` alias for People).
	 *
	 * Fails closed: an unknown area, an unknown user or a failing delegation
	 * lookup answers false.
	 *
	 * @param string $userId The user to check
	 * @param string $areaClass One of the five area classes
	 *
	 * @return bool
	 *
	 * @spec openspec/changes/archive/2026-10-04-admin-scoped-roles/tasks.md#1.3
	 */
	public function holds(string $userId, string $areaClass): bool {
		if ($userId === '' || in_array($areaClass, self::AREAS, true) === false) {
			return false;
		}

		if ($this->groupManager->isAdmin($userId) === true) {
			return true;
		}

		return in_array($areaClass, $this->delegatedAreas(userId: $userId), true);
	}//end holds()

	/**
	 * The area keys $userId holds, in area order.
	 *
	 * @param string $userId The user to check
	 *
	 * @return string[]
	 *
	 * @spec openspec/changes/archive/2026-10-04-admin-scoped-roles/tasks.md#1.3
	 */
	public function areasOf(string $userId): array {
		$held = [];
		foreach (self::AREAS as $key => $class) {
			if ($this->holds(userId: $userId, areaClass: $class) === true) {
				$held[] = $key;
			}
		}

		return $held;
	}//end areasOf()

	/**
	 * The Keepiq area classes delegated to the user's groups.
	 *
	 * @param string $userId The user to check
	 *
	 * @return string[]
	 */
	private function delegatedAreas(string $userId): array {
		$user = $this->userManager->get($userId);
		if ($user === null) {
			return [];
		}

		try {
			$byPriority = $this->settingsManager->getAllowedAdminSettings(AdminAreaSettings::SECTION, $user);
		} catch (Throwable $e) {
			// Fail closed: a lookup that cannot answer grants nothing.
			$this->logger->warning('Keepiq: admin area lookup failed: ' . $e->getMessage());
			return [];
		}

		$classes = [];
		foreach ($byPriority as $settings) {
			foreach ($settings as $setting) {
				$classes[] = get_class($setting);
			}
		}

		return $classes;
	}//end delegatedAreas()
}//end class
