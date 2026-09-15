<?php

/**
 * ConnectionReporter unit tests.
 *
 * The reporter tells integriq's connection registry what Keepiq's breach
 * lookups and SIEM drains met, and asks integriq to resolve a connection again
 * after a save. Every test guards one way it could quietly stop telling the
 * truth: reporting on every lookup, reporting before the refresh that would
 * retire the report, leaking a prefix or a token into a message, turning a
 * listener's failure into a failed call, or touching app config when integriq
 * is not installed.
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
 * @spec openspec/changes/adopt-connection-registry/specs/admin-integrations/spec.md#requirement-req-keepiq-conn-002-a-save-asks-integriq-to-look-again-and-a-lookup-or-a-drain-reports-what-it-met
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Keepiq\Tests\Unit\Service\Connection;

use OCA\Integriq\Event\ConnectionRefreshRequestedEvent;
use OCA\Integriq\Event\ConnectionStatusReportedEvent;
use OCA\Keepiq\Db\SiemSink;
use OCA\Keepiq\Service\Connection\ConnectionReporter;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\EventDispatcher\Event;
use OCP\EventDispatcher\IEventDispatcher;
use OCP\IAppConfig;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use ReflectionMethod;
use RuntimeException;

/**
 * Unit tests for ConnectionReporter.
 *
 * @covers \OCA\Keepiq\Service\Connection\ConnectionReporter
 */
class ConnectionReporterTest extends TestCase {

	/**
	 * Mocked event dispatcher.
	 *
	 * @var IEventDispatcher&MockObject
	 */
	private IEventDispatcher&MockObject $dispatcher;

	/**
	 * Mocked app config, backed by $this->store.
	 *
	 * @var IAppConfig&MockObject
	 */
	private IAppConfig&MockObject $appConfig;

	/**
	 * Mocked logger.
	 *
	 * @var LoggerInterface&MockObject
	 */
	private LoggerInterface&MockObject $logger;

	/**
	 * The app-config values, keyed by config key.
	 *
	 * @var array<string, string>
	 */
	private array $store = [];

	/**
	 * The Unix time the clock answers.
	 *
	 * @var int
	 */
	private int $now = 1_760_000_000;

	/**
	 * Every event handed to the dispatcher.
	 *
	 * @var array<int, Event>
	 */
	private array $sent = [];

	/**
	 * Set up the fixtures.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		$this->sent  = [];
		$this->store = [];

		$this->dispatcher = $this->createMock(originalClassName: IEventDispatcher::class);
		$this->dispatcher->method('dispatchTyped')->willReturnCallback(
			function (Event $event): void {
				$this->sent[] = $event;
			}
		);

		$this->appConfig = $this->createMock(originalClassName: IAppConfig::class);
		$this->appConfig->method('getValueString')->willReturnCallback(
			fn (string $app, string $key, string $default = ''): string => ($this->store[$key] ?? $default)
		);
		$this->appConfig->method('setValueString')->willReturnCallback(
			function (string $app, string $key, string $value): bool {
				$this->store[$key] = $value;
				return true;
			}
		);
		$this->appConfig->method('deleteKey')->willReturnCallback(
			function (string $app, string $key): void {
				unset($this->store[$key]);
			}
		);

		$this->logger = $this->createMock(originalClassName: LoggerInterface::class);
	}//end setUp()

	/**
	 * The reporter as production builds it.
	 *
	 * @param IEventDispatcher|null $dispatcher Another dispatcher, or null for the recording one.
	 * @param IAppConfig|null       $appConfig  Another app config, or null for the backed one.
	 *
	 * @return ConnectionReporter
	 */
	private function reporter(?IEventDispatcher $dispatcher = null, ?IAppConfig $appConfig = null): ConnectionReporter {
		$time = $this->createMock(originalClassName: ITimeFactory::class);
		$time->method('getTime')->willReturnCallback(fn (): int => $this->now);

		return new ConnectionReporter(
			eventDispatcher: ($dispatcher ?? $this->dispatcher),
			appConfig: ($appConfig ?? $this->appConfig),
			timeFactory: $time,
			logger: $this->logger,
		);
	}//end reporter()

