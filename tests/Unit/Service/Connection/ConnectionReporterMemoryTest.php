<?php

/**
 * ConnectionReporter memory and input edges: a stored report memory that
 * cannot be read as "status|time", a sink endpoint without a host, an answer
 * that is not an object, and a sink counter that throws.
 *
 * @category Tests
 * @package  OCA\Keepiq\Tests\Unit\Service\Connection
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://conduction.nl
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Keepiq\Tests\Unit\Service\Connection;

use OCA\Integriq\Event\ConnectionStatusReportedEvent;
use OCA\Keepiq\Db\SiemSink;
use OCA\Keepiq\Service\Connection\ConnectionObservations;
use OCA\Keepiq\Service\Connection\ConnectionReporter;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\EventDispatcher\Event;
use OCP\EventDispatcher\IEventDispatcher;
use OCP\IAppConfig;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use RuntimeException;

/**
 * The memory parsing and input edges of the connection reporter.
 *
 * @covers \OCA\Keepiq\Service\Connection\ConnectionReporter
 * @covers \OCA\Keepiq\Service\Connection\ConnectionObservations
 * @uses   \OCA\Keepiq\Db\SiemSink
 */
class ConnectionReporterMemoryTest extends TestCase {

	/**
	 * The fixed clock.
	 *
	 * @var integer
	 */
	private const NOW = 1_760_000_000;

	/**
	 * The dispatcher double.
	 *
	 * @var IEventDispatcher&MockObject
	 */
	private IEventDispatcher&MockObject $dispatcher;

	/**
	 * The app config double.
	 *
	 * @var IAppConfig&MockObject
	 */
	private IAppConfig&MockObject $appConfig;

	/**
	 * The logger double.
	 *
	 * @var LoggerInterface&MockObject
	 */
	private LoggerInterface&MockObject $logger;

	/**
	 * The stored app config values by key.
	 *
	 * @var array<string, string>
	 */
	private array $store = [];

	/**
	 * The events dispatched.
	 *
	 * @var list<Event>
	 */
	private array $sent = [];

	/**
	 * Set up the doubles.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		$this->sent       = [];
		$this->store      = [];
		$this->dispatcher = $this->createMock(IEventDispatcher::class);
		$this->dispatcher->method('dispatchTyped')->willReturnCallback(
			function (Event $event): void {
				$this->sent[] = $event;
			}
		);
		$this->appConfig = $this->createMock(IAppConfig::class);
		$this->appConfig->method('getValueString')->willReturnCallback(
			fn (string $app, string $key, string $default = ''): string => ($this->store[$key] ?? $default)
		);
		$this->appConfig->method('setValueString')->willReturnCallback(
			function (string $app, string $key, string $value): bool {
				$this->store[$key] = $value;
				return true;
			}
		);
		$this->logger = $this->createMock(LoggerInterface::class);
	}//end setUp()

	/**
	 * The reporter under test, on a fixed clock.
	 *
	 * @return ConnectionReporter
	 */
	private function reporter(): ConnectionReporter {
		$time = $this->createMock(ITimeFactory::class);
		$time->method('getTime')->willReturn(self::NOW);

		return new ConnectionReporter(
			eventDispatcher: $this->dispatcher,
			appConfig: $this->appConfig,
			timeFactory: $time,
			logger: $this->logger,
		);
	}//end reporter()

	/**
	 * Memories that cannot be read as "status|time".
	 *
	 * @return array<string, array{string}>
	 */
	public static function unreadableMemories(): array {
		return [
			'no separator'      => ['configured'],
			'empty time'        => ['configured|'],
			'non-numeric time'  => ['configured|soon'],
			'negative time'     => ['configured|-5'],
			'time with a space' => ['configured| 1760000000'],
		];
	}//end unreadableMemories()

