<?php

/**
 * Unit tests for SecretTypeService.
 *
 * @category Test
 * @package  OCA\Keepiq\Tests\Unit\Service
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

namespace OCA\Keepiq\Tests\Unit\Service;

use InvalidArgumentException;
use OCA\Keepiq\Db\SecretMapper;
use OCA\Keepiq\Db\SecretType;
use OCA\Keepiq\Db\SecretTypeMapper;
use OCA\Keepiq\Exception\ConflictException;
use OCA\Keepiq\Exception\ForbiddenException;
use OCA\Keepiq\Service\SecretTypeService;
use OCP\AppFramework\Db\DoesNotExistException;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * Tests for SecretTypeService.
 */
class SecretTypeServiceTest extends TestCase {

	/**
	 * @var SecretTypeService
	 */
	private SecretTypeService $service;

	/**
	 * @var SecretTypeMapper
	 */
	private $mapper;

	/**
	 * @var SecretMapper
	 */
	private $secretMapper;

	/**
	 * Set up fixtures.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		$this->mapper = $this->createMock(SecretTypeMapper::class);
		$this->secretMapper = $this->createMock(SecretMapper::class);
		$logger = $this->createMock(LoggerInterface::class);

		$this->service = new SecretTypeService(
			mapper: $this->mapper,
			secretMapper: $this->secretMapper,
			logger: $logger,
		);
	}//end setUp()

	/**
	 * Build a SecretType.
	 *
	 * @param string $scope The scope
	 * @param string|null $ownerId The owner ID
	 *
	 * @return SecretType
	 */
	private function makeType(string $scope = 'user', ?string $ownerId = 'alice'): SecretType {
		$type = new SecretType();
		$type->setId('type-1');
		$type->setName('custom');
		$type->setLabel('Custom');
		$type->setScope($scope);
		$type->setOwnerId($ownerId);
		return $type;
	}//end makeType()

	/**
	 * A user can create a user-scoped type.
	 *
	 * @return void
	 */
	public function testCreateUserType(): void {
		$this->mapper->method('countByName')->willReturn(0);
		$this->mapper->expects($this->once())->method('insert');

		$type = $this->service->createType(
			name: 'wifi',
			label: 'WiFi',
			scope: 'user',
			userId: 'alice',
			isAdmin: false,
		);

		$this->assertSame('user', $type->getScope());
		$this->assertSame('alice', $type->getOwnerId());
		$this->assertSame('wifi', $type->getName());
	}//end testCreateUserType()

	/**
	 * An admin can create a global type with null owner.
	 *
	 * @return void
	 */
	public function testCreateGlobalTypeAsAdmin(): void {
		$this->mapper->method('countByName')->willReturn(0);
		$this->mapper->expects($this->once())->method('insert');

		$type = $this->service->createType(
			name: 'corp',
			label: 'Corporate',
			scope: 'global',
			userId: 'admin',
			isAdmin: true,
		);

		$this->assertSame('global', $type->getScope());
		$this->assertNull($type->getOwnerId());
	}//end testCreateGlobalTypeAsAdmin()

	/**
	 * A non-admin cannot create a global type.
	 *
	 * @return void
	 */
	public function testCreateGlobalTypeAsNonAdminRejected(): void {
		$this->expectException(ForbiddenException::class);

		$this->service->createType(
			name: 'corp',
			label: 'Corporate',
			scope: 'global',
			userId: 'alice',
			isAdmin: false,
		);
	}//end testCreateGlobalTypeAsNonAdminRejected()

	/**
	 * A duplicate name is rejected with a conflict.
	 *
	 * @return void
	 */
	public function testDuplicateNameRejected(): void {
		$this->mapper->method('countByName')->willReturn(1);

		$this->expectException(ConflictException::class);

		$this->service->createType(
			name: 'login',
			label: 'My Login',
			scope: 'user',
			userId: 'alice',
			isAdmin: false,
		);
	}//end testDuplicateNameRejected()

	/**
	 * System types cannot be modified.
	 *
	 * @return void
	 */
	public function testSystemTypeImmutable(): void {
		$this->mapper->method('findById')->willReturn($this->makeType(scope: 'system', ownerId: null));

		$this->expectException(ForbiddenException::class);

		$this->service->updateType(id: 'type-1', label: 'Hacked', userId: 'alice', isAdmin: true);
	}//end testSystemTypeImmutable()

