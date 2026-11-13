<?php

/**
 * AttachmentGrantMapper against a recording connection.
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

use OCA\Keepiq\Db\AttachmentGrant;
use OCA\Keepiq\Db\AttachmentGrantMapper;
use OCA\Keepiq\Tests\Support\RecordingDatabase;
use OCP\AppFramework\Db\DoesNotExistException;
use PHPUnit\Framework\TestCase;

/**
 * The SQL each AttachmentGrantMapper query builds: lookups by id, attachment,
 * secret and recipient; the counts the migration flow reads (including the one
 * that leaves out grants on secrets whose migration already failed); and the
 * stable, bounded page of a recipient's grants on a suite.
 *
 * @covers \OCA\Keepiq\Db\AttachmentGrantMapper
 * @covers \OCA\Keepiq\Db\FindsByStringId
 * @uses   \OCA\Keepiq\Db\AttachmentGrant
 * @uses   \OCA\Keepiq\Db\StringPrimaryKey
 */
class AttachmentGrantMapperQueriesTest extends TestCase {
	use RecordingDatabase;

	/**
	 * The mapper over the recording connection.
	 *
	 * @return AttachmentGrantMapper
	 */
	private function mapper(): AttachmentGrantMapper {
		return new AttachmentGrantMapper(db: $this->recordingConnection());
	}//end mapper()

	/**
	 * Lookup by id hydrates the row; no row throws.
	 *
	 * @return void
	 */
	public function testFindById(): void {
		$this->queueRows(rows: [['id' => 'g1', 'attachment_id' => 'a1', 'wrapped_file_key' => 'wk']]);
		$mapper = $this->mapper();

		$grant = $mapper->findById(id: 'g1');

		$this->assertSame(expected: 'g1', actual: $grant->getId());
		$this->assertSame(expected: 'a1', actual: $grant->getAttachmentId());
		$this->assertSame(expected: 'wk', actual: $grant->getWrappedFileKey());
		$this->assertSame(expected: ['select(*)', 'from(keepiq_attachment_grants)', 'where(eq(id, :p0))'], actual: $this->opsOf(index: 0));
		$this->assertSame(expected: [':p0' => 'g1'], actual: $this->paramsOf(index: 0));

		$this->expectException(exception: DoesNotExistException::class);
		$mapper->findById(id: 'gone');
	}//end testFindById()

	/**
	 * An attachment's and a secret's grants, every row hydrated.
	 *
	 * @return void
	 */
	public function testFindByAttachmentAndBySecret(): void {
		$this->queueRows(rows: [['id' => 'g1'], ['id' => 'g2']]);
		$this->queueRows(rows: [['id' => 'g3', 'secret_id' => 's1']]);
		$mapper = $this->mapper();

		$byAttachment = $mapper->findByAttachment(attachmentId: 'a1');
		$bySecret = $mapper->findBySecret(secretId: 's1');

		$this->assertSame(expected: ['g1', 'g2'], actual: array_map(static fn (AttachmentGrant $g): string => $g->getId(), $byAttachment));
		$this->assertSame(expected: 's1', actual: $bySecret[0]->getSecretId());
		$this->assertSame(expected: ['select(*)', 'from(keepiq_attachment_grants)', 'where(eq(attachment_id, :p0))'], actual: $this->opsOf(index: 0));
		$this->assertSame(expected: ['select(*)', 'from(keepiq_attachment_grants)', 'where(eq(secret_id, :p1))'], actual: $this->opsOf(index: 1));
		$this->assertSame(expected: [':p0' => 'a1'], actual: $this->paramsOf(index: 0));
		$this->assertSame(expected: [':p1' => 's1'], actual: $this->paramsOf(index: 1));
	}//end testFindByAttachmentAndBySecret()

	/**
	 * A recipient's grant on an attachment is one row; none throws.
	 *
	 * @return void
	 */
	public function testFindForRecipient(): void {
		$this->queueRows(rows: [['id' => 'g4', 'recipient_id' => 'bob']]);
		$mapper = $this->mapper();

		$this->assertSame(expected: 'bob', actual: $mapper->findForRecipient(attachmentId: 'a1', recipientId: 'bob')->getRecipientId());

		$this->assertSame(
			expected: [
				'select(*)', 'from(keepiq_attachment_grants)', 'where(eq(attachment_id, :p0))',
				'andWhere(eq(recipient_id, :p1))', 'setMaxResults(1)',
			],
			actual: $this->opsOf(index: 0)
		);
		$this->assertSame(expected: [':p0' => 'a1', ':p1' => 'bob'], actual: $this->paramsOf(index: 0));

		$this->expectException(exception: DoesNotExistException::class);
		$mapper->findForRecipient(attachmentId: 'a1', recipientId: 'eve');
	}//end testFindForRecipient()

