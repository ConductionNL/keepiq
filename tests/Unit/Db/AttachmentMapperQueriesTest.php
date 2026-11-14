<?php

/**
 * AttachmentMapper against a recording connection.
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

use OCA\Keepiq\Db\Attachment;
use OCA\Keepiq\Db\AttachmentMapper;
use OCA\Keepiq\Tests\Support\RecordingDatabase;
use OCP\AppFramework\Db\DoesNotExistException;
use OCP\AppFramework\Db\MultipleObjectsReturnedException;
use PHPUnit\Framework\TestCase;

/**
 * The SQL each AttachmentMapper query builds: attachments by id and by the
 * secret they hang off, and the storage quota sum over the attachments of
 * the secrets a user personally owns, where no attachments sums to zero.
 *
 * @covers \OCA\Keepiq\Db\AttachmentMapper
 * @covers \OCA\Keepiq\Db\FindsByStringId
 * @uses   \OCA\Keepiq\Db\Attachment
 * @uses   \OCA\Keepiq\Db\StringPrimaryKey
 */
class AttachmentMapperQueriesTest extends TestCase {
	use RecordingDatabase;

	/**
	 * The mapper over the recording connection.
	 *
	 * @return AttachmentMapper
	 */
	private function mapper(): AttachmentMapper {
		return new AttachmentMapper(db: $this->recordingConnection());
	}//end mapper()

	/**
	 * By id hydrates the row, size typed as an integer; no row throws.
	 *
	 * @return void
	 */
	public function testFindById(): void {
		$this->queueRows(rows: [['id' => 'a1', 'blob_ref' => 'blob-1', 'size_bytes' => '2048']]);
		$mapper = $this->mapper();

		$attachment = $mapper->findById(id: 'a1');

		$this->assertSame(expected: 'a1', actual: $attachment->getId());
		$this->assertSame(expected: 'blob-1', actual: $attachment->getBlobRef());
		$this->assertSame(expected: 2048, actual: $attachment->getSizeBytes());
		$this->assertSame(expected: ['select(*)', 'from(keepiq_attachments)', 'where(eq(id, :p0))'], actual: $this->opsOf(index: 0));
		$this->assertSame(expected: [':p0' => 'a1'], actual: $this->paramsOf(index: 0));

		$this->expectException(exception: DoesNotExistException::class);
		$mapper->findById(id: 'gone');
	}//end testFindById()

	/**
	 * Two rows under one id is ambiguous.
	 *
	 * @return void
	 */
	public function testFindByIdRefusesTwoRows(): void {
		$this->queueRows(rows: [['id' => 'a1'], ['id' => 'a1']]);

		$this->expectException(exception: MultipleObjectsReturnedException::class);
		$this->mapper()->findById(id: 'a1');
	}//end testFindByIdRefusesTwoRows()

	/**
	 * A secret's attachments filter on the source secret.
	 *
	 * @return void
	 */
	public function testFindBySourceSecret(): void {
		$this->queueRows(rows: [['id' => 'a1', 'source_secret_id' => 's1'], ['id' => 'a2', 'source_secret_id' => 's1']]);

		$attachments = $this->mapper()->findBySourceSecret(sourceSecretId: 's1');

		$this->assertSame(expected: ['a1', 'a2'], actual: array_map(static fn (Attachment $a): string => $a->getId(), $attachments));
		$this->assertSame(expected: 's1', actual: $attachments[1]->getSourceSecretId());
		$this->assertSame(expected: ['select(*)', 'from(keepiq_attachments)', 'where(eq(source_secret_id, :p0))'], actual: $this->opsOf(index: 0));
		$this->assertSame(expected: [':p0' => 's1'], actual: $this->paramsOf(index: 0));
	}//end testFindBySourceSecret()

	/**
	 * The owner's byte sum joins the personally owned secrets and casts to int.
	 *
	 * @return void
	 */
	public function testSumBytesForOwnerJoinsPersonallyOwnedSecrets(): void {
		$this->queueRows(rows: [['total' => '4096']]);

		$this->assertSame(expected: 4096, actual: $this->mapper()->sumBytesForOwner(ownerId: 'alice'));

		$this->assertSame(
			expected: [
				'select(sum(a.size_bytes))', 'from(keepiq_attachments, a)',
				'innerJoin(a, keepiq_secrets, s, eq(a.source_secret_id, s.id))',
				'where(eq(s.owner_type, :p0))', 'andWhere(eq(s.owner_id, :p1))',
			],
			actual: $this->opsOf(index: 0)
		);
		$this->assertSame(expected: [':p0' => 'user', ':p1' => 'alice'], actual: $this->paramsOf(index: 0));
		$this->assertSame(expected: 'query', actual: $this->ranOf(index: 0));
	}//end testSumBytesForOwnerJoinsPersonallyOwnedSecrets()

	/**
	 * No row, or a NULL SUM over no attachments, counts as zero bytes.
	 *
	 * @return void
	 */
	public function testSumBytesForOwnerWithNothingIsZero(): void {
		$this->queueRows(rows: []);
		$this->queueRows(rows: [['total' => null]]);
		$mapper = $this->mapper();

		$this->assertSame(expected: 0, actual: $mapper->sumBytesForOwner(ownerId: 'bob'));
		$this->assertSame(expected: 0, actual: $mapper->sumBytesForOwner(ownerId: 'carol'));
	}//end testSumBytesForOwnerWithNothingIsZero()
}//end class
