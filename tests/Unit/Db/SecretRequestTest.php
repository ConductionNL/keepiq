<?php

/**
 * Unit tests for SecretRequest entity.
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
use OCA\Keepiq\Db\SecretRequest;
use PHPUnit\Framework\TestCase;

/**
 * Tests for SecretRequest entity.
 */
class SecretRequestTest extends TestCase {
	/**
	 * Test default values from the constructor.
	 *
	 * @return void
	 */
	public function testConstructorSetsDefaults(): void {
		$entity = new SecretRequest();

		$this->assertSame('', $entity->getSecretId());
		$this->assertSame('', $entity->getEncryptionSuiteId());
		$this->assertSame('', $entity->getToken());
		$this->assertSame('[]', $entity->getRequestedFields());
		$this->assertSame(SecretRequest::STATUS_PENDING, $entity->getStatus());
		$this->assertFalse($entity->getIsReRequest());
		$this->assertNull($entity->getExpiresAt());
		$this->assertNull($entity->getFulfilledAt());
		$this->assertNull($entity->getCreatedAt());
	}//end testConstructorSetsDefaults()

	/**
	 * Test all getters and setters round-trip.
	 *
	 * @return void
	 */
	public function testGettersAndSetters(): void {
		$entity = new SecretRequest();

		$entity->setId('req-1');
		$this->assertSame('req-1', $entity->getId());

		$entity->setSecretId('sec-1');
		$this->assertSame('sec-1', $entity->getSecretId());

		$entity->setEncryptionSuiteId('suite-1');
		$this->assertSame('suite-1', $entity->getEncryptionSuiteId());

		$entity->setToken('tok-deadbeef');
		$this->assertSame('tok-deadbeef', $entity->getToken());

		$entity->setRequestedFields('["password","login"]');
		$this->assertSame('["password","login"]', $entity->getRequestedFields());

		$entity->setStatus(SecretRequest::STATUS_FULFILLED);
		$this->assertSame('fulfilled', $entity->getStatus());

		$entity->setIsReRequest(true);
		$this->assertTrue($entity->getIsReRequest());

		$expires = new DateTime('2026-12-31T23:59:59+00:00');
		$entity->setExpiresAt($expires);
		$this->assertSame($expires, $entity->getExpiresAt());

		$entity->setCreatedBy('alice');
		$this->assertSame('alice', $entity->getCreatedBy());

		$createdAt = new DateTime('2026-06-11T10:00:00+00:00');
		$entity->setCreatedAt($createdAt);
		$this->assertSame($createdAt, $entity->getCreatedAt());

		$fulfilledAt = new DateTime('2026-06-11T11:00:00+00:00');
		$entity->setFulfilledAt($fulfilledAt);
		$this->assertSame($fulfilledAt, $entity->getFulfilledAt());
	}//end testGettersAndSetters()

	/**
	 * Test isExpired returns false when no expiry is set.
	 *
	 * @return void
	 */
	public function testIsExpiredFalseWhenNoExpiry(): void {
		$entity = new SecretRequest();
		$this->assertFalse($entity->isExpired());
	}//end testIsExpiredFalseWhenNoExpiry()

	/**
	 * Test isExpired returns true when expiry is in the past.
	 *
	 * @return void
	 */
	public function testIsExpiredTrueWhenPast(): void {
		$entity = new SecretRequest();
		$entity->setExpiresAt(new DateTime('2000-01-01T00:00:00+00:00'));
		$this->assertTrue($entity->isExpired());
	}//end testIsExpiredTrueWhenPast()

	/**
	 * Test isExpired returns false when expiry is in the future.
	 *
	 * @return void
	 */
	public function testIsExpiredFalseWhenFuture(): void {
		$entity = new SecretRequest();
		$entity->setExpiresAt(new DateTime('+1 year'));
		$this->assertFalse($entity->isExpired());
	}//end testIsExpiredFalseWhenFuture()

	/**
	 * Test jsonSerialize decodes requestedFields into an array.
	 *
	 * @return void
	 */
	public function testJsonSerializeDecodesRequestedFields(): void {
		$entity = new SecretRequest();
		$entity->setId('req-1');
		$entity->setSecretId('sec-1');
		$entity->setEncryptionSuiteId('suite-1');
		$entity->setToken('tok-1');
		$entity->setRequestedFields('["password","login","note"]');
		$entity->setStatus(SecretRequest::STATUS_PENDING);
		$entity->setIsReRequest(true);
		$entity->setCreatedBy('alice');
		$entity->setCreatedAt(new DateTime('2026-06-11T10:00:00+00:00'));

		$serialized = $entity->jsonSerialize();

		$this->assertSame(['password', 'login', 'note'], $serialized['requestedFields']);
		$this->assertSame('pending', $serialized['status']);
		$this->assertTrue($serialized['isReRequest']);
		$this->assertSame('alice', $serialized['createdBy']);
		$this->assertNotNull($serialized['createdAt']);
		$this->assertNull($serialized['fulfilledAt']);
	}//end testJsonSerializeDecodesRequestedFields()

	/**
	 * Test jsonSerialize falls back to an empty array when requestedFields is invalid JSON.
	 *
	 * @return void
	 */
	public function testJsonSerializeFallsBackOnInvalidJson(): void {
		$entity = new SecretRequest();
		$entity->setRequestedFields('not-json');

		$serialized = $entity->jsonSerialize();

		$this->assertSame([], $serialized['requestedFields']);
	}//end testJsonSerializeFallsBackOnInvalidJson()
}//end class
