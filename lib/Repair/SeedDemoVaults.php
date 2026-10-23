<?php

/**
 * Keepiq Seed Demo Vaults Repair Step
 *
 * Gives the demo accounts an administrator names a vault with a known demo
 * master password, so a demo instance can show the unlocked app. The owners
 * and the password come from app config, which an example set writes (see
 * lib/Settings/example-sets/). The password is public by design: a demo
 * vault holds only the demo folders and secrets of lib/Settings/demo-vault.json.
 *
 * Never for an administrator, never for an unknown account, and never for an
 * account that already has a suite of any status: an existing vault is not
 * replaced, extended or checked against the demo password.
 *
 * @category Repair
 * @package  OCA\Keepiq\Repair
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://conduction.nl
 *
 * @spec openspec/changes/example-set-demo-vault/specs/demo-vault/spec.md
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Keepiq\Repair;

use OCA\Keepiq\AppInfo\Application;
use OCA\Keepiq\Db\EncryptionSuiteMapper;
use OCP\IAppConfig;
use OCP\IGroupManager;
use OCP\IUserManager;
use OCP\Migration\IOutput;
use OCP\Migration\IRepairStep;
use Psr\Log\LoggerInterface;

/**
 * Mints a demo vault for each named demo account that has none.
 */
class SeedDemoVaults implements IRepairStep {
	/**
	 * App config key: the accounts that get a demo vault, comma separated.
	 */
	public const OWNERS_KEY = 'demo_vault_owners';

	/**
	 * App config key: the demo master password every demo vault opens with.
	 */
	public const PASSWORD_KEY = 'demo_vault_master_password';

	/**
	 * The folders and secrets of a demo vault.
	 */
	private const CONTENTS = __DIR__ . '/../Settings/demo-vault.json';

	/**
	 * Constructor.
	 *
	 * @param IAppConfig             $appConfig    Holds the owners and the password
	 * @param IUserManager           $userManager  Says whether an account exists
	 * @param IGroupManager          $groupManager Says whether an account is an administrator
	 * @param EncryptionSuiteMapper  $suiteMapper  Says whether an account has a vault
	 * @param SeedDevelopmentData    $suites       Mints a suite
	 * @param SeedDevelopmentSecrets $secrets      Fills a vault
	 * @param LoggerInterface        $logger       The logger
	 *
	 * @return void
	 */
	public function __construct(
		private IAppConfig $appConfig,
		private IUserManager $userManager,
		private IGroupManager $groupManager,
		private EncryptionSuiteMapper $suiteMapper,
		private SeedDevelopmentData $suites,
		private SeedDevelopmentSecrets $secrets,
		private LoggerInterface $logger,
	) {
	}//end __construct()

	/**
	 * Get the name of this repair step.
	 *
	 * @return string
	 */
	public function getName(): string {
		return 'Seed Keepiq demo vaults for the named demo accounts';
	}//end getName()

	/**
	 * Mint a demo vault for each named account that may have one and has none.
	 *
	 * @param IOutput $output The output interface for progress reporting
	 *
	 * @return void
	 *
	 * @spec openspec/changes/example-set-demo-vault/specs/demo-vault/spec.md#requirement-a-demo-vault-is-minted-only-for-a-named-cast-account
	 */
	public function run(IOutput $output): void {
		$owners   = array_filter(array_map('trim', explode(',', $this->appConfig->getValueString(Application::APP_ID, self::OWNERS_KEY, ''))));
		$password = $this->appConfig->getValueString(Application::APP_ID, self::PASSWORD_KEY, '');
		if ($owners === [] || $password === '') {
			return;
		}

		foreach (array_unique($owners) as $uid) {
			$refusal = $this->refusal(uid: $uid);
			if ($refusal !== '') {
				$output->info('Keepiq: no demo vault for '.$uid.': '.$refusal);
				continue;
			}

			$this->seed(uid: $uid, password: $password, output: $output);
		}
	}//end run()

	/**
	 * Why an account may not get a demo vault, or ''.
	 *
	 * @param string $uid The account
	 *
	 * @return string
	 *
	 * @spec openspec/changes/example-set-demo-vault/specs/demo-vault/spec.md#requirement-a-demo-vault-is-minted-only-for-a-named-cast-account
	 */
	private function refusal(string $uid): string {
		if ($this->userManager->get($uid) === null) {
			return 'no such account';
		}

		if ($this->groupManager->isAdmin($uid) === true) {
			return 'an administrator never gets a vault with a public password';
		}

		if ($this->suiteMapper->findByOwner(ownerType: 'user', ownerId: $uid) !== []) {
			return 'the account already has a vault';
		}

		return '';
	}//end refusal()

	/**
	 * Mint the suite and fill it with the demo folders and secrets.
	 *
	 * @param string  $uid      The account
	 * @param string  $password The demo master password
	 * @param IOutput $output   The output interface for progress reporting
	 *
	 * @return void
	 *
	 * @spec openspec/changes/example-set-demo-vault/specs/demo-vault/spec.md#requirement-a-demo-vault-holds-only-demo-secrets
	 */
	private function seed(string $uid, string $password, IOutput $output): void {
		$suite = $this->suites->mintSuite(userId: $uid, masterPassword: $password, output: $output);
		if ($suite === null) {
			return;
		}

		$contents = json_decode((string)file_get_contents(self::CONTENTS), true);
		$count    = $this->secrets->seedSecrets(
			ownerId: $uid,
			suite: $suite,
			folderNames: (array)($contents['folders'] ?? []),
			secrets: (array)($contents['secrets'] ?? [])
		);

		$output->info('Keepiq: demo vault for '.$uid.' with '.$count.' secrets');
		$this->logger->info('Keepiq: demo vault minted for '.$uid);
	}//end seed()
}//end class
