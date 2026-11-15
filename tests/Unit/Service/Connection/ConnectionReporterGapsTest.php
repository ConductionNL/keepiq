<?php

/**
 * ConnectionReporter edge paths: a memory that cannot be cleared, an event
 * class that is not an event, and the HTTP status a failed call still carries.
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

use OCA\Integriq\Event\ConnectionRefreshRequestedEvent;
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
 * The paths the main reporter test does not reach.
 *
 * @covers \OCA\Keepiq\Service\Connection\ConnectionReporter
 * @covers \OCA\Keepiq\Service\Connection\ConnectionObservations
 */
class ConnectionReporterGapsTest extends TestCase {

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
		$this->dispatcher = $this->createMock(IEventDispatcher::class);
		$this->dispatcher->method('dispatchTyped')->willReturnCallback(
			function (Event $event): void {
				$this->sent[] = $event;
			}
		);
		$this->appConfig = $this->createMock(IAppConfig::class);
		$this->logger    = $this->createMock(LoggerInterface::class);
	}//end setUp()

	/**
	 * The reporter under test.
	 *
	 * @return ConnectionReporter
	 */
	private function reporter(): ConnectionReporter {
		return new ConnectionReporter(
			eventDispatcher: $this->dispatcher,
			appConfig: $this->appConfig,
			timeFactory: $this->createMock(ITimeFactory::class),
			logger: $this->logger,
		);
	}//end reporter()

	/**
	 * A memory that cannot be cleared is logged by key and exception class, and the refresh is still sent.
	 *
	 * @return void
	 */
	public function testAMemoryThatCannotBeClearedIsLoggedAndTheRefreshStillGoes(): void {
		$this->appConfig->expects(self::once())
			->method('deleteKey')
			->with('keepiq', 'connection_report_hibp')
			->willThrowException(new RuntimeException('database gone'));
		$this->logger->expects(self::once())
			->method('warning')
			->with(
				'Keepiq: could not clear a connection report memory',
				['key' => 'hibp', 'exception' => RuntimeException::class]
			);

		self::assertTrue($this->reporter()->breachCheckSaved());

		self::assertCount(1, $this->sent);
		self::assertInstanceOf(ConnectionRefreshRequestedEvent::class, $this->sent[0]);
		self::assertSame('hibp', $this->sent[0]->key);
	}//end testAMemoryThatCannotBeClearedIsLoggedAndTheRefreshStillGoes()

	/**
	 * A resolved class that is not an event is never dispatched, and the refresh reports false.
	 *
	 * @return void
	 */
	public function testAClassThatIsNotAnEventIsNotDispatched(): void {
		$notAnEvent = new class('', '') {

			/**
			 * Takes the refresh event's arguments.
			 *
			 * @param string $app The app.
			 * @param string $key The connection key.
			 */
			public function __construct(public string $app, public string $key) {
			}
		};
		$reporter = new class($this->dispatcher, $this->appConfig, $this->createMock(ITimeFactory::class), $this->logger, $notAnEvent::class) extends ConnectionReporter {

			/**
			 * The class every event resolves to.
			 *
			 * @var string
			 */
			private string $resolved;

			/**
			 * @param IEventDispatcher $dispatcher The dispatcher.
			 * @param IAppConfig       $appConfig  The app config.
			 * @param ITimeFactory     $time       The clock.
			 * @param LoggerInterface  $logger     The logger.
			 * @param string           $resolved   The class every event resolves to.
			 */
			public function __construct(IEventDispatcher $dispatcher, IAppConfig $appConfig, ITimeFactory $time, LoggerInterface $logger, string $resolved) {
				parent::__construct(eventDispatcher: $dispatcher, appConfig: $appConfig, timeFactory: $time, logger: $logger);
				$this->resolved = $resolved;
			}

			/**
			 * Resolve every event to the non-event class.
			 *
			 * @param string $eventClass The requested class.
			 *
			 * @return string|null
			 */
			protected function resolveEventClass(string $eventClass): ?string {
				return $this->resolved;
			}
		};
		$this->logger->expects(self::never())->method('warning');

		self::assertFalse($reporter->breachCheckSaved());
		self::assertSame([], $this->sent);
	}//end testAClassThatIsNotAnEventIsNotDispatched()

	/**
	 * A failed call that still carries an answer gives that answer's status.
	 *
	 * @return void
	 */
	public function testAFailedCallWithAnAnswerGivesItsStatus(): void {
		$response  = new class {

			/**
			 * The status.
			 *
			 * @return string
			 */
			public function getStatusCode(): string {
				return '503';
			}
		};
		$exception = new class('upstream', $response) extends RuntimeException {

			/**
			 * @param string $message  The message.
			 * @param object $response The answer.
			 */
			public function __construct(string $message, private object $response) {
				parent::__construct($message);
			}

			/**
			 * The answer.
			 *
			 * @return object
			 */
			public function getResponse(): object {
				return $this->response;
			}
		};

		self::assertSame(503, $this->reporter()->httpStatusOf($exception));
	}//end testAFailedCallWithAnAnswerGivesItsStatus()

	/**
	 * A connection failure, or an answer without a status, gives no status.
	 *
	 * @return void
	 */
	public function testAFailureWithoutAStatusGivesNull(): void {
		$withoutStatus = new class('upstream') extends RuntimeException {

			/**
			 * An answer that has no status.
			 *
			 * @return object
			 */
			public function getResponse(): object {
				return new \stdClass();
			}
		};

		self::assertNull($this->reporter()->httpStatusOf(new RuntimeException('connect timeout')));
		self::assertNull($this->reporter()->httpStatusOf($withoutStatus));
	}//end testAFailureWithoutAStatusGivesNull()
}//end class
