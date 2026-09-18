<?php

/**
 * BreachProxyController log privacy tests.
 *
 * A failed range lookup is the one moment the breach proxy writes a line of
 * its own, and Nextcloud stamps that line with the user who typed the
 * password. The HTTP client's exception message names the request URL, which
 * ends in the 5-character hash prefix, so these tests drive the real
 * controller over a recording logger and read back exactly what an admin
 * would find in `nextcloud.log`: the exception class and the HTTP status, and
 * no part of the lookup.
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

use OCA\Keepiq\Controller\BreachProxyController;
use OCA\Keepiq\Service\Connection\ConnectionReporter;
use OCP\AppFramework\Utility\ITimeFactory;
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
use Psr\Log\LoggerInterface;
use RuntimeException;
use Throwable;

/**
 * Unit tests for what BreachProxyController::range() writes to the log.
 *
 * @covers \OCA\Keepiq\Controller\BreachProxyController
 * @uses   \OCA\Keepiq\Service\Connection\ConnectionReporter
 * @uses   \OCA\Keepiq\Service\Connection\ConnectionObservations
 */
class BreachProxyControllerLogPrivacyTest extends TestCase {

	/**
	 * The prefix every lookup here asks for. Valid hexadecimal, so it reaches
	 * the upstream call, and distinctive enough to find in a log line.
	 *
	 * @var string
	 */
	private const PREFIX = 'ABCDE';

	/**
	 * Mocked HTTP client service.
	 *
	 * @var IClientService&MockObject
	 */
	private IClientService&MockObject $clientService;

	/**
	 * Every warning the controller logged, as [message, context] pairs.
	 *
	 * @var array<int, array{0: string, 1: array<string, mixed>}>
	 */
	private array $logged = [];

	/**
	 * Set up the fixtures.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		$this->logged        = [];
		$this->clientService = $this->createMock(originalClassName: IClientService::class);
	}//end setUp()

	/**
	 * A failed lookup logs neither the prefix nor the URL, and names the class and status.
	 *
	 * The exception message is shaped like the one Nextcloud's Guzzle-backed
	 * client throws on a 4xx: it quotes the whole request URL, which ends in
	 * the prefix of a hash of a password the caller just typed.
	 *
	 * @return void
	 */
	public function testAFailedLookupLogsNeitherThePrefixNorTheUrl(): void {
		$this->upstreamThrows(exception: $this->answeredException(status: 429));

		$this->controller()->range(prefix: self::PREFIX);

		$this->assertCount(expectedCount: 1, haystack: $this->logged);
		[$message, $context] = $this->logged[0];

		$this->assertStringNotContainsString(needle: self::PREFIX, haystack: $message);
		$this->assertStringNotContainsString(needle: 'api.pwnedpasswords.com', haystack: $message);
		$this->assertStringNotContainsString(needle: 'range/', haystack: $message);
		$this->assertStringContainsString(needle: 'RuntimeException', haystack: $message);
		$this->assertStringContainsString(needle: '(HTTP 429)', haystack: $message);
		$this->assertSame(expected: ['app' => 'keepiq'], actual: $context);
	}//end testAFailedLookupLogsNeitherThePrefixNorTheUrl()

	/**
	 * A lookup nothing answered says so, and still carries no part of the lookup.
	 *
	 * This is the line that separates "Have I Been Pwned is down" from the
	 * refusal above, so the test pins it whole.
	 *
	 * @return void
	 */
	public function testALookupWithNoAnswerNamesTheClassAndSaysSo(): void {
		$this->upstreamThrows(
			exception: new RuntimeException('cURL error 28: timed out for https://api.pwnedpasswords.com/range/' . self::PREFIX)
		);

		$this->controller()->range(prefix: self::PREFIX);

		$this->assertCount(expectedCount: 1, haystack: $this->logged);
		[$message, $context] = $this->logged[0];

		$this->assertStringNotContainsString(needle: self::PREFIX, haystack: $message);
		$this->assertSame(
			expected: 'Keepiq: HIBP range lookup failed: RuntimeException (no answer)',
			actual: $message
		);
		$this->assertSame(expected: ['app' => 'keepiq'], actual: $context);
	}//end testALookupWithNoAnswerNamesTheClassAndSaysSo()

	/**
	 * Without the reporter the line still carries no part of the lookup.
	 *
	 * The status comes from the reporter, so an instance built without one
	 * loses the status. It must not gain the message in exchange.
	 *
	 * @return void
	 */
	public function testWithoutTheReporterTheLineStillCarriesNoPrefix(): void {
		$this->upstreamThrows(exception: $this->answeredException(status: 429));

		$this->controller(withReporter: false)->range(prefix: self::PREFIX);

		$this->assertCount(expectedCount: 1, haystack: $this->logged);
		$this->assertStringNotContainsString(needle: self::PREFIX, haystack: $this->logged[0][0]);
		$this->assertStringContainsString(needle: '(no answer)', haystack: $this->logged[0][0]);
	}//end testWithoutTheReporterTheLineStillCarriesNoPrefix()

	/**
	 * The controller, with a recording logger and a real reporter.
	 *
	 * @param bool $withReporter False to build it as an instance without the reporter.
	 *
	 * @return BreachProxyController
	 */
	private function controller(bool $withReporter = true): BreachProxyController {
		$appConfig = $this->createMock(originalClassName: IAppConfig::class);
		$appConfig->method('getValueBool')->willReturn(true);
		$appConfig->method('getValueString')->willReturn('');

		$cache = $this->createMock(originalClassName: ICache::class);
		$cache->method('get')->willReturn(null);

		$cacheFactory = $this->createMock(originalClassName: ICacheFactory::class);
		$cacheFactory->method('createDistributed')->willReturn($cache);

		$userSession = $this->createMock(originalClassName: IUserSession::class);
		$userSession->method('getUser')->willReturn($this->createMock(originalClassName: IUser::class));

		$logger = $this->createMock(originalClassName: LoggerInterface::class);
		$logger->method('warning')->willReturnCallback(
			function (string|\Stringable $message, array $context = []): void {
				$this->logged[] = [(string) $message, $context];
			}
		);

		$time = $this->createMock(originalClassName: ITimeFactory::class);
		$time->method('getTime')->willReturn(1_760_000_000);

		$reporter = null;
		if ($withReporter === true) {
			$reporter = new ConnectionReporter(
				eventDispatcher: $this->createMock(originalClassName: IEventDispatcher::class),
				appConfig: $appConfig,
				timeFactory: $time,
				logger: $this->createMock(originalClassName: LoggerInterface::class),
			);
		}

		return new BreachProxyController(
			request: $this->createMock(originalClassName: IRequest::class),
			appConfig: $appConfig,
			clientService: $this->clientService,
			cacheFactory: $cacheFactory,
			userSession: $userSession,
			logger: $logger,
			connectionReporter: $reporter,
		);
	}//end controller()

	/**
	 * Let the upstream call throw this exception.
	 *
	 * @param Throwable $exception What the client throws.
	 *
	 * @return void
	 */
	private function upstreamThrows(Throwable $exception): void {
		$client = $this->createMock(originalClassName: IClient::class);
		$client->method('get')->willThrowException($exception);
		$this->clientService->method('newClient')->willReturn($client);
	}//end upstreamThrows()

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
