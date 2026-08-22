<?php

/**
 * Unit tests for the secret-export-gdpr event classes.
 *
 * Locks down the no-secret-material guarantee: the event payloads carry counts
 * and modes only, with no key/login/password/value/ciphertext field. Also
 * confirms the AuditListener contract (getUserId + getMetadata) is satisfied.
 *
 * @category Test
 * @package  OCA\Keepiq\Tests\Unit\Event
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

namespace OCA\Keepiq\Tests\Unit\Event;

use OCA\Keepiq\Event\AccountDataDeletedEvent;
use OCA\Keepiq\Event\GdprExportPerformedEvent;
use OCA\Keepiq\Event\SecretExportedEvent;
use OCA\Keepiq\Service\DeletionReport;
use PHPUnit\Framework\TestCase;

/**
 * Tests for the typed export/deletion events.
 */
class ExportGdprEventTest extends TestCase {
	/**
	 * The metadata keys that must NEVER appear in any event payload.
	 *
	 * @var string[]
	 */
	private const FORBIDDEN = ['key', 'login', 'password', 'value', 'additionalFields', 'ciphertext', 'payload', 'secret'];

	/**
	 * Assert a payload contains no secret-material keys (recursively).
	 *
	 * @param array<string,mixed> $payload The payload
	 *
	 * @return void
	 */
	private function assertNoSecretMaterial(array $payload): void {
		foreach ($payload as $value) {
			if (is_array($value) === true) {
				$this->assertNoSecretMaterial($value);
			}
		}

		foreach (self::FORBIDDEN as $forbidden) {
			$this->assertArrayNotHasKey($forbidden, $payload, "forbidden key '$forbidden' present");
		}
	}//end assertNoSecretMaterial()

	/**
	 * SecretExportedEvent: actor + counts/modes only, matching the whitelist.
	 *
	 * @return void
	 */
	public function testSecretExportedEventPayload(): void {
		$event = new SecretExportedEvent(userId: 'alice', mode: 'encrypted-backup', scope: 'vault', secretCount: 120);
		$this->assertSame('alice', $event->getUserId());
		$meta = $event->getMetadata();
		$this->assertSame(['mode' => 'encrypted-backup', 'scope' => 'vault', 'secretCount' => 120], $meta);
		$this->assertNoSecretMaterial($meta);
	}//end testSecretExportedEventPayload()

	/**
	 * GdprExportPerformedEvent records the vault-inclusion flag, no material.
	 *
	 * @return void
	 */
	public function testGdprExportPerformedEventPayload(): void {
		$withVault = new GdprExportPerformedEvent(userId: 'alice', includesVault: true);
		$this->assertTrue($withVault->includesVault());
		$this->assertSame('alice', $withVault->getUserId());
		$this->assertSame('metadata-and-vault', $withVault->getMetadata()['scope']);
		$this->assertNoSecretMaterial($withVault->getMetadata());

		$metaOnly = new GdprExportPerformedEvent(userId: 'alice', includesVault: false);
		$this->assertSame('metadata-only', $metaOnly->getMetadata()['scope']);
	}//end testGdprExportPerformedEventPayload()

	/**
	 * AccountDataDeletedEvent carries trigger + counts only, matching the
	 * vault.account_deleted whitelist keys.
	 *
	 * @return void
	 */
	public function testAccountDataDeletedEventPayload(): void {
		$report = new DeletionReport();
		$report->secretsDeleted = 200;
		$report->secretsTransferred = 3;
		$report->sharesDetached = 12;
		$report->sharesRemoved = 5;
		$report->requestsDeleted = 4;
		$report->suitesDeleted = 1;

		$event = new AccountDataDeletedEvent(userId: 'alice', trigger: 'user-deleted', report: $report);
		$this->assertSame('alice', $event->getUserId());
		$this->assertSame('user-deleted', $event->getTrigger());

		$meta = $event->getMetadata();
		$this->assertSame('user-deleted', $meta['trigger']);
		$this->assertSame(203, $meta['secretCount']);
		$this->assertSame(17, $meta['shareCount']);
		$this->assertSame(4, $meta['requestCount']);
		$this->assertSame(1, $meta['suiteCount']);
		$this->assertNoSecretMaterial($meta);
	}//end testAccountDataDeletedEventPayload()

	/**
	 * The event classes expose NO accessor that returns secret material.
	 *
	 * @return void
	 */
	public function testNoSecretMaterialAccessors(): void {
		foreach ([SecretExportedEvent::class, GdprExportPerformedEvent::class, AccountDataDeletedEvent::class] as $class) {
			$methods = get_class_methods($class);
			foreach (['getKey', 'getLogin', 'getPassword', 'getValue', 'getCiphertext', 'getSecret'] as $banned) {
				$this->assertNotContains($banned, $methods, "$class exposes $banned");
			}
		}
	}//end testNoSecretMaterialAccessors()
}//end class
