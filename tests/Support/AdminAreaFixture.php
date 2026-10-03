<?php

/**
 * Builds a real AdminAreaAuthorizer for controller and service tests.
 *
 * The authorizer is real, so a test exercises the same rule the app runs:
 * instance admin first, then the vault_admin alias for People, then the
 * delegated area classes. Only its collaborators are doubles. Delegated
 * areas are real area instances, because Nextcloud matches a delegation on
 * `get_class()` and a mock's class name would never match.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 */

declare(strict_types=1);

namespace OCA\Keepiq\Tests\Support;

use OCA\Keepiq\Service\AdminAreaAuthorizer;
use OCA\Keepiq\Settings\AdminSettings;
use OCP\App\IAppManager;
use OCP\AppFramework\Services\IInitialState;
use OCP\IAppConfig;
use OCP\IGroupManager;
use OCP\IL10N;
use OCP\IUser;
use OCP\IUserManager;
use OCP\Settings\IManager;
use Psr\Log\LoggerInterface;

/**
 * Mix into a TestCase to build area authorizers.
 */
trait AdminAreaFixture {
	/**
	 * Area classes delegated to every user when a test does not pass its own.
	 *
	 * @var string[]
	 */
	protected array $delegatedAreas = [];

	/**
	 * An authorizer over $groupManager in which every user is delegated
	 * exactly the area classes in $delegated.
	 *
	 * @param IGroupManager|null $groupManager The group manager (isAdmin, isInGroup); a stub answering false when null
	 * @param string[]|null $delegated Area classes delegated to the user's groups; $delegatedAreas when null
	 *
	 * @return AdminAreaAuthorizer
	 */
	protected function areaAuthorizer(?IGroupManager $groupManager = null, ?array $delegated = null): AdminAreaAuthorizer {
		$userManager = $this->createStub(IUserManager::class);
		$userManager->method('get')->willReturn($this->createStub(IUser::class));

		$settings = [];
		foreach (($delegated ?? $this->delegatedAreas) as $class) {
			$settings[] = $this->realArea(class: $class);
		}

		$settingsManager = $this->createStub(IManager::class);
		$settingsManager->method('getAllowedAdminSettings')->willReturn($settings === [] ? [] : [10 => $settings]);

		return new AdminAreaAuthorizer(
			groupManager: ($groupManager ?? $this->createStub(IGroupManager::class)),
			userManager: $userManager,
			settingsManager: $settingsManager,
			logger: $this->createStub(LoggerInterface::class),
		);
	}//end areaAuthorizer()

	/**
	 * A real instance of one area class.
	 *
	 * @param string $class The area class
	 *
	 * @return object
	 */
	protected function realArea(string $class): object {
		$l10n = $this->createStub(IL10N::class);
		$l10n->method('t')->willReturnArgument(0);
		$state = $this->createStub(IInitialState::class);
		if ($class === AdminSettings::class) {
			return new AdminSettings(
				l10n: $l10n,
				initialState: $state,
				appManager: $this->createStub(IAppManager::class),
				appConfig: $this->createStub(IAppConfig::class),
				groupManager: $this->createStub(IGroupManager::class),
			);
		}

		return new $class(l10n: $l10n, initialState: $state);
	}//end realArea()
}//end trait
