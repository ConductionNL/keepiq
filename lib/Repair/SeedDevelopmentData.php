<?php

/**
 * Keepiq Seed Development Data Repair Step
 *
 * Creates a test user EncryptionSuite with a known master password for development.
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

use DateTime;
use Exception;
use OCA\Keepiq\Db\EncryptionSuite;
use OCA\Keepiq\Db\EncryptionSuiteMapper;
use OCA\Keepiq\Service\CertificateAuthorityService;
use OCA\Keepiq\Service\EncryptService;
use OCP\IConfig;
use OCP\Migration\IOutput;
use OCP\Migration\IRepairStep;
use Psr\Log\LoggerInterface;
use Ramsey\Uuid\Uuid;

/**
 * Development seed data: creates a test user EncryptionSuite with a known master password.
 * Only runs when Nextcloud debug mode is enabled.
 */
class SeedDevelopmentData implements IRepairStep {
	private const DEV_USER_ID = 'admin';
	private const DEV_MASTER_PASSWORD = 'Oj';

	/**
	 * Constructor for SeedDevelopmentData.
	 *
	 * @param EncryptionSuiteMapper $suiteMapper The encryption suite mapper
	 * @param CertificateAuthorityService $caService The CA service
	 * @param EncryptService $encryptService The encrypt service
	 * @param DevSuiteIntegrityGuard $suiteGuard The existing-suite reusability guard
	 * @param IConfig $config The config interface
	 * @param LoggerInterface $logger The logger interface
	 *
	 * @return void
	 */
	public function __construct(
		private EncryptionSuiteMapper $suiteMapper,
		private CertificateAuthorityService $caService,
		private EncryptService $encryptService,
		private DevSuiteIntegrityGuard $suiteGuard,
		private IConfig $config,
		private LoggerInterface $logger,
	) {
	}//end __construct()

	/**
	 * Get the name of this repair step.
	 *
	 * @return string
	 */
	public function getName(): string {
		return 'Seed Keepiq development data (debug only)';
	}//end getName()

	/**
	 * Run the repair step to seed development data.
	 *
	 * @param IOutput $output The output interface for progress reporting
	 *
	 * @return void
	 *
	 * @spec openspec/changes/retrofit-2026-05-25-doriath-coverage/tasks.md#task-6
	 */
	public function run(IOutput $output): void {
		if ($this->config->getSystemValueBool('debug', false) === false) {
			return;
		}

		$output->info('Seeding Keepiq development data...');

		// Check if the dev user already has a REUSABLE suite. The guard keeps a
		// sound one (and reports true so we mint nothing), and discards a
		// mismatched one together with everything encrypted under it.
		if ($this->suiteGuard->ensureReusableSuite(
			userId: self::DEV_USER_ID,
			masterPassword: self::DEV_MASTER_PASSWORD,
			output: $output
		) === true
		) {
			return;
		}

		$this->mintSuite(userId: self::DEV_USER_ID, masterPassword: self::DEV_MASTER_PASSWORD, output: $output);
	}//end run()

	/**
	 * Create an active EncryptionSuite for a user: a fresh RSA key pair, the
	 * public key signed by the CA, the private key encrypted with the master
	 * password. The caller decides whether the user may get one.
	 *
	 * @param string  $userId         The owner of the new suite
	 * @param string  $masterPassword The password that unlocks it
	 * @param IOutput $output         The output interface for progress reporting
	 *
	 * @return EncryptionSuite|null The suite, or null when no key or certificate could be made
	 *
	 * @spec openspec/specs/demo-vault/spec.md#requirement-a-demo-vault-is-minted-only-for-a-named-cast-account
	 */
	public function mintSuite(string $userId, string $masterPassword, IOutput $output): ?EncryptionSuite {
		$mint = new SuiteMint(userId: $userId, masterPassword: $masterPassword, output: $output);
		if ($this->generateKeys(mint: $mint) === false || $this->signCertificate(mint: $mint) === false) {
			return null;
		}

		return $this->storeSuite(mint: $mint);
	}//end mintSuite()

	/**
	 * Generate the RSA key pair.
	 *
	 * @param SuiteMint $mint The suite being minted
	 *
	 * @return bool False when no key pair could be made
	 *
	 * @SuppressWarnings(PHPMD.UndefinedVariable) openssl_pkey_export populates $privateKeyPem
	 *   via by-reference output param — PHPMD cannot trace by-ref semantics.
	 */
	private function generateKeys(SuiteMint $mint): bool {
		$keyPair = openssl_pkey_new(
			options: [
				'private_key_bits' => 4096,
				'private_key_type' => OPENSSL_KEYTYPE_RSA,
			]
		);
		if ($keyPair === false) {
			$mint->output->warning('Failed to generate RSA key pair for dev seed');
			return false;
		}

		openssl_pkey_export(key: $keyPair, output: $privateKeyPem);
		$mint->privateKeyPem = $privateKeyPem;
		$mint->publicKeyPem = openssl_pkey_get_details(key: $keyPair)['key'];
		return true;
	}//end generateKeys()

	/**
	 * Sign the public key with the CA.
	 *
	 * @param SuiteMint $mint The suite being minted
	 *
	 * @return bool False when the CA is not available
	 */
	private function signCertificate(SuiteMint $mint): bool {
		try {
			$mint->certificate = $this->caService->signPublicKey(
				publicKeyPem: $mint->publicKeyPem,
				commonName: $mint->userId,
				privateKeyPem: $mint->privateKeyPem
			);
			return true;
		} catch (Exception $e) {
			$mint->output->warning('CA not available for dev seed: ' . $e->getMessage());
			return false;
		}
	}//end signCertificate()

	/**
	 * Encrypt the private key with the master password, store the active
	 * suite and report it.
	 *
	 * @param SuiteMint $mint The suite being minted
	 *
	 * @return EncryptionSuite The stored suite
	 */
	private function storeSuite(SuiteMint $mint): EncryptionSuite {
		$encryptedPrivateKey = $this->encryptService->encryptPrivateKey(
			pem: $mint->privateKeyPem,
			password: $mint->masterPassword
		);
		$suite = new EncryptionSuite();
		$suite->setId(Uuid::uuid4()->toString());
		$suite->setOwnerType('user');
		$suite->setOwnerId($mint->userId);
		$suite->setCertificate($mint->certificate);
		$suite->setPrivateKey($encryptedPrivateKey);
		$suite->setStatus('active');
		$suite->setCreatedAt(new DateTime());

		$this->suiteMapper->insert($suite);

		$mint->output->info('Dev EncryptionSuite created for user: ' . $mint->userId);
		$this->logger->info('Keepiq dev seed: EncryptionSuite created for user: ' . $mint->userId);

		return $suite;
	}//end storeSuite()
}//end class
