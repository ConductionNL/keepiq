<?php

/**
 * SecretListFilter narrows and organises a secret listing.
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

use InvalidArgumentException;
use OCA\Keepiq\Db\SecretListFilter;
use OCA\Keepiq\Db\SecretListOrganisation;
use OCA\Keepiq\Db\SecretMapper;
use OCA\Keepiq\Tests\Support\RecordingDatabase;
use PHPUnit\Framework\TestCase;

/**
 * The owner always narrows; folder, type and state only when given.
 *
 * @covers \OCA\Keepiq\Db\SecretListFilter
 * @uses   \OCA\Keepiq\Db\SecretStateFilter
 * @uses   \OCA\Keepiq\Db\SecretListOrganisation
 */
class SecretListFilterTest extends TestCase {
	use RecordingDatabase;

	/**
	 * A filter with the given optional parts.
	 *
	 * @param string|null $folderId The folder, or null.
	 * @param string|null $typeId   The type, or null.
	 * @param string|null $state    The state, or null.
	 *
	 * @return SecretListFilter
	 */
	private function filter(?string $folderId=null, ?string $typeId=null, ?string $state=null): SecretListFilter {
		return new SecretListFilter(
			ownerType: 'user',
			ownerId: 'alice',
			folderId: $folderId,
			typeId: $typeId,
			state: $state,
			favourite: null,
			tag: null
		);
	}//end filter()

	/**
	 * Without optional parts only the owner narrows.
	 *
	 * @return void
	 */
	public function testNarrowWithOnlyTheOwner(): void {
		$this->filter()->narrow(qb: $this->recordingQueryBuilder());

		$this->assertSame(['where(eq(owner_type, :p0))', 'andWhere(eq(owner_id, :p1))'], $this->opsOf(0));
		$this->assertSame([':p0' => 'user', ':p1' => 'alice'], $this->paramsOf(0));
	}//end testNarrowWithOnlyTheOwner()

	/**
	 * Folder, type and the live state each add their conditions after the owner.
	 *
	 * @return void
	 */
	public function testNarrowWithFolderTypeAndState(): void {
		$this->filter(folderId: 'f1', typeId: 't1', state: SecretMapper::STATE_LIVE)->narrow(qb: $this->recordingQueryBuilder());

		$this->assertSame(
			[
				'where(eq(owner_type, :p0))', 'andWhere(eq(owner_id, :p1))', 'andWhere(eq(folder_id, :p2))',
				'andWhere(eq(type_id, :p3))', 'andWhere(isNull(trashed_at))', 'andWhere(isNull(archived_at))',
			],
			$this->opsOf(0)
		);
		$this->assertSame([':p0' => 'user', ':p1' => 'alice', ':p2' => 'f1', ':p3' => 't1'], $this->paramsOf(0));
	}//end testNarrowWithFolderTypeAndState()

	/**
	 * An unknown state is refused rather than ignored.
	 *
	 * @return void
	 */
	public function testNarrowRefusesAnUnknownState(): void {
		$this->expectException(InvalidArgumentException::class);

		$this->filter(state: 'bogus')->narrow(qb: $this->recordingQueryBuilder());
	}//end testNarrowRefusesAnUnknownState()

	/**
	 * Organising hands back the organisation it applied.
	 *
	 * @return void
	 */
	public function testOrganiseReturnsTheOrganisation(): void {
		$this->assertInstanceOf(SecretListOrganisation::class, $this->filter()->organise(qb: $this->recordingQueryBuilder()));
	}//end testOrganiseReturnsTheOrganisation()
}//end class
