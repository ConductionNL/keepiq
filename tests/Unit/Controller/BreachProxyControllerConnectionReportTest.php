<?php

/**
 * BreachProxyController connection report tests.
 *
 * A range lookup that reaches Have I Been Pwned reports its HTTP status to
 * integriq's connection registry. These tests drive the real controller and
 * the real reporter over a recording dispatcher, so they see exactly what
 * integriq would receive: which lookups report, with which status, and that
 * no message carries the caller's hash prefix or the request URL.
 *
 * @category Tests
 * @package  OCA\Keepiq\Tests\Unit\Controller
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://conduction.nl
 *
 * @spec openspec/changes/adopt-connection-registry/specs/admin-integrations/spec.md#requirement-req-keepiq-conn-003-a-report-names-a-status-code-or-a-host-and-nothing-a-user-typed
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Keepiq\Tests\Unit\Controller;

use OCA\Integriq\Event\ConnectionStatusReportedEvent;
use OCA\Keepiq\Controller\BreachProxyController;
use OCA\Keepiq\Service\Connection\ConnectionReporter;
use OCP\AppFramework\Http;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\EventDispatcher\Event;
use OCP\EventDispatcher\IEventDispatcher;
use OCP\Http\Client\IClient;
use OCP\Http\Client\IClientService;
use OCP\Http\Client\IResponse;
use OCP\IAppConfig;
use OCP\ICache;
use OCP\ICacheFactory;
use OCP\IRequest;
use OCP\IUser;
use OCP\IUserSession;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use RuntimeException;

/**
 * Unit tests for the connection report of BreachProxyController::range().
 *
 * @covers \OCA\Keepiq\Controller\BreachProxyController
 */
class BreachProxyControllerConnectionReportTest extends TestCase {

	/**
	 * The prefix every lookup here asks for.
	 *
	 * @var string
	 */
	private const PREFIX = 'C0FFE';

	/**
	 * Mocked app config: breach checking on, report memory backed by $store.
	 *
	 * @var IAppConfig&MockObject
	 */
	private IAppConfig&MockObject $appConfig;

	/**
	 * Mocked HTTP client service.
	 *
	 * @var IClientService&MockObject
	 */
	private IClientService&MockObject $clientService;

	/**
	 * Mocked cache.
	 *
	 * @var ICache&MockObject
	 */
	private ICache&MockObject $cache;

	/**
	 * The app-config string values, keyed by config key.
	 *
	 * @var array<string, string>
	 */
	private array $store = [];

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

		$this->appConfig = $this->createMock(originalClassName: IAppConfig::class);
		$this->appConfig->method('getValueBool')->willReturn(true);
		$this->appConfig->method('getValueString')->willReturnCallback(
			fn (string $app, string $key, string $default = ''): string => ($this->store[$key] ?? $default)
		);
		$this->appConfig->method('setValueString')->willReturnCallback(
			function (string $app, string $key, string $value): bool {
				$this->store[$key] = $value;
				return true;
			}
		);

