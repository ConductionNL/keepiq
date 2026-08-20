<?php

/**
 * Unit tests for GroupShare entity.
 *
 * @category Test
 * @package  OCA\Doriath\Tests\Unit\Db
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

namespace OCA\Doriath\Tests\Unit\Db;

use DateTime;
use OCA\Doriath\Db\GroupShare;
use PHPUnit\Framework\TestCase;

/**
 * Tests for GroupShare entity.
 */
class GroupShareTest extends TestCase {
	/**
	 * Default field values after construction.
	 *
	 * @return void
	 */
	public function testConstructorSetsDefaults(): void {
		$entity = new GroupShare();

		$this->assertSame('', $entity->getId());
		$this->assertSame('', $entity->getSecretId());
		$this->assertSame('', $entity->getGroupId());
		$this->assertSame('', $entity->getCreatedBy());
		$this->assertNull($entity->getCreatedAt());
	}//end testConstructorSetsDefaults()

	/**
	 * Getters and setters round-trip.
	 *
	 * @return void
	 */
	public function testGettersAndSetters(): void {
		$entity = new GroupShare();
		$now = new DateTime('2026-06-11T10:00:00+00:00');

		$entity->setId('gs-1');
		$entity->setSecretId('s-1');
		$entity->setGroupId('admin');
		$entity->setCreatedBy('alice');
		$entity->setCreatedAt($now);

		$this->assertSame('gs-1', $entity->getId());
		$this->assertSame('s-1', $entity->getSecretId());
		$this->assertSame('admin', $entity->getGroupId());
		$this->assertSame('alice', $entity->getCreatedBy());
		$this->assertSame($now, $entity->getCreatedAt());
	}//end testGettersAndSetters()

	/**
	 * jsonSerialize emits the expected shape.
	 *
	 * @return void
	 */
	public function testJsonSerializeShape(): void {
		$entity = new GroupShare();
		$entity->setId('gs-1');
		$entity->setSecretId('s-1');
		$entity->setGroupId('admin');
		$entity->setCreatedBy('alice');
		$entity->setCreatedAt(new DateTime('2026-06-11T10:00:00+00:00'));

		$payload = $entity->jsonSerialize();

		$this->assertSame('gs-1', $payload['id']);
		$this->assertSame('s-1', $payload['secretId']);
		$this->assertSame('admin', $payload['groupId']);
		$this->assertSame('alice', $payload['createdBy']);
		$this->assertStringContainsString('2026-06-11', (string)$payload['createdAt']);
	}//end testJsonSerializeShape()
}//end class
