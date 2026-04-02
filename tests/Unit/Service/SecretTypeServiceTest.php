<?php

/**
 * Unit tests for SecretTypeService.
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
use OCA\Doriath\Db\Secret;
use OCA\Doriath\Db\SecretMapper;
use OCA\Doriath\Db\SecretType;
use OCA\Doriath\Db\SecretTypeMapper;
use OCA\Doriath\Service\SecretTypeService;
use OCP\AppFramework\Db\DoesNotExistException;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

/**
 * Tests for SecretTypeService.
 */
class SecretTypeServiceTest extends TestCase
{

    /**
     * The service under test.
     *
     * @var SecretTypeService
     */
    private SecretTypeService $service;

    /**
     * The mocked secret type mapper.
     *
     * @var SecretTypeMapper
     */
    private SecretTypeMapper $typeMapper;

    /**
     * The mocked secret mapper.
     *
     * @var SecretMapper
     */
    private SecretMapper $secretMapper;

    /**
     * Set up test fixtures.
     *
     * @return void
     */
    protected function setUp(): void
    {
        $this->typeMapper   = $this->createMock(originalClassName: SecretTypeMapper::class);
        $this->secretMapper = $this->createMock(originalClassName: SecretMapper::class);
        $logger             = $this->createMock(originalClassName: LoggerInterface::class);

        $this->service = new SecretTypeService(
            typeMapper: $this->typeMapper,
            secretMapper: $this->secretMapper,
            logger: $logger,
        );
    }//end setUp()

    /**
     * Test that getAvailableTypes delegates to the mapper.
     *
     * @return void
     */
    public function testGetAvailableTypesDelegatesToMapper(): void
    {
        $type1 = new SecretType();
        $type1->setId('type-1');
        $type2 = new SecretType();
        $type2->setId('type-2');

        $this->typeMapper->method('findAvailableForUser')
            ->with('testuser')
            ->willReturn([$type1, $type2]);

        $result = $this->service->getAvailableTypes('testuser');

        $this->assertCount(expectedCount: 2, haystack: $result);
        $this->assertSame(expected: 'type-1', actual: $result[0]->getId());
        $this->assertSame(expected: 'type-2', actual: $result[1]->getId());
    }//end testGetAvailableTypesDelegatesToMapper()

    /**
     * Test that getSystemLoginType returns the login type from the mapper.
     *
     * @return void
     */
    public function testGetSystemLoginType(): void
    {
        $loginType = new SecretType();
        $loginType->setId('login-type-id');
        $loginType->setName('login');
        $loginType->setScope('system');

        $this->typeMapper->method('findByName')
            ->with('login')
            ->willReturn($loginType);

        $result = $this->service->getSystemLoginType();

        $this->assertSame(expected: $loginType, actual: $result);
        $this->assertEquals(expected: 'login', actual: $result->getName());
    }//end testGetSystemLoginType()

    /**
     * Test that createType with scope 'user' calls insert.
     *
     * @return void
     */
    public function testCreateTypeSuccess(): void
    {
        $this->typeMapper->method('findByName')
            ->willThrowException(new DoesNotExistException('not found'));

        $this->typeMapper->expects($this->once())->method('insert');

        $result = $this->service->createType('mytype', 'My Type', 'user', 'testuser');

        $this->assertEquals(expected: 'mytype', actual: $result->getName());
        $this->assertEquals(expected: 'My Type', actual: $result->getLabel());
        $this->assertEquals(expected: 'user', actual: $result->getScope());
        $this->assertEquals(expected: 'testuser', actual: $result->getOwnerId());
    }//end testCreateTypeSuccess()

    /**
     * Test that createType with scope 'global' calls insert.
     *
     * @return void
     */
    public function testCreateTypeGlobalScope(): void
    {
        $this->typeMapper->method('findByName')
            ->willThrowException(new DoesNotExistException('not found'));

        $this->typeMapper->expects($this->once())->method('insert');

        $result = $this->service->createType('globaltype', 'Global Type', 'global', null);

        $this->assertEquals(expected: 'global', actual: $result->getScope());
    }//end testCreateTypeGlobalScope()

