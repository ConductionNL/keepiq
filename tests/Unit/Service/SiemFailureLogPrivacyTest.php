<?php

/**
 * A failed SIEM delivery never writes the sink URL or its token anywhere
 * (keepiq#728): not to the log, the queue row, the sink row or the admin's
 * test-fire answer.
 *
 * The transport is real; the HTTP client throws an exception shaped like
 * Guzzle's (a message naming the full request URL, and the response).
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

use OCA\Keepiq\BackgroundJob\DeliverSiemEventsJob;
use OCA\Keepiq\Db\SiemQueueItem;
use OCA\Keepiq\Db\SiemQueueItemMapper;
use OCA\Keepiq\Db\SiemSink;
use OCA\Keepiq\Db\SiemSinkMapper;
use OCA\Keepiq\Service\SiemService;
use OCA\Keepiq\Service\SiemSinkService;
use OCA\Keepiq\Service\SiemTransport;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\Http\Client\IClient;
use OCP\Http\Client\IClientService;
use OCP\Http\Client\IResponse;
use OCP\IGroupManager;
use OCP\Security\ICrypto;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\AbstractLogger;
use ReflectionMethod;
use RuntimeException;
use Stringable;

/**
 * The credential-free failure description, through every place it lands.
 */
class SiemFailureLogPrivacyTest extends TestCase {

	/**
	 * A webhook URL with a credential in its userinfo and its query.
	 */
	private const ENDPOINT = 'https://user:s3cr3tpw@siem.example.org/ingest?token=abc123tok';

	/**
	 * Sink mapper mock.
	 *
	 * @var SiemSinkMapper&MockObject
	 */
	private SiemSinkMapper $sinkMapper;

	/**
	 * HTTP client service mock.
	 *
	 * @var IClientService&MockObject
	 */
	private IClientService $clientService;

	/**
	 * The service under test.
	 *
	 * @var SiemService
	 */
	private SiemService $service;

	/**
	 * The admin sink service.
	 *
	 * @var SiemSinkService
	 */
	private SiemSinkService $sinkService;

