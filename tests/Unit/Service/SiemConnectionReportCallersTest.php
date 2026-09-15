<?php

/**
 * SIEM connection report caller tests.
 *
 * A sink create, change or delete refreshes the SIEM export row, and a drain
 * reports what it met. These tests drive the real services and, where the
 * order or the payload matters, the real reporter over a recording
 * dispatcher, so they see what integriq would receive.
 *
 * @category Tests
 * @package  OCA\Keepiq\Tests\Unit\Service
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

namespace OCA\Keepiq\Tests\Unit\Service;

use OCA\Keepiq\Db\SiemQueueItem;
use OCA\Keepiq\Db\SiemQueueItemMapper;
use OCA\Keepiq\Db\SiemSink;
use OCA\Keepiq\Db\SiemSinkMapper;
use OCA\Keepiq\Service\Connection\ConnectionReporter;
use OCA\Keepiq\Service\SiemService;
use OCA\Keepiq\Service\SiemSinkService;
use OCA\Keepiq\Service\SiemTransport;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\EventDispatcher\Event;
use OCP\EventDispatcher\IEventDispatcher;
use OCP\IAppConfig;
use OCP\IGroupManager;
use OCP\Security\ICrypto;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use RuntimeException;

/**
 * Unit tests for the SIEM callers of ConnectionReporter.
 *
 * @covers \OCA\Keepiq\Service\SiemSinkService
 * @covers \OCA\Keepiq\Service\SiemService
 */
class SiemConnectionReportCallersTest extends TestCase {

	/**
	 * Mocked sink mapper.
	 *
	 * @var SiemSinkMapper&MockObject
	 */
	private SiemSinkMapper&MockObject $sinkMapper;

	/**
	 * Mocked queue mapper.
	 *
	 * @var SiemQueueItemMapper&MockObject
	 */
	private SiemQueueItemMapper&MockObject $queueMapper;

	/**
	 * Mocked transport.
	 *
	 * @var SiemTransport&MockObject
	 */
	private SiemTransport&MockObject $transport;

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
		$this->sent        = [];
		$this->sinkMapper  = $this->createMock(originalClassName: SiemSinkMapper::class);
		$this->queueMapper = $this->createMock(originalClassName: SiemQueueItemMapper::class);
		$this->transport   = $this->createMock(originalClassName: SiemTransport::class);

