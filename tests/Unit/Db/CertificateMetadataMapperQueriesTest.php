<?php

/**
 * CertificateMetadataMapper against a recording connection.
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

use OCA\Keepiq\Db\CertificateMetadata;
use OCA\Keepiq\Db\CertificateMetadataMapper;
use OCA\Keepiq\Tests\Support\RecordingDatabase;
use OCP\AppFramework\Db\DoesNotExistException;
use PHPUnit\Framework\TestCase;

/**
 * Certificate metadata is read per secret, listed per owner keyed by secret, and deleted per secret.
 *
 * @covers \OCA\Keepiq\Db\CertificateMetadataMapper
 * @uses   \OCA\Keepiq\Db\CertificateMetadata
 * @uses   \OCA\Keepiq\Db\StringPrimaryKey
 */
class CertificateMetadataMapperQueriesTest extends TestCase {
	use RecordingDatabase;

	/**
	 * The mapper over a recording connection.
	 *
	 * @return CertificateMetadataMapper
	 */
	private function mapper(): CertificateMetadataMapper {
		return new CertificateMetadataMapper($this->recordingConnection());
	}//end mapper()

	/**
	 * The row of one secret is read by its secret id.
	 *
	 * @return void
	 */
	public function testFindBySecretIdReadsTheSecretsRow(): void {
		$this->queueRows([['id' => 'm1', 'secret_id' => 's1', 'owner_id' => 'alice', 'subject' => 'CN=example']]);

		$row = $this->mapper()->findBySecretId(secretId: 's1');

		$this->assertSame('m1', $row->getId());
		$this->assertSame('CN=example', $row->getSubject());
		$this->assertSame(['select(*)', 'from(keepiq_certificate_metadata)', 'where(eq(secret_id, :p0))'], $this->opsOf(0));
		$this->assertSame([':p0' => 's1'], $this->paramsOf(0));
	}//end testFindBySecretIdReadsTheSecretsRow()

	/**
	 * A secret without metadata is not found.
	 *
	 * @return void
	 */
	public function testFindBySecretIdThrowsWhenThereIsNoRow(): void {
		$this->expectException(DoesNotExistException::class);

		$this->mapper()->findBySecretId(secretId: 'missing');
	}//end testFindBySecretIdThrowsWhenThereIsNoRow()

	/**
	 * An owner's rows come back keyed by their secret id.
	 *
	 * @return void
	 */
	public function testFindByOwnerKeysTheRowsBySecret(): void {
		$this->queueRows(
			[
				['id' => 'm1', 'secret_id' => 's1', 'owner_id' => 'alice'],
				['id' => 'm2', 'secret_id' => 's2', 'owner_id' => 'alice'],
			]
		);

		$rows = $this->mapper()->findByOwner(ownerId: 'alice');

		$this->assertSame(['s1', 's2'], array_keys($rows));
		$this->assertSame('m2', $rows['s2']->getId());
		$this->assertContainsOnlyInstancesOf(CertificateMetadata::class, $rows);
		$this->assertSame(['select(*)', 'from(keepiq_certificate_metadata)', 'where(eq(owner_id, :p0))'], $this->opsOf(0));
		$this->assertSame([':p0' => 'alice'], $this->paramsOf(0));
	}//end testFindByOwnerKeysTheRowsBySecret()

	/**
	 * An owner without rows gets an empty map.
	 *
	 * @return void
	 */
	public function testFindByOwnerWithoutRowsIsEmpty(): void {
		$this->assertSame([], $this->mapper()->findByOwner(ownerId: 'bob'));
	}//end testFindByOwnerWithoutRowsIsEmpty()

	/**
	 * Deleting by secret id runs one delete statement on that secret.
	 *
	 * @return void
	 */
	public function testDeleteBySecretIdRunsOneDelete(): void {
		$this->mapper()->deleteBySecretId(secretId: 's9');

		$this->assertSame(['delete(keepiq_certificate_metadata)', 'where(eq(secret_id, :p0))'], $this->opsOf(0));
		$this->assertSame([':p0' => 's9'], $this->paramsOf(0));
		$this->assertSame('statement', $this->ranOf(0));
	}//end testDeleteBySecretIdRunsOneDelete()
}//end class
