<?php

/**
 * Unit tests for the admin and member views of the certificate inventory and
 * the renewal checklist's refusals in CertificateLifecycleService.
 *
 * @category Test
 * @package  OCA\Keepiq\Tests\Unit\Service
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

namespace OCA\Keepiq\Tests\Unit\Service;

use InvalidArgumentException;
use OCA\Keepiq\Db\CACertificate;
use OCA\Keepiq\Db\CACertificateMapper;
use OCA\Keepiq\Db\CertificateMetadataMapper;
use OCA\Keepiq\Db\EncryptionSuite;
use OCA\Keepiq\Db\EncryptionSuiteMapper;
use OCA\Keepiq\Db\Secret;
use OCA\Keepiq\Db\SecretMapper;
use OCA\Keepiq\Db\SecretType;
use OCA\Keepiq\Db\SecretTypeMapper;
use OCA\Keepiq\Service\CertificateIssuanceService;
use OCA\Keepiq\Service\CertificateLifecycleService;
use OCA\Keepiq\Service\CertificateMetadataService;
use OCA\Keepiq\Service\SecretService;
use OCP\AppFramework\Db\DoesNotExistException;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

/**
 * Who sees which inventory rows, and who may ask for a renewal checklist.
 */
class CertificateLifecycleInventoryTest extends TestCase {

	/** @var SecretMapper&MockObject */
	private SecretMapper $secretMapper;

	/** @var SecretTypeMapper&MockObject */
	private SecretTypeMapper $typeMapper;

	/** @var EncryptionSuiteMapper&MockObject */
	private EncryptionSuiteMapper $suiteMapper;

	/** @var CACertificateMapper&MockObject */
	private CACertificateMapper $caMapper;

	private CertificateLifecycleService $service;

	/**
	 * Fresh doubles; the certificate type exists and nobody stores certificates.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		parent::setUp();
		$metadataMapper = $this->createMock(CertificateMetadataMapper::class);
		$metadataMapper->method('findByOwner')->willReturn([]);
		$this->secretMapper = $this->createMock(SecretMapper::class);
		$this->secretMapper->method('findByOwner')->willReturn([]);
		$this->typeMapper = $this->createMock(SecretTypeMapper::class);
		$type = new SecretType();
		$type->setId('type-cert');
		$this->typeMapper->method('findByName')->willReturn($type);
		$this->suiteMapper = $this->createMock(EncryptionSuiteMapper::class);
		$this->caMapper = $this->createMock(CACertificateMapper::class);

		$this->service = new CertificateLifecycleService(
			metadataMapper: $metadataMapper,
			secretMapper: $this->secretMapper,
			suiteMapper: $this->suiteMapper,
			caMapper: $this->caMapper,
			metadataService: new CertificateMetadataService(
				metadataMapper: $metadataMapper,
				secretMapper: $this->secretMapper,
				typeMapper: $this->typeMapper,
				secretService: $this->createMock(SecretService::class),
			),
			issuanceService: $this->createMock(CertificateIssuanceService::class),
			eventDispatcher: null,
		);
	}//end setUp()

	/**
	 * A self-signed certificate PEM.
	 *
	 * @param string $commonName The subject CN
	 * @param array<string,mixed> $keyOptions The openssl key options
	 *
	 * @return string
	 */
	private function selfSigned(string $commonName, array $keyOptions): string {
		$key = openssl_pkey_new($keyOptions);
		$csr = openssl_csr_new(['commonName' => $commonName], $key, ['digest_alg' => 'sha256']);
		$x509 = openssl_csr_sign($csr, null, $key, 30, ['digest_alg' => 'sha256']);
		openssl_x509_export(certificate: $x509, output: $pem);

		return $pem;
	}//end selfSigned()

	/**
	 * An active suite of a user.
	 *
	 * @param string $id The suite id
	 * @param string $ownerId The owner
	 * @param string|null $pem Its certificate
	 *
	 * @return EncryptionSuite
	 */
	private function suite(string $id, string $ownerId, ?string $pem): EncryptionSuite {
		$suite = new EncryptionSuite();
		$suite->setId($id);
		$suite->setOwnerType('user');
		$suite->setOwnerId($ownerId);
		$suite->setStatus('active');
		$suite->setCertificate($pem);

		return $suite;
	}//end suite()

