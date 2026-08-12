<?php

/**
 * Unit tests for SecretService machine write-back methods.
 *
 * @category Test
 * @package  OCA\Doriath\Tests\Unit\Service
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

namespace OCA\Doriath\Tests\Unit\Service;

use DateTime;
use InvalidArgumentException;
use OCA\Doriath\Db\EncryptionSuite;
use OCA\Doriath\Db\EncryptionSuiteMapper;
use OCA\Doriath\Db\Secret;
use OCA\Doriath\Db\SecretMapper;
use OCA\Doriath\Exception\NotFoundException;
use OCA\Doriath\Service\LinkShareService;
use OCA\Doriath\Service\MigrationService;
use OCA\Doriath\Service\SecretService;
use OCA\Doriath\Service\SecretTypeService;
use OCP\AppFramework\Db\DoesNotExistException;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

/**
 * Tests createByApplication / updateByApplication — the machine write-back
 * path (application is the principal, own-vault scoped, ciphertext-only).
 */
class SecretServiceMachineWriteTest extends TestCase {

	private SecretService $service;

	private $mapper;

	private $typeService;

	private $suiteMapper;

	/**
	 * Wire the service with mocks.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		$this->mapper = $this->createMock(SecretMapper::class);
		$this->typeService = $this->createMock(SecretTypeService::class);
		$this->suiteMapper = $this->createMock(EncryptionSuiteMapper::class);

		$this->service = new SecretService(
			mapper: $this->mapper,
			typeService: $this->typeService,
			suiteMapper: $this->suiteMapper,
			migrationService: $this->createMock(MigrationService::class),
			linkShareService: $this->createMock(LinkShareService::class),
			logger: $this->createMock(LoggerInterface::class),
		);

		$this->typeService->method('resolveTypeForSecret')->willReturn('api_key');
	}//end setUp()

	/**
	 * Build an application-owned secret.
	 *
	 * @param string $id The secret id
	 * @param string $ownerId The owning application id
	 *
	 * @return Secret
	 */
	private function appSecret(string $id, string $ownerId = 'app-1'): Secret {
		$secret = new Secret();
		$secret->setId($id);
		$secret->setName('token');
		$secret->setKey('OLD-CIPHER');
		$secret->setOwnerType('application');
		$secret->setOwnerId($ownerId);
		$secret->setEncryptionSuiteId('suite-1');
		$secret->setUpdatedAt(new DateTime('2026-01-01T00:00:00+00:00'));
		$secret->setKeyUpdatedAt(new DateTime('2026-01-01T00:00:00+00:00'));
		return $secret;
	}//end appSecret()

	/**
	 * createByApplication stores an application-owned secret with the app's
	 * active suite and the app as owner.
	 *
	 * @return void
	 */
	public function testCreateByApplication(): void {
		$suite = new EncryptionSuite();
		$suite->setId('suite-1');
		$this->suiteMapper->method('findActiveByOwner')->willReturn($suite);

		$captured = null;
		$this->mapper->expects($this->once())->method('insert')
			->willReturnCallback(
				function (Secret $s) use (&$captured) {
					$captured = $s;
					return $s;
				}
			);

		$secret = $this->service->createByApplication(
			data: ['name' => 'new-token', 'key' => 'CIPHER'],
			applicationId: 'app-1'
		);

		$this->assertSame('application', $secret->getOwnerType());
		$this->assertSame('app-1', $secret->getOwnerId());
		$this->assertSame('suite-1', $secret->getEncryptionSuiteId());
		$this->assertSame('CIPHER', $captured->getKey());
	}//end testCreateByApplication()

	/**
	 * createByApplication requires a name and key.
	 *
	 * @return void
	 */
	public function testCreateByApplicationRequiresFields(): void {
		$this->expectException(InvalidArgumentException::class);
		$this->service->createByApplication(data: ['name' => ''], applicationId: 'app-1');
	}//end testCreateByApplicationRequiresFields()

	/**
	 * updateByApplication on a cross-vault secret raises NotFoundException
	 * (no existence oracle).
	 *
	 * @return void
	 */
	public function testUpdateByApplicationCrossVaultNotFound(): void {
		$this->mapper->method('findById')->willReturn($this->appSecret('s1', 'app-2'));

		$this->expectException(NotFoundException::class);
		$this->service->updateByApplication(
			id: 's1',
			data: ['key' => 'NEW'],
			applicationId: 'app-1'
		);
	}//end testUpdateByApplicationCrossVaultNotFound()

	/**
	 * updateByApplication on a nonexistent id raises NotFoundException.
	 *
	 * @return void
	 */
	public function testUpdateByApplicationMissingNotFound(): void {
		$this->mapper->method('findById')
			->willThrowException(new DoesNotExistException('none'));

		$this->expectException(NotFoundException::class);
		$this->service->updateByApplication(id: 'x', data: [], applicationId: 'app-1');
	}//end testUpdateByApplicationMissingNotFound()

	/**
	 * updateByApplication advances updatedAt and, when the key blob
	 * changes, keyUpdatedAt.
	 *
	 * @return void
	 */
	public function testUpdateByApplicationAdvancesTimestamps(): void {
		$secret = $this->appSecret('s1', 'app-1');
		$oldKeyU = $secret->getKeyUpdatedAt();
		$oldUpd = $secret->getUpdatedAt();
		$this->mapper->method('findById')->willReturn($secret);
		$this->mapper->expects($this->once())->method('update');

		$result = $this->service->updateByApplication(
			id: 's1',
			data: ['key' => 'ROTATED-CIPHER'],
			applicationId: 'app-1'
		);

		$this->assertSame('ROTATED-CIPHER', $result->getKey());
		$this->assertGreaterThanOrEqual($oldUpd, $result->getUpdatedAt());
		$this->assertGreaterThanOrEqual($oldKeyU, $result->getKeyUpdatedAt());
	}//end testUpdateByApplicationAdvancesTimestamps()

	/**
	 * updateByApplication with an empty key is rejected.
	 *
	 * @return void
	 */
	public function testUpdateByApplicationEmptyKeyRejected(): void {
		$this->mapper->method('findById')->willReturn($this->appSecret('s1', 'app-1'));

		$this->expectException(InvalidArgumentException::class);
		$this->service->updateByApplication(
			id: 's1',
			data: ['key' => ''],
			applicationId: 'app-1'
		);
	}//end testUpdateByApplicationEmptyKeyRejected()
}//end class
