<?php

/**
 * Unit tests for the `totp` and `passkeys` value fields on the entities that hold a copy of a secret's value.
 *
 * @category Test
 * @package  OCA\Keepiq\Tests\Unit\Db
 *
 * @author    Conduction Development Team <dev@conductio.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git-id>
 *
 * @link https://conduction.nl
 */

declare(strict_types=1);

namespace OCA\Keepiq\Tests\Unit\Db;

use OCA\Keepiq\AppInfo\Application;
use OCA\Keepiq\Db\FederatedShare;
use OCA\Keepiq\Db\FederationPartner;
use OCA\Keepiq\Db\SecretVersion;
use OCA\Keepiq\Exception\PartnerUpgradeRequiredException;
use PHPUnit\Framework\TestCase;

/**
 * Tests for SecretVersion, FederatedShare and FederationPartner value fields.
 */
class ValueFieldCopiesTest extends TestCase {
	/**
	 * A version's metadata view carries no ciphertext; the blob view carries all five value fields.
	 *
	 * @return void
	 */
	public function testSecretVersionSerializesTheNewBlobsOnlyWithBlobs(): void {
		$version = new SecretVersion();
		$version->setId('v-1');
		$version->setKey('K');
		$version->setLogin('L');
		$version->setAdditionalFields('A');
		$version->setTotp('T');
		$version->setPasskeys('P');

		$metadata = $version->jsonSerialize();
		$this->assertArrayNotHasKey('totp', $metadata);
		$this->assertArrayNotHasKey('passkeys', $metadata);

		$blobs = $version->jsonSerializeWithBlobs();
		$this->assertSame('K', $blobs['key']);
		$this->assertSame('L', $blobs['login']);
		$this->assertSame('A', $blobs['additionalFields']);
		$this->assertSame('T', $blobs['totp']);
		$this->assertSame('P', $blobs['passkeys']);
	}//end testSecretVersionSerializesTheNewBlobsOnlyWithBlobs()

	/**
	 * A version snapshot from before the new fields reads back null for both.
	 *
	 * @return void
	 */
	public function testSecretVersionHydratesTheNewColumns(): void {
		$old = SecretVersion::fromRow(['id' => 'v-2', 'key' => 'K']);
		$this->assertNull($old->getTotp());
		$this->assertNull($old->getPasskeys());

		$new = SecretVersion::fromRow(['id' => 'v-3', 'totp' => 'T', 'passkeys' => 'P']);
		$this->assertSame('T', $new->getTotp());
		$this->assertSame('P', $new->getPasskeys());
		$this->assertSame('string', $new->getFieldTypes()['totp']);
		$this->assertSame('string', $new->getFieldTypes()['passkeys']);
	}//end testSecretVersionHydratesTheNewColumns()

	/**
	 * A federated share stores both ciphertexts but never serializes them for the owner's API.
	 *
	 * @return void
	 */
	public function testFederatedShareStoresButNeverSerializesTheNewCiphertext(): void {
		$share = FederatedShare::fromRow(['id' => 'f-1', 'totp' => 'T', 'passkeys' => 'P']);

		$this->assertSame('T', $share->getTotp());
		$this->assertSame('P', $share->getPasskeys());
		$this->assertSame('string', $share->getFieldTypes()['totp']);
		$this->assertSame('string', $share->getFieldTypes()['passkeys']);

		$data = $share->jsonSerialize();
		$this->assertArrayNotHasKey('totp', $data);
		$this->assertArrayNotHasKey('passkeys', $data);
	}//end testFederatedShareStoresButNeverSerializesTheNewCiphertext()

	/**
	 * A partner's advertised value fields map to `value_fields` and are null until advertised.
	 *
	 * @return void
	 */
	public function testFederationPartnerValueFieldsColumn(): void {
		$partner = new FederationPartner();
		$this->assertNull($partner->getValueFields());
		$this->assertSame('value_fields', $partner->propertyToColumn('valueFields'));
		$this->assertSame('string', $partner->getFieldTypes()['valueFields']);

		$hydrated = FederationPartner::fromRow(['id' => 'p-1', 'value_fields' => '["key","totp"]']);
		$this->assertSame('["key","totp"]', $hydrated->getValueFields());
	}//end testFederationPartnerValueFieldsColumn()

	/**
	 * The refusal for an outdated partner names the version it needs.
	 *
	 * @return void
	 */
	public function testPartnerUpgradeRequiredNamesTheVersion(): void {
		$default = new PartnerUpgradeRequiredException();
		$this->assertSame(Application::TOTP_PASSKEYS_FIELDS_SINCE, $default->getRequiredVersion());
		$this->assertStringContainsString(Application::TOTP_PASSKEYS_FIELDS_SINCE, $default->getMessage());
		$this->assertSame('partner_upgrade_required', PartnerUpgradeRequiredException::CODE);

		$explicit = new PartnerUpgradeRequiredException(requiredVersion: '9.9.9');
		$this->assertSame('9.9.9', $explicit->getRequiredVersion());
		$this->assertStringContainsString('9.9.9', $explicit->getMessage());
	}//end testPartnerUpgradeRequiredNamesTheVersion()
}//end class
