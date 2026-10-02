<?php

/**
 * A sink reports its connector settings and never its credential.
 *
 * @category Test
 * @package  OCA\Keepiq\Tests\Unit\Db
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

namespace OCA\Keepiq\Tests\Unit\Db;

use OCA\Keepiq\Db\SiemSink;
use PHPUnit\Framework\TestCase;

/**
 * SiemSink serialization of the connector fields.
 *
 * @spec openspec/specs/siem-vendor-connectors/spec.md
 */
class SiemSinkConnectorTest extends TestCase {

	/**
	 * The serialized sink holds format, options and hasCredential, and no key
	 * or value holds the credential ciphertext.
	 *
	 * @return void
	 */
	public function testCredentialNeverSerialized(): void {
		$sink = new SiemSink();
		$sink->setId('s1');
		$sink->setType('sentinel');
		$sink->setFormat('json');
		$sink->setCredentialEnc('CIPHERTEXT-OF-CLIENT-SECRET');
		$sink->setConnectorOptions((string)json_encode(['tenantId' => 't-1', 'clientId' => 'c-1']));

		$data = $sink->jsonSerialize();
		$this->assertSame('json', $data['format']);
		$this->assertSame(['tenantId' => 't-1', 'clientId' => 'c-1'], $data['connectorOptions']);
		$this->assertTrue($data['hasCredential']);
		$this->assertArrayNotHasKey('credentialEnc', $data);
		$this->assertStringNotContainsString('CIPHERTEXT-OF-CLIENT-SECRET', (string)json_encode($data));

		$empty = new SiemSink();
		$this->assertFalse($empty->jsonSerialize()['hasCredential']);
		$this->assertSame([], $empty->jsonSerialize()['connectorOptions']);
	}//end testCredentialNeverSerialized()
}//end class