	/**
	 * Wire the real transport and services over mocks.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		$this->sinkMapper = $this->createMock(SiemSinkMapper::class);
		$queueMapper = $this->createMock(SiemQueueItemMapper::class);
		$this->clientService = $this->createMock(IClientService::class);
		$transport = new SiemTransport(crypto: $this->createMock(ICrypto::class), clientService: $this->clientService);
		$this->sinkService = new SiemSinkService(
			sinkMapper: $this->sinkMapper,
			queueMapper: $queueMapper,
			crypto: $this->createMock(ICrypto::class),
			transport: $transport,
		);
		$this->service = new SiemService(
			sinkMapper: $this->sinkMapper,
			queueMapper: $queueMapper,
			transport: $transport,
			sinkService: $this->sinkService,
			groupManager: $this->createMock(IGroupManager::class),
			notificationService: null,
			logger: new class extends AbstractLogger {
				/**
				 * Discard.
				 *
				 * @param mixed $level The level
				 * @param string|Stringable $message The message
				 * @param array<mixed> $context The context
				 *
				 * @return void
				 */
				public function log($level, string|Stringable $message, array $context = []): void {
				}//end log()
			},
		);
	}//end setUp()

	/**
	 * A sink pointed at the credential-bearing URL.
	 *
	 * @return SiemSink
	 */
	private function sink(): SiemSink {
		$sink = new SiemSink();
		$sink->setId('sink-1');
		$sink->setName('SOC');
		$sink->setType('webhook');
		$sink->setEnabled(true);
		$sink->setEndpoint(self::ENDPOINT);
		return $sink;
	}//end sink()

	/**
	 * An exception shaped like Guzzle's ServerException: the message names
	 * the full request URL, the response carries the status.
	 *
	 * @param int $status The HTTP status
	 *
	 * @return RuntimeException
	 */
	private function guzzleShaped(int $status): RuntimeException {
		$response = $this->createMock(IResponse::class);
		$response->method('getStatusCode')->willReturn($status);

		return new class('Server error: `POST '.self::ENDPOINT.'` resulted in a `'.$status.'` response', $response) extends RuntimeException {
			/**
			 * Constructor.
			 *
			 * @param string $message The message
			 * @param IResponse $response The response
			 *
			 * @return void
			 */
			public function __construct(string $message, private IResponse $response) {
				parent::__construct($message);
			}//end __construct()

			/**
			 * The response, as Guzzle's RequestException has it.
			 *
			 * @return IResponse
			 */
			public function getResponse(): IResponse {
				return $this->response;
			}//end getResponse()
		};
	}//end guzzleShaped()

	/**
	 * Assert a stored or shown text carries no part of the credential.
	 *
	 * @param string|null $text The text
	 *
	 * @return void
	 */
	private function assertNoCredential(?string $text): void {
		$this->assertNotNull($text);
		foreach (['s3cr3tpw', 'abc123tok', 'token=', 'user:', '/ingest'] as $needle) {
			$this->assertStringNotContainsString($needle, $text);
		}
	}//end assertNoCredential()

	/**
	 * The queue row and the sink row hold class, status and host only.
	 *
	 * @return void
	 */
	public function testAFailedDeliveryStoresNoCredential(): void {
		$client = $this->createMock(IClient::class);
		$client->method('post')->willThrowException($this->guzzleShaped(500));
		$this->clientService->method('newClient')->willReturn($client);

		$item = new SiemQueueItem();
		$item->setId('item-1');
		$item->setSinkId('sink-1');
		$item->setPayload('{}');
		$item->setStatus('pending');
		$sink = $this->sink();

		$this->assertFalse($this->service->deliverOne(sink: $sink, item: $item));

		$this->assertNoCredential($item->getLastError());
		$this->assertNoCredential($sink->getLastError());
		$this->assertStringContainsString('(HTTP 500)', (string)$sink->getLastError());
		$this->assertStringContainsString('at siem.example.org', (string)$sink->getLastError());
	}//end testAFailedDeliveryStoresNoCredential()

	/**
	 * A non-2xx answer the transport sees itself is described by its status.
	 *
	 * @return void
	 */
	public function testANon2xxAnswerIsDescribedByItsStatus(): void {
		$response = $this->createMock(IResponse::class);
		$response->method('getStatusCode')->willReturn(503);
		$client = $this->createMock(IClient::class);
		$client->method('post')->willReturn($response);
		$this->clientService->method('newClient')->willReturn($client);

		$item = new SiemQueueItem();
		$item->setPayload('{}');
		$item->setStatus('pending');
		$sink = $this->sink();

		$this->service->deliverOne(sink: $sink, item: $item);

		$this->assertSame('SiemDeliveryException (HTTP 503) at siem.example.org', $sink->getLastError());
	}//end testANon2xxAnswerIsDescribedByItsStatus()

	/**
	 * The admin's test-fire answer carries no credential either.
	 *
	 * @return void
	 */
	public function testATestFireAnswerCarriesNoCredential(): void {
		$client = $this->createMock(IClient::class);
		$client->method('post')->willThrowException($this->guzzleShaped(401));
		$this->clientService->method('newClient')->willReturn($client);
		$this->sinkMapper->method('findById')->willReturn($this->sink());

		$result = $this->sinkService->testSink(adminUid: 'admin', sinkId: 'sink-1');

		$this->assertFalse($result['ok']);
		$this->assertNoCredential($result['error']);
		$this->assertStringContainsString('(HTTP 401)', (string)$result['error']);
	}//end testATestFireAnswerCarriesNoCredential()

	/**
	 * The drain job's log line names the class, never the message.
	 *
	 * @return void
	 */
	public function testTheDrainJobLogsNoCredential(): void {
		$siem = $this->createMock(SiemService::class);
		$siem->method('deliverDue')->willThrowException($this->guzzleShaped(500));
		$lines = [];
		$logger = new class($lines) extends AbstractLogger {
			/**
			 * Constructor.
			 *
			 * @param array<string> $lines The captured lines
			 *
			 * @return void
			 */
			public function __construct(private array &$lines) {
			}//end __construct()

			/**
			 * Capture the line.
			 *
			 * @param mixed $level The level
			 * @param string|Stringable $message The message
			 * @param array<mixed> $context The context
			 *
			 * @return void
			 */
			public function log($level, string|Stringable $message, array $context = []): void {
				$this->lines[] = (string)$message.json_encode($context);
			}//end log()
		};
		$job = new DeliverSiemEventsJob(
			time: $this->createMock(ITimeFactory::class),
			siemService: $siem,
			logger: $logger,
		);

		(new ReflectionMethod($job, 'run'))->invoke($job, null);

		$this->assertCount(1, $lines);
		$this->assertNoCredential($lines[0]);
	}//end testTheDrainJobLogsNoCredential()
}//end class
