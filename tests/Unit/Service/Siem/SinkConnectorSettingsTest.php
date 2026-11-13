<?php

/**
 * Unit tests for SinkConnectorSettings.
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

use InvalidArgumentException;
use OCA\Keepiq\Db\SiemSink;
use OCA\Keepiq\Service\Siem\SinkConnectorSettings;
use PHPUnit\Framework\TestCase;

/**
 * Tests for SinkConnectorSettings.
 *
 * @covers \OCA\Keepiq\Service\Siem\SinkConnectorSettings
 * @uses   \OCA\Keepiq\Db\SiemSink
 */
class SinkConnectorSettingsTest extends TestCase {
	/**
	 * The settings under test.
	 *
	 * @var SinkConnectorSettings
	 */
	private SinkConnectorSettings $settings;

	/**
	 * Set up the settings.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		parent::setUp();
		$this->settings = new SinkConnectorSettings();
	}//end setUp()

	/**
	 * The minimal valid sentinel options.
	 *
	 * @return array<string, string>
	 */
	private function sentinelOptions(): array {
		return [
			'tenantId'               => 'tenant',
			'clientId'               => 'client',
			'dataCollectionEndpoint' => 'https://dce.example',
			'dcrImmutableId'         => 'dcr-1',
		];
	}//end sentinelOptions()

	/**
	 * Assert that forCreate refuses the params with the exact message.
	 *
	 * @param array<string, mixed> $params  The params.
	 * @param string               $message The expected message.
	 *
	 * @return void
	 */
	private function assertCreateRefused(array $params, string $message): void {
		try {
			$this->settings->forCreate(params: $params);
		} catch (InvalidArgumentException $e) {
			$this->assertSame($message, $e->getMessage());
			return;
		}

		$this->fail('forCreate accepted '.json_encode($params));
	}//end assertCreateRefused()

	/**
	 * A syslog sink takes any endpoint scheme, defaults to json and has no options.
	 *
	 * @return void
	 */
	public function testSyslogCreateDefaults(): void {
		$this->assertSame(
			['type' => 'syslog', 'endpoint' => 'udp://10.0.0.1:514', 'format' => 'json', 'connectorOptions' => null],
			$this->settings->forCreate(params: ['type' => 'syslog', 'endpoint' => 'udp://10.0.0.1:514'])
		);
	}//end testSyslogCreateDefaults()

	/**
	 * A syslog sink may use cef.
	 *
	 * @return void
	 */
	public function testSyslogMayUseCef(): void {
		$result = $this->settings->forCreate(params: ['type' => 'syslog', 'endpoint' => 'tcp://log:514', 'format' => 'cef']);

		$this->assertSame('cef', $result['format']);
	}//end testSyslogMayUseCef()

	/**
	 * Unknown types, a missing endpoint and plain-http endpoints are refused.
	 *
	 * @return void
	 */
	public function testCreateRefusesBadTypeAndEndpoint(): void {
		$this->assertCreateRefused(['type' => 'ftp', 'endpoint' => 'https://x'], 'type must be one of: syslog, webhook, splunk_hec, sentinel');
		$this->assertCreateRefused(['endpoint' => 'https://x'], 'type must be one of: syslog, webhook, splunk_hec, sentinel');
		$this->assertCreateRefused(['type' => 'webhook'], 'endpoint is required');
		$this->assertCreateRefused(['type' => 'webhook', 'endpoint' => ''], 'endpoint is required');
		$this->assertCreateRefused(['type' => 'webhook', 'endpoint' => 'http://hook'], 'webhook endpoints must be https://');
	}//end testCreateRefusesBadTypeAndEndpoint()

