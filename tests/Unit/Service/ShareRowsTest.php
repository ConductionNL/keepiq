<?php

/**
 * FanOutShareRow, DirectShareRow and NewAttachmentGrant: one requested
 * share as the share flows read it, and the attachment grant row it writes.
 *
 * @category Test
 * @package  OCA\Keepiq\Tests\Unit\Service
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @link https://conduction.nl
 */

declare(strict_types=1);

namespace OCA\Keepiq\Tests\Unit\Service;

use OCA\Keepiq\Db\Secret;
use OCA\Keepiq\Service\DirectShareRow;
use OCA\Keepiq\Service\FanOutShareRow;
use OCA\Keepiq\Service\NewAttachmentGrant;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * The share rows.
 */
class ShareRowsTest extends TestCase {
	/**
	 * A complete fan-out row.
	 */
	private const ROW = ['sourceSecretId' => 's1', 'targetUserId' => 'bob', 'encryptedKey' => 'key-ct'];

	/**
	 * Rows that miss one of the three required parts.
	 *
	 * @return array<string, array{0: array<string, mixed>}>
	 */
	public static function incompleteRows(): array {
		return [
			'no source'   => [['sourceSecretId' => ''] + self::ROW],
			'no target'   => [['targetUserId' => ''] + self::ROW],
			'no key'      => [['encryptedKey' => ''] + self::ROW],
			'nothing'     => [[]],
		];
	}//end incompleteRows()

	/**
	 * A fan-out row is complete only with a source, a target and a key.
	 *
	 * @param array<string, mixed> $row The requested row.
	 *
	 * @return void
	 */
	#[DataProvider('incompleteRows')]
	public function testAFanOutRowNeedsAllThreeParts(array $row): void {
		$this->assertFalse(condition: (new FanOutShareRow(row: $row))->isComplete());
		$direct = new DirectShareRow(
			userId: 'anne',
			sourceSecretId: (string)($row['sourceSecretId'] ?? ''),
			targetUserId: (string)($row['targetUserId'] ?? ''),
			encryptedKey: (string)($row['encryptedKey'] ?? ''),
			row: $row
		);
		$this->assertTrue(condition: $direct->isIncomplete());
	}//end testAFanOutRowNeedsAllThreeParts()

	/**
	 * The recipient ciphertext carries the key and the optional fields, empty ones as null.
	 *
	 * @return void
	 */
	public function testTheCiphertextCarriesTheOptionalFields(): void {
		$extra = ['encryptedLogin' => 'login-ct', 'encryptedAdditionalFields' => '', 'encryptedTotp' => 12, 'encryptedPasskeys' => null];
		$row   = new FanOutShareRow(row: self::ROW + $extra);
		$this->assertTrue(condition: $row->isComplete());
		$ciphertext = $row->ciphertext();
		$this->assertSame(
			expected: ['bob', 'key-ct', 'login-ct', null, '12', null],
			actual: [$ciphertext->recipientId, $ciphertext->key, $ciphertext->login, $ciphertext->additionalFields, $ciphertext->totp, $ciphertext->passkeys]
		);
	}//end testTheCiphertextCarriesTheOptionalFields()

	/**
	 * A fan-out row reports a skip with its reason and a creation with the copy's id.
	 *
	 * @return void
	 */
	public function testAFanOutRowReportsItsOutcome(): void {
		$row = new FanOutShareRow(row: self::ROW);
		$pair = ['sourceSecretId' => 's1', 'targetUserId' => 'bob'];
		$this->assertSame(expected: $pair + ['reason' => 'no_public_key'], actual: $row->skipped(reason: 'no_public_key'));
		$this->assertSame(expected: $pair + ['recipientSecretId' => 'copy-1'], actual: $row->created(copyId: 'copy-1'));
	}//end testAFanOutRowReportsItsOutcome()

	/**
	 * A direct share row reports its outcome and carries the value fields as given.
	 *
	 * @return void
	 */
	public function testADirectShareRowReportsItsOutcome(): void {
		$row  = new DirectShareRow(userId: 'anne', sourceSecretId: 's1', targetUserId: 'bob', encryptedKey: 'key-ct', row: ['encryptedTotp' => 'totp-ct']);
		$copy = new Secret();
		$copy->setId('copy-1');
		$this->assertFalse(condition: $row->isIncomplete());
		$this->assertSame(expected: ['sourceSecretId' => 's1', 'targetUserId' => 'bob', 'status' => 'skipped'], actual: $row->outcome(status: 'skipped'));
		$this->assertSame(
			expected: ['sourceSecretId' => 's1', 'targetUserId' => 'bob', 'status' => 'created', 'recipientSecretId' => 'copy-1'],
			actual: $row->created(copy: $copy)
		);
		$this->assertSame(expected: ['totp' => 'totp-ct', 'passkeys' => null], actual: $row->carriedFields());
	}//end testADirectShareRowReportsItsOutcome()

	/**
	 * A new attachment grant becomes a row with a fresh id and every part it was given.
	 *
	 * @return void
	 */
	public function testANewAttachmentGrantBecomesARow(): void {
		$grant = new NewAttachmentGrant(
			attachmentId: 'att-1',
			secretId: 's1',
			recipientType: 'user',
			recipientId: 'bob',
			wrappedFileKey: 'wrapped',
			suiteId: null
		);
		$first  = $grant->toRow();
		$second = $grant->toRow();
		$this->assertMatchesRegularExpression(pattern: '/^[0-9a-f-]{36}$/', string: (string)$first->getId());
		$this->assertNotSame(expected: $first->getId(), actual: $second->getId());
		$this->assertSame(
			expected: ['att-1', 's1', 'user', 'bob', 'wrapped', null],
			actual: [
				$first->getAttachmentId(),
				$first->getSecretId(),
				$first->getRecipientType(),
				$first->getRecipientId(),
				$first->getWrappedFileKey(),
				$first->getEncryptionSuiteId(),
			]
		);
		$this->assertLessThanOrEqual(expected: time(), actual: $first->getCreatedAt()->getTimestamp());
	}//end testANewAttachmentGrantBecomesARow()
}//end class
