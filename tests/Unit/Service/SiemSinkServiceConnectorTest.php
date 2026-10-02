<?php

/**
 * SIEM connector validation and write-only credentials.
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

use InvalidArgumentException;
use OCA\Keepiq\Db\SiemQueueItemMapper;
use OCA\Keepiq\Db\SiemSink;
use OCA\Keepiq\Db\SiemSinkMapper;
use OCA\Keepiq\Event\Audit\AuditEvent;
use OCA\Keepiq\Service\SiemAuditTrail;
use OCA\Keepiq\Service\SiemSinkService;
use OCA\Keepiq\Service\SiemTransport;
use OCP\EventDispatcher\IEventDispatcher;
use OCP\Http\Client\IClientService;
use OCP\Security\ICrypto;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

/**
 * SiemSinkService accepts and rejects connector settings.
 *
 * @spec openspec/changes/audit-siem-vendor-connectors/tasks.md#1.3
 */
class SiemSinkServiceConnectorTest extends TestCase {
	private SiemSinkService $service;

	private SiemSinkMapper&MockObject $sinkMapper;

	private ICrypto&MockObject $crypto;

	/** @var AuditEvent[] */
	private array $audits = [];

	/**
	 * A service over mocked mappers; ICrypto marks what it encrypted.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		$this->sinkMapper = $this->createMock(SiemSinkMapper::class);
		$this->sinkMapper->method('insert')->willReturnArgument(0);
		$this->sinkMapper->method('update')->willReturnArgument(0);
		$this->crypto = $this->createMock(ICrypto::class);
		$this->crypto->method('encrypt')->willReturnCallback(static fn (string $v): string => 'ENC(' . strlen($v) . ')');
		$dispatcher = $this->createMock(IEventDispatcher::class);
		$dispatcher->method('dispatchTyped')->willReturnCallback(function (object $e): void {
			$this->audits[] = $e;
		});
		$this->service = new SiemSinkService(
			sinkMapper: $this->sinkMapper,
			queueMapper: $this->createMock(SiemQueueItemMapper::class),
			crypto: $this->crypto,
			transport: new SiemTransport(crypto: $this->crypto, clientService: $this->createMock(IClientService::class)),
			auditTrail: new SiemAuditTrail(eventDispatcher: $dispatcher),
		);
	}//end setUp()

	/**
	 * Valid Sentinel options.
	 *
	 * @return array<string,string>
	 */
	private static function sentinelOptions(): array {
		return [
			'tenantId' => '00000000-0000-0000-0000-000000000001',
			'clientId' => '00000000-0000-0000-0000-000000000002',
			'dataCollectionEndpoint' => 'https://keepiq-dce.westeurope-1.ingest.monitor.azure.com',
			'dcrImmutableId' => 'dcr-0123456789abcdef',
		];
	}//end sentinelOptions()

	/**
	 * Splunk HEC: stored with its options, the token encrypted, and the
	 * serialized sink reports hasCredential but no token.
	 *
	 * @return void
	 */
	public function testSplunkSinkIsAccepted(): void {
		$sink = $this->service->createSink(adminUid: 'admin', params: [
			'type' => 'splunk_hec',
			'endpoint' => 'https://splunk.example.org:8088/services/collector/event',
			'credential' => 'hec-token-0f9e',
			'connectorOptions' => ['index' => 'security'],
		]);
		$this->assertSame('splunk_hec', $sink->getType());
		$this->assertSame('json', $sink->getFormat());
		$this->assertSame('ENC(14)', $sink->getCredentialEnc());
		$data = $sink->jsonSerialize();
		$this->assertTrue($data['hasCredential']);
		$this->assertSame(['index' => 'security'], $data['connectorOptions']);
		$this->assertStringNotContainsString('hec-token-0f9e', (string)json_encode($data));
	}//end testSplunkSinkIsAccepted()

	/**
	 * Sentinel: the stream and authority host get their defaults.
	 *
	 * @return void
	 */
	public function testSentinelSinkIsAcceptedWithDefaults(): void {
		$sink = $this->service->createSink(adminUid: 'admin', params: [
			'type' => 'sentinel',
			'endpoint' => self::sentinelOptions()['dataCollectionEndpoint'],
			'credential' => 'client-secret-xyz',
			'connectorOptions' => self::sentinelOptions(),
		]);
		$options = $sink->connectorOptionsArray();
		$this->assertSame('Custom-KeepiqAudit', $options['streamName']);
		$this->assertSame('https://login.microsoftonline.com', $options['authorityHost']);
		$this->assertSame('00000000-0000-0000-0000-000000000001', $options['tenantId']);
	}//end testSentinelSinkIsAcceptedWithDefaults()

