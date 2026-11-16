<?php

/**
 * The sink fields of a request, read with the create defaults and the
 * update "unchanged" rules the controller arguments used to carry.
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

use OCA\Keepiq\Service\Siem\SiemSinkRequest;
use OCP\IRequest;
use PHPUnit\Framework\TestCase;

/**
 * SiemSinkRequest.
 *
 * @covers \OCA\Keepiq\Service\Siem\SiemSinkRequest
 *
 * @spec openspec/specs/siem-vendor-connectors/spec.md#requirement-named-siem-connectors-on-a-sink
 */
class SiemSinkRequestTest extends TestCase {

	/**
	 * A request answering the given params.
	 *
	 * @param array<string,mixed> $params The body
	 *
	 * @return SiemSinkRequest
	 */
	private function request(array $params): SiemSinkRequest {
		$request = $this->createMock(IRequest::class);
		$request->method('getParam')->willReturnCallback(static fn (string $k, $d = null) => $params[$k] ?? $d);
		return new SiemSinkRequest(request: $request);
	}//end request()

	/**
	 * A create gets every field with the old defaults.
	 *
	 * @return void
	 */
	public function testCreateDefaults(): void {
		$this->assertSame(
			[
				'name' => '', 'type' => 'splunk_hec', 'endpoint' => 'https://s', 'tls' => true, 'hmacSecret' => '',
				'categoryFilter' => [], 'queueCap' => 1000, 'enabled' => true, 'format' => 'json',
				'credential' => 'tok', 'connectorOptions' => ['index' => 'x'],
			],
			$this->request(['type' => 'splunk_hec', 'endpoint' => 'https://s', 'credential' => 'tok', 'connectorOptions' => ['index' => 'x']])->forCreate()
		);
		$created = $this->request(['type' => 'syslog', 'tls' => false, 'enabled' => 'false', 'queueCap' => '50', 'format' => 'cef'])->forCreate();
		$this->assertFalse($created['tls']);
		$this->assertFalse($created['enabled']);
		$this->assertSame(50, $created['queueCap']);
		$this->assertSame('cef', $created['format']);
	}//end testCreateDefaults()

	/**
	 * An update carries only supplied fields, and always both secrets.
	 *
	 * @return void
	 */
	public function testUpdateCarriesOnlySuppliedFields(): void {
		$this->assertSame(['hmacSecret' => '', 'credential' => ''], $this->request(['name' => '', 'endpoint' => ''])->forUpdate());
		$this->assertSame(
			['hmacSecret' => '', 'credential' => 'new', 'name' => 'Renamed', 'tls' => false, 'queueCap' => 20, 'connectorOptions' => []],
			$this->request(['name' => 'Renamed', 'tls' => false, 'queueCap' => 20, 'credential' => 'new', 'connectorOptions' => []])->forUpdate()
		);
	}//end testUpdateCarriesOnlySuppliedFields()

	/**
	 * A blank format on create falls back to json; a supplied one is kept.
	 *
	 * @return void
	 */
	public function testCreateBlankFormatFallsBackToJson(): void {
		$this->assertSame('json', $this->request(params: ['format' => ''])->forCreate()['format']);
		$this->assertSame('json', $this->request(params: [])->forCreate()['format']);
		$this->assertSame('splunk', $this->request(params: ['format' => 'splunk'])->forCreate()['format']);
	}//end testCreateBlankFormatFallsBackToJson()

	/**
	 * Malformed fields read as absent on create: a non-scalar string is '',
	 * a non-numeric cap and a non-array list take the defaults.
	 *
	 * @return void
	 */
	public function testCreateTreatsMalformedFieldsAsAbsent(): void {
		$created = $this->request(params:
			[
				'name' => ['nested'],
				'endpoint' => 12,
				'queueCap' => 'many',
				'categoryFilter' => 'auth',
				'connectorOptions' => 'x',
				'tls' => '',
				'enabled' => '',
			]
		)->forCreate();

		$this->assertSame('', $created['name']);
		$this->assertSame('12', $created['endpoint']);
		$this->assertSame(1000, $created['queueCap']);
		$this->assertSame([], $created['categoryFilter']);
		$this->assertSame([], $created['connectorOptions']);
		$this->assertTrue($created['tls']);
		$this->assertTrue($created['enabled']);
	}//end testCreateTreatsMalformedFieldsAsAbsent()

	/**
	 * Boolean fields accept the usual string spellings.
	 *
	 * @return void
	 */
	public function testCreateReadsBooleanSpellings(): void {
		$on = $this->request(params: ['tls' => 'yes', 'enabled' => '1'])->forCreate();
		$this->assertTrue($on['tls']);
		$this->assertTrue($on['enabled']);

		$off = $this->request(params: ['tls' => 'off', 'enabled' => 0])->forCreate();
		$this->assertFalse($off['tls']);
		$this->assertFalse($off['enabled']);
	}//end testCreateReadsBooleanSpellings()

	/**
	 * An update carries format, enabled and categoryFilter when supplied,
	 * and drops a non-numeric cap and a non-array list.
	 *
	 * @return void
	 */
	public function testUpdateCarriesRemainingFieldsAndDropsMalformedOnes(): void {
		$this->assertSame(
			[
				'hmacSecret' => 'h', 'credential' => '', 'endpoint' => 'https://e', 'format' => 'cef',
				'enabled' => true, 'categoryFilter' => ['auth'],
			],
			$this->request(params:
				[
					'hmacSecret' => 'h', 'endpoint' => 'https://e', 'format' => 'cef', 'enabled' => 'true',
					'categoryFilter' => ['auth'], 'queueCap' => 'lots', 'connectorOptions' => 'x', 'tls' => '',
				]
			)->forUpdate()
		);
	}//end testUpdateCarriesRemainingFieldsAndDropsMalformedOnes()

	/**
	 * A numeric-string cap is cast to an int; zero is supplied, not absent.
	 *
	 * @return void
	 */
	public function testUpdateKeepsAZeroCap(): void {
		$this->assertSame(0, $this->request(params: ['queueCap' => '0'])->forUpdate()['queueCap']);
		$this->assertSame(7, $this->request(params: ['queueCap' => '7.9'])->forUpdate()['queueCap']);
	}//end testUpdateKeepsAZeroCap()
}//end class
