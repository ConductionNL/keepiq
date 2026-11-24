<?php

/**
 * SecretTypeMatch narrows a secret listing to one type.
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

use OCA\Keepiq\Db\SecretTypeMatch;
use OCA\Keepiq\Tests\Support\RecordingDatabase;
use PHPUnit\Framework\TestCase;

/**
 * Any type matches on its id; the Passkey type also takes the rows holding
 * passkey ciphertext.
 *
 * @covers \OCA\Keepiq\Db\SecretTypeMatch
 *
 * @spec openspec/specs/login-one-time-codes/spec.md#requirement-the-passkey-type-filter-also-finds-logins-with-passkeys
 */
class SecretTypeMatchTest extends TestCase {
	use RecordingDatabase;

	/**
	 * Narrow a fresh query to the type, with the type's name queued for the lookup.
	 *
	 * @param string|null $name The name the lookup answers, null for no such type.
	 *
	 * @return void
	 */
	private function narrowTo(?string $name): void {
		$rows = [];
		if ($name !== null) {
			$rows = [['name' => $name]];
		}

		$this->queueRows(rows: $rows);
		$qb = $this->recordingQueryBuilder();
		(new SecretTypeMatch(db: $this->recordingConnection()))->narrow(qb: $qb, typeId: 't1');
	}//end narrowTo()

	/**
	 * On the Passkey type, rows with passkey ciphertext match as well.
	 *
	 * @return void
	 */
	public function testThePasskeyTypeAlsoMatchesRowsWithPasskeys(): void {
		$this->narrowTo(name: 'passkey');

		$this->assertSame(
			expected: ['andWhere(orX(eq(type_id, :p0), andX(isNotNull(passkeys), neq(passkeys, :p2))))'],
			actual: $this->opsOf(index: 0)
		);
		$this->assertSame(expected: [':p0' => 't1', ':p2' => ''], actual: $this->paramsOf(index: 0));
		$this->assertSame(
			expected: ['select(name)', 'from(keepiq_secret_types)', 'where(eq(id, :p1))', 'setMaxResults(1)'],
			actual: $this->opsOf(index: 1)
		);
		$this->assertSame(expected: [':p1' => 't1'], actual: $this->paramsOf(index: 1));
	}//end testThePasskeyTypeAlsoMatchesRowsWithPasskeys()

	/**
	 * Any other type matches on its id alone.
	 *
	 * @return void
	 */
	public function testAnotherTypeMatchesItsIdAlone(): void {
		$this->narrowTo(name: 'login');

		$this->assertSame(expected: ['andWhere(eq(type_id, :p0))'], actual: $this->opsOf(index: 0));
	}//end testAnotherTypeMatchesItsIdAlone()

	/**
	 * An unknown type id matches on its id alone, never widened.
	 *
	 * @return void
	 */
	public function testAnUnknownTypeMatchesItsIdAlone(): void {
		$this->narrowTo(name: null);

		$this->assertSame(expected: ['andWhere(eq(type_id, :p0))'], actual: $this->opsOf(index: 0));
	}//end testAnUnknownTypeMatchesItsIdAlone()
}//end class
