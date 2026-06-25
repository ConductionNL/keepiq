<?php

/**
 * Doriath Seed Development Data Repair Step
 *
 * Creates a test user EncryptionSuite with a known master password for development.
 *
 * @category Repair
 * @package  OCA\Doriath\Repair
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

namespace OCA\Doriath\Repair;

use DateTime;
use Exception;
use OCA\Doriath\Db\EncryptionSuite;
use OCA\Doriath\Db\EncryptionSuiteMapper;
use OCA\Doriath\Db\FolderMapper;
use OCA\Doriath\Db\SecretMapper;
use OCA\Doriath\Service\CertificateAuthorityService;
use OCA\Doriath\Service\DecryptService;
use OCA\Doriath\Service\EncryptService;
use OCP\AppFramework\Db\DoesNotExistException;
use OCP\IConfig;
use Ramsey\Uuid\Uuid;
use OCP\Migration\IOutput;
use OCP\Migration\IRepairStep;
use Psr\Log\LoggerInterface;

/**
 * Development seed data: creates a test user EncryptionSuite with a known master password.
 * Only runs when Nextcloud debug mode is enabled.
 */
class SeedDevelopmentData implements IRepairStep
{
    private const DEV_USER_ID         = 'admin';
    private const DEV_MASTER_PASSWORD = 'Oj';

    /**
     * Constructor for SeedDevelopmentData.
     *
     * @param EncryptionSuiteMapper       $suiteMapper    The encryption suite mapper
     * @param CertificateAuthorityService $caService      The CA service
     * @param EncryptService              $encryptService The encrypt service
     * @param DecryptService              $decryptService The decrypt service (suite validation)
     * @param SecretMapper                $secretMapper   The secret mapper (rebuild cleanup)
     * @param FolderMapper                $folderMapper   The folder mapper (rebuild cleanup)
     * @param IConfig                     $config         The config interface
     * @param LoggerInterface             $logger         The logger interface
     *
     * @return void
     */
    public function __construct(
        private EncryptionSuiteMapper $suiteMapper,
        private CertificateAuthorityService $caService,
        private EncryptService $encryptService,
        private DecryptService $decryptService,
        private SecretMapper $secretMapper,
        private FolderMapper $folderMapper,
        private IConfig $config,
        private LoggerInterface $logger,
    ) {
    }//end __construct()

    /**
     * Get the name of this repair step.
     *
     * @return string
     */
    public function getName(): string
    {
        return 'Seed Doriath development data (debug only)';
    }//end getName()

    /**
     * Run the repair step to seed development data.
     *
     * @param IOutput $output The output interface for progress reporting
     *
     * @return void
     *
     * @SuppressWarnings(PHPMD.UndefinedVariable) openssl_pkey_export populates $privateKeyPem
     *   via by-reference output param — PHPMD cannot trace by-ref semantics.
     *
     * @spec openspec/changes/retrofit-2026-05-25-doriath-coverage/tasks.md#task-6
     */
    public function run(IOutput $output): void
    {
        if ($this->config->getSystemValueBool('debug', false) === false) {
            return;
        }

        $output->info('Seeding Doriath development data...');

        // Check if dev user already has a suite. A suite is only reusable when
        // its certificate's public key matches the AES-wrapped private key — a
        // pre-fix seed (or a public-only re-sign) could persist a certificate
        // bound to a DIFFERENT key pair, which leaves the suite unable to
        // decrypt anything the browser newly encrypts under its certificate
        // (the read-after-write decrypt failure). When the existing suite is
        // sound we keep it; when it is mismatched we rebuild it so the dev vault
        // is usable again.
        try {
            $existing = $this->suiteMapper->findActiveByOwner(ownerType: 'user', ownerId: self::DEV_USER_ID);
            if ($this->suiteKeyPairMatches(suite: $existing) === true) {
                $output->info('Dev user already has a sound EncryptionSuite, skipping');
                return;
            }

            $output->warning(
                'Dev EncryptionSuite certificate does not match its private key — rebuilding it'
            );
            $this->discardMismatchedSuite(suite: $existing, output: $output);
        } catch (DoesNotExistException) {
            // Good — no suite yet.
        }

        // Generate RSA key pair.
        $keyPair = openssl_pkey_new(
            options: [
                'private_key_bits' => 4096,
                'private_key_type' => OPENSSL_KEYTYPE_RSA,
            ]
        );

        if ($keyPair === false) {
            $output->warning('Failed to generate RSA key pair for dev seed');
            return;
        }

        openssl_pkey_export(key: $keyPair, output: $privateKeyPem);
        $keyDetails   = openssl_pkey_get_details(key: $keyPair);
        $publicKeyPem = $keyDetails['key'];

        // Sign the public key with the CA.
        try {
            $certificate = $this->caService->signPublicKey(
                publicKeyPem: $publicKeyPem,
                commonName: self::DEV_USER_ID,
                privateKeyPem: $privateKeyPem
            );
        } catch (Exception $e) {
            $output->warning('CA not available for dev seed: '.$e->getMessage());
            return;
        }

        // Encrypt the private key with the dev master password.
        $encryptedPrivateKey = $this->encryptService->encryptPrivateKey(
            pem: $privateKeyPem,
            password: self::DEV_MASTER_PASSWORD
        );

        // Create the EncryptionSuite.
        $suite = new EncryptionSuite();
        $suite->setId(Uuid::uuid4()->toString());
        $suite->setOwnerType('user');
        $suite->setOwnerId(self::DEV_USER_ID);
        $suite->setCertificate($certificate);
        $suite->setPrivateKey($encryptedPrivateKey);
        $suite->setStatus('active');
        $suite->setCreatedAt(new DateTime());

        $this->suiteMapper->insert($suite);

        $output->info('Dev EncryptionSuite created for user: '.self::DEV_USER_ID);
        $this->logger->info('Doriath dev seed: EncryptionSuite created with master password: '.self::DEV_MASTER_PASSWORD);
    }//end run()