		$this->clientService = $this->createMock(originalClassName: IClientService::class);
		$this->cache         = $this->createMock(originalClassName: ICache::class);
	}//end setUp()

	/**
	 * The controller with a real reporter over a recording dispatcher.
	 *
	 * @param bool $withReporter False to build it as an instance without the reporter.
	 *
	 * @return BreachProxyController
	 */
	private function controller(bool $withReporter = true): BreachProxyController {
		$cacheFactory = $this->createMock(originalClassName: ICacheFactory::class);
		$cacheFactory->method('createDistributed')->willReturn($this->cache);

		$user        = $this->createMock(originalClassName: IUser::class);
		$userSession = $this->createMock(originalClassName: IUserSession::class);
		$userSession->method('getUser')->willReturn($user);

		$dispatcher = $this->createMock(originalClassName: IEventDispatcher::class);
		$dispatcher->method('dispatchTyped')->willReturnCallback(
			function (Event $event): void {
				$this->sent[] = $event;
			}
		);

		$time = $this->createMock(originalClassName: ITimeFactory::class);
		$time->method('getTime')->willReturn(1_760_000_000);

		$reporter = null;
		if ($withReporter === true) {
			$reporter = new ConnectionReporter(
				eventDispatcher: $dispatcher,
				appConfig: $this->appConfig,
				timeFactory: $time,
				logger: new NullLogger(),
			);
		}

		return new BreachProxyController(
			request: $this->createMock(originalClassName: IRequest::class),
			appConfig: $this->appConfig,
			clientService: $this->clientService,
			cacheFactory: $cacheFactory,
			userSession: $userSession,
			logger: new NullLogger(),
			connectionReporter: $reporter,
		);
	}//end controller()

	/**
	 * Let the upstream answer with this status and body.
	 *
	 * @param int $status The HTTP status.
	 *
	 * @return void
	 */
	private function upstreamAnswers(int $status): void {
		$response = $this->createMock(originalClassName: IResponse::class);
		$response->method('getBody')->willReturn('0018A45C4D1DEF81644B54AB7F969B88D65:1');
		$response->method('getStatusCode')->willReturn($status);

		$client = $this->createMock(originalClassName: IClient::class);
		$client->method('get')->willReturn($response);
		$this->clientService->method('newClient')->willReturn($client);
	}//end upstreamAnswers()

	/**
	 * Let the upstream call throw this exception.
	 *
	 * @param RuntimeException $exception What the client throws.
	 *
	 * @return void
	 */
	private function upstreamThrows(RuntimeException $exception): void {
		$client = $this->createMock(originalClassName: IClient::class);
		$client->method('get')->willThrowException($exception);
		$this->clientService->method('newClient')->willReturn($client);
	}//end upstreamThrows()

	/**
	 * A lookup that reached the upstream reports configured under hibp.
	 *
	 * @return void
	 */
	public function testALookupThatReachedTheUpstreamReportsConfigured(): void {
		$this->upstreamAnswers(status: 200);

		$response = $this->controller()->range(prefix: self::PREFIX);

		$this->assertSame(expected: Http::STATUS_OK, actual: $response->getStatus());
		$this->assertCount(expectedCount: 1, haystack: $this->sent);
		$this->assertInstanceOf(expected: ConnectionStatusReportedEvent::class, actual: $this->sent[0]);
		$this->assertSame(expected: 'hibp', actual: $this->sent[0]->key);
		$this->assertSame(expected: 'configured', actual: $this->sent[0]->status);
	}//end testALookupThatReachedTheUpstreamReportsConfigured()

	/**
	 * A thrown 429 keeps its status and reports limited, without the URL or the prefix.
	 *
	 * Nextcloud's client throws on a 4xx, and Guzzle's message names the full
	 * request URL, which ends in the prefix.
	 *
	 * @return void
	 */
	public function testAThrown429ReportsLimitedWithoutThePrefix(): void {
		$this->upstreamThrows(exception: $this->answeredException(status: 429));

		$response = $this->controller()->range(prefix: self::PREFIX);

		$this->assertSame(expected: Http::STATUS_SERVICE_UNAVAILABLE, actual: $response->getStatus());
		$this->assertCount(expectedCount: 1, haystack: $this->sent);
		$this->assertSame(expected: 'limited', actual: $this->sent[0]->status);
		$this->assertStringNotContainsString(needle: self::PREFIX, haystack: $this->sent[0]->message);
		$this->assertStringNotContainsString(needle: 'pwnedpasswords.com/range', haystack: $this->sent[0]->message);
	}//end testAThrown429ReportsLimitedWithoutThePrefix()

	/**
	 * A lookup that got no answer reports error, and still names no part of the lookup.
	 *
	 * @return void
	 */
	public function testALookupWithNoAnswerReportsErrorWithoutThePrefix(): void {
		$this->upstreamThrows(
			exception: new RuntimeException('cURL error 28: timed out for https://api.pwnedpasswords.com/range/' . self::PREFIX)
		);

		$this->controller()->range(prefix: self::PREFIX);

		$this->assertCount(expectedCount: 1, haystack: $this->sent);
		$this->assertSame(expected: 'error', actual: $this->sent[0]->status);
		$this->assertSame(expected: 'The last range lookup got no answer from Have I Been Pwned.', actual: $this->sent[0]->message);
	}//end testALookupWithNoAnswerReportsErrorWithoutThePrefix()

	/**
	 * A cache hit, a refused prefix and a switched-off check make no call and report nothing.
	 *
	 * @return void
	 */
	public function testALookupThatMadeNoCallReportsNothing(): void {
		$this->clientService->expects($this->never())->method('newClient');
		$this->cache->method('get')->willReturn('CAFE:5');

		$this->controller()->range(prefix: self::PREFIX);
		$this->controller()->range(prefix: 'NOT-HEX');

		$this->assertSame(expected: [], actual: $this->sent);
	}//end testALookupThatMadeNoCallReportsNothing()

	/**
	 * Without the reporter the lookup answers exactly as before.
	 *
	 * @return void
	 */
	public function testWithoutTheReporterTheLookupStillAnswers(): void {
		$this->upstreamThrows(exception: $this->answeredException(status: 503));

		$response = $this->controller(withReporter: false)->range(prefix: self::PREFIX);

		$this->assertSame(expected: Http::STATUS_SERVICE_UNAVAILABLE, actual: $response->getStatus());
		$this->assertSame(expected: [], actual: $this->sent);
	}//end testWithoutTheReporterTheLookupStillAnswers()

	/**
	 * An exception that carries the upstream's answer, shaped like Guzzle's.
	 *
	 * @param int $status The answer's HTTP status.
	 *
	 * @return RuntimeException
	 */
	private function answeredException(int $status): RuntimeException {
		$answer = $this->createMock(originalClassName: IResponse::class);
		$answer->method('getStatusCode')->willReturn($status);

		$message = 'Client error: `GET https://api.pwnedpasswords.com/range/' . self::PREFIX . '` resulted in a `' . $status . '` response';

		return new class(message: $message, response: $answer) extends RuntimeException {

			/**
			 * Constructor.
			 *
			 * @param string    $message  The exception message.
			 * @param IResponse $response The answer the call got.
			 */
			public function __construct(string $message, private IResponse $response) {
				parent::__construct(message: $message);
			}//end __construct()

			/**
			 * The answer the call got.
			 *
			 * @return IResponse
			 */
			public function getResponse(): IResponse {
				return $this->response;
			}//end getResponse()
		};
	}//end answeredException()
}//end class
