<?php

/**
 * Unit tests for the field list an item type carries (admin-secret-types).
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
use OCA\Keepiq\Db\SecretMapper;
use OCA\Keepiq\Db\SecretType;
use OCA\Keepiq\Db\SecretTypeMapper;
use OCA\Keepiq\Exception\ForbiddenException;
use OCA\Keepiq\Service\SecretTypeService;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

/**
 * An administrator defines the fields of a type; the service keeps the list valid.
 *
 * @spec openspec/specs/admin-secret-types/spec.md#requirement-item-type-definitions
 */
class SecretTypeFieldsTest extends TestCase {

	/**
	 * The service under test, with a real entity and mocked mappers.
	 *
	 * @var SecretTypeService
	 */
	private SecretTypeService $service;

	/**
	 * The type mapper double.
	 *
	 * @var SecretTypeMapper&\PHPUnit\Framework\MockObject\MockObject
	 */
	private $mapper;

	/**
	 * Set up fixtures.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		$this->mapper = $this->createMock(SecretTypeMapper::class);
		$this->mapper->method('countByName')->willReturn(0);
		$this->service = new SecretTypeService(
			mapper: $this->mapper,
			secretMapper: $this->createMock(SecretMapper::class),
			logger: $this->createMock(LoggerInterface::class),
		);
	}//end setUp()

	/**
	 * The Server access example from the spec.
	 *
	 * @return list<array<string,mixed>>
	 */
	private function serverAccessFields(): array {
		return [
			['key' => 'host', 'label' => 'Host', 'kind' => 'url', 'required' => true],
			['key' => 'port', 'label' => 'Port', 'kind' => 'text', 'required' => false],
			['key' => 'root-password', 'label' => 'Root password', 'kind' => 'hidden'],
		];
	}//end serverAccessFields()

	/**
	 * An administrator creates a global type with fields; order and flags survive.
	 *
	 * @return void
	 */
	public function testAdminCreatesGlobalTypeWithFields(): void {
		$this->mapper->expects($this->once())->method('insert');

		$type = $this->service->createType(
			name: 'server-access',
			label: 'Server access',
			scope: 'global',
			userId: 'admin',
			isAdmin: true,
			fields: $this->serverAccessFields(),
		);

		$json = $type->jsonSerialize();
		$this->assertSame(
			[
				['key' => 'host', 'label' => 'Host', 'kind' => 'url', 'required' => true],
				['key' => 'port', 'label' => 'Port', 'kind' => 'text', 'required' => false],
				['key' => 'root-password', 'label' => 'Root password', 'kind' => 'hidden', 'required' => false],
			],
			$json['fields']
		);
	}//end testAdminCreatesGlobalTypeWithFields()

	/**
	 * A type without fields serialises an empty list, so built-ins keep their forms.
	 *
	 * @return void
	 */
	public function testTypeWithoutFieldsHasEmptyList(): void {
		$type = new SecretType();
		$this->assertSame([], $type->jsonSerialize()['fields']);
	}//end testTypeWithoutFieldsHasEmptyList()

	/**
	 * A duplicate key is refused.
	 *
	 * @return void
	 */
	public function testDuplicateKeyIsRefused(): void {
		$this->expectException(InvalidArgumentException::class);
		$this->service->createType(
			name: 'dup',
			label: 'Dup',
			scope: 'global',
			userId: 'admin',
			isAdmin: true,
			fields: [
				['key' => 'host', 'label' => 'Host', 'kind' => 'text'],
				['key' => 'host', 'label' => 'Host again', 'kind' => 'text'],
			],
		);
	}//end testDuplicateKeyIsRefused()

	/**
	 * An unknown kind is refused.
	 *
	 * @return void
	 */
	public function testUnknownKindIsRefused(): void {
		$this->expectException(InvalidArgumentException::class);
		$this->service->createType(
			name: 'odd',
			label: 'Odd',
			scope: 'global',
			userId: 'admin',
			isAdmin: true,
			fields: [['key' => 'x', 'label' => 'X', 'kind' => 'totp']],
		);
	}//end testUnknownKindIsRefused()

	/**
	 * More than thirty fields is refused.
	 *
	 * @return void
	 */
	public function testMoreThanThirtyFieldsIsRefused(): void {
		$fields = [];
		for ($i = 0; $i < 31; $i++) {
			$fields[] = ['key' => 'f'.$i, 'label' => 'Field '.$i, 'kind' => 'text'];
		}

		$this->expectException(InvalidArgumentException::class);
		$this->service->createType(
			name: 'many',
			label: 'Many',
			scope: 'global',
			userId: 'admin',
			isAdmin: true,
			fields: $fields,
		);
	}//end testMoreThanThirtyFieldsIsRefused()

	/**
	 * Two fields with the same label are refused: values are stored under the label.
	 *
	 * @return void
	 */
	public function testDuplicateLabelIsRefused(): void {
		$this->expectException(InvalidArgumentException::class);
		$this->service->createType(
			name: 'twin',
			label: 'Twin',
			scope: 'global',
			userId: 'admin',
			isAdmin: true,
			fields: [
				['key' => 'a', 'label' => 'Host', 'kind' => 'text'],
				['key' => 'b', 'label' => 'Host', 'kind' => 'url'],
			],
		);
	}//end testDuplicateLabelIsRefused()

	/**
	 * A reserved label (key, login, url) is refused: it would land in a built-in column.
	 *
	 * @return void
	 */
	public function testReservedLabelIsRefused(): void {
		$this->expectException(InvalidArgumentException::class);
		$this->service->createType(
			name: 'res',
			label: 'Res',
			scope: 'global',
			userId: 'admin',
			isAdmin: true,
			fields: [['key' => 'u', 'label' => 'URL', 'kind' => 'url']],
		);
	}//end testReservedLabelIsRefused()

	/**
	 * A regular user cannot define a global type, fields or not.
	 *
	 * @return void
	 */
	public function testRegularUserCannotCreateGlobalType(): void {
		$this->expectException(ForbiddenException::class);
		$this->service->createType(
			name: 'server-access',
			label: 'Server access',
			scope: 'global',
			userId: 'bob',
			isAdmin: false,
			fields: $this->serverAccessFields(),
		);
	}//end testRegularUserCannotCreateGlobalType()

	/**
	 * An administrator edits the fields of a global type; a label-only update keeps them.
	 *
	 * @return void
	 */
	public function testUpdateReplacesFieldsAndLabelOnlyKeepsThem(): void {
		$type = new SecretType();
		$type->setId('type-1');
		$type->setName('server-access');
		$type->setLabel('Server access');
		$type->setScope('global');
		$type->setFieldList($this->serverAccessFields());
		$this->mapper->method('findById')->willReturn($type);

		$updated = $this->service->updateType(
			id: 'type-1',
			label: 'Server',
			userId: 'admin',
			isAdmin: true,
			fields: [['key' => 'host', 'label' => 'Host', 'kind' => 'url', 'required' => true]],
		);
		$this->assertCount(1, $updated->jsonSerialize()['fields']);

		$relabelled = $this->service->updateType(id: 'type-1', label: 'Servers', userId: 'admin', isAdmin: true);
		$this->assertSame('Servers', $relabelled->getLabel());
		$this->assertCount(1, $relabelled->jsonSerialize()['fields']);
	}//end testUpdateReplacesFieldsAndLabelOnlyKeepsThem()
}//end class