	/**
	 * The attachment count reads the aliased count; no row counts as zero.
	 *
	 * @return void
	 */
	public function testCountByAttachment(): void {
		$this->queueRows(rows: [['cnt' => '3']]);
		$mapper = $this->mapper();

		$this->assertSame(expected: 3, actual: $mapper->countByAttachment(attachmentId: 'a1'));
		$this->assertSame(expected: 0, actual: $mapper->countByAttachment(attachmentId: 'a2'));

		$this->assertSame(
			expected: ['select(count(*, cnt))', 'from(keepiq_attachment_grants)', 'where(eq(attachment_id, :p0))'],
			actual: $this->opsOf(index: 0)
		);
		$this->assertSame(expected: [':p0' => 'a1'], actual: $this->paramsOf(index: 0));
		$this->assertSame(expected: 'query', actual: $this->ranOf(index: 0));
	}//end testCountByAttachment()

	/**
	 * A recipient's grants on a suite are counted by suite, type and id.
	 *
	 * @return void
	 */
	public function testCountBySuiteForRecipient(): void {
		$this->queueRows(rows: [['cnt' => 7]]);
		$mapper = $this->mapper();

		$this->assertSame(expected: 7, actual: $mapper->countBySuiteForRecipient(encryptionSuiteId: 'suite-1', recipientType: 'user', recipientId: 'bob'));
		$this->assertSame(expected: 0, actual: $mapper->countBySuiteForRecipient(encryptionSuiteId: 'suite-1', recipientType: 'user', recipientId: 'eve'));

		$this->assertSame(
			expected: [
				'select(count(*, cnt))', 'from(keepiq_attachment_grants)', 'where(eq(encryption_suite_id, :p0))',
				'andWhere(eq(recipient_type, :p1))', 'andWhere(eq(recipient_id, :p2))',
			],
			actual: $this->opsOf(index: 0)
		);
		$this->assertSame(expected: [':p0' => 'suite-1', ':p1' => 'user', ':p2' => 'bob'], actual: $this->paramsOf(index: 0));
	}//end testCountBySuiteForRecipient()

	/**
	 * The unaccounted count joins the secret and leaves out failed migrations.
	 *
	 * @return void
	 */
	public function testCountUnaccountedBySuiteForRecipient(): void {
		$this->queueRows(rows: [['cnt' => '2']]);
		$mapper = $this->mapper();

		$this->assertSame(
			expected: 2,
			actual: $mapper->countUnaccountedBySuiteForRecipient(encryptionSuiteId: 'suite-1', recipientType: 'group', recipientId: 'staff')
		);
		$this->assertSame(
			expected: 0,
			actual: $mapper->countUnaccountedBySuiteForRecipient(encryptionSuiteId: 'suite-1', recipientType: 'group', recipientId: 'none')
		);

		$this->assertSame(
			expected: [
				'select(count(*, cnt))', 'from(keepiq_attachment_grants, g)',
				'innerJoin(g, keepiq_secrets, s, eq(g.secret_id, s.id))',
				'where(eq(g.encryption_suite_id, :p0))', 'andWhere(eq(g.recipient_type, :p1))',
				'andWhere(eq(g.recipient_id, :p2))', 'andWhere(isNull(s.migration_error))',
			],
			actual: $this->opsOf(index: 0)
		);
		$this->assertSame(expected: [':p0' => 'suite-1', ':p1' => 'group', ':p2' => 'staff'], actual: $this->paramsOf(index: 0));
	}//end testCountUnaccountedBySuiteForRecipient()

	/**
	 * The page is ordered by id and clamps a non-positive limit and negative offset.
	 *
	 * @return void
	 */
	public function testFindBySuiteForRecipientPagesStably(): void {
		$this->queueRows(rows: [['id' => 'g1'], ['id' => 'g2']]);
		$mapper = $this->mapper();

		$page = $mapper->findBySuiteForRecipient(encryptionSuiteId: 'suite-1', recipientType: 'user', recipientId: 'bob');
		$mapper->findBySuiteForRecipient(encryptionSuiteId: 'suite-1', recipientType: 'user', recipientId: 'bob', limit: 0, offset: -5);
		$mapper->findBySuiteForRecipient(encryptionSuiteId: 'suite-1', recipientType: 'user', recipientId: 'bob', limit: 20, offset: 40);

		$this->assertSame(expected: ['g1', 'g2'], actual: array_map(static fn (AttachmentGrant $g): string => $g->getId(), $page));
		$this->assertSame(
			expected: [
				'select(*)', 'from(keepiq_attachment_grants)', 'where(eq(encryption_suite_id, :p0))',
				'andWhere(eq(recipient_type, :p1))', 'andWhere(eq(recipient_id, :p2))', 'orderBy(id, ASC)',
				'setMaxResults(100)', 'setFirstResult(0)',
			],
			actual: $this->opsOf(index: 0)
		);
		$this->assertSame(expected: [':p0' => 'suite-1', ':p1' => 'user', ':p2' => 'bob'], actual: $this->paramsOf(index: 0));
		$this->assertSame(expected: ['setMaxResults(1)', 'setFirstResult(0)'], actual: array_slice($this->opsOf(index: 1), 6));
		$this->assertSame(expected: ['setMaxResults(20)', 'setFirstResult(40)'], actual: array_slice($this->opsOf(index: 2), 6));
	}//end testFindBySuiteForRecipientPagesStably()
}//end class
