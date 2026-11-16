<?php

/**
 * SecretTag, SecretUrl and UsedProofNonce entities: hydration with declared types.
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

use OCA\Keepiq\Db\SecretTag;
use OCA\Keepiq\Db\SecretUrl;
use OCA\Keepiq\Db\UsedProofNonce;
use PHPUnit\Framework\TestCase;

/**
 * Database rows arrive as strings; each entity casts its columns to the types
 * it declares, so callers can compare positions and expiry times as integers.
 *
 * @covers \OCA\Keepiq\Db\SecretTag
 * @covers \OCA\Keepiq\Db\SecretUrl
 * @covers \OCA\Keepiq\Db\UsedProofNonce
 */
class SmallEntityHydrationTest extends TestCase {

	/**
	 * A tag row hydrates its holder, secret and tag, and marks nothing as changed.
	 *
	 * @return void
	 */
	public function testSecretTagFromRow(): void {
		$tag = SecretTag::fromRow(['id' => '4', 'secret_id' => 'sec-1', 'owner_id' => 'alice', 'tag' => 'work']);

		$this->assertSame(expected: 4, actual: $tag->getId());
		$this->assertSame(expected: 'sec-1', actual: $tag->getSecretId());
		$this->assertSame(expected: 'alice', actual: $tag->getOwnerId());
		$this->assertSame(expected: 'work', actual: $tag->getTag());
		$this->assertSame(expected: [], actual: $tag->getUpdatedFields());
	}//end testSecretTagFromRow()

	/**
	 * A url row casts its position to an integer.
	 *
	 * @return void
	 */
	public function testSecretUrlFromRowCastsThePosition(): void {
		$url = SecretUrl::fromRow(
			['id' => '5', 'secret_id' => 'sec-1', 'owner_id' => 'alice', 'url' => 'https://example.org', 'position' => '2']
		);

		$this->assertSame(expected: 'sec-1', actual: $url->getSecretId());
		$this->assertSame(expected: 'alice', actual: $url->getOwnerId());
		$this->assertSame(expected: 'https://example.org', actual: $url->getUrl());
		$this->assertSame(expected: 2, actual: $url->getPosition());
	}//end testSecretUrlFromRowCastsThePosition()

	/**
	 * A nonce row casts its expiry to an integer timestamp.
	 *
	 * @return void
	 */
	public function testUsedProofNonceFromRowCastsTheExpiry(): void {
		$nonce = UsedProofNonce::fromRow(['id' => '6', 'nonce_hash' => 'abc', 'expires_at' => '1700000000']);

		$this->assertSame(expected: 'abc', actual: $nonce->getNonceHash());
		$this->assertSame(expected: 1700000000, actual: $nonce->getExpiresAt());
	}//end testUsedProofNonceFromRowCastsTheExpiry()

	/**
	 * Setting a field marks it as updated, so the mapper writes it.
	 *
	 * @return void
	 */
	public function testASetFieldIsMarkedUpdated(): void {
		$nonce = new UsedProofNonce();
		$nonce->setNonceHash('h');
		$nonce->setExpiresAt(10);

		$this->assertSame(expected: ['nonceHash' => true, 'expiresAt' => true], actual: $nonce->getUpdatedFields());
	}//end testASetFieldIsMarkedUpdated()
}//end class