	/**
	 * A user cannot modify another user's type.
	 *
	 * @return void
	 */
	public function testCannotModifyOtherUsersType(): void {
		$this->mapper->method('findById')->willReturn($this->makeType(scope: 'user', ownerId: 'bob'));

		$this->expectException(ForbiddenException::class);

		$this->service->updateType(id: 'type-1', label: 'Mine', userId: 'alice', isAdmin: false);
	}//end testCannotModifyOtherUsersType()

	/**
	 * Deleting a custom type reassigns its secrets to login.
	 *
	 * @return void
	 */
	public function testDeleteTypeFallsBackToLogin(): void {
		$this->mapper->method('findById')->willReturn($this->makeType(scope: 'user', ownerId: 'alice'));

		$login = new SecretType();
		$login->setId('login-id');
		$login->setName('login');
		$this->mapper->method('findByName')->willReturn($login);

		$this->secretMapper->expects($this->once())
			->method('reassignType')
			->with('type-1', 'login-id');
		$this->mapper->expects($this->once())->method('delete');

		$this->service->deleteType(id: 'type-1', userId: 'alice', isAdmin: false);
	}//end testDeleteTypeFallsBackToLogin()

	/**
	 * resolveTypeForSecret returns the login type when no type is given.
	 *
	 * @return void
	 */
	public function testResolveDefaultsToLogin(): void {
		$login = new SecretType();
		$login->setId('login-id');
		$login->setName('login');
		$this->mapper->method('findByName')->willReturn($login);

		$resolved = $this->service->resolveTypeForSecret(typeId: null, userId: 'alice');

		$this->assertSame('login-id', $resolved);
	}//end testResolveDefaultsToLogin()

	/**
	 * An unknown type ID is rejected.
	 *
	 * @return void
	 */
	public function testResolveUnknownTypeRejected(): void {
		$this->mapper->method('findById')->willThrowException(new DoesNotExistException('nope'));

		$this->expectException(\InvalidArgumentException::class);

		$this->service->resolveTypeForSecret(typeId: 'ghost', userId: 'alice');
	}//end testResolveUnknownTypeRejected()

	/**
	 * Golden: the refusals of a new type, in check order, each with its
	 * message, and none of them reaches the name lookup.
	 *
	 * @return void
	 */
	public function testCreateTypeRefusesInCheckOrder(): void {
		$this->mapper->expects($this->never())->method('countByName');
		$this->mapper->expects($this->never())->method('insert');
		$cases = [
			['   ', 'L', 'user', false, InvalidArgumentException::class, 'Type name and label are required'],
			['n', '', 'user', false, InvalidArgumentException::class, 'Type name and label are required'],
			['n', 'L', 'team', false, InvalidArgumentException::class, 'Scope must be user or global'],
			['', 'L', 'team', false, InvalidArgumentException::class, 'Type name and label are required'],
			['n', 'L', 'global', false, ForbiddenException::class, 'Only administrators can create global types'],
		];
		foreach ($cases as [$name, $label, $scope, $isAdmin, $class, $message]) {
			try {
				$this->service->createType(name: $name, label: $label, scope: $scope, userId: 'alice', isAdmin: $isAdmin);
				$this->fail('created ' . $name . '/' . $scope);
			} catch (Throwable $exception) {
				$this->assertInstanceOf($class, $exception);
				$this->assertSame($message, $exception->getMessage());
			}
		}
	}//end testCreateTypeRefusesInCheckOrder()

	/**
	 * Golden: a new type carries the trimmed name, the label, its scope and
	 * owner, a fresh id and a creation time, and is checked for its trimmed name.
	 *
	 * @return void
	 */
	public function testCreateTypeStoresTheTrimmedNameAndItsOwner(): void {
		$this->mapper->expects($this->once())->method('countByName')->with('wifi')->willReturn(0);
		$inserted = [];
		$this->mapper->method('insert')->willReturnCallback(
			static function (SecretType $type) use (&$inserted): SecretType {
				$inserted[] = $type;
				return $type;
			}
		);

		$type = $this->service->createType(name: '  wifi ', label: ' WiFi ', scope: 'user', userId: 'alice', isAdmin: true);

		$this->assertSame([$type], $inserted);
		$this->assertSame(['wifi', ' WiFi ', 'user', 'alice'], [$type->getName(), $type->getLabel(), $type->getScope(), $type->getOwnerId()]);
		$this->assertMatchesRegularExpression('/^[0-9a-f-]{36}$/', $type->getId());
		$this->assertNotNull($type->getCreatedAt());
	}//end testCreateTypeStoresTheTrimmedNameAndItsOwner()
}//end class