	/**
	 * Splunk and Sentinel need a non-empty string credential; a webhook does not.
	 *
	 * @return void
	 */
	public function testCredentialRequiredOnlyForConnectorTypes(): void {
		$this->assertCreateRefused(['type' => 'splunk_hec', 'endpoint' => 'https://hec'], 'splunk_hec needs a credential (the HEC token or the client secret)');
		$this->assertCreateRefused(['type' => 'splunk_hec', 'endpoint' => 'https://hec', 'credential' => ['t']], 'splunk_hec needs a credential (the HEC token or the client secret)');
		$this->assertCreateRefused(
			['type' => 'sentinel', 'endpoint' => 'https://s', 'credential' => '', 'connectorOptions' => $this->sentinelOptions()],
			'sentinel needs a credential (the HEC token or the client secret)'
		);

		$webhook = $this->settings->forCreate(params: ['type' => 'webhook', 'endpoint' => 'https://hook']);
		$this->assertSame('webhook', $webhook['type']);
	}//end testCredentialRequiredOnlyForConnectorTypes()

	/**
	 * Formats other than json/cef are refused, and cef only for syslog.
	 *
	 * @return void
	 */
	public function testFormatRules(): void {
		$this->assertCreateRefused(['type' => 'syslog', 'endpoint' => 'udp://l', 'format' => 'xml'], 'format must be json or cef');
		$this->assertCreateRefused(['type' => 'webhook', 'endpoint' => 'https://h', 'format' => 'cef'], 'format cef is only for syslog sinks');
	}//end testFormatRules()

	/**
	 * Splunk options are filtered of empty values and stringified.
	 *
	 * @return void
	 */
	public function testSplunkOptionsAreCleanedAndEncoded(): void {
		$result = $this->settings->forCreate(
			params: [
				'type'             => 'splunk_hec',
				'endpoint'         => 'https://hec',
				'credential'       => 'token',
				'connectorOptions' => ['index' => 'main', 'sourcetype' => ''],
			]
		);

		$this->assertSame('{"index":"main"}', $result['connectorOptions']);
	}//end testSplunkOptionsAreCleanedAndEncoded()

	/**
	 * An option that does not belong to the type, or a non-array, is refused.
	 *
	 * @return void
	 */
	public function testForeignOrMalformedOptionsAreRefused(): void {
		$this->assertCreateRefused(
			['type' => 'splunk_hec', 'endpoint' => 'https://hec', 'credential' => 't', 'connectorOptions' => ['tenantId' => 'x']],
			'connectorOptions.tenantId is not an option of splunk_hec'
		);
		$this->assertCreateRefused(
			['type' => 'webhook', 'endpoint' => 'https://h', 'connectorOptions' => ['index' => 'x']],
			'connectorOptions.index is not an option of webhook'
		);
		$this->assertCreateRefused(
			['type' => 'webhook', 'endpoint' => 'https://h', 'connectorOptions' => 'index=x'],
			'connectorOptions must be an object'
		);
	}//end testForeignOrMalformedOptionsAreRefused()

	/**
	 * Sentinel fills the stream and authority defaults.
	 *
	 * @return void
	 */
	public function testSentinelFillsDefaults(): void {
		$result = $this->settings->forCreate(
			params: ['type' => 'sentinel', 'endpoint' => 'https://s', 'credential' => 'secret', 'connectorOptions' => $this->sentinelOptions()]
		);

		$this->assertSame(
			$this->sentinelOptions() + ['streamName' => 'Custom-KeepiqAudit', 'authorityHost' => 'https://login.microsoftonline.com'],
			json_decode((string)$result['connectorOptions'], true)
		);
	}//end testSentinelFillsDefaults()