	/**
	 * The reporter as it behaves on an instance without integriq.
	 *
	 * Only the class lookup is replaced. The stubs make both event classes
	 * resolvable in this process, so absence is simulated at the one seam
	 * that asks.
	 *
	 * @return ConnectionReporter
	 */
	private function reporterWithoutIntegriq(): ConnectionReporter {
		$time = $this->createMock(originalClassName: ITimeFactory::class);

		return new class($this->dispatcher, $this->appConfig, $time, $this->logger) extends ConnectionReporter {

			/**
			 * Integriq is not installed, so no class resolves.
			 *
			 * @param string $eventClass The class name asked for.
			 *
			 * @return string|null Always null.
			 */
			protected function resolveEventClass(string $eventClass): ?string {
				return null;
			}//end resolveEventClass()
		};
	}//end reporterWithoutIntegriq()

	/**
	 * A sink as the drain leaves it.
	 *
	 * @param string      $endpoint The stored endpoint.
	 * @param string|null $status   The last delivery status.
	 *
	 * @return SiemSink
	 */
	private function sink(string $endpoint, ?string $status): SiemSink {
		$sink = new SiemSink();
		$sink->setEndpoint($endpoint);
		$sink->setLastDeliveryStatus($status);
		return $sink;
	}//end sink()

	/**
	 * The status of every event sent, with its class and key.
	 *
	 * @return array<int, array{0: string, 1: string, 2: string}>
	 */
	private function sentSummary(): array {
		return array_map(
			static fn (Event $event): array => [
				(new \ReflectionClass($event))->getShortName(),
				(string) $event->key,
				(property_exists($event, 'status') === true ? $event->status : ''),
			],
			$this->sent
		);
	}//end sentSummary()

	/**
	 * A range lookup reports with this app's id, the hibp key, the status and the message.
	 *
	 * @return void
	 */
	public function testARangeLookupReportsUnderTheHibpKey(): void {
		$this->assertTrue(condition: $this->reporter()->reportBreachLookup(httpStatus: 200));

		$this->assertCount(expectedCount: 1, haystack: $this->sent);
		$event = $this->sent[0];
		$this->assertInstanceOf(expected: ConnectionStatusReportedEvent::class, actual: $event);
		$this->assertSame(expected: 'keepiq', actual: $event->app);
		$this->assertSame(expected: 'hibp', actual: $event->key);
		$this->assertSame(expected: 'configured', actual: $event->status);
		$this->assertSame(expected: 'The last range lookup reached Have I Been Pwned.', actual: $event->message);
		$this->assertSame(expected: 'configured|' . $this->now, actual: $this->store['connection_report_hibp']);
	}//end testARangeLookupReportsUnderTheHibpKey()

	/**
	 * A drain reports under the siem key, judging only the sinks it tried.
	 *
	 * @return void
	 */
	public function testADrainReportsUnderTheSiemKey(): void {
		$this->assertTrue(
			condition: $this->reporter()->reportSiemDrain(
				enabledSinks: 2,
				attemptedSinks: [
					$this->sink(endpoint: 'https://siem.gemeente.example/in', status: 'ok'),
					$this->sink(endpoint: 'logs.gemeente.example:6514', status: 'failing'),
				]
			)
		);

		$this->assertSame(expected: [['ConnectionStatusReportedEvent', 'siem', 'limited']], actual: $this->sentSummary());
		$this->assertSame(
			expected: '1 of 2 SIEM sinks took their last delivery. The first to fail is at logs.gemeente.example.',
			actual: $this->sent[0]->message
		);
	}//end testADrainReportsUnderTheSiemKey()