	/**
	 * An admin sees every active suite and the CA's root and intermediate,
	 * each parsed by the server.
	 *
	 * @return void
	 */
	public function testAnAdminSeesEverySuiteAndTheCa(): void {
		$this->suiteMapper->expects($this->once())->method('findAllActive')->willReturn([
			$this->suite(id: 'suite-a', ownerId: 'alice', pem: null),
			$this->suite(id: 'suite-b', ownerId: 'bob', pem: null),
		]);
		$this->suiteMapper->expects($this->never())->method('findActiveByOwner');
		$root = new CACertificate();
		$root->setCertificate($this->selfSigned(commonName: 'Keepiq Root', keyOptions: ['private_key_bits' => 2048, 'private_key_type' => OPENSSL_KEYTYPE_RSA]));
		$intermediate = new CACertificate();
		$intermediate->setCertificate($this->selfSigned(commonName: 'Keepiq Intermediate', keyOptions: ['private_key_type' => OPENSSL_KEYTYPE_EC, 'curve_name' => 'prime256v1']));
		$this->caMapper->method('findRoot')->willReturn($root);
		$this->caMapper->method('findActiveIntermediate')->willReturn($intermediate);

		$inventory = $this->service->inventory(userId: 'admin', isAdmin: true);

		$this->assertSame(['suite-a', 'suite-b'], array_column($inventory['suites'], 'id'));
		$this->assertSame([null, null], array_column($inventory['suites'], 'metadata'));
		$this->assertSame(['root', 'intermediate'], array_column($inventory['ca'], 'caRole'));
		$this->assertSame(['ca', 'ca'], array_column($inventory['ca'], 'kind'));
		$this->assertStringStartsWith('CN=Keepiq Root', $inventory['ca'][0]['metadata']['subject']);
		$this->assertSame('RSA', $inventory['ca'][0]['metadata']['keyType']);
		$this->assertStringStartsWith('CN=Keepiq Intermediate', $inventory['ca'][1]['metadata']['subject']);
		$this->assertSame('EC', $inventory['ca'][1]['metadata']['keyType']);
		$this->assertSame(256, $inventory['ca'][1]['metadata']['keyBits']);
		$this->assertSame([], $inventory['stored']);
	}//end testAnAdminSeesEverySuiteAndTheCa()

	/**
	 * Before the CA is bootstrapped an admin's inventory has no CA rows.
	 *
	 * @return void
	 */
	public function testAnAdminSeesNoCaRowsBeforeTheCaExists(): void {
		$this->suiteMapper->method('findAllActive')->willReturn([]);
		$this->caMapper->method('findRoot')->willThrowException(new DoesNotExistException(''));

		$inventory = $this->service->inventory(userId: 'admin', isAdmin: true);

		$this->assertSame(['stored' => [], 'suites' => [], 'ca' => []], $inventory);
	}//end testAnAdminSeesNoCaRowsBeforeTheCaExists()

	/**
	 * A member without an active suite sees an empty inventory, and the CA is
	 * never read for them.
	 *
	 * @return void
	 */
	public function testAMemberWithoutASuiteSeesNothing(): void {
		$this->suiteMapper->method('findActiveByOwner')->with('user', 'carol')->willThrowException(new DoesNotExistException(''));
		$this->suiteMapper->expects($this->never())->method('findAllActive');
		$this->caMapper->expects($this->never())->method('findRoot');

		$this->assertSame(['stored' => [], 'suites' => [], 'ca' => []], $this->service->inventory(userId: 'carol', isAdmin: false));
	}//end testAMemberWithoutASuiteSeesNothing()

	/**
	 * A renewal checklist is refused for another user's secret and for a
	 * secret that is not a certificate.
	 *
	 * @return void
	 */
	public function testARenewalChecklistIsRefusedForAForeignOrNonCertificateSecret(): void {
		$foreign = new Secret();
		$foreign->setId('secret-foreign');
		$foreign->setOwnerType('user');
		$foreign->setOwnerId('bob');
		$foreign->setTypeId('type-cert');
		$login = new Secret();
		$login->setId('secret-login');
		$login->setOwnerType('user');
		$login->setOwnerId('alice');
		$login->setTypeId('type-login');
		$this->secretMapper->method('findById')->willReturnCallback(
			static fn (string $id): Secret => $id === 'secret-foreign' ? $foreign : $login
		);

		$messages = [];
		foreach (['secret-foreign', 'secret-login'] as $secretId) {
			try {
				$this->service->renewalChecklist(secretId: $secretId, userId: 'alice');
			} catch (InvalidArgumentException $exception) {
				$messages[] = $exception->getMessage();
			}
		}

		$this->assertSame(
			['Only the owner may request a renewal checklist', 'Secret is not a certificate-type secret'],
			$messages
		);
	}//end testARenewalChecklistIsRefusedForAForeignOrNonCertificateSecret()
}//end class
