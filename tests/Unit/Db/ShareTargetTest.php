<?php

/**
 * Unit tests for ShareTarget entity.
 *
 * @category Test
 * @package  OCA\Keepiq\Tests\Unit\Db
 *
 * @author    Conduction Development Team <dev@conductio.nl>
 * @copyright 2024 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git-id>
 *
 * @link https://conduction.nl
 */

declare(strict_types=1);

namespace OCA\Keepiq\Tests\Unit\Db;

use DateTime;
use OCA\Keepiq\Db\Secret;
use OCA\Keepiq\Db\ShareTarget;
use PHPUnit\Framework\TestCase;

/**
 * Tests for ShareTarget entity.
 */
class ShareTargetTest extends TestCase {
	/**
	 * Test that the constructor sets sensible defaults.
	 *
	 * @return void
	 */
	public function testConstructorSetsDefaults(): void {
		$entity = new ShareTarget();

		$this->assertSame('', $entity->getSourceSecretId());
		$this->assertSame('', $entity->getTargetUserId());
		$this->assertSame('', $entity->getSecretId());
		$this->assertNull($entity->getGroupShareId());
		$this->assertSame('', $entity->getCreatedBy());
		$this->assertNull($entity->getCreatedAt());
		$this->assertSame('', $entity->getId());
	}//end testConstructorSetsDefaults()

	/**
	 * Test getters and setters round-trip every field.
	 *
	 * @return void
	 */
	public function testGettersAndSetters(): void {
		$entity = new ShareTarget();

		$entity->setId('st-uuid-1');
		$this->assertSame('st-uuid-1', $entity->getId());

		$entity->setSourceSecretId('src-1');
		$this->assertSame('src-1', $entity->getSourceSecretId());

		$entity->setTargetUserId('bob');
		$this->assertSame('bob', $entity->getTargetUserId());

		$entity->setSecretId('copy-1');
		$this->assertSame('copy-1', $entity->getSecretId());

		$entity->setGroupShareId('gs-1');
		$this->assertSame('gs-1', $entity->getGroupShareId());

		$entity->setCreatedBy('alice');
		$this->assertSame('alice', $entity->getCreatedBy());

		$createdAt = new DateTime('2026-01-01T00:00:00+00:00');
		$entity->setCreatedAt($createdAt);
		$this->assertSame($createdAt, $entity->getCreatedAt());
	}//end testGettersAndSetters()

	/**
	 * Test jsonSerialize emits the expected keys.
	 *
	 * @return void
	 */
	public function testJsonSerializeIncludesAllFields(): void {
		$entity = new ShareTarget();
		$entity->setId('st-1');
		$entity->setSourceSecretId('src-1');
		$entity->setTargetUserId('bob');
		$entity->setSecretId('copy-1');
		$entity->setGroupShareId(null);
		$entity->setCreatedBy('alice');
		$entity->setCreatedAt(new DateTime('2026-02-01T10:00:00+00:00'));

		$serialized = $entity->jsonSerialize();

		$this->assertSame('st-1', $serialized['id']);
		$this->assertSame('src-1', $serialized['sourceSecretId']);
		$this->assertSame('bob', $serialized['targetUserId']);
		$this->assertSame('copy-1', $serialized['secretId']);
		$this->assertNull($serialized['groupShareId']);
		$this->assertSame('alice', $serialized['createdBy']);
		$this->assertNotNull($serialized['createdAt']);
	}//end testJsonSerializeIncludesAllFields()

	/**
	 * Test the createdAt field formats in ISO-8601 with offset.
	 *
	 * @return void
	 */
	public function testJsonSerializeCreatedAtFormat(): void {
		$entity = new ShareTarget();
		$createdAt = new DateTime('2026-06-15T14:30:00+02:00');
		$entity->setCreatedAt($createdAt);

		$serialized = $entity->jsonSerialize();

		$this->assertSame($createdAt->format('c'), $serialized['createdAt']);
	}//end testJsonSerializeCreatedAtFormat()

	/**
	 * Test group-share linkage is optional and round-trips correctly.
	 *
	 * @return void
	 */
	public function testGroupShareIdIsOptional(): void {
		$entity = new ShareTarget();
		$this->assertNull($entity->getGroupShareId());

		$entity->setGroupShareId('gs-99');
		$this->assertSame('gs-99', $entity->getGroupShareId());

		$entity->setGroupShareId(null);
		$this->assertNull($entity->getGroupShareId());
	}//end testGroupShareIdIsOptional()

	/**
	 * Build a secret for the ownership-guard tests.
	 *
	 * @param string $id        The id
	 * @param string $ownerType The owner type
	 * @param string $ownerId   The owner id
	 *
	 * @return Secret
	 */
	private function secret(string $id, string $ownerType, string $ownerId): Secret {
		$secret = new Secret();
		$secret->setId($id);
		$secret->setOwnerType($ownerType);
		$secret->setOwnerId($ownerId);
		return $secret;
	}//end secret()

	/**
	 * isHeldByTarget is true only when the copy is the one named, user-owned,
	 * and owned by the row's target (GHSA-m5p9-6pw7-pf3g).
	 *
	 * @return void
	 */
	public function testIsHeldByTargetRecognisesAGenuineRecipientCopy(): void {
		$row = new ShareTarget();
		$row->setSecretId('copy-1');
		$row->setTargetUserId('bob');

		$this->assertTrue($row->isHeldByTarget($this->secret('copy-1', 'user', 'bob')));
	}//end testIsHeldByTargetRecognisesAGenuineRecipientCopy()

	/**
	 * A copy owned by another user, a team-owned copy, or a different id is not
	 * held by the target — the cross-user corruption the guard refuses.
	 *
	 * @return void
	 */
	public function testIsHeldByTargetRefusesACopyNotHeldByTheTarget(): void {
		$row = new ShareTarget();
		$row->setSecretId('copy-1');
		$row->setTargetUserId('bob');

		$this->assertFalse($row->isHeldByTarget($this->secret('copy-1', 'user', 'alice')), 'owned by another user');
		$this->assertFalse($row->isHeldByTarget($this->secret('copy-1', 'team', 'bob')), 'team-owned');
		$this->assertFalse($row->isHeldByTarget($this->secret('other', 'user', 'bob')), 'a different copy id');
	}//end testIsHeldByTargetRefusesACopyNotHeldByTheTarget()

	/**
	 * The guard does NOT stop the core advisory case where the target IS the
	 * owner of a pre-existing secret — that is closed by retiring the
	 * client-supplied-id create path. Here it returns true.
	 *
	 * @return void
	 */
	public function testIsHeldByTargetDoesNotStopTheTargetEqualsOwnerCase(): void {
		$row = new ShareTarget();
		$row->setSecretId('alices-own');
		$row->setTargetUserId('alice');

		$this->assertTrue($row->isHeldByTarget($this->secret('alices-own', 'user', 'alice')));
	}//end testIsHeldByTargetDoesNotStopTheTargetEqualsOwnerCase()
}//end class
