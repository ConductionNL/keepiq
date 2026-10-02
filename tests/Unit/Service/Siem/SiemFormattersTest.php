<?php

/**
 * SIEM connector formatters: CEF escaping and severity, Splunk HEC and
 * Sentinel shapes, and the no-secret-material guard.
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

use OCA\Keepiq\Event\Audit\AuditEventTypes;
use OCA\Keepiq\Service\Siem\CefFormatter;
use OCA\Keepiq\Service\Siem\JsonFormatter;
use OCA\Keepiq\Service\Siem\SentinelRowFormatter;
use OCA\Keepiq\Service\Siem\SplunkHecFormatter;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * The formatters.
 *
 * @spec openspec/changes/audit-siem-vendor-connectors/tasks.md#2.1
 */
class SiemFormattersTest extends TestCase {

	/**
	 * A fixed payload.
	 *
	 * @param string $eventType The event type
	 * @param array<string,mixed> $metadata The metadata
	 *
	 * @return array<string,mixed>
	 */
	private static function payload(string $eventType = 'suite.revoked', array $metadata = ['suiteId' => 'suite-9']): array {
		return [
			'eventType' => $eventType,
			'category' => explode('.', $eventType)[0],
			'actorType' => 'user',
			'actorId' => 'admin',
			'objectType' => 'suite',
			'objectId' => 'suite-9',
			'occurredAt' => '2026-10-02T12:00:00+00:00',
			'metadata' => $metadata,
		];
	}//end payload()

	/**
	 * The CEF line for a suite revocation.
	 *
	 * @return void
	 */
	public function testCefLine(): void {
		$line = (new CefFormatter())->format(self::payload(), '0.3.4');
		$this->assertSame(
			'CEF:0|Conduction|Keepiq|0.3.4|suite.revoked|suite revoked|8|rt=1790942400000 cat=suite act=suite.revoked suser=admin'
			. ' cs1Label=actorType cs1=user cs2Label=objectType cs2=suite cs3Label=objectId cs3=suite-9 msg={"suiteId":"suite-9"}',
			$line
		);
	}//end testCefLine()

	/**
	 * Header fields escape \ and |; extension values escape \, = and line breaks.
	 *
	 * @return void
	 */
	public function testCefEscaping(): void {
		$this->assertSame('a\\|b\\\\c', CefFormatter::header('a|b\\c'));
		$this->assertSame('k\\=v\\\\x\\ny\\nz\\r', CefFormatter::extension("k=v\\x\ny\r\nz\r"));

		$line = (new CefFormatter())->format(self::payload('share.created', ['shareId' => 'a|b=c']), '1|2');
		$this->assertStringStartsWith('CEF:0|Conduction|Keepiq|1\\|2|share.created|', $line);
		$this->assertStringContainsString('msg={"shareId":"a|b\\=c"}', $line);
		// A pipe in a header value cannot add a header field: the seven header
		// fields are still separated by exactly six unescaped pipes.
		$this->assertSame(6, preg_match_all('/(?<!\\\\)\|/', explode('|rt=', $line)[0]));
	}//end testCefEscaping()

	/**
	 * One line per category: severity follows the fixed map.
	 *
	 * @return array<string,array{string,int}>
	 */
	public static function categories(): array {
		return [
			'honey' => ['honey.triggered', 10],
			'suite' => ['suite.revoked', 8],
			'emergency' => ['emergency.granted', 7],
			'share' => ['share.created', 5],
			'secret' => ['secret.updated', 3],
			'siem' => ['siem.sink_tested', 3],
		];
	}//end categories()

	/**
	 * The severity field of a category.
	 *
	 * @param string $eventType The event type
	 * @param int $severity The expected severity
	 *
	 * @return void
	 */
	#[DataProvider('categories')]
	public function testCefSeverityPerCategory(string $eventType, int $severity): void {
		$fields = explode('|', (new CefFormatter())->format(self::payload($eventType), '1'));
		$this->assertSame((string)$severity, $fields[6]);
	}//end testCefSeverityPerCategory()