    /**
     * Verify a suite's certificate public key matches its wrapped private key.
     *
     * The dev master password unwraps the private key (zero-knowledge: this only
     * works for the dev seed, which uses a known password). When the certificate
     * was bound to a different key pair — a pre-fix seed, or a public-only
     * re-sign that minted a throwaway key — the moduli differ and any value the
     * browser encrypts under the certificate cannot be decrypted with the
     * private key.
     *
     * @param EncryptionSuite $suite The suite to validate
     *
     * @return bool True when the certificate and private key form one key pair.
     */
    private function suiteKeyPairMatches(EncryptionSuite $suite): bool
    {
        $certificate = $suite->getCertificate();
        $wrappedKey  = $suite->getPrivateKey();
        if ($certificate === null || $wrappedKey === null) {
            return false;
        }

        try {
            $privatePem = $this->decryptService->decryptPrivateKey($wrappedKey, self::DEV_MASTER_PASSWORD);
        } catch (Exception) {
            return false;
        }

        $private = openssl_pkey_get_private($privatePem);
        $public  = openssl_pkey_get_public($certificate);
        if ($private === false || $public === false) {
            return false;
        }

        $privateDetails = openssl_pkey_get_details($private);
        $publicDetails  = openssl_pkey_get_details($public);
        if ($privateDetails === false || $publicDetails === false) {
            return false;
        }

        if (isset($privateDetails['rsa']['n'], $publicDetails['rsa']['n']) === false) {
            return false;
        }

        return hash_equals($privateDetails['rsa']['n'], $publicDetails['rsa']['n']);
    }//end suiteKeyPairMatches()

    /**
     * Drop a mismatched dev suite and its secrets so they are rebuilt cleanly.
     *
     * The dev secrets were encrypted under the broken certificate, so they are
     * deleted alongside the suite; SeedDevelopmentSecrets re-creates them under
     * the fresh, matching certificate. Dev-only data — no production secret is
     * ever touched (this repair step is gated behind debug mode).
     *
     * @param EncryptionSuite $suite  The mismatched suite to discard
     * @param IOutput         $output The repair output channel
     *
     * @return void
     */
    private function discardMismatchedSuite(EncryptionSuite $suite, IOutput $output): void
    {
        $deletedSecrets = $this->secretMapper->deleteByOwnerUser(self::DEV_USER_ID);
        // Drop the dev folders too so SeedDevelopmentSecrets recreates its
        // 'Work'/'Personal' tree without colliding with the unique-sibling rule.
        $this->folderMapper->deleteByOwnerUser(self::DEV_USER_ID);
        $this->suiteMapper->deleteByOwnerUser(self::DEV_USER_ID);
        $output->info(
            'Discarded mismatched dev suite '.$suite->getId().' and '.$deletedSecrets.' dev secrets for rebuild'
        );
        $this->logger->warning(
            'Doriath dev seed: rebuilt mismatched EncryptionSuite for '.self::DEV_USER_ID
            .' (deleted '.$deletedSecrets.' secrets encrypted under the broken certificate)'
        );
    }//end discardMismatchedSuite()
}//end class
