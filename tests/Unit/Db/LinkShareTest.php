<?php

/**
 * Unit tests for LinkShare entity.
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
use OCA\Doriath\Db\LinkShare;
use PHPUnit\Framework\TestCase;

/**
 * Tests for LinkShare entity.
 */
class LinkShareTest extends TestCase
{
    /**
     * Test that the constructor sets defaults.
     *
     * @return void
     */
    public function testConstructorSetsDefaults(): void
    {
        $entity = new LinkShare();

        $this->assertSame('', $entity->getSecretId());
        $this->assertSame('', $entity->getToken());
        $this->assertSame('', $entity->getEncryptedSecretSnapshot());
        $this->assertSame('', $entity->getArgon2idSalt());
        $this->assertSame('', $entity->getEncryptionSuiteId());
        $this->assertSame(1, $entity->getUsageLimit());
        $this->assertSame(0, $entity->getUsageCount());
        $this->assertSame(0, $entity->getFailedAttempts());
        $this->assertSame('', $entity->getCreatedBy());
        $this->assertNull($entity->getCreatedAt());
        $this->assertNull($entity->getExpiresAt());
    }//end testConstructorSetsDefaults()

    /**
     * Test getters and setters for all fields.
     *
     * @return void
     */
    public function testGettersAndSetters(): void
    {
        $entity = new LinkShare();

        $entity->setId('ls-uuid-1');
        $this->assertSame('ls-uuid-1', $entity->getId());

        $entity->setSecretId('secret-1');
        $this->assertSame('secret-1', $entity->getSecretId());

        $entity->setToken('deadbeefdeadbeefdeadbeefdeadbeef');
        $this->assertSame('deadbeefdeadbeefdeadbeefdeadbeef', $entity->getToken());

        $entity->setEncryptedSecretSnapshot('base64-blob');
        $this->assertSame('base64-blob', $entity->getEncryptedSecretSnapshot());

        $entity->setArgon2idSalt('base64-salt');
        $this->assertSame('base64-salt', $entity->getArgon2idSalt());

        $entity->setEncryptionSuiteId('suite-1');
        $this->assertSame('suite-1', $entity->getEncryptionSuiteId());

        $entity->setUsageLimit(5);
        $this->assertSame(5, $entity->getUsageLimit());

        $entity->setUsageCount(2);
        $this->assertSame(2, $entity->getUsageCount());

        $entity->setFailedAttempts(3);
        $this->assertSame(3, $entity->getFailedAttempts());

        $entity->setCreatedBy('alice');
        $this->assertSame('alice', $entity->getCreatedBy());

        $createdAt = new DateTime('2026-01-01T00:00:00+00:00');
        $entity->setCreatedAt($createdAt);
        $this->assertSame($createdAt, $entity->getCreatedAt());

        $expiresAt = new DateTime('2026-12-31T00:00:00+00:00');
        $entity->setExpiresAt($expiresAt);
        $this->assertSame($expiresAt, $entity->getExpiresAt());
    }//end testGettersAndSetters()

    /**
     * Test that jsonSerialize OMITS the encrypted blob and salt.
     *
     * @return void
     */
    public function testJsonSerializeOmitsBlobAndSalt(): void
    {
        $entity = new LinkShare();
        $entity->setId('ls-001');
        $entity->setSecretId('secret-7');
        $entity->setToken('tok-001');
        $entity->setEncryptedSecretSnapshot('SUPER-SECRET-BLOB');
        $entity->setArgon2idSalt('SUPER-SECRET-SALT');
        $entity->setEncryptionSuiteId('suite-9');
        $entity->setUsageLimit(3);
        $entity->setUsageCount(1);
        $entity->setFailedAttempts(0);
        $entity->setCreatedBy('bob');
        $entity->setCreatedAt(new DateTime('2026-02-01T10:00:00+00:00'));

        $serialized = $entity->jsonSerialize();

        $this->assertArrayNotHasKey('encryptedSecretSnapshot', $serialized);
        $this->assertArrayNotHasKey('argon2idSalt', $serialized);
        $this->assertSame('ls-001', $serialized['id']);
        $this->assertSame('secret-7', $serialized['secretId']);
        $this->assertSame('tok-001', $serialized['token']);
        $this->assertSame(3, $serialized['usageLimit']);
        $this->assertSame(1, $serialized['usageCount']);
        $this->assertSame(2, $serialized['remaining']);
        $this->assertSame('bob', $serialized['createdBy']);
        $this->assertNotNull($serialized['createdAt']);
        $this->assertNull($serialized['expiresAt']);
    }//end testJsonSerializeOmitsBlobAndSalt()

    /**
     * Test that the remaining count never goes below zero.
     *
     * @return void
     */
    public function testRemainingNeverNegative(): void
    {
        $entity = new LinkShare();
        $entity->setUsageLimit(2);
        $entity->setUsageCount(5);

        $serialized = $entity->jsonSerialize();

        $this->assertSame(0, $serialized['remaining']);
    }//end testRemainingNeverNegative()

    /**
     * Test the public serialization INCLUDES the blob and salt but no owner.
     *
     * @return void
     */
    public function testJsonSerializePublicIncludesBlobAndSalt(): void
    {
        $entity = new LinkShare();
        $entity->setEncryptedSecretSnapshot('BLOB');
        $entity->setArgon2idSalt('SALT');
        $entity->setUsageLimit(4);
        $entity->setUsageCount(1);
        $entity->setCreatedBy('charlie');

        $public = $entity->jsonSerializePublic();

        $this->assertSame('BLOB', $public['encryptedSecretSnapshot']);
        $this->assertSame('SALT', $public['argon2idSalt']);
        $this->assertSame(4, $public['usageLimit']);
        $this->assertSame(1, $public['usageCount']);
        $this->assertArrayNotHasKey('createdBy', $public);
        $this->assertArrayNotHasKey('token', $public);
    }//end testJsonSerializePublicIncludesBlobAndSalt()

    /**
     * Test DateTime fields format correctly in jsonSerialize.
     *
     * @return void
     */
    public function testJsonSerializeDateTimeFormat(): void
    {
        $entity  = new LinkShare();
        $expires = new DateTime('2026-06-15T14:30:00+02:00');
        $entity->setExpiresAt($expires);

        $serialized = $entity->jsonSerialize();

        $this->assertSame($expires->format('c'), $serialized['expiresAt']);
    }//end testJsonSerializeDateTimeFormat()
}//end class