	/**
	 * CEF is a syslog format.
	 *
	 * @return void
	 */
	public function testCefSyslogSinkIsAccepted(): void {
		$sink = $this->service->createSink(adminUid: 'admin', params: [
			'type' => 'syslog',
			'endpoint' => 'qradar.example.org:6514',
			'format' => 'cef',
		]);
		$this->assertSame('cef', $sink->getFormat());
	}//end testCefSyslogSinkIsAccepted()

	/**
	 * Every refused combination, and no sink is stored for any of them.
	 *
	 * @return array<string,array{array<string,mixed>}>
	 */
	public static function refused(): array {
		$sentinel = [
			'type' => 'sentinel', 'endpoint' => 'https://dce.example', 'credential' => 's',
			'connectorOptions' => self::sentinelOptions(),
		];
		return [
			'unknown type' => [['type' => 'datadog', 'endpoint' => 'https://x']],
			'cef on webhook' => [['type' => 'webhook', 'endpoint' => 'https://x', 'format' => 'cef']],
			'cef on splunk' => [['type' => 'splunk_hec', 'endpoint' => 'https://x', 'credential' => 't', 'format' => 'cef']],
			'unknown format' => [['type' => 'syslog', 'endpoint' => 'h:514', 'format' => 'leef']],
			'splunk over http' => [['type' => 'splunk_hec', 'endpoint' => 'http://x', 'credential' => 't']],
			'splunk without token' => [['type' => 'splunk_hec', 'endpoint' => 'https://x']],
			'splunk unknown option' => [['type' => 'splunk_hec', 'endpoint' => 'https://x', 'credential' => 't', 'connectorOptions' => ['token' => 'x']]],
			'sentinel without secret' => [array_diff_key($sentinel, ['credential' => 1])],
			'sentinel without tenant' => [array_replace($sentinel, ['connectorOptions' => array_diff_key(self::sentinelOptions(), ['tenantId' => 1])])],
			'sentinel without rule' => [array_replace($sentinel, ['connectorOptions' => array_diff_key(self::sentinelOptions(), ['dcrImmutableId' => 1])])],
			'sentinel http endpoint' => [array_replace($sentinel, ['endpoint' => 'http://dce.example'])],
			'sentinel http authority' => [array_replace($sentinel, ['connectorOptions' => self::sentinelOptions() + ['authorityHost' => 'http://login.example']])],
			'options on syslog' => [['type' => 'syslog', 'endpoint' => 'h:514', 'connectorOptions' => ['index' => 'x']]],
		];
	}//end refused()

	/**
	 * A refused combination throws and stores nothing.
	 *
	 * @param array<string,mixed> $params The create params
	 *
	 * @return void
	 */
	#[DataProvider('refused')]
	public function testRefusedCombinations(array $params): void {
		$this->sinkMapper->expects($this->never())->method('insert');
		$this->expectException(InvalidArgumentException::class);
		$this->service->createSink(adminUid: 'admin', params: $params);
	}//end testRefusedCombinations()

	/**
	 * An update with a blank credential keeps the stored one and applies the
	 * new options; the update audit names the connector type, never the token.
	 *
	 * @return void
	 */
	public function testUpdateKeepsTheCredentialAndAuditsTheType(): void {
		$sink = new SiemSink();
		$sink->setId('s1');
		$sink->setType('splunk_hec');
		$sink->setEndpoint('https://splunk.example.org:8088/services/collector/event');
		$sink->setCredentialEnc('ENC-OLD');
		$this->sinkMapper->method('findById')->willReturn($sink);
		$this->crypto->expects($this->never())->method('encrypt');

		$updated = $this->service->updateSink(adminUid: 'admin', sinkId: 's1', params: [
			'credential' => '',
			'connectorOptions' => ['index' => 'audit'],
		]);
		$this->assertSame('ENC-OLD', $updated->getCredentialEnc());
		$this->assertSame(['index' => 'audit'], $updated->connectorOptionsArray());

		$this->assertCount(1, $this->audits);
		$meta = $this->audits[0]->getMetadata();
		$this->assertSame('splunk_hec', $meta['type']);
		$this->assertStringNotContainsString('ENC-OLD', (string)json_encode($meta));
	}//end testUpdateKeepsTheCredentialAndAuditsTheType()

	/**
	 * An update cannot put CEF on a non-syslog sink.
	 *
	 * @return void
	 */
	public function testUpdateRefusesCefOnWebhook(): void {
		$sink = new SiemSink();
		$sink->setId('s1');
		$sink->setType('webhook');
		$sink->setEndpoint('https://x');
		$this->sinkMapper->method('findById')->willReturn($sink);
		$this->sinkMapper->expects($this->never())->method('update');
		$this->expectException(InvalidArgumentException::class);
		$this->service->updateSink(adminUid: 'admin', sinkId: 's1', params: ['format' => 'cef']);
	}//end testUpdateRefusesCefOnWebhook()
}//end class
