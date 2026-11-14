<?php

/**
 * Tests for the per-item master password re-prompt flag on a secret.
 *
 * @category Test
 * @package  OCA\Keepiq\Tests\Unit\Service
 *
 * @author    Conduction Development Team <dev@conductio.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://conduction.nl
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @spec openspec/changes/crypto-item-reprompt/tasks.md#task-1.1
 */

declare(strict_types=1);

namespace OCA\Keepiq\Tests\Unit\Service;

use OCA\Keepiq\Db\EncryptionSuite;
use OCA\Keepiq\Db\EncryptionSuiteMapper;
use OCA\Keepiq\Db\FolderMapper;
use OCA\Keepiq\Db\Secret;
use OCA\Keepiq\Db\SecretMapper;
use OCA\Keepiq\Event\Audit\AuditEventFactory;
use OCA\Keepiq\Migration\Version001020Date20261114120000;
use OCA\Keepiq\Service\FolderOwnershipGuard;
use OCA\Keepiq\Service\LinkShareService;
use OCA\Keepiq\Service\MigrationService;
use OCA\Keepiq\Service\SecretService;
use OCA\Keepiq\Service\SecretTypeService;
use OCA\Keepiq\Service\ViewOnlyCopyGuard;
use OCP\DB\ISchemaWrapper;
use OCP\DB\Schema\IColumn;
use OCP\IDBConnection;
use OCP\Migration\IOutput;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

/**
 * The re-prompt flag is stored on create, changed on update, serialised, and
 * added by its migration.
 *
 * @covers \OCA\Keepiq\Service\SecretService
 * @covers \OCA\Keepiq\Db\Secret
 * @covers \OCA\Keepiq\Migration\Version001020Date20261114120000
 */
class SecretRepromptTest extends TestCase {

	/**
	 * The secret mapper double.
	 *
	 * @var SecretMapper&MockObject
	 */
	private SecretMapper&MockObject $mapper;

	/**
	 * The rows written through update().
	 *
	 * @var array<int, Secret>
	 */
	private array $updated = [];

	/**
	 * The service under test.
	 *
	 * @var SecretService
	 */
	private SecretService $service;

	/**
	 * Build the service with doubles at its boundaries.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		$this->mapper = $this->createMock(SecretMapper::class);
		$this->mapper->method('insert')->willReturnArgument(0);
		$this->mapper->method('update')->willReturnCallback(
			function (Secret $secret): Secret {
				$this->updated[] = $secret;
				return $secret;
			}
		);

		$suite = new EncryptionSuite();
		$suite->setId('suite-1');
		$suite->setStatus('active');
		$suiteMapper = $this->createMock(EncryptionSuiteMapper::class);
		$suiteMapper->method('findActiveByOwner')->willReturn($suite);

		$typeService = $this->createMock(SecretTypeService::class);
		$typeService->method('resolveTypeForSecret')->willReturn('login-id');
		$migrationService = $this->createMock(MigrationService::class);
		$migrationService->method('isWriteLocked')->willReturn(false);

		// Positional on purpose, as SecretServiceFolderOwnershipTest: the guard is the constructor's last argument.
		$this->service = new SecretService(
			$this->mapper,
			$typeService,
			$suiteMapper,
			$migrationService,
			$this->createMock(LinkShareService::class),
			$this->createMock(LoggerInterface::class),
			$this->createMock(IDBConnection::class),
			$this->createStub(ViewOnlyCopyGuard::class),
			null,
			null,
			null,
			null,
			null,
			null,
			null,
			null,
			null,
			new AuditEventFactory(),
			new FolderOwnershipGuard($this->createMock(FolderMapper::class)),
		);
	}//end setUp()

	/**
	 * Bob's stored secret.
	 *
	 * @param bool $reprompt Whether it is flagged.
	 *
	 * @return Secret
	 */
	private function bobsSecret(bool $reprompt): Secret {
		$secret = new Secret();
		$secret->setId('secret-bob');
		$secret->setName('Bank');
		$secret->setKey('CIPHERTEXT');
		$secret->setOwnerType('user');
		$secret->setOwnerId('bob');
		$secret->setEncryptionSuiteId('suite-1');
		$secret->setReprompt($reprompt);
		return $secret;
	}//end bobsSecret()

