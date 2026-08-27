<?php

/**
 * Keepiq Dev Suite Integrity Guard
 *
 * Decides whether the development EncryptionSuite on disk is still usable,
 * and tears it (and the data encrypted under it) down when it is not.
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

use Exception;
use OCA\Keepiq\Db\EncryptionSuite;
use OCA\Keepiq\Db\EncryptionSuiteMapper;
use OCA\Keepiq\Db\FolderMapper;
use OCA\Keepiq\Db\SecretMapper;
use OCA\Keepiq\Service\DecryptService;
use OCP\AppFramework\Db\DoesNotExistException;
use OCP\Migration\IOutput;
use Psr\Log\LoggerInterface;

/**
 * Guards the reusability of the dev vault's EncryptionSuite.
 *
 * A suite is only reusable when its certificate's public key matches the
 * AES-wrapped private key. A pre-fix seed — or a public-only re-sign that
 * minted a throwaway key — could persist a certificate bound to a DIFFERENT
 * key pair, which leaves the suite unable to decrypt anything the browser
 * newly encrypts under its certificate. That failure only shows up as a
 * read-after-write decrypt error much later, so it is checked up front here.
 *
 * Extracted from {@see SeedDevelopmentData} because "is the existing suite
 * sound, and if not, remove it and everything encrypted under it" is a
 * complete decision with its own collaborators (the decrypt service and the
 * two cascade mappers), separate from "mint a fresh suite" — which is all the
 * repair step itself now does.
 *
 * DEV-ONLY. Every caller is gated behind Nextcloud debug mode; no production
 * secret is ever reachable from this class.
 */
class DevSuiteIntegrityGuard {
	/**
	 * Constructor for DevSuiteIntegrityGuard.
	 *
	 * @param EncryptionSuiteMapper $suiteMapper The encryption suite mapper
	 * @param SecretMapper $secretMapper The secret mapper (rebuild cleanup)
	 * @param FolderMapper $folderMapper The folder mapper (rebuild cleanup)
	 * @param DecryptService $decryptService The decrypt service (suite validation)
	 * @param LoggerInterface $logger The logger interface
	 *
	 * @return void
	 */
	public function __construct(
		private EncryptionSuiteMapper $suiteMapper,
		private SecretMapper $secretMapper,
		private FolderMapper $folderMapper,
		private DecryptService $decryptService,
		private LoggerInterface $logger,
	) {
	}//end __construct()

	/**
	 * Report whether a sound dev suite is already in place, discarding a
	 * mismatched one on the way.
	 *
	 * @param string $userId The dev user ID
	 * @param string $masterPassword The dev master password (known by design)
	 * @param IOutput $output The repair output channel
	 *
	 * @return bool True when a sound suite exists and the caller must NOT
	 *              mint a new one. False when there is no suite, or a
	 *              mismatched one was just discarded — either way the caller
	 *              owes a fresh suite.
	 *
	 * @spec openspec/changes/retrofit-2026-05-25-doriath-coverage/tasks.md#task-6
	 */
	public function ensureReusableSuite(string $userId, string $masterPassword, IOutput $output): bool {
		try {
			$existing = $this->suiteMapper->findActiveByOwner(ownerType: 'user', ownerId: $userId);
		} catch (DoesNotExistException) {
			// Good — no suite yet.
			return false;
		}

		if ($this->suiteKeyPairMatches(suite: $existing, masterPassword: $masterPassword) === true) {
			$output->info('Dev user already has a sound EncryptionSuite, skipping');
			return true;
		}

		$output->warning(
			'Dev EncryptionSuite certificate does not match its private key — rebuilding it'
		);
		$this->discardMismatchedSuite(suite: $existing, userId: $userId, output: $output);

		return false;
	}//end ensureReusableSuite()

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
	 * @param string $masterPassword The dev master password
	 *
	 * @return bool True when the certificate and private key form one key pair.
	 */
	private function suiteKeyPairMatches(EncryptionSuite $suite, string $masterPassword): bool {
		$certificate = $suite->getCertificate();
		$wrappedKey = $suite->getPrivateKey();
		if ($certificate === null || $wrappedKey === null) {
			return false;
		}

		try {
			$privatePem = $this->decryptService->decryptPrivateKey($wrappedKey, $masterPassword);
		} catch (Exception) {
			return false;
		}

		$private = openssl_pkey_get_private($privatePem);
		$public = openssl_pkey_get_public($certificate);
		if ($private === false || $public === false) {
			return false;
		}

		$privateDetails = openssl_pkey_get_details($private);
		$publicDetails = openssl_pkey_get_details($public);
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
	 * ever touched (the calling repair step is gated behind debug mode).
	 *
	 * @param EncryptionSuite $suite The mismatched suite to discard
	 * @param string $userId The dev user ID
	 * @param IOutput $output The repair output channel
	 *
	 * @return void
	 */
	private function discardMismatchedSuite(EncryptionSuite $suite, string $userId, IOutput $output): void {
		$deletedSecrets = $this->secretMapper->deleteByOwnerUser($userId);
		// Drop the dev folders too so SeedDevelopmentSecrets recreates its
		// 'Work'/'Personal' tree without colliding with the unique-sibling rule.
		$this->folderMapper->deleteByOwnerUser($userId);
		$this->suiteMapper->deleteByOwnerUser($userId);
		$output->info(
			'Discarded mismatched dev suite ' . $suite->getId() . ' and ' . $deletedSecrets . ' dev secrets for rebuild'
		);
		$this->logger->warning(
			'Keepiq dev seed: rebuilt mismatched EncryptionSuite for ' . $userId
			. ' (deleted ' . $deletedSecrets . ' secrets encrypted under the broken certificate)'
		);

	}//end discardMismatchedSuite()
}//end class