	/**
	 * A drain that tried no sink while sinks are on sends nothing.
	 *
	 * @return void
	 */
	public function testADrainThatMetNothingSendsNothing(): void {
		$this->assertFalse(condition: $this->reporter()->reportSiemDrain(enabledSinks: 3, attemptedSinks: []));
		$this->assertSame(expected: [], actual: $this->sent);
		$this->assertArrayNotHasKey(key: 'connection_report_siem', array: $this->store);
	}//end testADrainThatMetNothingSendsNothing()

	/**
	 * Deleting the last sink refreshes first and reports after.
	 *
	 * Integriq retires every observation older than the refresh, so a report
	 * sent before it would be thrown away.
	 *
	 * @return void
	 */
	public function testASinkChangeRefreshesBeforeItReports(): void {
		$this->assertTrue(condition: $this->reporter()->siemSinksChanged(enabledSinkCount: static fn (): int => 0));

		$this->assertSame(
			expected: [['ConnectionRefreshRequestedEvent', 'siem', ''], ['ConnectionStatusReportedEvent', 'siem', 'unconfigured']],
			actual: $this->sentSummary()
		);
		$this->assertSame(expected: 'keepiq', actual: $this->sent[0]->app);
	}//end testASinkChangeRefreshesBeforeItReports()

	/**
	 * A sink change with sinks still on sends the refresh alone.
	 *
	 * @return void
	 */
	public function testASinkChangeWithSinksOnSendsTheRefreshAlone(): void {
		$this->assertFalse(condition: $this->reporter()->siemSinksChanged(enabledSinkCount: static fn (): int => 1));
		$this->assertSame(expected: [['ConnectionRefreshRequestedEvent', 'siem', '']], actual: $this->sentSummary());
	}//end testASinkChangeWithSinksOnSendsTheRefreshAlone()

	/**
	 * A breach check save sends a refresh for hibp and no report.
	 *
	 * @return void
	 */
	public function testABreachCheckSaveSendsARefreshOnly(): void {
		$this->assertTrue(condition: $this->reporter()->breachCheckSaved());
		$this->assertSame(expected: [['ConnectionRefreshRequestedEvent', 'hibp', '']], actual: $this->sentSummary());
		$this->assertSame(expected: 'keepiq', actual: $this->sent[0]->app);
	}//end testABreachCheckSaveSendsARefreshOnly()

	/**
	 * The same outcome reports once an hour, not on every lookup.
	 *
	 * @return void
	 */
	public function testTheSameStatusReportsOnceAnHour(): void {
		$reporter = $this->reporter();

		$this->assertTrue(condition: $reporter->reportBreachLookup(httpStatus: 200));
		$this->now += 600;
		$this->assertFalse(condition: $reporter->reportBreachLookup(httpStatus: 200));
		$this->now += (ConnectionReporter::REPEAT_SECONDS - 601);
		$this->assertFalse(condition: $reporter->reportBreachLookup(httpStatus: 200));
		$this->now += 1;
		$this->assertTrue(condition: $reporter->reportBreachLookup(httpStatus: 200));

		$this->assertCount(expectedCount: 2, haystack: $this->sent);
	}//end testTheSameStatusReportsOnceAnHour()

	/**
	 * A different outcome reports after five minutes, and not before.
	 *
	 * @return void
	 */
	public function testAChangedStatusWaitsFiveMinutes(): void {
		$reporter = $this->reporter();

		$this->assertTrue(condition: $reporter->reportBreachLookup(httpStatus: 200));
		$this->now += (ConnectionReporter::CHANGE_SECONDS - 1);
		$this->assertFalse(condition: $reporter->reportBreachLookup(httpStatus: 429));
		$this->now += 1;
		$this->assertTrue(condition: $reporter->reportBreachLookup(httpStatus: 429));

		$this->assertSame(
			expected: ['configured', 'limited'],
			actual: array_map(static fn (Event $event): string => $event->status, $this->sent)
		);
	}//end testAChangedStatusWaitsFiveMinutes()