		$this->sinkMapper->method('insert')->willReturnArgument(0);
		$this->sinkMapper->method('update')->willReturnArgument(0);
	}//end setUp()

	/**
	 * A real reporter over a recording dispatcher and an in-memory app config.
	 *
	 * @return ConnectionReporter
	 */
	private function recordingReporter(): ConnectionReporter {
		$dispatcher = $this->createMock(originalClassName: IEventDispatcher::class);
		$dispatcher->method('dispatchTyped')->willReturnCallback(
			function (Event $event): void {
				$this->sent[] = $event;
			}
		);

		$store     = [];
		$appConfig = $this->createMock(originalClassName: IAppConfig::class);
		$appConfig->method('getValueString')->willReturnCallback(
			static function (string $app, string $key, string $default = '') use (&$store): string {
				return ($store[$key] ?? $default);
			}
		);
		$appConfig->method('setValueString')->willReturnCallback(
			static function (string $app, string $key, string $value) use (&$store): bool {
				$store[$key] = $value;
				return true;
			}
		);

		$time = $this->createMock(originalClassName: ITimeFactory::class);
		$time->method('getTime')->willReturn(1_760_000_000);

		return new ConnectionReporter(
			eventDispatcher: $dispatcher,
			appConfig: $appConfig,
			timeFactory: $time,
			logger: new NullLogger(),
		);
	}//end recordingReporter()

	/**
	 * The sink service with this reporter.
	 *
	 * @param ConnectionReporter|null $reporter The reporter, or null for an instance without one.
	 *
	 * @return SiemSinkService
	 */
	private function sinkService(?ConnectionReporter $reporter): SiemSinkService {
		return new SiemSinkService(
			sinkMapper: $this->sinkMapper,
			queueMapper: $this->queueMapper,
			crypto: $this->createMock(originalClassName: ICrypto::class),
			transport: $this->transport,
			connectionReporter: $reporter,
		);
	}//end sinkService()

	/**
	 * The drain service with this reporter.
	 *
	 * @param ConnectionReporter|null $reporter The reporter, or null for an instance without one.
	 *
	 * @return SiemService
	 */
	private function siemService(?ConnectionReporter $reporter): SiemService {
		return new SiemService(
			sinkMapper: $this->sinkMapper,
			queueMapper: $this->queueMapper,
			transport: $this->transport,
			sinkService: $this->sinkService(reporter: null),
			groupManager: $this->createMock(originalClassName: IGroupManager::class),
			notificationService: null,
			logger: new NullLogger(),
			connectionReporter: $reporter,
		);
	}//end siemService()

	/**
	 * A stored sink.
	 *
	 * @param string $id       The sink id.
	 * @param string $endpoint The endpoint.
	 *
	 * @return SiemSink
	 */
	private function sink(string $id, string $endpoint): SiemSink {
		$sink = new SiemSink();
		$sink->setId($id);
		$sink->setType('webhook');
		$sink->setEndpoint($endpoint);
		return $sink;
	}//end sink()

	/**
	 * A due queue row for a sink.
	 *
	 * @param string $sinkId The sink id.
	 *
	 * @return SiemQueueItem
	 */
	private function dueItem(string $sinkId): SiemQueueItem {
		$item = new SiemQueueItem();
		$item->setId('item-' . $sinkId);
		$item->setSinkId($sinkId);
		$item->setPayload('{}');
		$item->setStatus('pending');
		return $item;
	}//end dueItem()

	/**
	 * The class short name, key and status of every event sent.
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
	 * Deleting the last sink refreshes the SIEM row, then reports it unconfigured.
	 *
	 * @return void
	 */
	public function testDeletingTheLastSinkRefreshesThenReports(): void {
		$this->sinkMapper->method('findById')->willReturn($this->sink(id: 'sink-1', endpoint: 'https://siem.gemeente.example/in'));
		$this->sinkMapper->method('findEnabled')->willReturn([]);

		$this->sinkService(reporter: $this->recordingReporter())->deleteSink(adminUid: 'admin', sinkId: 'sink-1');

		$this->assertSame(
			expected: [['ConnectionRefreshRequestedEvent', 'siem', ''], ['ConnectionStatusReportedEvent', 'siem', 'unconfigured']],
			actual: $this->sentSummary()
		);
	}//end testDeletingTheLastSinkRefreshesThenReports()

	/**
	 * Creating and changing a sink each ask for a refresh.
	 *
	 * @return void
	 */
	public function testCreatingAndChangingASinkRefresh(): void {
		$sink = $this->sink(id: 'sink-1', endpoint: 'https://siem.gemeente.example/in');
		$this->sinkMapper->method('findById')->willReturn($sink);
		$this->sinkMapper->method('findEnabled')->willReturn([$sink]);

		$service = $this->sinkService(reporter: $this->recordingReporter());
		$service->createSink(adminUid: 'admin', params: ['type' => 'webhook', 'endpoint' => 'https://siem.gemeente.example/in']);
		$service->updateSink(adminUid: 'admin', sinkId: 'sink-1', params: ['name' => 'Main SIEM']);

		$this->assertSame(
			expected: [['ConnectionRefreshRequestedEvent', 'siem', ''], ['ConnectionRefreshRequestedEvent', 'siem', '']],
			actual: $this->sentSummary()
		);
	}//end testCreatingAndChangingASinkRefresh()

	/**
	 * A refused sink create wrote nothing and asks for nothing.
	 *
	 * @return void
	 */
	public function testARefusedSinkCreateAsksForNothing(): void {
		$reporter = $this->createMock(originalClassName: ConnectionReporter::class);
		$reporter->expects($this->never())->method('siemSinksChanged');

		try {
			$this->sinkService(reporter: $reporter)->createSink(adminUid: 'admin', params: ['type' => 'webhook', 'endpoint' => 'http://plain.example']);
			$this->fail(message: 'A plain http webhook must be refused.');
		} catch (\InvalidArgumentException) {
			$this->addToAssertionCount(count: 1);
		}
	}//end testARefusedSinkCreateAsksForNothing()

	/**
	 * A drain hands the reporter only the sinks it tried, after the attempt, with the enabled count.
	 *
	 * @return void
	 */
	public function testADrainReportsTheSinksItTried(): void {
		$tried = $this->sink(id: 'sink-1', endpoint: 'https://siem.gemeente.example/in');
		$idle  = $this->sink(id: 'sink-2', endpoint: 'https://idle.gemeente.example/in');
		$this->sinkMapper->method('findEnabled')->willReturn([$tried, $idle]);
		$this->queueMapper->method('findDue')->willReturnCallback(
			fn (string $sinkId): array => ($sinkId === 'sink-1' ? [$this->dueItem(sinkId: 'sink-1'), $this->dueItem(sinkId: 'sink-1')] : [])
		);
		$this->transport->method('deliver')->willThrowException(new RuntimeException('down'));

		$reporter = $this->createMock(originalClassName: ConnectionReporter::class);
		$reporter->expects($this->once())->method('reportSiemDrain')->with(
			2,
			$this->callback(
				static fn (array $sinks): bool => $sinks === [$tried] && $tried->getLastDeliveryStatus() === 'failing'
			)
		)->willReturn(true);

		$this->siemService(reporter: $reporter)->deliverDue();
	}//end testADrainReportsTheSinksItTried()

	/**
	 * A drain with a failing sink reaches integriq as an error naming the host only.
	 *
	 * @return void
	 */
	public function testADrainWithAFailingSinkReportsItsHostOnly(): void {
		$sink = $this->sink(id: 'sink-1', endpoint: 'https://svc:s3cr3t@siem.gemeente.example/in?token=s3cr3t');
		$this->sinkMapper->method('findEnabled')->willReturn([$sink]);
		$this->queueMapper->method('findDue')->willReturn([$this->dueItem(sinkId: 'sink-1')]);
		$this->transport->method('deliver')->willThrowException(new RuntimeException('401 for https://svc:s3cr3t@siem.gemeente.example/in'));

		$this->siemService(reporter: $this->recordingReporter())->deliverDue();

		$this->assertSame(expected: [['ConnectionStatusReportedEvent', 'siem', 'error']], actual: $this->sentSummary());
		$this->assertSame(
			expected: 'The last delivery to the SIEM sink at siem.gemeente.example failed.',
			actual: $this->sent[0]->message
		);
	}//end testADrainWithAFailingSinkReportsItsHostOnly()

	/**
	 * Without the reporter a drain and a sink change behave exactly as before.
	 *
	 * @return void
	 */
	public function testWithoutTheReporterNothingChanges(): void {
		$sink = $this->sink(id: 'sink-1', endpoint: 'https://siem.gemeente.example/in');
		$this->sinkMapper->method('findById')->willReturn($sink);
		$this->sinkMapper->method('findEnabled')->willReturn([$sink]);
		$this->queueMapper->method('findDue')->willReturn([$this->dueItem(sinkId: 'sink-1')]);

		$this->assertSame(expected: 1, actual: $this->siemService(reporter: null)->deliverDue());
		$this->sinkService(reporter: null)->deleteSink(adminUid: 'admin', sinkId: 'sink-1');
		$this->assertSame(expected: [], actual: $this->sent);
	}//end testWithoutTheReporterNothingChanges()
}//end class
