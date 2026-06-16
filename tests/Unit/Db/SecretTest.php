<?php

/**
 * Unit tests for the Secret entity.
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

use OCA\Doriath\Db\Secret;
use PHPUnit\Framework\TestCase;

/**
 * Tests for the Secret entity.
 */
class SecretTest extends TestCase
{
    /**
     * Build a populated secret.
     *
     * @return Secret
     */
    private function makeSecret(): Secret
    {
        $secret = new Secret();
        $secret->setId('s-1');
        $secret->setName('GitHub');
        $secret->setUrl('https://github.com');
        $secret->setTypeId('login-id');
        $secret->setFolderId('folder-1');
        $secret->setKey('ENCRYPTED_KEY');
        $secret->setLogin('ENCRYPTED_LOGIN');
        $secret->setAdditionalFields('ENCRYPTED_EXTRA');
        $secret->setEncryptionSuiteId('suite-1');
        $secret->setOwnerType('user');
        $secret->setOwnerId('alice');
        return $secret;
    }//end makeSecret()

    /**
     * jsonSerialize returns the encrypted blobs and metadata.
     *
     * @return void
     */
    public function testJsonSerializeIncludesEncryptedBlobs(): void
    {
        $data = $this->makeSecret()->jsonSerialize();

        $this->assertSame('ENCRYPTED_KEY', $data['key']);
        $this->assertSame('ENCRYPTED_LOGIN', $data['login']);
        $this->assertSame('ENCRYPTED_EXTRA', $data['additionalFields']);
        $this->assertSame('GitHub', $data['name']);
        $this->assertFalse($data['blocked']);
    }//end testJsonSerializeIncludesEncryptedBlobs()

    /**
     * jsonSerializeBlocked omits the encrypted blobs and flags the secret.
     *
     * @return void
     */
    public function testJsonSerializeBlockedOmitsBlobs(): void
    {
        $data = $this->makeSecret()->jsonSerializeBlocked('Encryption suite is revoked');

        $this->assertArrayNotHasKey('key', $data);
        $this->assertArrayNotHasKey('login', $data);
        $this->assertArrayNotHasKey('additionalFields', $data);
        $this->assertTrue($data['blocked']);
        $this->assertSame('Encryption suite is revoked', $data['blockedReason']);
        $this->assertSame('GitHub', $data['name']);
    }//end testJsonSerializeBlockedOmitsBlobs()

    /**
     * Regression lock for the owner_type NOT-NULL fix (Phase-0).
     *
     * The ownerType property defaults to '' (NOT 'user'). NC's QBMapper only
     * writes columns that getUpdatedFields() reports as dirty, and Entity::setter
     * skips marking a field dirty when the new value equals the current one. If
     * the default were 'user', setOwnerType('user') would be a no-op, ownerType
     * would NOT be in getUpdatedFields(), and the INSERT would omit it — leaving
     * the NOT-NULL column null and failing the write. With default '', the setter
     * marks it dirty so 'user' is persisted on INSERT.
     *
     * @return void
     */
    public function testSetOwnerTypeUserMarksColumnDirtyForInsert(): void
    {
        $secret = new Secret();

        // Fresh entity: nothing dirty yet, ownerType is the '' default.
        $this->assertSame('', $secret->getOwnerType());
        $this->assertArrayNotHasKey('ownerType', $secret->getUpdatedFields());

        // Setting the common-case 'user' value MUST mark the column dirty so the
        // QBMapper INSERT writes it (the column is NOT NULL). getUpdatedFields()
        // is keyed by property name.
        $secret->setOwnerType('user');

        $this->assertSame('user', $secret->getOwnerType());
        $this->assertArrayHasKey(
            'ownerType',
            $secret->getUpdatedFields(),
            'setOwnerType("user") must mark ownerType dirty so it is written on INSERT'
        );
    }//end testSetOwnerTypeUserMarksColumnDirtyForInsert()
}//end class
