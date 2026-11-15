<?php

/**
 * SecretTypeMapper against a recording connection.
 *
 * @category Test
 * @package  OCA\Keepiq\Tests\Unit\Db
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git-id>
 *
 * @link https://conduction.nl
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Keepiq\Tests\Unit\Db;

use OCA\Keepiq\Db\SecretType;
use OCA\Keepiq\Db\SecretTypeMapper;
use OCA\Keepiq\Tests\Support\RecordingDatabase;
use OCP\AppFramework\Db\DoesNotExistException;
use OCP\AppFramework\Db\MultipleObjectsReturnedException;
use PHPUnit\Framework\TestCase;

/**
 * The SQL each SecretTypeMapper query builds. A user sees system and global
 * types plus only their own user-scoped ones, sorted by label; names are
 * globally unique, so the by-name lookup is single-row and countable.
 *
 * @covers \OCA\Keepiq\Db\SecretTypeMapper
 * @covers \OCA\Keepiq\Db\FindsByStringId
 * @uses   \OCA\Keepiq\Db\SecretType
 * @uses   \OCA\Keepiq\Db\StringPrimaryKey
 */
class SecretTypeMapperQueriesTest extends TestCase {
	use RecordingDatabase;

	/**
	 * The mapper over the recording connection.
	 *
	 * @return SecretTypeMapper
	 */
	private function mapper(): SecretTypeMapper {
		return new SecretTypeMapper($this->recordingConnection());
	}//end mapper()

	/**
	 * Lookups by id and by name hydrate the single row.
	 *
	 * @return void
	 */
	public function testFindByIdAndByName(): void {
		$this->queueRows([['id' => 't1', 'name' => 'login', 'label' => 'Login', 'scope' => 'system']]);
		$this->queueRows([['id' => 't2', 'name' => 'card', 'scope' => 'user', 'owner_id' => 'alice']]);
		$mapper = $this->mapper();

		$byId = $mapper->findById(id: 't1');
		$byName = $mapper->findByName(name: 'card');

		$this->assertInstanceOf(SecretType::class, $byId);
		$this->assertSame('login', $byId->getName());
		$this->assertSame('Login', $byId->getLabel());
		$this->assertSame('system', $byId->getScope());
		$this->assertSame('t2', $byName->getId());
		$this->assertSame('alice', $byName->getOwnerId());
		$this->assertSame(['select(*)', 'from(keepiq_secret_types)', 'where(eq(id, :p0))'], $this->opsOf(0));
		$this->assertSame([':p0' => 't1'], $this->paramsOf(0));
		$this->assertSame(['select(*)', 'from(keepiq_secret_types)', 'where(eq(name, :p1))'], $this->opsOf(1));
		$this->assertSame([':p1' => 'card'], $this->paramsOf(1));
	}//end testFindByIdAndByName()

	/**
	 * An unknown name throws DoesNotExistException.
	 *
	 * @return void
	 */
	public function testFindByNameThrowsWhenMissing(): void {
		$this->expectException(DoesNotExistException::class);

		$this->mapper()->findByName(name: 'ghost');
	}//end testFindByNameThrowsWhenMissing()

	/**
	 * A duplicate id throws MultipleObjectsReturnedException.
	 *
	 * @return void
	 */
	public function testFindByIdThrowsOnDuplicates(): void {
		$this->queueRows([['id' => 't1'], ['id' => 't1']]);
		$this->expectException(MultipleObjectsReturnedException::class);

		$this->mapper()->findById(id: 't1');
	}//end testFindByIdThrowsOnDuplicates()

	/**
	 * System types are filtered on scope = system.
	 *
	 * @return void
	 */
	public function testFindSystemTypes(): void {
		$this->queueRows([['id' => 't1', 'scope' => 'system'], ['id' => 't3', 'scope' => 'system']]);

		$rows = $this->mapper()->findSystemTypes();

		$this->assertSame(['t1', 't3'], array_map(static fn (SecretType $t) => $t->getId(), $rows));
		$this->assertSame(['select(*)', 'from(keepiq_secret_types)', 'where(eq(scope, :p0))'], $this->opsOf(0));
		$this->assertSame([':p0' => 'system'], $this->paramsOf(0));
	}//end testFindSystemTypes()

	/**
	 * A user's available types: system or global or (user and owned), by label.
	 *
	 * @return void
	 */
	public function testFindAvailableForUser(): void {
		$this->queueRows([['id' => 't1', 'label' => 'A'], ['id' => 't9', 'label' => 'B']]);

		$rows = $this->mapper()->findAvailableForUser(userId: 'alice');

		$this->assertCount(2, $rows);
		$this->assertSame('t9', $rows[1]->getId());
		$this->assertSame(
			[
				'select(*)', 'from(keepiq_secret_types)',
				'where(orX(eq(scope, :p0), eq(scope, :p1), andX(eq(scope, :p2), eq(owner_id, :p3))))',
				'orderBy(label, ASC)',
			],
			$this->opsOf(0)
		);
		$this->assertSame([':p0' => 'system', ':p1' => 'global', ':p2' => 'user', ':p3' => 'alice'], $this->paramsOf(0));
	}//end testFindAvailableForUser()

	/**
	 * findByScope filters on the given scope.
	 *
	 * @return void
	 */
	public function testFindByScope(): void {
		$this->assertSame([], $this->mapper()->findByScope(scope: 'global'));

		$this->assertSame(['select(*)', 'from(keepiq_secret_types)', 'where(eq(scope, :p0))'], $this->opsOf(0));
		$this->assertSame([':p0' => 'global'], $this->paramsOf(0));
		$this->assertSame('query', $this->ranOf(0));
	}//end testFindByScope()

	/**
	 * countByName reads cnt and answers 0 without a row.
	 *
	 * @return void
	 */
	public function testCountByName(): void {
		$this->queueRows([['cnt' => '2']]);
		$mapper = $this->mapper();

		$this->assertSame(2, $mapper->countByName(name: 'login'));
		$this->assertSame(0, $mapper->countByName(name: 'none'));

		$this->assertSame(['select(count(*, cnt))', 'from(keepiq_secret_types)', 'where(eq(name, :p0))'], $this->opsOf(0));
		$this->assertSame([':p0' => 'login'], $this->paramsOf(0));
		$this->assertSame([':p1' => 'none'], $this->paramsOf(1));
	}//end testCountByName()
}//end class