	/**
	 * Each connection keeps its own memory.
	 *
	 * @return void
	 */
	public function testEachConnectionKeepsItsOwnMemory(): void {
		$reporter = $this->reporter();

		$this->assertTrue(condition: $reporter->reportBreachLookup(httpStatus: 200));
		$this->assertTrue(condition: $reporter->reportSiemDrain(enabledSinks: 0, attemptedSinks: []));

		$this->assertCount(expectedCount: 2, haystack: $this->sent);
	}//end testEachConnectionKeepsItsOwnMemory()

	/**
	 * A save clears the memory of its own key only, so the next outcome goes out at once.
	 *
	 * @return void
	 */
	public function testASaveClearsItsOwnMemory(): void {
		$reporter = $this->reporter();
		$reporter->reportBreachLookup(httpStatus: 200);
		$reporter->reportSiemDrain(enabledSinks: 0, attemptedSinks: []);

		$reporter->breachCheckSaved();

		$this->assertArrayNotHasKey(key: 'connection_report_hibp', array: $this->store);
		$this->assertArrayHasKey(key: 'connection_report_siem', array: $this->store);
		$this->assertTrue(condition: $reporter->reportBreachLookup(httpStatus: 200));
	}//end testASaveClearsItsOwnMemory()

	/**
	 * No message carries the lookup input, a token, a path or a full URL.
	 *
	 * @return void
	 */
	public function testNoMessageCarriesTheInputOrAToken(): void {
		$reporter = $this->reporter();
		$prefix   = 'ABCDE';
		$token    = 's3cr3t-token';

		foreach ([200, 429, 404, 503, null] as $status) {
			$this->store = [];
			$reporter->reportBreachLookup(httpStatus: $status);
		}

		$this->store = [];
		$reporter->reportSiemDrain(
			enabledSinks: 2,
			attemptedSinks: [
				$this->sink(endpoint: 'https://svc:' . $token . '@hooks.gemeente.example/ingest?token=' . $token, status: 'failing'),
				$this->sink(endpoint: 'https://hooks.gemeente.example/' . $token, status: 'ok'),
			]
		);

		$this->assertCount(expectedCount: 6, haystack: $this->sent);
		foreach ($this->sent as $event) {
			$this->assertStringNotContainsString(needle: $prefix, haystack: $event->message);
			$this->assertStringNotContainsString(needle: $token, haystack: $event->message);
			$this->assertStringNotContainsString(needle: '://', haystack: $event->message);
			$this->assertStringNotContainsString(needle: '/ingest', haystack: $event->message);
			$this->assertStringNotContainsString(needle: 'pwnedpasswords', haystack: $event->message);
		}
	}//end testNoMessageCarriesTheInputOrAToken()

	/**
	 * Without integriq nothing is read, counted, sent, stored, cleared or logged.
	 *
	 * @return void
	 */
	public function testWithoutIntegriqNothingIsReadSentStoredOrLogged(): void {
		$this->dispatcher->expects($this->never())->method('dispatchTyped');
		$this->appConfig->expects($this->never())->method('getValueString');
		$this->appConfig->expects($this->never())->method('setValueString');
		$this->appConfig->expects($this->never())->method('deleteKey');
		$this->logger->expects($this->never())->method('warning');

		$reporter = $this->reporterWithoutIntegriq();
		$counted  = false;

		$this->assertFalse(condition: $reporter->reportBreachLookup(httpStatus: 503));
		$this->assertFalse(condition: $reporter->breachCheckSaved());
		$this->assertFalse(
			condition: $reporter->siemSinksChanged(
				enabledSinkCount: static function () use (&$counted): int {
					$counted = true;
					return 0;
				}
			)
		);
		$this->assertFalse(condition: $reporter->reportSiemDrain(enabledSinks: 0, attemptedSinks: []));
		$this->assertFalse(condition: $counted);
	}//end testWithoutIntegriqNothingIsReadSentStoredOrLogged()