	/**
	 * Snapshot of the Splunk HEC envelope, with and without options.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/audit-siem-vendor-connectors/tasks.md#2.2
	 */
	public function testSplunkEnvelope(): void {
		$f = new SplunkHecFormatter();
		$this->assertSame(
			'{"time":1790942400,"host":"cloud.example.org","source":"keepiq","sourcetype":"keepiq:audit","event":'
			. '{"eventType":"suite.revoked","category":"suite","actorType":"user","actorId":"admin","objectType":"suite",'
			. '"objectId":"suite-9","occurredAt":"2026-10-02T12:00:00+00:00","metadata":{"suiteId":"suite-9"}}}',
			json_encode($f->format(self::payload(), 'cloud.example.org'))
		);
		$with = $f->format(self::payload(), 'h', ['index' => 'security', 'sourcetype' => 'custom:audit']);
		$this->assertSame('security', $with['index']);
		$this->assertSame('custom:audit', $with['sourcetype']);
	}//end testSplunkEnvelope()

	/**
	 * Snapshot of the Sentinel row.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/audit-siem-vendor-connectors/tasks.md#2.2
	 */
	public function testSentinelRow(): void {
		$this->assertSame(
			'{"TimeGenerated":"2026-10-02T12:00:00Z","EventType":"suite.revoked","Category":"suite","ActorType":"user",'
			. '"ActorId":"admin","ObjectType":"suite","ObjectId":"suite-9","Metadata":{"suiteId":"suite-9"}}',
			json_encode((new SentinelRowFormatter())->format(self::payload()))
		);
		$this->assertSame(SentinelRowFormatter::COLUMNS, array_keys((new SentinelRowFormatter())->format(self::payload())));
	}//end testSentinelRow()

	/**
	 * The guard: a payload with a planted forbidden metadata key and a planted
	 * top-level field reaches no formatter's output, and every value in each
	 * output is either derived from the payload or a fixed vendor constant.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/audit-siem-vendor-connectors/tasks.md#2.3
	 */
	public function testNoFormatterCarriesAnythingButThePayload(): void {
		$metadata = ['suiteId' => 'suite-9'];
		foreach (AuditEventTypes::FORBIDDEN_KEYS as $i => $key) {
			$metadata[$key] = 'PLANTED-SECRET-' . $i;
		}

		$payload = self::payload('suite.revoked', $metadata);
		$payload['secretValue'] = 'PLANTED-TOP-LEVEL';

		$outputs = [
			'json' => (new JsonFormatter())->format($payload),
			'cef' => (new CefFormatter())->format($payload, '0.3.4'),
			'splunk' => (string)json_encode((new SplunkHecFormatter())->format($payload, 'cloud.example.org')),
			'sentinel' => (string)json_encode((new SentinelRowFormatter())->format($payload)),
		];
		foreach ($outputs as $name => $out) {
			$this->assertStringNotContainsString('PLANTED', $out, $name . ' carries planted material');
			foreach (AuditEventTypes::FORBIDDEN_KEYS as $key) {
				$this->assertStringNotContainsString('"' . $key . '"', $out, $name . ' carries the key ' . $key);
			}
		}

		$allowed = [
			'suite.revoked', 'suite', 'user', 'admin', 'suite-9', '2026-10-02T12:00:00+00:00', '2026-10-02T12:00:00Z',
			1790942400, 1790942400.0, '1790942400',
			// vendor constants
			'keepiq', 'keepiq:audit', 'cloud.example.org',
		];
		$decoded = [
			'splunk' => json_decode($outputs['splunk'], true),
			'sentinel' => json_decode($outputs['sentinel'], true),
			'json' => json_decode($outputs['json'], true),
		];
		foreach ($decoded as $name => $out) {
			array_walk_recursive(
				$out,
				function ($leaf) use ($allowed, $name): void {
					$this->assertContains($leaf, $allowed, $name . ' output holds a value that is neither from the payload nor a vendor constant');
				}
			);
			$this->assertNotEmpty($out);
		}
	}//end testNoFormatterCarriesAnythingButThePayload()
}//end class
