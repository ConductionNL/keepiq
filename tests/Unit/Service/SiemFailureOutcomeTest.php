<?php

/**
 * Unit tests for SiemFailureOutcome.
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

use OCA\Keepiq\Db\SiemSink;
use OCA\Keepiq\Exception\SiemDeliveryException;
use OCA\Keepiq\Service\SiemFailureOutcome;
use PHPUnit\Framework\TestCase;
use RuntimeException;

/**
 * Tests for the privacy-safe description of a SIEM delivery failure.
 *
 * @covers \OCA\Keepiq\Service\SiemFailureOutcome
 * @uses   \OCA\Keepiq\Db\SiemSink
 * @uses   \OCA\Keepiq\Db\StringPrimaryKey
 * @uses   \OCA\Keepiq\Exception\SiemDeliveryException
 */
class SiemFailureOutcomeTest extends TestCase {
	private function sink(string $endpoint): SiemSink {
		$sink = new SiemSink();
		$sink->setEndpoint($endpoint);

		return $sink;
	}

	public function testDescribesADeliveryExceptionWithItsStatus(): void {
		$outcome = new SiemFailureOutcome();

		$this->assertSame(
			'SiemDeliveryException (HTTP 503)',
			$outcome->describe(new SiemDeliveryException('secret body', 503))
		);
	}

	public function testDescribesADeliveryExceptionWithoutStatus(): void {
		$this->assertSame(
			'SiemDeliveryException (no answer)',
			(new SiemFailureOutcome())->describe(new SiemDeliveryException('x'))
		);
	}

	public function testReadsTheStatusFromAnExceptionResponse(): void {
		$exception = new class('boom') extends RuntimeException {
			public function getResponse(): object {
				return new class {
					public function getStatusCode(): string {
						return '418';
					}
				};
			}
		};

		$this->assertStringEndsWith('(HTTP 418)', (new SiemFailureOutcome())->describe($exception));
	}

	public function testIgnoresAResponseWithoutStatusCode(): void {
		$exception = new class('boom') extends RuntimeException {
			public function getResponse(): ?object {
				return null;
			}
		};

		$this->assertStringEndsWith('(no answer)', (new SiemFailureOutcome())->describe($exception));
	}

	public function testAppendsTheHostOfAnHttpsSink(): void {
		$this->assertSame(
			'RuntimeException (no answer) at siem.example.org',
			(new SiemFailureOutcome())->describe(
				new RuntimeException('token=abc'),
				$this->sink('https://user:pw@siem.example.org:8443/path?x=1')
			)
		);
	}

	public function testAppendsTheHostOfASyslogSink(): void {
		$this->assertSame(
			'RuntimeException (no answer) at logs.example.org',
			(new SiemFailureOutcome())->describe(new RuntimeException('x'), $this->sink('logs.example.org:514'))
		);
	}

	public function testOmitsAnUnparseableHost(): void {
		$this->assertSame(
			'RuntimeException (no answer)',
			(new SiemFailureOutcome())->describe(new RuntimeException('x'), $this->sink('https://'))
		);
	}

	public function testClassOfStripsTheNamespace(): void {
		$outcome = new SiemFailureOutcome();

		$this->assertSame('SiemDeliveryException', $outcome->classOf(new SiemDeliveryException('x')));
		$this->assertSame('RuntimeException', $outcome->classOf(new RuntimeException('x')));
	}
}
