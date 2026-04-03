<?php

/**
 * Unit tests for SecretService.
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

use InvalidArgumentException;
use OCA\Doriath\Db\EncryptionSuite;
use OCA\Doriath\Db\FolderMapper;
use OCA\Doriath\Db\Secret;
use OCA\Doriath\Db\SecretMapper;
use OCA\Doriath\Db\SecretType;
use OCA\Doriath\Service\EncryptionSuiteService;
use OCA\Doriath\Service\MigrationService;
use OCA\Doriath\Service\SecretService;
use OCA\Doriath\Service\SecretTypeService;
use OCP\AppFramework\OCS\OCSForbiddenException;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

/**
 * Tests for SecretService.
 */
class SecretServiceTest extends TestCase
{

    /**
     * The service under test.
     *
     * @var SecretService
     */
    private SecretService $service;

    /**
     * The mocked secret mapper.
     *
     * @var SecretMapper
     */
    private SecretMapper $secretMapper;

    /**
     * The mocked secret type service.
     *
     * @var SecretTypeService
     */
    private SecretTypeService $typeService;

    /**
     * The mocked encryption suite service.
     *
     * @var EncryptionSuiteService
     */
    private EncryptionSuiteService $suiteService;

    /**
     * The mocked migration service.
     *
     * @var MigrationService
     */
    private MigrationService $migrationService;

    /**
     * The mocked folder mapper.
     *
     * @var FolderMapper
     */
    private FolderMapper $folderMapper;

    /**
     * Set up test fixtures.
     *
     * @return void
     */
    protected function setUp(): void
    {
        $this->secretMapper     = $this->createMock(originalClassName: SecretMapper::class);
        $this->typeService      = $this->createMock(originalClassName: SecretTypeService::class);
        $this->suiteService     = $this->createMock(originalClassName: EncryptionSuiteService::class);
        $this->migrationService = $this->createMock(originalClassName: MigrationService::class);
        $this->folderMapper     = $this->createMock(originalClassName: FolderMapper::class);
        $logger                 = $this->createMock(originalClassName: LoggerInterface::class);

        $this->service = new SecretService(
            secretMapper: $this->secretMapper,
            typeService: $this->typeService,
            suiteService: $this->suiteService,
            migrationService: $this->migrationService,
            folderMapper: $this->folderMapper,
            logger: $logger,
        );
    }//end setUp()

    /**
     * Test that create inserts a secret and defaults to the login type.
     *
     * @return void
     */
    public function testCreateSecretSuccess(): void
    {
        $activeSuite = new EncryptionSuite();
        $activeSuite->setId('suite-1');
        $activeSuite->setStatus('active');

        $loginType = new SecretType();
        $loginType->setId('login-type-id');
        $loginType->setName('login');

        $this->migrationService->method('isWriteLocked')->willReturn(false);
        $this->suiteService->method('getActiveSuite')->willReturn($activeSuite);
        $this->typeService->method('getSystemLoginType')->willReturn($loginType);

        $this->secretMapper->expects($this->once())->method('insert');

        $result = $this->service->create(
            ['name' => 'My Secret', 'url' => 'https://example.com'],
            'testuser'
        );

        $this->assertEquals(expected: 'My Secret', actual: $result->getName());
        $this->assertEquals(expected: 'login-type-id', actual: $result->getTypeId());
        $this->assertEquals(expected: 'suite-1', actual: $result->getEncryptionSuiteId());
        $this->assertEquals(expected: 'user', actual: $result->getOwnerType());
        $this->assertEquals(expected: 'testuser', actual: $result->getOwnerId());
    }//end testCreateSecretSuccess()

    /**
     * Test that create throws when the user is write-locked.
     *
     * @return void
     */
    public function testCreateSecretWriteLockedThrows(): void
    {
        $this->migrationService->method('isWriteLocked')->willReturn(true);

        $this->expectException(exception: OCSForbiddenException::class);
        $this->expectExceptionMessageMatches(regularExpression: '/Write operations are locked/');

        $this->service->create(['name' => 'My Secret'], 'testuser');
    }//end testCreateSecretWriteLockedThrows()

    /**
     * Test that get returns the secret when the suite is active.
     *
     * @return void
     */
    public function testGetSecretSuccess(): void
    {
        $secret = new Secret();
        $secret->setId('secret-1');
        $secret->setOwnerId('testuser');
        $secret->setEncryptionSuiteId('suite-1');

        $suite = new EncryptionSuite();
        $suite->setId('suite-1');
        $suite->setStatus('active');

        $this->secretMapper->method('findById')->willReturn($secret);
        $this->suiteService->method('getSuite')->willReturn($suite);

        $result = $this->service->get('secret-1', 'testuser');

        $this->assertSame(expected: $secret, actual: $result);
    }//end testGetSecretSuccess()

    /**
     * Test that get throws when the encryption suite is revoked.
     *
     * @return void
     */
    public function testGetSecretRevokedSuiteThrows(): void
    {
        $secret = new Secret();
        $secret->setId('secret-1');
        $secret->setOwnerId('testuser');
        $secret->setEncryptionSuiteId('suite-1');

        $suite = new EncryptionSuite();
        $suite->setId('suite-1');
        $suite->setStatus('revoked');

        $this->secretMapper->method('findById')->willReturn($secret);
        $this->suiteService->method('getSuite')->willReturn($suite);

        $this->expectException(exception: OCSForbiddenException::class);
        $this->expectExceptionMessageMatches(regularExpression: '/revoked/');

        $this->service->get('secret-1', 'testuser');
    }//end testGetSecretRevokedSuiteThrows()

