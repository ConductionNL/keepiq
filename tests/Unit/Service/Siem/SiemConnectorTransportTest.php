<?php

/**
 * SIEM connector delivery: CEF over a real TCP socket, Splunk HEC and
 * Sentinel against a mocked HTTP client, and test-fire per connector.
 *
 * @category Test
 * @package  OCA\Keepiq\Tests\Unit\Service\Siem
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

namespace OCA\Keepiq\Tests\Unit\Service\Siem;

use OCA\Keepiq\Db\SiemQueueItem;
use OCA\Keepiq\Db\SiemQueueItemMapper;
use OCA\Keepiq\Db\SiemSink;
use OCA\Keepiq\Db\SiemSinkMapper;
use OCA\Keepiq\Exception\SiemDeliveryException;
use OCA\Keepiq\Service\SiemService;
use OCA\Keepiq\Service\SiemSinkService;
use OCA\Keepiq\Service\SiemTransport;
use OCP\App\IAppManager;
use OCP\Http\Client\IClient;
use OCP\Http\Client\IClientService;
use OCP\Http\Client\IResponse;
use OCP\IGroupManager;
use OCP\Security\ICrypto;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;

/**
 * SiemTransport per connector.
 *
 * @spec openspec/specs/siem-vendor-connectors/spec.md
 */
class SiemConnectorTransportTest extends TestCase {

	/** @var array<int,array{string,array<string,mixed>}> */
	private array $posts = [];

	/** @var array<int,array{int,string}> Responses to hand out, in order */
	private array $answers = [];

	private SiemTransport $transport;

	private ICrypto $crypto;

	/**
	 * A transport over a recording HTTP client.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		$this->crypto = $this->createMock(ICrypto::class);
		$this->crypto->method('decrypt')->willReturnCallback(static fn (string $v): string => substr($v, 4));
		$client = $this->createMock(IClient::class);
		$client->method('post')->willReturnCallback(function (string $url, array $options): IResponse {
			$this->posts[] = [$url, $options];
			[$status, $body] = array_shift($this->answers) ?? [500, ''];
			$response = $this->createMock(IResponse::class);
			$response->method('getStatusCode')->willReturn($status);
			$response->method('getBody')->willReturn($body);
			return $response;
		});
		$service = $this->createMock(IClientService::class);
		$service->method('newClient')->willReturn($client);
		$apps = $this->createMock(IAppManager::class);
		$apps->method('getAppVersion')->willReturn('0.3.4');
		$this->transport = new SiemTransport(crypto: $this->crypto, clientService: $service, appManager: $apps);
	}//end setUp()

	/**
	 * A queued payload.
	 *
	 * @return string
	 */
	private static function payload(): string {
		return (string)json_encode([
			'eventType' => 'suite.revoked', 'category' => 'suite', 'actorType' => 'user', 'actorId' => 'admin',
			'objectType' => 'suite', 'objectId' => 'suite-9', 'occurredAt' => '2026-10-02T12:00:00+00:00',
			'metadata' => ['suiteId' => 'suite-9'],
		]);
	}//end payload()

	/**
	 * A sink of a type.
	 *
	 * @param string $type The type
	 * @param string $endpoint The endpoint
	 *
	 * @return SiemSink
	 */
	private static function sink(string $type, string $endpoint): SiemSink {
		$sink = new SiemSink();
		$sink->setId('sink-1');
		$sink->setType($type);
		$sink->setEndpoint($endpoint);
		$sink->setTls(false);
		$sink->setQueueCap(10);
		return $sink;
	}//end sink()

	/**
	 * Read one octet-framed syslog message from a local listener.
	 *
	 * @param SiemSink $sink A syslog sink; its endpoint is set to the listener
	 *
	 * @return string The MSG part
	 */
	private function viaSocket(SiemSink $sink): string {
		$server = stream_socket_server('tcp://127.0.0.1:0', $errno, $errstr);
		$this->assertNotFalse($server, $errstr);
		$sink->setEndpoint((string)stream_socket_get_name($server, false));
		$this->transport->deliver(sink: $sink, payloadJson: self::payload());
		$conn = stream_socket_accept($server, 5);
		$frame = (string)stream_get_contents($conn);
		fclose($conn);
		fclose($server);
		[$length, $message] = explode(' ', $frame, 2);
		$this->assertSame((int)$length, strlen($message), 'RFC 6587 octet count');
		$this->assertMatchesRegularExpression('/^<134>1 \S+ nextcloud keepiq - - - /', $message);
		return (string)preg_replace('/^<134>1 \S+ nextcloud keepiq - - - /', '', $message);
	}//end viaSocket()