	/**
	 * A create stores the flag when asked, and leaves it off otherwise.
	 *
	 * @return void
	 */
	public function testCreateStoresTheFlagOnlyWhenAsked(): void {
		$flagged = $this->service->create(data: ['name' => 'Bank', 'key' => 'KEY', 'reprompt' => true], userId: 'bob');
		$plain = $this->service->create(data: ['name' => 'Mail', 'key' => 'KEY'], userId: 'bob');
		$truthy = $this->service->create(data: ['name' => 'Odd', 'key' => 'KEY', 'reprompt' => 'yes'], userId: 'bob');

		$this->assertTrue($flagged->getReprompt());
		$this->assertFalse($plain->getReprompt());
		$this->assertFalse($truthy->getReprompt(), 'Only a real true sets the flag.');
	}//end testCreateStoresTheFlagOnlyWhenAsked()

	/**
	 * An update switches the flag on and off.
	 *
	 * @return void
	 */
	public function testUpdateSwitchesTheFlag(): void {
		$this->mapper->method('findById')->willReturnOnConsecutiveCalls($this->bobsSecret(false), $this->bobsSecret(true));

		$on = $this->service->update(id: 'secret-bob', data: ['reprompt' => true], userId: 'bob');
		$off = $this->service->update(id: 'secret-bob', data: ['reprompt' => false], userId: 'bob');

		$this->assertTrue($on->getReprompt());
		$this->assertFalse($off->getReprompt());
		$this->assertCount(2, $this->updated);
	}//end testUpdateSwitchesTheFlag()

	/**
	 * An update that does not send the flag keeps it.
	 *
	 * @return void
	 */
	public function testAnUpdateWithoutTheFlagKeepsIt(): void {
		$this->mapper->method('findById')->willReturn($this->bobsSecret(true));

		$secret = $this->service->update(id: 'secret-bob', data: ['name' => 'Bank NL'], userId: 'bob');

		$this->assertTrue($secret->getReprompt());
		$this->assertSame('Bank NL', $secret->getName());
	}//end testAnUpdateWithoutTheFlagKeepsIt()

	/**
	 * The flag is in the serialised secret, so the list and the extension know it before decrypting.
	 *
	 * @return void
	 */
	public function testTheFlagIsSerialised(): void {
		$this->assertTrue($this->bobsSecret(true)->jsonSerialize()['reprompt']);
		$this->assertFalse($this->bobsSecret(false)->jsonSerialize()['reprompt']);
		$this->assertFalse((new Secret())->jsonSerialize()['reprompt']);
		$this->assertTrue($this->bobsSecret(true)->jsonSerializeBlocked('suite')['reprompt']);
	}//end testTheFlagIsSerialised()

	/**
	 * The migration adds a nullable boolean column that starts off, and only once.
	 *
	 * @return void
	 */
	public function testTheMigrationAddsTheColumnOnce(): void {
		$added = [];
		$table = $this->createMock((new \ReflectionMethod(ISchemaWrapper::class, 'getTable'))->getReturnType()->getName());
		$table->method('hasColumn')->willReturnOnConsecutiveCalls(false, true);
		$table->method('addColumn')->willReturnCallback(
			function (string $name, mixed $type, array $options) use (&$added): object {
				$added[$name] = [($type instanceof \BackedEnum ? (string)$type->value : (string)$type), $options];
				return $this->createMock(IColumn::class);
			}
		);
		$schema = $this->createMock(ISchemaWrapper::class);
		$schema->method('hasTable')->with(Version001020Date20261114120000::TABLE)->willReturn(true);
		$schema->method('getTable')->willReturn($table);
		$step = new Version001020Date20261114120000();

		$this->assertSame($schema, $step->changeSchema($this->createMock(IOutput::class), static fn () => $schema, []));
		$this->assertSame(['reprompt' => ['boolean', ['notnull' => false, 'default' => false]]], $added);
		$this->assertNull($step->changeSchema($this->createMock(IOutput::class), static fn () => $schema, []));
	}//end testTheMigrationAddsTheColumnOnce()
}//end class
