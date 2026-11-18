<?php

/**
 * Unit tests for AuditMetadataPolicy.
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
use OCA\Keepiq\Event\Audit\AuditMetadataPolicy;
use PHPUnit\Framework\TestCase;

/**
 * The policy tables keep their contents.
 *
 * @covers \OCA\Keepiq\Event\Audit\AuditMetadataPolicy
 */
class AuditMetadataPolicyTest extends TestCase {

	/**
	 * The whitelist is keyed by event-type strings and lists metadata key names.
	 *
	 * @return void
	 */
	public function testTheWhitelistIsKeyedByEventType(): void {
		$this->assertSame(['typeId', 'folderId'], AuditMetadataPolicy::WHITELIST[AuditEventTypes::SECRET_CREATED]);
		$this->assertSame([], AuditMetadataPolicy::WHITELIST[AuditEventTypes::SECRET_READ]);
		$this->assertSame(['partnerId', 'from', 'to'], AuditMetadataPolicy::WHITELIST['federation_partner.value_fields_changed']);
	}//end testTheWhitelistIsKeyedByEventType()

	/**
	 * Secret material is forbidden and user-referencing keys are listed for anonymisation.
	 *
	 * @return void
	 */
	public function testForbiddenAndUserReferencingKeys(): void {
		$this->assertSame(
			['key', 'login', 'password', 'value', 'additionalFields', 'totp', 'passkeys', 'ciphertext', 'payload'],
			AuditMetadataPolicy::FORBIDDEN_KEYS
		);
		$this->assertContains('recipientId', AuditMetadataPolicy::USER_REFERENCING_METADATA_KEYS);
		$this->assertContains('skippedUserIds', AuditMetadataPolicy::USER_REFERENCING_METADATA_KEYS);
		$this->assertSame([], array_intersect(AuditMetadataPolicy::FORBIDDEN_KEYS, AuditMetadataPolicy::USER_REFERENCING_METADATA_KEYS));
	}//end testForbiddenAndUserReferencingKeys()
}//end class