	/**
	 * Sentinel refuses a missing required option and non-https URLs.
	 *
	 * @return void
	 */
	public function testSentinelRefusesMissingOptionsAndPlainHttp(): void {
		$base = ['type' => 'sentinel', 'endpoint' => 'https://s', 'credential' => 'secret'];

		$options = $this->sentinelOptions();
		unset($options['dcrImmutableId']);
		$this->assertCreateRefused($base + ['connectorOptions' => $options], 'sentinel needs connectorOptions.dcrImmutableId');

		$options = ['tenantId' => ''] + $this->sentinelOptions();
		$this->assertCreateRefused($base + ['connectorOptions' => $options], 'sentinel needs connectorOptions.tenantId');

		$options = ['dataCollectionEndpoint' => 'http://dce'] + $this->sentinelOptions();
		$this->assertCreateRefused($base + ['connectorOptions' => $options], 'connectorOptions.dataCollectionEndpoint must be https://');

		$options = $this->sentinelOptions() + ['authorityHost' => 'http://login'];
		$this->assertCreateRefused($base + ['connectorOptions' => $options], 'connectorOptions.authorityHost must be https://');
	}//end testSentinelRefusesMissingOptionsAndPlainHttp()

	/**
	 * An update with no params leaves the sink untouched.
	 *
	 * @return void
	 */
	public function testEmptyUpdateChangesNothing(): void {
		$sink = new SiemSink();
		$sink->setType('webhook');
		$sink->setEndpoint('https://old');
		$sink->setFormat('json');
		$sink->setConnectorOptions('{"a":"b"}');
		$sink->resetUpdatedFields();

		$this->settings->applyUpdate(sink: $sink, params: ['endpoint' => '', 'format' => '']);

		$this->assertSame('https://old', $sink->getEndpoint());
		$this->assertSame('{"a":"b"}', $sink->getConnectorOptions());
		$this->assertSame([], $sink->getUpdatedFields());
	}//end testEmptyUpdateChangesNothing()

	/**
	 * An update validates against the sink's stored type.
	 *
	 * @return void
	 */
	public function testUpdateValidatesAgainstStoredType(): void {
		$sink = new SiemSink();
		$sink->setType('splunk_hec');

		$this->settings->applyUpdate(
			sink: $sink,
			params: ['endpoint' => 'https://new', 'format' => 'json', 'connectorOptions' => ['index' => 'sec']]
		);

		$this->assertSame('https://new', $sink->getEndpoint());
		$this->assertSame('json', $sink->getFormat());
		$this->assertSame('{"index":"sec"}', $sink->getConnectorOptions());
	}//end testUpdateValidatesAgainstStoredType()

	/**
	 * Empty connector options clear the stored options.
	 *
	 * @return void
	 */
	public function testUpdateWithEmptyOptionsClearsThem(): void {
		$sink = new SiemSink();
		$sink->setType('splunk_hec');
		$sink->setConnectorOptions('{"index":"main"}');

		$this->settings->applyUpdate(sink: $sink, params: ['connectorOptions' => ['index' => '']]);

		$this->assertNull($sink->getConnectorOptions());
	}//end testUpdateWithEmptyOptionsClearsThem()

	/**
	 * A refused update leaves the endpoint unchanged.
	 *
	 * @return void
	 */
	public function testRefusedUpdateKeepsTheEndpoint(): void {
		$sink = new SiemSink();
		$sink->setType('webhook');
		$sink->setEndpoint('https://old');

		try {
			$this->settings->applyUpdate(sink: $sink, params: ['endpoint' => 'http://plain']);
			$this->fail('applyUpdate accepted a plain-http endpoint');
		} catch (InvalidArgumentException $e) {
			$this->assertSame('webhook endpoints must be https://', $e->getMessage());
		}

		$this->assertSame('https://old', $sink->getEndpoint());
	}//end testRefusedUpdateKeepsTheEndpoint()

	/**
	 * Updating a non-syslog sink to cef is refused.
	 *
	 * @return void
	 */
	public function testUpdateRefusesCefOffSyslog(): void {
		$sink = new SiemSink();
		$sink->setType('webhook');

		$this->expectException(InvalidArgumentException::class);
		$this->expectExceptionMessage('format cef is only for syslog sinks');

		$this->settings->applyUpdate(sink: $sink, params: ['format' => 'cef']);
	}//end testUpdateRefusesCefOffSyslog()
}//end class
