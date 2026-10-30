<?php

/**
 * Unit tests for the setup check that warns while the share quarantine scan
 * (GHSA-m5p9-6pw7-pf3g) is pending.
 *
 * @category Test
 * @package  OCA\Keepiq\Tests\Unit\SetupCheck
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

namespace OCA\Keepiq\Tests\Unit\SetupCheck;

use OCA\Keepiq\Service\AuditService;
use OCA\Keepiq\Service\ShareQuarantineScanner;
use OCA\Keepiq\Service\SiemService;
use OCA\Keepiq\SetupCheck\ShareQuarantineScanPending;
use OCP\IAppConfig;
use OCP\IDBConnection;
use OCP\IL10N;
use OCP\SetupCheck\SetupResult;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;

/**
 * Tests for ShareQuarantineScanPending.
 *
 * @spec openspec/specs/user-sharing/spec.md#requirement-planted-share-rows-are-quarantined
 */
class ShareQuarantineScanPendingTest extends TestCase {

	/**
	 * The check over a scanner whose completion key holds the given value.
	 *
	 * @param string $completed The stored completion key, the generation a pass completed in ('' when unset)
	 *
	 * @return ShareQuarantineScanPending
	 */
	private function check(string $completed): ShareQuarantineScanPending {
		$appConfig = $this->createMock(IAppConfig::class);
		$appConfig->method('getValueString')->willReturnCallback(
			static fn (string $app, string $key, string $default = ''): string => ($key === ShareQuarantineScanner::COMPLETED_KEY && $completed !== '' ? $completed : $default)
		);
		$l10n = $this->createMock(IL10N::class);
		$l10n->method('t')->willReturnArgument(0);

		return new ShareQuarantineScanPending(
			scanner: new ShareQuarantineScanner(
				db: $this->createMock(IDBConnection::class),
				appConfig: $appConfig,
				logger: new NullLogger(),
				auditService: $this->createMock(AuditService::class),
				siem: $this->createMock(SiemService::class),
			),
			l10n: $l10n,
		);
	}//end check()

	/**
	 * While no pass has completed, the admin overview warns that planted rows
	 * are still exploitable and points at the background job and the log.
	 *
	 * @return void
	 */
	public function testWarnsWhileTheScanIsPending(): void {
		$result = $this->check(completed: '')->run();

		$this->assertSame(SetupResult::WARNING, $result->getSeverity());
		$this->assertStringContainsString('not quarantined and can still be used against other users\' secrets', (string)$result->getDescription());
		$this->assertStringContainsString('urgent', (string)$result->getDescription());
		$this->assertStringContainsString('ShareQuarantineScanJob', (string)$result->getDescription());
		$this->assertStringContainsString('log', (string)$result->getDescription());
	}//end testWarnsWhileTheScanIsPending()

	/**
	 * Once a pass has completed in the current generation, the check passes; a
	 * pass of an earlier generation does not count.
	 *
	 * @return void
	 */
	public function testPassesOnceTheScanCompleted(): void {
		$check = $this->check(completed: ShareQuarantineScanner::INITIAL_GENERATION);

		$this->assertSame(SetupResult::SUCCESS, $check->run()->getSeverity());
		$this->assertSame('security', $check->getCategory());
		$this->assertSame(SetupResult::WARNING, $this->check(completed: 'an-earlier-generation')->run()->getSeverity());
	}//end testPassesOnceTheScanCompleted()
}//end class