    /**
     * Test that get throws when the encryption suite is compromised.
     *
     * @return void
     */
    public function testGetSecretCompromisedSuiteThrows(): void
    {
        $secret = new Secret();
        $secret->setId('secret-1');
        $secret->setOwnerId('testuser');
        $secret->setEncryptionSuiteId('suite-1');

        $suite = new EncryptionSuite();
        $suite->setId('suite-1');
        $suite->setStatus('compromised');

        $this->secretMapper->method('findById')->willReturn($secret);
        $this->suiteService->method('getSuite')->willReturn($suite);

        $this->expectException(exception: OCSForbiddenException::class);
        $this->expectExceptionMessageMatches(regularExpression: '/compromised/');

        $this->service->get('secret-1', 'testuser');
    }//end testGetSecretCompromisedSuiteThrows()

    /**
     * Test that update saves changed fields and calls the mapper.
     *
     * @return void
     */
    public function testUpdateSecretSuccess(): void
    {
        $secret = new Secret();
        $secret->setId('secret-1');
        $secret->setOwnerId('testuser');
        $secret->setName('Old Name');

        $this->migrationService->method('isWriteLocked')->willReturn(false);
        $this->secretMapper->method('findById')->willReturn($secret);
        $this->secretMapper->expects($this->once())->method('update');

        $result = $this->service->update('secret-1', ['name' => 'New Name'], 'testuser');

        $this->assertEquals(expected: 'New Name', actual: $result->getName());
    }//end testUpdateSecretSuccess()

    /**
     * Test that update throws when the user is write-locked.
     *
     * @return void
     */
    public function testUpdateSecretWriteLockedThrows(): void
    {
        $this->migrationService->method('isWriteLocked')->willReturn(true);

        $this->expectException(exception: OCSForbiddenException::class);
        $this->expectExceptionMessageMatches(regularExpression: '/Write operations are locked/');

        $this->service->update('secret-1', ['name' => 'New Name'], 'testuser');
    }//end testUpdateSecretWriteLockedThrows()

    /**
     * Test that delete removes the secret via the mapper.
     *
     * @return void
     */
    public function testDeleteSecretSuccess(): void
    {
        $secret = new Secret();
        $secret->setId('secret-1');
        $secret->setOwnerId('testuser');

        $this->secretMapper->method('findById')->willReturn($secret);
        $this->secretMapper->expects($this->once())->method('delete')->with($secret);

        $this->service->delete('secret-1', 'testuser');
    }//end testDeleteSecretSuccess()

    /**
     * Test that list returns the correct structure with secrets and total.
     *
     * @return void
     */
    public function testListSecretsSuccess(): void
    {
        $secret = new Secret();
        $secret->setId('secret-1');
        $secret->setName('My Secret');
        $secret->setOwnerId('testuser');
        $secret->setOwnerType('user');
        $secret->setEncryptionSuiteId('suite-1');

        $suite = new EncryptionSuite();
        $suite->setId('suite-1');
        $suite->setStatus('active');

        $this->secretMapper->method('findByOwner')->willReturn([$secret]);
        $this->secretMapper->method('countByOwner')->willReturn(1);
        $this->suiteService->method('getSuite')->willReturn($suite);

        $result = $this->service->list('testuser', null, 'name', 'ASC', 1, 50);

        $this->assertEquals(expected: 1, actual: $result['total']);
        $this->assertCount(expectedCount: 1, haystack: $result['results']);
        $this->assertEquals(expected: 'secret-1', actual: $result['results'][0]['id']);
    }//end testListSecretsSuccess()

    /**
     * Test that list masks credential fields for secrets with a revoked suite.
     *
     * @return void
     */
    public function testListSecretsMasksBlockedSuites(): void
    {
        $secret = new Secret();
        $secret->setId('secret-1');
        $secret->setName('My Secret');
        $secret->setOwnerId('testuser');
        $secret->setOwnerType('user');
        $secret->setEncryptionSuiteId('suite-revoked');
        $secret->setKey('encrypted-key');
        $secret->setLogin('encrypted-login');
        $secret->setAdditionalFields('extra-data');

        $revokedSuite = new EncryptionSuite();
        $revokedSuite->setId('suite-revoked');
        $revokedSuite->setStatus('revoked');

        $this->secretMapper->method('findByOwner')->willReturn([$secret]);
        $this->secretMapper->method('countByOwner')->willReturn(1);
        $this->suiteService->method('getSuite')->willReturn($revokedSuite);

        $result = $this->service->list('testuser', null, 'name', 'ASC', 1, 50);

        $row = $result['results'][0];
        // jsonSerialize() excludes encrypted fields by default (list-safe).
        $this->assertArrayNotHasKey(key: 'key', array: $row);
        $this->assertArrayNotHasKey(key: 'login', array: $row);
        $this->assertArrayNotHasKey(key: 'additionalFields', array: $row);
        $this->assertTrue(condition: $row['blocked'] ?? false);
    }//end testListSecretsMasksBlockedSuites()

    /**
     * Test that search delegates to searchByNameOrUrl and returns results.
     *
     * @return void
     */
    public function testSearchSecrets(): void
    {
        $secret = new Secret();
        $secret->setId('secret-1');
        $secret->setName('Github Token');
        $secret->setOwnerId('testuser');
        $secret->setOwnerType('user');
        $secret->setEncryptionSuiteId('suite-1');

        // Return many results so Levenshtein stage is skipped.
        $sqlResults = array_fill(0, 50, $secret);

        $this->secretMapper->method('searchByNameOrUrl')
            ->with('testuser', 'github')
            ->willReturn($sqlResults);

        $result = $this->service->search('testuser', 'github', 1, 10);

        // 50 unique results (all same id will de-duplicate to 1 in merged map).
        $this->assertEquals(expected: 1, actual: $result['total']);
    }//end testSearchSecrets()
}//end class