	/**
	 * The class lookup answers null for a class nobody ships, and the class for a stub.
	 *
	 * This is the real guard, not the test double above.
	 *
	 * @return void
	 */
	public function testTheLookupAnswersNullForAnAbsentClass(): void {
		$method   = new ReflectionMethod(ConnectionReporter::class, 'resolveEventClass');
		$reporter = $this->reporter();

		$this->assertNull(actual: $method->invoke($reporter, 'OCA\\Nobody\\Event\\ShipsThisEvent'));
		$this->assertSame(
			expected: '\\' . ConnectionReporter::STATUS_EVENT,
			actual: $method->invoke($reporter, ConnectionReporter::STATUS_EVENT)
		);
	}//end testTheLookupAnswersNullForAnAbsentClass()

	/**
	 * The event names are the ones the contract fixes.
	 *
	 * A string class name is exactly the reference that rots into a silent
	 * no-op after a rename, so it is compared to the stubs' real names.
	 *
	 * @return void
	 */
	public function testTheEventNamesAreTheContractNames(): void {
		$this->assertSame(expected: ConnectionStatusReportedEvent::class, actual: ConnectionReporter::STATUS_EVENT);
		$this->assertSame(expected: ConnectionRefreshRequestedEvent::class, actual: ConnectionReporter::REFRESH_EVENT);
	}//end testTheEventNamesAreTheContractNames()

	/**
	 * The keys the reporter sends are the keys the declaration ships, in order.
	 *
	 * Integriq refuses a report for an undeclared key with only a warning, so
	 * a drifted key would read as a silent no-op.
	 *
	 * @return void
	 */
	public function testTheKeysAreTheDeclaredKeys(): void {
		$declaration = json_decode(
			(string) file_get_contents(dirname(__DIR__, 4) . '/lib/Settings/connections.json'),
			true,
			512,
			JSON_THROW_ON_ERROR
		);

		$this->assertSame(
			expected: array_column($declaration['connections'], 'key'),
			actual: ConnectionReporter::KEYS
		);
		$this->assertSame(expected: ConnectionReporter::APP_ID, actual: $declaration['app']);
	}//end testTheKeysAreTheDeclaredKeys()

	/**
	 * A listener that throws never escapes, and leaves the memory unwritten.
	 *
	 * An unwritten memory means the next call tries again instead of keeping
	 * quiet for an hour about a report that never landed.
	 *
	 * @return void
	 */
	public function testAThrowingListenerNeverEscapes(): void {
		$dispatcher = $this->createMock(originalClassName: IEventDispatcher::class);
		$dispatcher->method('dispatchTyped')->willThrowException(new RuntimeException('registry down'));
		$this->logger->expects($this->exactly(count: 2))->method('warning')
			->with($this->stringContains(string: 'could not send'), $this->arrayHasKey(key: 'key'));

		$reporter = $this->reporter(dispatcher: $dispatcher);

		$this->assertFalse(condition: $reporter->reportBreachLookup(httpStatus: 200));
		$this->assertFalse(condition: $reporter->breachCheckSaved());
		$this->assertArrayNotHasKey(key: 'connection_report_hibp', array: $this->store);
	}//end testAThrowingListenerNeverEscapes()

	/**
	 * A failing app config never escapes into the lookup.
	 *
	 * @return void
	 */
	public function testAFailingAppConfigNeverEscapes(): void {
		$appConfig = $this->createMock(originalClassName: IAppConfig::class);
		$appConfig->method('getValueString')->willThrowException(new RuntimeException('type conflict'));
		$this->logger->expects($this->once())->method('warning')
			->with($this->stringContains(string: 'could not report'), $this->arrayHasKey(key: 'exception'));

		$this->assertFalse(condition: $this->reporter(appConfig: $appConfig)->reportBreachLookup(httpStatus: 200));
		$this->assertSame(expected: [], actual: $this->sent);
	}//end testAFailingAppConfigNeverEscapes()
}//end class
