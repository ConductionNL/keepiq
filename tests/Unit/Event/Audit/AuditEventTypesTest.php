<?php

/**
 * Unit tests for AuditEventTypes.
 *
 * @category Test
 * @package  OCA\Keepiq\Tests\Unit\Event\Audit
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

namespace OCA\Keepiq\Tests\Unit\Event\Audit;

use OCA\Keepiq\Event\Audit\AuditEventTypes;
use PHPUnit\Framework\TestCase;
use ReflectionClass;

/**
 * Tests for AuditEventTypes.
 *
 * @covers \OCA\Keepiq\Event\Audit\AuditEventTypes
 */
class AuditEventTypesTest extends TestCase {
	/**
	 * Every whitelisted event type is known.
	 *
	 * @return void
	 */
	public function testWhitelistedTypesAreKnown(): void {
		$this->assertTrue(AuditEventTypes::isKnown(AuditEventTypes::SECRET_CREATED));
		$this->assertTrue(AuditEventTypes::isKnown(AuditEventTypes::FEDERATION_PARTNER_VALUE_FIELDS_CHANGED));
		foreach (array_keys(AuditEventTypes::WHITELIST) as $type) {
			$this->assertTrue(AuditEventTypes::isKnown($type), $type);
		}
	}//end testWhitelistedTypesAreKnown()

	/**
	 * Unknown, empty and case-changed types are not known.
	 *
	 * @return void
	 */
	public function testUnknownTypesAreNotKnown(): void {
		$this->assertFalse(AuditEventTypes::isKnown('secret.exploded'));
		$this->assertFalse(AuditEventTypes::isKnown(''));
		$this->assertFalse(AuditEventTypes::isKnown(strtoupper(AuditEventTypes::SECRET_CREATED)));
		$this->assertFalse(AuditEventTypes::isKnown(AuditEventTypes::SECRET_CREATED.' '));
	}//end testUnknownTypesAreNotKnown()

	/**
	 * Every event-type constant has a whitelist entry, so none is silently unknown.
	 *
	 * @return void
	 */
	public function testEveryTypeConstantIsWhitelisted(): void {
		$constants = (new ReflectionClass(AuditEventTypes::class))->getConstants();
		$types     = array_filter($constants, 'is_string');

		$this->assertNotEmpty($types);
		foreach ($types as $name => $type) {
			$this->assertTrue(AuditEventTypes::isKnown($type), $name.' is not whitelisted');
		}
	}//end testEveryTypeConstantIsWhitelisted()

	/**
	 * No whitelist entry allows a forbidden (secret-bearing) metadata key.
	 *
	 * @return void
	 */
	public function testNoWhitelistEntryAllowsAForbiddenKey(): void {
		foreach (AuditEventTypes::WHITELIST as $type => $allowed) {
			$this->assertSame(
				[],
				array_values(array_intersect($allowed, AuditEventTypes::FORBIDDEN_KEYS)),
				$type.' whitelists a forbidden key'
			);
		}
	}//end testNoWhitelistEntryAllowsAForbiddenKey()

	/**
	 * The forbidden list names the secret-bearing fields.
	 *
	 * @return void
	 */
	public function testForbiddenKeysNameTheSecretFields(): void {
		foreach (['key', 'login', 'password', 'value', 'totp', 'passkeys', 'ciphertext'] as $key) {
			$this->assertContains($key, AuditEventTypes::FORBIDDEN_KEYS);
		}
	}//end testForbiddenKeysNameTheSecretFields()

	/**
	 * Every user-referencing key is whitelisted somewhere, so anonymization has something to scrub.
	 *
	 * @return void
	 */
	public function testUserReferencingKeysAreWhitelistedSomewhere(): void {
		$allAllowed = array_merge(...array_values(AuditEventTypes::WHITELIST));

		foreach (AuditEventTypes::USER_REFERENCING_METADATA_KEYS as $key) {
			$this->assertContains($key, $allAllowed, $key.' is never whitelisted');
		}
	}//end testUserReferencingKeysAreWhitelistedSomewhere()
}//end class
