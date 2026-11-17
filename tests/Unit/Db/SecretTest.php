<?php

/**
 * Unit tests for the Secret entity.
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

use OCA\Keepiq\Db\Secret;
use PHPUnit\Framework\TestCase;

/**
 * Tests for the Secret entity.
 */
class SecretTest extends TestCase {
	/**
	 * Build a populated secret.
	 *
	 * @return Secret
	 */
	private function makeSecret(): Secret {
		$secret = new Secret();
		$secret->setId('s-1');
		$secret->setName('GitHub');
		$secret->setUrl('https://github.com');
		$secret->setTypeId('login-id');
		$secret->setFolderId('folder-1');
		$secret->setKey('ENCRYPTED_KEY');
		$secret->setLogin('ENCRYPTED_LOGIN');
		$secret->setAdditionalFields('ENCRYPTED_EXTRA');
		$secret->setTotp('ENCRYPTED_TOTP');
		$secret->setPasskeys('ENCRYPTED_PASSKEYS');
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
	public function testJsonSerializeIncludesEncryptedBlobs(): void {
		$data = $this->makeSecret()->jsonSerialize();

		$this->assertSame('ENCRYPTED_KEY', $data['key']);
		$this->assertSame('ENCRYPTED_LOGIN', $data['login']);
		$this->assertSame('ENCRYPTED_EXTRA', $data['additionalFields']);
		$this->assertSame('ENCRYPTED_TOTP', $data['totp']);
		$this->assertSame('ENCRYPTED_PASSKEYS', $data['passkeys']);
		$this->assertSame('GitHub', $data['name']);
		$this->assertFalse($data['blocked']);
	}//end testJsonSerializeIncludesEncryptedBlobs()

	/**
	 * A secret written by an older client has neither new field, and says so as null.
	 *
	 * @return void
	 */
	public function testNewValueFieldsSerializeAsNullWhenUnset(): void {
		$data = (new Secret())->jsonSerialize();

		$this->assertArrayHasKey('totp', $data);
		$this->assertArrayHasKey('passkeys', $data);
		$this->assertNull($data['totp']);
		$this->assertNull($data['passkeys']);
	}//end testNewValueFieldsSerializeAsNullWhenUnset()

	/**
	 * Every value field is serialized, and the legacy list is the value list without the new two.
	 *
	 * @return void
	 */
	public function testValueFieldListsMatchTheSerializedBlobs(): void {
		$data = $this->makeSecret()->jsonSerialize();
		foreach (Secret::VALUE_FIELDS as $field) {
			$this->assertArrayHasKey($field, $data);
		}

		$this->assertSame(['totp', 'passkeys'], array_values(array_diff(Secret::VALUE_FIELDS, Secret::LEGACY_VALUE_FIELDS)));
		$this->assertSame([], array_diff(Secret::LEGACY_VALUE_FIELDS, Secret::VALUE_FIELDS));
	}//end testValueFieldListsMatchTheSerializedBlobs()

	/**
	 * The new fields hydrate from and map to columns of the same name.
	 *
	 * @return void
	 */
	public function testNewValueFieldsMapToTheirColumns(): void {
		$secret = Secret::fromRow(['id' => 's-2', 'totp' => 'T', 'passkeys' => 'P']);

		$this->assertSame('T', $secret->getTotp());
		$this->assertSame('P', $secret->getPasskeys());
		$this->assertSame('totp', $secret->propertyToColumn('totp'));
		$this->assertSame('passkeys', $secret->propertyToColumn('passkeys'));
		$this->assertSame('string', $secret->getFieldTypes()['totp']);
		$this->assertSame('string', $secret->getFieldTypes()['passkeys']);
	}//end testNewValueFieldsMapToTheirColumns()

	/**
	 * A secret holding only a TOTP seed or only passkeys still holds a value.
	 *
	 * @return void
	 */
	public function testHoldsNoValuesCountsTotpAndPasskeys(): void {
		$this->assertTrue((new Secret())->holdsNoValues());

		$withTotp = new Secret();
		$withTotp->setTotp('ENCRYPTED_TOTP');
		$this->assertFalse($withTotp->holdsNoValues());

		$withPasskeys = new Secret();
		$withPasskeys->setPasskeys('ENCRYPTED_PASSKEYS');
		$this->assertFalse($withPasskeys->holdsNoValues());

		// A placeholder filled with custom fields only holds them pending until the owner opens it.
		$withPending = new Secret();
		$withPending->appendPendingAdditionalFields(ciphertext: 'FILLED_BLOB', schemas: []);
		$this->assertFalse($withPending->holdsNoValues());
	}//end testHoldsNoValuesCountsTotpAndPasskeys()

	/**
	 * jsonSerializeBlocked omits the encrypted blobs and flags the secret.
	 *
	 * @return void
	 */
	public function testJsonSerializeBlockedOmitsBlobs(): void {
		$data = $this->makeSecret()->jsonSerializeBlocked('Encryption suite is revoked');

		$this->assertArrayNotHasKey('key', $data);
		$this->assertArrayNotHasKey('login', $data);
		$this->assertArrayNotHasKey('additionalFields', $data);
		$this->assertArrayNotHasKey('totp', $data);
		$this->assertArrayNotHasKey('passkeys', $data);
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
	public function testSetOwnerTypeUserMarksColumnDirtyForInsert(): void {
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

	/**
	 * The pending extra-field fills read back as a list of strings only: none
	 * stored, an empty value, non-list JSON and non-string entries all read as
	 * nothing.
	 *
	 * @return void
	 *
	 * @spec openspec/specs/secret-requests/spec.md#requirement-requestable-fields
	 */
	public function testPendingAdditionalFieldListReadsOnlyStrings(): void {
		$secret = new Secret();
		$this->assertSame([], $secret->pendingAdditionalFieldList());

		$secret->setPendingAdditionalFields('');
		$this->assertSame([], $secret->pendingAdditionalFieldList());

		$secret->setPendingAdditionalFields('"FILL_1"');
		$this->assertSame([], $secret->pendingAdditionalFieldList());

		$secret->setPendingAdditionalFields('not-json');
		$this->assertSame([], $secret->pendingAdditionalFieldList());

		$secret->setPendingAdditionalFields('{"a":"FILL_1","b":7,"c":"FILL_2","d":null}');
		$this->assertSame(['FILL_1', 'FILL_2'], $secret->pendingAdditionalFieldList());
	}//end testPendingAdditionalFieldListReadsOnlyStrings()

	/**
	 * A fill held back with its request's schemas keeps them next to its blob,
	 * an older entry stored as a bare string reads with none, and dropping the
	 * merged blobs drops their schemas with them.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/vault-custom-field-kinds-and-ssh-key/specs/vault-custom-fields/spec.md#requirement-a-request-carries-the-schemas-of-its-fields
	 */
	public function testPendingSchemasStayAlignedWithTheirBlobs(): void {
		$hidden = ['PIN' => ['type' => 'string', 'hidden' => true]];
		$secret = new Secret();
		$secret->setPendingAdditionalFields('["OLD_FILL"]');
		$secret->appendPendingAdditionalFields(ciphertext: 'PIN_FILL', schemas: $hidden);
		$secret->appendPendingAdditionalFields(ciphertext: 'PLAIN_FILL', schemas: []);

		$this->assertSame(['OLD_FILL', 'PIN_FILL', 'PLAIN_FILL'], $secret->pendingAdditionalFieldList());
		$this->assertEquals([(object)[], (object)$hidden, (object)[]], $secret->jsonSerialize()['pendingFieldSchemas']);

		$secret->dropMergedPending(count: 1);
		$this->assertSame(['PIN_FILL', 'PLAIN_FILL'], $secret->pendingAdditionalFieldList());
		$this->assertEquals([(object)$hidden, (object)[]], $secret->jsonSerialize()['pendingFieldSchemas']);

		$secret->dropMergedPending(count: 2);
		$this->assertNull($secret->getPendingAdditionalFields());
	}//end testPendingSchemasStayAlignedWithTheirBlobs()
}//end class
