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
}//end class
