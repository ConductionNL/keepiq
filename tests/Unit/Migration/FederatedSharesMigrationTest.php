<?php

/**
 * The federated shares migration creates the two share tables and adds the
 * two federation columns to secrets.
 *
 * @category Test
 * @package  OCA\Keepiq\Tests\Unit\Migration
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

namespace OCA\Keepiq\Tests\Unit\Migration;

use OCA\Keepiq\Db\Secret;
use OCA\Keepiq\Migration\Version001012Date20261004120000;
use OCP\DB\ISchemaWrapper;
use OCP\Migration\IOutput;
use PHPUnit\Framework\TestCase;

/**
 * Task 1.1 of sharing-federated-recipients.
 *
 * @spec openspec/changes/sharing-federated-recipients/specs/federated-sharing/spec.md#requirement-federated-shares-carry-only-browser-made-ciphertext
 */
class FederatedSharesMigrationTest extends TestCase {

	/**
	 * On an install without them, the step creates both tables with the
	 * columns the design names and adds `federated_source` and `read_only`
	 * to secrets.
	 *
	 * @return void
	 */
	public function testCreatesBothTablesAndAddsTheSecretColumns(): void {
		$created = [];
		$secretsAdded = [];
		$secrets = $this->tableDouble(added: $secretsAdded);

		$wrapper = $this->createMock(ISchemaWrapper::class);
		$wrapper->method('hasTable')->willReturnCallback(static fn (string $name): bool => $name === 'keepiq_secrets');
		$wrapper->method('getTable')->willReturn($secrets);
		$wrapper->method('createTable')->willReturnCallback(
			function (string $name) use (&$created): object {
				$created[$name] = [];
				return $this->tableDouble(added: $created[$name]);
			}
		);

		$step = new Version001012Date20261004120000();
		$this->assertSame($wrapper, $step->changeSchema($this->createMock(IOutput::class), fn () => $wrapper, []));

		$this->assertSame(['keepiq_federated_shares', 'keepiq_federated_inbound'], array_keys($created));
		$this->assertSame(
			[
				'id', 'source_secret_id', 'owner_id', 'recipient_cloud_id', 'partner_id',
				'recipient_cert_fingerprint', 'key', 'login', 'additional_fields', 'shared_secret_hash',
				'status', 'pending_notification', 'notify_attempts', 'next_notify_at', 'created_at', 'updated_at',
			],
			array_keys($created['keepiq_federated_shares'])
		);
		$this->assertSame(
			[
				'id', 'recipient_uid', 'sender_cloud_id', 'partner_id', 'remote_share_id', 'name',
				'shared_secret_enc', 'secret_id', 'status', 'received_at', 'updated_at',
			],
			array_keys($created['keepiq_federated_inbound'])
		);
		// The ciphertext columns hold RSA envelopes, so they are TEXT-sized and
		// the login and extra fields may be empty.
		$this->assertSame(['notnull' => false], $created['keepiq_federated_shares']['login']);
		$this->assertSame(['notnull' => true, 'length' => 16, 'default' => 'pending'], $created['keepiq_federated_inbound']['status']);

		$this->assertSame(['federated_source', 'read_only'], array_keys($secretsAdded));
		$this->assertSame(['notnull' => false, 'length' => 255], $secretsAdded['federated_source']);
		$this->assertSame(['notnull' => false, 'default' => false], $secretsAdded['read_only']);

		// A secret loaded from a row written before the step is neither.
		$secret = new Secret();
		$this->assertFalse($secret->getReadOnly() === true);
		$this->assertNull($secret->getFederatedSource());
	}//end testCreatesBothTablesAndAddsTheSecretColumns()

	/**
	 * A second run, with the tables and columns in place, changes nothing.
	 *
	 * @return void
	 */
	public function testIsANoopWhenEverythingExists(): void {
		$added = [];
		$wrapper = $this->createMock(ISchemaWrapper::class);
		$wrapper->method('hasTable')->willReturn(true);
		$wrapper->method('getTable')->willReturn($this->tableDouble(added: $added, exists: true));
		$wrapper->expects($this->never())->method('createTable');

		$this->assertNull((new Version001012Date20261004120000())->changeSchema($this->createMock(IOutput::class), fn () => $wrapper, []));
		$this->assertSame([], $added);
	}//end testIsANoopWhenEverythingExists()

	/**
	 * A table double of whatever type ISchemaWrapper::getTable() declares,
	 * recording addColumn() calls (see ConsolidatedSchemaMigrationTest for why
	 * the type is read from the signature).
	 *
	 * @param array<string,array<string,mixed>> $added Receives name => options
	 * @param bool $exists Whether hasColumn() answers true
	 *
	 * @return object
	 */
	private function tableDouble(array &$added, bool $exists = false): object {
		$type = (new \ReflectionMethod(ISchemaWrapper::class, 'getTable'))->getReturnType();
		$class = ($type instanceof \ReflectionNamedType) ? $type->getName() : \stdClass::class;
		$mock = $this->getMockBuilder($class)->disableOriginalConstructor()->getMock();
		$mock->method('hasColumn')->willReturn($exists);
		$addReturn = (new \ReflectionMethod($class, 'addColumn'))->getReturnType();
		$mock->method('addColumn')->willReturnCallback(
			function (string $name, mixed $colType, array $options = []) use (&$added, $addReturn): mixed {
				$added[$name] = $options;
				if ($addReturn instanceof \ReflectionNamedType && $addReturn->isBuiltin() === false) {
					return $this->createMock($addReturn->getName());
				}

				return null;
			}
		);
		$selfReturn = static fn () => $mock;
		foreach (['setPrimaryKey', 'addIndex', 'addUniqueIndex'] as $method) {
			$returns = (new \ReflectionMethod($class, $method))->getReturnType();
			if ($returns instanceof \ReflectionNamedType && $returns->isBuiltin() === false) {
				$mock->method($method)->willReturnCallback($selfReturn);
			}
		}

		return $mock;
	}//end tableDouble()
}//end class
