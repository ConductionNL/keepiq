<?php

/**
 * Unit tests for the admin CA-health card of DashboardSummaryService.
 *
 * @category Test
 * @package  OCA\Keepiq\Tests\Unit\Service
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

namespace OCA\Keepiq\Tests\Unit\Service;

use InvalidArgumentException;
use OCA\Keepiq\Service\CertificateAuthorityService;
use OCA\Keepiq\Service\DashboardSummaryService;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use RuntimeException;

/**
 * The CA-health card mirrors the CA status, degrades to null, and is admin-only.
 */
#[CoversClass(DashboardSummaryService::class)]
class DashboardSummaryCaHealthTest extends TestCase {
	/**
	 * A summary service with only a CA service and a logger.
	 *
	 * @param CertificateAuthorityService $caService The CA service
	 * @param LoggerInterface|null $logger The logger
	 *
	 * @return DashboardSummaryService
	 */
	private function service(CertificateAuthorityService $caService, ?LoggerInterface $logger = null): DashboardSummaryService {
		return new DashboardSummaryService(
			logger: $logger ?? $this->createMock(originalClassName: LoggerInterface::class),
			caService: $caService,
		);
	}//end service()

	/**
	 * The card carries status, both expiries and the issued count.
	 *
	 * @return void
	 */
	public function testAdminCardMirrorsTheCaStatus(): void {
		$ca = $this->createMock(originalClassName: CertificateAuthorityService::class);
		$ca->method('getStatus')->willReturn([
			'status' => 'healthy',
			'root' => ['expiresAt' => '2040-01-01T00:00:00+00:00', 'subject' => 'ignored'],
			'intermediate' => ['expiresAt' => '2030-01-01T00:00:00+00:00'],
			'issued' => 12,
		]);

		$summary = $this->service(caService: $ca)->fetchSummary(userId: 'root', isAdmin: true);

		$this->assertSame(
			[
				'status' => 'healthy',
				'rootExpiresAt' => '2040-01-01T00:00:00+00:00',
				'intermediateExpiresAt' => '2030-01-01T00:00:00+00:00',
				'issued' => 12,
			],
			$summary['ca_health']
		);
	}//end testAdminCardMirrorsTheCaStatus()

	/**
	 * A CA without root, intermediate or issued count yields nulls for them.
	 *
	 * @return void
	 */
	public function testMissingCaPartsAreNull(): void {
		$ca = $this->createMock(originalClassName: CertificateAuthorityService::class);
		$ca->method('getStatus')->willReturn(['status' => 'uninitialised']);

		$summary = $this->service(caService: $ca)->fetchSummary(userId: 'root', isAdmin: true);

		$this->assertSame(
			[
				'status' => 'uninitialised',
				'rootExpiresAt' => null,
				'intermediateExpiresAt' => null,
				'issued' => null,
			],
			$summary['ca_health']
		);
	}//end testMissingCaPartsAreNull()

	/**
	 * A failing CA leaves the card null and logs a warning, the summary still renders.
	 *
	 * @return void
	 */
	public function testFailingCaDegradesToNullWithAWarning(): void {
		$ca = $this->createMock(originalClassName: CertificateAuthorityService::class);
		$ca->method('getStatus')->willThrowException(new RuntimeException('disk gone'));
		$logger = $this->createMock(originalClassName: LoggerInterface::class);
		$logger->expects($this->once())->method('warning')->with(
			'Keepiq: CA-health card unavailable: disk gone',
			['app' => 'keepiq']
		);

		$summary = $this->service(caService: $ca, logger: $logger)->fetchSummary(userId: 'root', isAdmin: true);

		$this->assertArrayHasKey('ca_health', $summary);
		$this->assertNull($summary['ca_health']);
		$this->assertTrue($summary['is_admin']);
	}//end testFailingCaDegradesToNullWithAWarning()

	/**
	 * A non-admin never triggers the CA lookup and gets no card.
	 *
	 * @return void
	 */
	public function testNonAdminDoesNotSeeTheCard(): void {
		$ca = $this->createMock(originalClassName: CertificateAuthorityService::class);
		$ca->expects($this->never())->method('getStatus');

		$summary = $this->service(caService: $ca)->fetchSummary(userId: 'alice', isAdmin: false);

		$this->assertArrayNotHasKey('ca_health', $summary);
		$this->assertFalse($summary['is_admin']);
	}//end testNonAdminDoesNotSeeTheCard()

	/**
	 * An empty user id is refused before any counter runs.
	 *
	 * @return void
	 */
	public function testEmptyUserIdIsRefused(): void {
		$ca = $this->createMock(originalClassName: CertificateAuthorityService::class);
		$ca->expects($this->never())->method('getStatus');

		$this->expectException(InvalidArgumentException::class);
		$this->expectExceptionMessage('userId is required');
		$this->service(caService: $ca)->fetchSummary(userId: '', isAdmin: true);
	}//end testEmptyUserIdIsRefused()
}//end class