	/**
	 * A memory that is not "status|digits" counts as no memory: the same status goes out at once and the memory
	 * is rewritten in the readable form.
	 *
	 * @param string $memory The stored memory.
	 *
	 * @return void
	 *
	 * @dataProvider unreadableMemories
	 */
	public function testAnUnreadableMemoryDoesNotHoldTheReportBack(string $memory): void {
		$this->store['connection_report_hibp'] = $memory;

		self::assertTrue($this->reporter()->reportBreachLookup(httpStatus: 200));

		self::assertCount(1, $this->sent);
		self::assertInstanceOf(ConnectionStatusReportedEvent::class, $this->sent[0]);
		self::assertSame('configured', $this->sent[0]->status);
		self::assertSame('configured|' . self::NOW, $this->store['connection_report_hibp']);
	}//end testAnUnreadableMemoryDoesNotHoldTheReportBack()

	/**
	 * A readable memory of the same status written a second ago still holds the report back, so the parse is
	 * what decides, not the mere presence of a memory.
	 *
	 * @return void
	 */
	public function testAReadableRecentMemoryHoldsTheSameStatusBack(): void {
		$this->store['connection_report_hibp'] = 'configured|' . (self::NOW - 1);

		self::assertFalse($this->reporter()->reportBreachLookup(httpStatus: 200));

		self::assertSame([], $this->sent);
		self::assertSame('configured|' . (self::NOW - 1), $this->store['connection_report_hibp']);
	}//end testAReadableRecentMemoryHoldsTheSameStatusBack()

	/**
	 * A failed delivery to a sink whose endpoint has no host reports the failure without an "at" clause.
	 *
	 * @return void
	 */
	public function testAFailedSinkWithoutAHostIsReportedWithoutOne(): void {
		$sink = new SiemSink();
		$sink->setEndpoint('https://');
		$sink->setLastDeliveryStatus('failed');

		self::assertTrue(
			$this->reporter()->reportSiemDrain(
				enabledSinks: 1,
				attemptedSinks: ['first' => $sink],
				sinkCount: static fn (): int => 1
			)
		);

		self::assertCount(1, $this->sent);
		self::assertSame('siem', $this->sent[0]->key);
		self::assertSame('error', $this->sent[0]->status);
		self::assertSame('The last delivery to the SIEM sink failed.', $this->sent[0]->message);
	}//end testAFailedSinkWithoutAHostIsReportedWithoutOne()

	/**
	 * An endpoint that does not parse to a host reduces to the empty string.
	 *
	 * @return void
	 */
	public function testAnEndpointWithoutAHostReducesToNothing(): void {
		$observations = new ConnectionObservations();

		self::assertSame('', $observations->siemSinkHost(endpoint: 'https://'));
		self::assertSame('', $observations->siemSinkHost(endpoint: 'file:///var/log/audit'));
		self::assertSame('siem.example', $observations->siemSinkHost(endpoint: 'udp://siem.example:514'));
	}//end testAnEndpointWithoutAHostReducesToNothing()

	/**
	 * An exception whose getResponse() gives no object carries no status.
	 *
	 * @return void
	 */
	public function testAnAnswerThatIsNotAnObjectGivesNoStatus(): void {
		$withoutAnswer = new class('upstream') extends RuntimeException {

			/**
			 * No answer was received.
			 *
			 * @return null
			 */
			public function getResponse() {
				return null;
			}
		};

		self::assertNull($this->reporter()->httpStatusOf($withoutAnswer));
	}//end testAnAnswerThatIsNotAnObjectGivesNoStatus()

	/**
	 * A sink counter that throws is logged by key and exception class; nothing is sent or stored.
	 *
	 * @return void
	 */
	public function testAThrowingSinkCounterIsLoggedAndNothingIsSent(): void {
		$this->logger->expects($this->once())->method('warning')
			->with(
				'Keepiq: could not report a connection to integriq',
				['key' => 'siem', 'exception' => RuntimeException::class]
			);

		$result = $this->reporter()->reportSiemDrain(
			enabledSinks: 0,
			attemptedSinks: [],
			sinkCount: static function (): int {
				throw new RuntimeException('mapper down');
			}
		);

		self::assertFalse($result);
		self::assertSame([], $this->sent);
		self::assertSame([], $this->store);
	}//end testAThrowingSinkCounterIsLoggedAndNothingIsSent()
}//end class
