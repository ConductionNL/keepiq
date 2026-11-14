<?php

/**
 * Unit tests for BootstrapCertificateAuthority.
 *
 * @category Test
 * @package  OCA\Keepiq\Tests\Unit\Repair
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

namespace OCA\Keepiq\Tests\Unit\Repair;

use OCA\Keepiq\Repair\BootstrapCertificateAuthority;
use OCA\Keepiq\Service\CertificateAuthorityService;
use OCP\Migration\IOutput;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use RuntimeException;

/**
 * Tests for BootstrapCertificateAuthority.
 *
 * @covers \OCA\Keepiq\Repair\BootstrapCertificateAuthority
 */
class BootstrapCertificateAuthorityTest extends TestCase {

	/**
	 * The repair step has a stable name.
	 *
	 * @return void
	 */
	public function testName(): void {
		$step = new BootstrapCertificateAuthority(
			$this->createMock(CertificateAuthorityService::class),
			$this->createMock(LoggerInterface::class)
		);

		$this->assertSame('Bootstrap Keepiq Certificate Authority', $step->getName());
	}//end testName()

	/**
	 * A successful bootstrap reports success and logs nothing.
	 *
	 * @return void
	 */
	public function testASuccessfulBootstrapIsReported(): void {
		$ca = $this->createMock(CertificateAuthorityService::class);
		$ca->expects($this->once())->method('bootstrap');
		$logger = $this->createMock(LoggerInterface::class);
		$logger->expects($this->never())->method('error');
		$output = $this->createMock(IOutput::class);
		$messages = [];
		$output->method('info')->willReturnCallback(static function (string $m) use (&$messages): void {
			$messages[] = $m;
		});
		$output->expects($this->never())->method('warning');

		(new BootstrapCertificateAuthority($ca, $logger))->run($output);

		$this->assertSame(
			['Bootstrapping Keepiq Certificate Authority...', 'Certificate Authority bootstrapped successfully'],
			$messages
		);
	}//end testASuccessfulBootstrapIsReported()

	/**
	 * A failing bootstrap is a warning and a logged error, never an exception out of the repair step.
	 *
	 * @return void
	 */
	public function testAFailingBootstrapWarnsAndLogsWithoutThrowing(): void {
		$ca = $this->createMock(CertificateAuthorityService::class);
		$ca->method('bootstrap')->willThrowException(new RuntimeException('no openssl'));
		$logger = $this->createMock(LoggerInterface::class);
		$logger->expects($this->once())->method('error')->with('Keepiq CA bootstrap failed', ['exception' => 'no openssl']);
		$output = $this->createMock(IOutput::class);
		$output->expects($this->once())->method('info')->with('Bootstrapping Keepiq Certificate Authority...');
		$output->expects($this->once())->method('warning')->with('CA bootstrap failed: no openssl');

		(new BootstrapCertificateAuthority($ca, $logger))->run($output);
	}//end testAFailingBootstrapWarnsAndLogsWithoutThrowing()
}//end class