	/**
	 * A cef syslog sink sends the CEF line inside the RFC 5424 frame.
	 *
	 * @return void
	 */
	public function testCefOverSyslog(): void {
		$sink = self::sink('syslog', '');
		$sink->setFormat('cef');
		$msg = $this->viaSocket(sink: $sink);
		$this->assertStringStartsWith('CEF:0|Conduction|Keepiq|0.3.4|suite.revoked|suite revoked|8|', $msg);
	}//end testCefOverSyslog()

	/**
	 * A json syslog sink sends the stored payload byte for byte, as before.
	 *
	 * @return void
	 */
	public function testJsonSyslogIsUnchanged(): void {
		$this->assertSame(self::payload(), $this->viaSocket(sink: self::sink('syslog', '')));
	}//end testJsonSyslogIsUnchanged()

	/**
	 * Splunk HEC: the token header and the event envelope; 200 with code 0
	 * is the only success.
	 *
	 * @return void
	 *
	 * @spec openspec/specs/siem-vendor-connectors/spec.md
	 */
	public function testSplunkDelivery(): void {
		$sink = self::sink('splunk_hec', 'https://splunk.example.org:8088/services/collector/event');
		$sink->setCredentialEnc('ENC:hec-token-1');
		$sink->setConnectorOptions('{"index":"security"}');

		$this->answers = [[200, '{"text":"Success","code":0}']];
		$this->transport->deliver(sink: $sink, payloadJson: self::payload());
		[$url, $options] = $this->posts[0];
		$this->assertSame('https://splunk.example.org:8088/services/collector/event', $url);
		$this->assertSame('Splunk hec-token-1', $options['headers']['Authorization']);
		$body = json_decode($options['body'], true);
		$this->assertSame('security', $body['index']);
		$this->assertSame('keepiq:audit', $body['sourcetype']);
		$this->assertSame('suite.revoked', $body['event']['eventType']);

		foreach ([[403, '{"text":"Invalid token","code":4}'], [200, '{"text":"Server is busy","code":9}'], [200, 'not json']] as $answer) {
			$this->answers = [$answer];
			try {
				$this->transport->deliver(sink: $sink, payloadJson: self::payload());
				$this->fail('accepted ' . json_encode($answer));
			} catch (SiemDeliveryException $e) {
				$this->assertSame($answer[0], $e->getHttpStatus());
			}
		}
	}//end testSplunkDelivery()

	/**
	 * A rejected Splunk delivery enters the retry path of the drain.
	 *
	 * @return void
	 *
	 * @spec openspec/specs/siem-vendor-connectors/spec.md
	 */
	public function testSplunkFailureEntersRetry(): void {
		$sink = self::sink('splunk_hec', 'https://splunk.example.org:8088/services/collector/event');
		$sink->setCredentialEnc('ENC:hec-token-1');
		$item = new SiemQueueItem();
		$item->setPayload(self::payload());
		$item->setAttempts(0);
		$sinkMapper = $this->createMock(SiemSinkMapper::class);
		$queueMapper = $this->createMock(SiemQueueItemMapper::class);
		$service = new SiemService(
			sinkMapper: $sinkMapper,
			queueMapper: $queueMapper,
			transport: $this->transport,
			sinkService: new SiemSinkService(sinkMapper: $sinkMapper, queueMapper: $queueMapper, crypto: $this->crypto, transport: $this->transport),
			groupManager: $this->createMock(IGroupManager::class),
			notificationService: null,
			logger: new NullLogger(),
		);
		$this->answers = [[403, '{"code":4}']];
		$this->assertFalse($service->deliverOne($sink, $item));
		$this->assertSame(1, $item->getAttempts());
		$this->assertNotNull($item->getNextAttemptAt());
		$this->assertSame('failing', $sink->getLastDeliveryStatus());
		$this->assertStringNotContainsString('hec-token-1', (string)$sink->getLastError());
	}//end testSplunkFailureEntersRetry()

	/**
	 * A Sentinel sink.
	 *
	 * @return SiemSink
	 */
	private static function sentinel(): SiemSink {
		$sink = self::sink('sentinel', 'https://dce.westeurope-1.ingest.monitor.azure.com');
		$sink->setCredentialEnc('ENC:client-secret-1');
		$sink->setConnectorOptions((string)json_encode([
			'tenantId' => 'tenant-1', 'clientId' => 'client-1',
			'dataCollectionEndpoint' => 'https://dce.westeurope-1.ingest.monitor.azure.com',
			'dcrImmutableId' => 'dcr-abc', 'streamName' => 'Custom-KeepiqAudit', 'authorityHost' => 'https://login.microsoftonline.com',
		]));
		return $sink;
	}//end sentinel()

