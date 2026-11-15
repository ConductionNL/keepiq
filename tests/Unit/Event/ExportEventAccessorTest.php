<?php

/**
 * Unit tests for the accessors and edge payloads of the export and deletion events.
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
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\UsesClass;
use PHPUnit\Framework\TestCase;

/**
 * Tests for the export and account-deletion event accessors.
 */
#[CoversClass(SecretExportedEvent::class)]
#[CoversClass(GdprExportPerformedEvent::class)]
#[CoversClass(AccountDataDeletedEvent::class)]
#[UsesClass(DeletionReport::class)]
class ExportEventAccessorTest extends TestCase {
	/**
	 * SecretExportedEvent hands back mode, scope and count as given.
	 *
	 * @return void
	 */
	public function testSecretExportedAccessors(): void {
		$event = new SecretExportedEvent('bob', 'cxp', 'folders', 0);

		$this->assertSame('bob', $event->getUserId());
		$this->assertSame('cxp', $event->getMode());
		$this->assertSame('folders', $event->getScope());
		$this->assertSame(0, $event->getSecretCount());
		$this->assertSame(['mode', 'scope', 'secretCount'], array_keys($event->getMetadata()));
		$this->assertSame(['mode' => 'cxp', 'scope' => 'folders', 'secretCount' => 0], $event->getMetadata());
	}//end testSecretExportedAccessors()

	/**
	 * A GDPR export always reports the gdpr-package mode, with or without the vault.
	 *
	 * @return void
	 */
	public function testGdprExportModeIsFixed(): void {
		$metaOnly = new GdprExportPerformedEvent('carol', false);

		$this->assertFalse($metaOnly->includesVault());
		$this->assertSame('carol', $metaOnly->getUserId());
		$this->assertSame(['mode' => 'gdpr-package', 'scope' => 'metadata-only'], $metaOnly->getMetadata());
		$this->assertSame(['mode' => 'gdpr-package', 'scope' => 'metadata-and-vault'], (new GdprExportPerformedEvent('carol', true))->getMetadata());
	}//end testGdprExportModeIsFixed()

	/**
	 * The deletion event returns the very report it was built with.
	 *
	 * @return void
	 */
	public function testAccountDeletedReturnsTheSameReport(): void {
		$report = new DeletionReport();
		$event = new AccountDataDeletedEvent('dave', 'in-app', $report);

		$this->assertSame($report, $event->getReport());
		$this->assertSame('in-app', $event->getTrigger());
		$this->assertSame('dave', $event->getUserId());
	}//end testAccountDeletedReturnsTheSameReport()

	/**
	 * Counts not on the audit whitelist (folders, link shares) stay out of the
	 * metadata, an empty report yields zeros, and the key order is fixed.
	 *
	 * @return void
	 */
	public function testAccountDeletedMetadataSumsOnlyWhitelistedCounts(): void {
		$empty = new AccountDataDeletedEvent('erin', 'user-deleted', new DeletionReport());
		$this->assertSame(
			['trigger' => 'user-deleted', 'secretCount' => 0, 'shareCount' => 0, 'requestCount' => 0, 'suiteCount' => 0],
			$empty->getMetadata()
		);

		$report = new DeletionReport();
		$report->foldersDeleted = 9;
		$report->linkSharesDeleted = 7;
		$report->secretsTransferred = 2;
		$report->sharesRemoved = 1;

		$meta = (new AccountDataDeletedEvent('erin', 'in-app', $report))->getMetadata();
		$this->assertSame(['trigger', 'secretCount', 'shareCount', 'requestCount', 'suiteCount'], array_keys($meta));
		$this->assertSame(2, $meta['secretCount']);
		$this->assertSame(1, $meta['shareCount']);
		$this->assertSame(0, $meta['requestCount']);
		$this->assertSame(0, $meta['suiteCount']);
	}//end testAccountDeletedMetadataSumsOnlyWhitelistedCounts()
}//end class