    /**
     * Test that createType with scope 'system' throws InvalidArgumentException.
     *
     * @return void
     */
    public function testCreateTypeSystemScopeRejected(): void
    {
        $this->expectException(exception: InvalidArgumentException::class);
        $this->expectExceptionMessageMatches(regularExpression: '/system/');

        $this->service->createType('systemtype', 'System Type', 'system', null);
    }//end testCreateTypeSystemScopeRejected()

    /**
     * Test that createType throws when the name already exists.
     *
     * @return void
     */
    public function testCreateTypeDuplicateNameRejected(): void
    {
        $existing = new SecretType();
        $existing->setId('existing-id');
        $existing->setName('login');

        $this->typeMapper->method('findByName')->willReturn($existing);

        $this->expectException(exception: InvalidArgumentException::class);
        $this->expectExceptionMessageMatches(regularExpression: '/already exists/');

        $this->service->createType('login', 'Login Again', 'user', 'testuser');
    }//end testCreateTypeDuplicateNameRejected()

    /**
     * Test that deleteType re-points secrets to the login type before deleting.
     *
     * @return void
     */
    public function testDeleteTypeWithFallback(): void
    {
        $type = new SecretType();
        $type->setId('custom-type-id');
        $type->setName('custom');
        $type->setScope('user');
        $type->setOwnerId('testuser');

        $loginType = new SecretType();
        $loginType->setId('login-type-id');
        $loginType->setName('login');
        $loginType->setScope('system');

        $secret1 = new Secret();
        $secret1->setId('secret-1');
        $secret1->setTypeId('custom-type-id');

        $secret2 = new Secret();
        $secret2->setId('secret-2');
        $secret2->setTypeId('custom-type-id');

        $this->typeMapper->method('findById')->willReturn($type);
        $this->typeMapper->method('findByName')->willReturn($loginType);

        $this->secretMapper->method('findByTypeId')
            ->with('custom-type-id')
            ->willReturn([$secret1, $secret2]);

        $this->secretMapper->expects($this->exactly(2))->method('update');
        $this->typeMapper->expects($this->once())->method('delete');

        $this->service->deleteType('custom-type-id', 'testuser');

        $this->assertEquals(expected: 'login-type-id', actual: $secret1->getTypeId());
        $this->assertEquals(expected: 'login-type-id', actual: $secret2->getTypeId());
    }//end testDeleteTypeWithFallback()

    /**
     * Test that deleteType throws when the type is system-scoped.
     *
     * @return void
     */
    public function testDeleteSystemTypeRejected(): void
    {
        $type = new SecretType();
        $type->setId('system-type-id');
        $type->setName('login');
        $type->setScope('system');

        $this->typeMapper->method('findById')->willReturn($type);

        $this->expectException(exception: InvalidArgumentException::class);
        $this->expectExceptionMessageMatches(regularExpression: '/System secret types cannot be deleted/');

        $this->service->deleteType('system-type-id', 'admin');
    }//end testDeleteSystemTypeRejected()

    /**
     * Test that updateType updates the label and calls the mapper.
     *
     * @return void
     */
    public function testUpdateTypeSuccess(): void
    {
        $type = new SecretType();
        $type->setId('custom-type-id');
        $type->setName('custom');
        $type->setLabel('Old Label');
        $type->setScope('user');
        $type->setOwnerId('testuser');

        $this->typeMapper->method('findById')->willReturn($type);
        $this->typeMapper->expects($this->once())->method('update');

        $result = $this->service->updateType('custom-type-id', 'New Label', 'testuser');

        $this->assertEquals(expected: 'New Label', actual: $result->getLabel());
    }//end testUpdateTypeSuccess()

    /**
     * Test that updateType throws when the type is system-scoped.
     *
     * @return void
     */
    public function testUpdateSystemTypeRejected(): void
    {
        $type = new SecretType();
        $type->setId('system-type-id');
        $type->setName('login');
        $type->setScope('system');

        $this->typeMapper->method('findById')->willReturn($type);

        $this->expectException(exception: InvalidArgumentException::class);
        $this->expectExceptionMessageMatches(regularExpression: '/System secret types cannot be modified/');

        $this->service->updateType('system-type-id', 'New Label', 'admin');
    }//end testUpdateSystemTypeRejected()
}//end class
