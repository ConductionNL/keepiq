<?php

/**
 * The JSON columns of the audit entry, compliance report and dashboard
 * setting entities read back, and the secret version's two API forms.
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

use OCA\Keepiq\Db\AuditEntry;
use OCA\Keepiq\Db\ComplianceReport;
use OCA\Keepiq\Db\DashboardSetting;
use OCA\Keepiq\Db\SecretVersion;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * The stored JSON fields.
 */
class StoredJsonFieldsTest extends TestCase {
	/**
	 * Stored audit metadata and what it reads as.
	 *
	 * @return array<string, array{0: string|null, 1: array<string, mixed>}>
	 */
	public static function metadata(): array {
		return [
			'none'      => [null, []],
			'empty'     => ['', []],
			'an object' => ['{"reason":"retention","count":3}', ['reason' => 'retention', 'count' => 3]],
			'not json'  => ['reason=retention', []],
			'a string'  => ['"retention"', []],
		];
	}//end metadata()

	/**
	 * Audit metadata reads as a map, or empty when there is none to read.
	 *
	 * @param string|null $stored The stored metadata.
	 * @param array<string, mixed> $expected The metadata read back.
	 *
	 * @return void
	 */
	#[DataProvider('metadata')]
	public function testAuditMetadataReadsAsAMap(?string $stored, array $expected): void {
		$entry = new AuditEntry();
		$entry->setMetadata($stored);
		$this->assertSame(expected: $expected, actual: $entry->getMetadataArray());
		$this->assertSame(expected: $expected, actual: $entry->jsonSerialize()['metadata']);
	}//end testAuditMetadataReadsAsAMap()

	/**
	 * A compliance report's aggregate and configuration read as maps, or empty.
	 *
	 * @return void
	 */
	public function testAComplianceReportsJsonReadsAsMaps(): void {
		$report = new ComplianceReport();
		$report->setAggregate('{"secrets":12}');
		$report->setConfigSnapshot('broken');
		$json = $report->jsonSerialize();
		$this->assertSame(expected: ['secrets' => 12], actual: $json['aggregate']);
		$this->assertSame(expected: [], actual: $json['configSnapshot']);
	}//end testAComplianceReportsJsonReadsAsMaps()

	/**
	 * Stored setting values and what they read as.
	 *
	 * @return array<string, array{0: string, 1: mixed}>
	 */
	public static function settings(): array {
		return [
			'a list'    => ['["a","b"]', ['a', 'b']],
			'a number'  => ['3', 3],
			'false'     => ['false', false],
			'null'      => ['null', null],
			'not json'  => ['{oops', null],
			'a string'  => ['"dark"', 'dark'],
		];
	}//end settings()

	/**
	 * A dashboard setting's value decodes to whatever JSON it holds, null when it is not JSON.
	 *
	 * @param string $stored The stored value.
	 * @param mixed $expected The value read back.
	 *
	 * @return void
	 */
	#[DataProvider('settings')]
	public function testADashboardSettingDecodesItsValue(string $stored, mixed $expected): void {
		$setting = new DashboardSetting();
		$setting->setSettingValue($stored);
		$this->assertSame(expected: $expected, actual: $setting->getDecodedValue());
		$this->assertSame(expected: $expected, actual: $setting->jsonSerialize()['settingValue']);
	}//end testADashboardSettingDecodesItsValue()

	/**
	 * A secret version's list form leaves out its ciphertext; the full form adds all five fields.
	 *
	 * @return void
	 */
	public function testASecretVersionShowsCiphertextOnlyWhenAsked(): void {
		$version = new SecretVersion();
		$version->setId('v1');
		$version->setSecretId('s1');
		$version->setVersionNumber(2);
		$version->setKey('key-ct');
		$version->setTotp('totp-ct');
		$list = $version->jsonSerialize();
		$this->assertSame(expected: ['v1', 's1', 2], actual: [$list['id'], $list['secretId'], $list['versionNumber']]);
		$this->assertArrayNotHasKey(key: 'key', array: $list);
		$this->assertStringNotContainsString(needle: '-ct', haystack: (string)json_encode($list));
		$full = $version->jsonSerializeWithBlobs();
		$this->assertSame(
			expected: ['key' => 'key-ct', 'login' => null, 'additionalFields' => null, 'totp' => 'totp-ct', 'passkeys' => null],
			actual: array_diff_key($full, $list)
		);
	}//end testASecretVersionShowsCiphertextOnlyWhenAsked()
}//end class
