<?php

/**
 * AccountSuiteCleanupService and ApplicationJwkResolver: the suites and
 * escrowed keys removed with an account, and the signing key read from an
 * application's certificate.
 *
 * @category Test
 * @package  OCA\Keepiq\Tests\Unit\Service
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @link https://conduction.nl
 */

declare(strict_types=1);

namespace OCA\Keepiq\Tests\Unit\Service;

use OCA\Keepiq\Db\Application;
use OCA\Keepiq\Db\EmergencyContactMapper;
use OCA\Keepiq\Db\EncryptionSuite;
use OCA\Keepiq\Db\EncryptionSuiteMapper;
use OCA\Keepiq\Db\PasskeyMapper;
use OCA\Keepiq\Db\SuiteMigrationMapper;
use OCA\Keepiq\Service\AccountSuiteCleanupService;
use OCA\Keepiq\Service\ApplicationJwkResolver;
use OCA\Keepiq\Service\DeletionReport;
use OCP\AppFramework\Db\DoesNotExistException;
use PHPUnit\Framework\TestCase;
use RuntimeException;

/**
 * The account key cleanup and the application key resolver.
 */
class AccountKeyCleanupTest extends TestCase {
	/**
	 * A suite with the given id.
	 *
	 * @param string $id The suite id.
	 * @param string|null $certificate The suite's certificate.
	 *
	 * @return EncryptionSuite
	 */
	private function suite(string $id, ?string $certificate=null): EncryptionSuite {
		$suite = new EncryptionSuite();
		$suite->setId($id);
		$suite->setCertificate($certificate);
		return $suite;
	}//end suite()

	/**
	 * The user's suites go, their migrations first, and the count is reported.
	 *
	 * @return void
	 */
	public function testTheSuitesGoWithTheirMigrations(): void {
		$suites = $this->createMock(originalClassName: EncryptionSuiteMapper::class);
		$suites->method('findByOwner')->with('user', 'anne')->willReturn([$this->suite(id: 's1'), $this->suite(id: 's2')]);
		$suites->expects($this->once())->method('deleteByOwnerUser')->with('anne')->willReturn(2);
		$migrations = $this->createMock(originalClassName: SuiteMigrationMapper::class);
		$migrations->expects($this->once())->method('deleteBySuiteIds')->with(['s1', 's2']);
		$report = new DeletionReport();
		$cleanup = new AccountSuiteCleanupService(
			suiteMapper: $suites,
			migrationMapper: $migrations,
			emergencyMapper: $this->createMock(originalClassName: EmergencyContactMapper::class),
			passkeyMapper: $this->createMock(originalClassName: PasskeyMapper::class)
		);
		$cleanup->removeSuites(userId: 'anne', report: $report);
		$this->assertSame(expected: 2, actual: $report->suitesDeleted);
	}//end testTheSuitesGoWithTheirMigrations()

	/**
	 * Emergency contacts and passkeys go, and both counts are reported.
	 *
	 * @return void
	 */
	public function testTheEscrowedKeysGo(): void {
		$emergency = $this->createMock(originalClassName: EmergencyContactMapper::class);
		$emergency->expects($this->once())->method('deleteByUser')->with('anne')->willReturn(3);
		$passkeys = $this->createMock(originalClassName: PasskeyMapper::class);
		$passkeys->method('findByOwner')->with('anne')->willReturn(['p1', 'p2']);
		$passkeys->expects($this->once())->method('deleteByOwner')->with('anne');
		$report  = new DeletionReport();
		$cleanup = new AccountSuiteCleanupService(
			suiteMapper: $this->createMock(originalClassName: EncryptionSuiteMapper::class),
			migrationMapper: $this->createMock(originalClassName: SuiteMigrationMapper::class),
			emergencyMapper: $emergency,
			passkeyMapper: $passkeys
		);
		$cleanup->removeEscrowedKeys(userId: 'anne', report: $report);
		$this->assertSame(expected: [3, 2], actual: [$report->emergencyDeleted, $report->passkeysDeleted]);
	}//end testTheEscrowedKeysGo()

	/**
	 * A resolver over an application whose active suite is the given one, or none.
	 *
	 * @param EncryptionSuite|null $suite The active suite.
	 *
	 * @return ApplicationJwkResolver
	 */
	private function resolver(?EncryptionSuite $suite): ApplicationJwkResolver {
		$suites = $this->createMock(originalClassName: EncryptionSuiteMapper::class);
		$found  = $suites->method('findActiveByOwner')->with('application', 'app-1');
		if ($suite === null) {
			$found->willThrowException(new DoesNotExistException('none'));
		} else {
			$found->willReturn($suite);
		}

		return new ApplicationJwkResolver(suiteMapper: $suites);
	}//end resolver()

	/**
	 * The application app-1.
	 *
	 * @return Application
	 */
	private function application(): Application {
		$application = new Application();
		$application->setId('app-1');
		return $application;
	}//end application()

	/**
	 * The key is the public key of the application's certificate.
	 *
	 * @return void
	 */
	public function testTheKeyComesFromTheCertificate(): void {
		$private = openssl_pkey_new(['private_key_type' => OPENSSL_KEYTYPE_RSA, 'private_key_bits' => 2048]);
		$cert    = openssl_csr_sign(openssl_csr_new(['CN' => 'app-1'], $private), null, $private, 1);
		openssl_x509_export($cert, $pem);
		$jwk     = $this->resolver(suite: $this->suite(id: 's1', certificate: $pem))->forApplication(application: $this->application());
		$details = openssl_pkey_get_details($private);
		$this->assertSame(expected: 'RSA', actual: $jwk->get('kty'));
		$this->assertSame(expected: rtrim(strtr(base64_encode($details['rsa']['n']), '+/', '-_'), '='), actual: $jwk->get('n'));
		$this->assertFalse(condition: $jwk->has('d'));
	}//end testTheKeyComesFromTheCertificate()

	/**
	 * No active suite, no certificate, or a certificate that is not one, gives no key.
	 *
	 * @return void
	 */
	public function testNoUsableCertificateGivesNoKey(): void {
		$broken = "-----BEGIN CERTIFICATE-----\nnope\n-----END CERTIFICATE-----\n";
		$cases  = [
			'No active encryption suite for application'   => null,
			'Application has no certificate'                => $this->suite(id: 's1'),
			'Unable to extract public key from certificate' => $this->suite(id: 's1', certificate: $broken),
		];
		foreach ($cases as $message => $suite) {
			try {
				$this->resolver(suite: $suite)->forApplication(application: $this->application());
				$this->fail(message: 'A key was resolved: '.$message);
			} catch (RuntimeException $e) {
				$this->assertSame(expected: $message, actual: $e->getMessage());
			}
		}

		try {
			$this->resolver(suite: $this->suite(id: 's1', certificate: ''))->forApplication(application: $this->application());
			$this->fail(message: 'A key was resolved from an empty certificate');
		} catch (RuntimeException $e) {
			$this->assertSame(expected: 'Application has no certificate', actual: $e->getMessage());
		}
	}//end testNoUsableCertificateGivesNoKey()
}//end class