	/**
	 * One token serves two deliveries in a run; each row is accepted with 204.
	 *
	 * @return void
	 *
	 * @spec openspec/specs/siem-vendor-connectors/spec.md
	 */
	public function testSentinelReusesTheTokenInARun(): void {
		$this->answers = [[200, '{"access_token":"tok-1","expires_in":3599}'], [204, ''], [204, '']];
		$this->transport->deliver(sink: self::sentinel(), payloadJson: self::payload());
		$this->transport->deliver(sink: self::sentinel(), payloadJson: self::payload());
		$this->assertCount(3, $this->posts);
		[$tokenUrl, $tokenOptions] = $this->posts[0];
		$this->assertSame('https://login.microsoftonline.com/tenant-1/oauth2/v2.0/token', $tokenUrl);
		parse_str($tokenOptions['body'], $form);
		$this->assertSame(['grant_type' => 'client_credentials', 'client_id' => 'client-1', 'client_secret' => 'client-secret-1', 'scope' => 'https://monitor.azure.com//.default'], $form);
		[$url, $options] = $this->posts[1];
		$this->assertSame('https://dce.westeurope-1.ingest.monitor.azure.com/dataCollectionRules/dcr-abc/streams/Custom-KeepiqAudit?api-version=2023-01-01', $url);
		$this->assertSame('Bearer tok-1', $options['headers']['Authorization']);
		$rows = json_decode($options['body'], true);
		$this->assertCount(1, $rows);
		$this->assertSame('suite.revoked', $rows[0]['EventType']);
	}//end testSentinelReusesTheTokenInARun()

	/**
	 * A 401 gets a new token and one retry; a second 401 fails into retry.
	 *
	 * @return void
	 *
	 * @spec openspec/specs/siem-vendor-connectors/spec.md
	 */
	public function testSentinelRetriesOnceAfter401(): void {
		$this->answers = [[200, '{"access_token":"tok-1"}'], [401, ''], [200, '{"access_token":"tok-2"}'], [204, '']];
		$this->transport->deliver(sink: self::sentinel(), payloadJson: self::payload());
		$this->assertSame('Bearer tok-2', $this->posts[3][1]['headers']['Authorization']);

		$this->answers = [[401, ''], [200, '{"access_token":"tok-3"}'], [401, '']];
		try {
			$this->transport->deliver(sink: self::sentinel(), payloadJson: self::payload());
			$this->fail('a second 401 was accepted');
		} catch (SiemDeliveryException $e) {
			$this->assertSame(401, $e->getHttpStatus());
		}
	}//end testSentinelRetriesOnceAfter401()

	/**
	 * Test-fire through SiemSinkService gives an outcome per connector, never
	 * naming the credential.
	 *
	 * @return void
	 *
	 * @spec openspec/specs/siem-vendor-connectors/spec.md
	 */
	public function testTestFirePerConnector(): void {
		$sinks = ['splunk_hec' => self::sink('splunk_hec', 'https://splunk.example.org:8088/services/collector/event'), 'sentinel' => self::sentinel()];
		$sinks['splunk_hec']->setCredentialEnc('ENC:hec-token-1');
		foreach ($sinks as $type => $sink) {
			$mapper = $this->createMock(SiemSinkMapper::class);
			$mapper->method('findById')->willReturn($sink);
			$service = new SiemSinkService(sinkMapper: $mapper, queueMapper: $this->createMock(SiemQueueItemMapper::class), crypto: $this->crypto, transport: $this->transport);

			$this->answers = ($type === 'splunk_hec') ? [[200, '{"code":0}']] : [[200, '{"access_token":"t"}'], [204, '']];
			$this->assertSame(['ok' => true, 'error' => null], $service->testSink(adminUid: 'admin', sinkId: 'sink-1'), $type);

			$this->answers = ($type === 'splunk_hec') ? [[403, '{"code":4}']] : [[400, '{"error":"invalid_client"}']];
			$result = $service->testSink(adminUid: 'admin', sinkId: 'sink-1');
			$this->assertFalse($result['ok']);
			$host = ($type === 'splunk_hec') ? 'splunk.example.org' : 'dce.westeurope-1.ingest.monitor.azure.com';
			$this->assertSame('SiemDeliveryException (HTTP ' . (($type === 'splunk_hec') ? 403 : 400) . ') at ' . $host, $result['error']);
			$this->assertStringNotContainsString('secret', (string)$result['error']);
		}
	}//end testTestFirePerConnector()
}//end class
