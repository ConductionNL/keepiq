<?php

/**
 * Keepiq Dev Vault Loader
 *
 * Loads the development user's vault for the development seeds, or says why a seed skips.
 *
 * @category Repair
 * @package  OCA\Keepiq\Repair
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

namespace OCA\Keepiq\Repair;

use OCA\Keepiq\Db\EncryptionSuiteMapper;
use OCA\Keepiq\Db\SecretMapper;
use OCP\AppFramework\Db\DoesNotExistException;
use OCP\Migration\IOutput;

/**
 * The step every development seed starts with: the dev user must have an
 * active suite and at least one secret. Built by each seed.
 *
 * @spec exclude dev-only seed support, no spec scenario
 */
final class DevVaultLoader {
	private const DEV_USER_ID = 'admin';

	/**
	 * Constructor for DevVaultLoader.
	 *
	 * @param EncryptionSuiteMapper $suiteMapper The suite mapper
	 * @param SecretMapper $secretMapper The secret mapper
	 *
	 * @return void
	 */
	public function __construct(
		private readonly EncryptionSuiteMapper $suiteMapper,
		private readonly SecretMapper $secretMapper,
	) {
	}//end __construct()

	/**
	 * The dev user's vault, or null after telling the output which part is
	 * missing.
	 *
	 * @param IOutput $output The repair output
	 * @param string $seedName How the skip message names the seed
	 *
	 * @return DevVault|null
	 *
	 * @spec exclude dev-only seed support, no spec scenario
	 */
	public function load(IOutput $output, string $seedName): ?DevVault {
		try {
			$suite = $this->suiteMapper->findActiveByOwner('user', self::DEV_USER_ID);
		} catch (DoesNotExistException) {
			$output->info('Keepiq: no dev EncryptionSuite, skipping ' . $seedName);
			return null;
		}

		$secrets = $this->secretMapper->findByOwner('user', self::DEV_USER_ID);
		if ($secrets === []) {
			$output->info('Keepiq: no dev secrets, skipping ' . $seedName);
			return null;
		}

		return new DevVault(suiteId: $suite->getId(), secrets: $secrets);
	}//end load()
}//end class
