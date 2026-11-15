<?php

/**
 * SiemSink: the sink's stored options read back, and what its API form
 * shows; the encrypted secrets never leave it.
 *
 * @category Test
 * @package  OCA\Keepiq\Tests\Unit\Db
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @link https://conduction.nl
 */

declare(strict_types=1);

namespace OCA\Keepiq\Tests\Unit\Db;

use DateTime;
use OCA\Keepiq\Db\SiemSink;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * The SIEM sink entity.
 */
class SiemSinkTest extends TestCase {
	/**
	 * Stored category filters and what they read as.
	 *
	 * @return array<string, array{0: string|null, 1: array<int, string>|null}>
	 */
	public static function categoryFilters(): array {
		return [
			'none'          => [null, null],
			'empty'         => ['', null],
			'an empty list' => ['[]', null],
			'not json'      => ['secret,share', null],
			'a list'        => ['["secret","share"]', ['secret', 'share']],
			'numbers'       => ['[1,2]', ['1', '2']],
			'a json string' => ['"secret"', null],
		];
	}//end categoryFilters()

	/**
	 * A category filter reads as a list of names, or null for every category.
	 *
	 * @param string|null $stored The stored filter.
	 * @param array<int, string>|null $expected The filter read back.
	 *
	 * @return void
	 */
	#[DataProvider('categoryFilters')]
	public function testTheCategoryFilterReadsAsAList(?string $stored, ?array $expected): void {
		$sink = new SiemSink();
		$sink->setCategoryFilter($stored);
		$this->assertSame(expected: $expected, actual: $sink->categoryFilterArray());
	}//end testTheCategoryFilterReadsAsAList()

	/**
	 * Connector options read as a map of strings, or empty when unreadable.
	 *
	 * @return void
	 */
	public function testConnectorOptionsReadAsStrings(): void {
		$sink = new SiemSink();
		$this->assertSame(expected: [], actual: $sink->connectorOptionsArray());
		$sink->setConnectorOptions('{"index":"keepiq","port":9200,"verbose":true}');
		$this->assertSame(expected: ['index' => 'keepiq', 'port' => '9200', 'verbose' => '1'], actual: $sink->connectorOptionsArray());
		$sink->setConnectorOptions('not json');
		$this->assertSame(expected: [], actual: $sink->connectorOptionsArray());
		$sink->setConnectorOptions('');
		$this->assertSame(expected: [], actual: $sink->connectorOptionsArray());
	}//end testConnectorOptionsReadAsStrings()

	/**
	 * The API form says whether a secret is set, never what it is.
	 *
	 * @return void
	 */
	public function testTheApiFormHidesTheSecrets(): void {
		$sink = new SiemSink();
		$sink->setId('sink-1');
		$sink->setName('Splunk');
		$sink->setType('splunk_hec');
		$sink->setEndpoint('https://siem.example.org/hec');
		$sink->setHmacSecretEnc('enc:hmac');
		$sink->setCredentialEnc('enc:token');
		$sink->setCreatedAt(new DateTime('2026-11-14T12:00:00+00:00'));
		$json = $sink->jsonSerialize();
		$this->assertTrue(condition: $json['hasHmacSecret']);
		$this->assertTrue(condition: $json['hasCredential']);
		$this->assertStringNotContainsString(needle: 'enc:', haystack: (string)json_encode($json));
		$this->assertSame(
			expected: ['sink-1', 'Splunk', 'splunk_hec', true, true, 'json', 1000],
			actual: [$json['id'], $json['name'], $json['type'], $json['enabled'], $json['tls'], $json['format'], $json['queueCap']]
		);
		$this->assertSame(expected: '2026-11-14T12:00:00+00:00', actual: $json['createdAt']);
		$this->assertNull(actual: $json['lastSuccessAt']);
	}//end testTheApiFormHidesTheSecrets()

	/**
	 * Without secrets, or with empty ones, the API form says none are set.
	 *
	 * @return void
	 */
	public function testEmptySecretsAreNotSet(): void {
		$sink = new SiemSink();
		$this->assertFalse(condition: $sink->jsonSerialize()['hasHmacSecret']);
		$sink->setHmacSecretEnc('');
		$sink->setCredentialEnc('');
		$json = $sink->jsonSerialize();
		$this->assertFalse(condition: $json['hasHmacSecret']);
		$this->assertFalse(condition: $json['hasCredential']);
	}//end testEmptySecretsAreNotSet()
}//end class
