<?php

/**
 * The connection declaration integriq reads.
 *
 * `lib/Settings/connections.json` is static JSON that integriq turns into the
 * rows of Keepiq's Integrations page. Nothing in Keepiq reads it at runtime,
 * so a broken file fails nowhere in this repo: integriq skips it whole and the
 * page goes empty on some other instance. Every assertion here is a way that
 * file could go wrong without a sound.
 *
 * @category Test
 * @package  OCA\Keepiq\Tests\Unit\Settings
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://conduction.nl
 *
 * @spec openspec/changes/adopt-connection-registry/specs/admin-integrations/spec.md#requirement-req-keepiq-conn-001-keepiq-declares-its-outside-connections-in-one-static-file
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Keepiq\Tests\Unit\Settings;

use Opis\JsonSchema\Errors\ErrorFormatter;
use Opis\JsonSchema\Validator;
use PHPUnit\Framework\TestCase;

/**
 * Guards lib/Settings/connections.json against hydra connection-registry D2 and D12.
 *
 * @coversNothing
 */
class ConnectionsDeclarationTest extends TestCase {

	/**
	 * Integriq's schema, fetched with `gh api` from integriq `development` on
	 * 2026-09-14, where the file was last changed in
	 * a93665880f7f552d8280b84a1f5ce402507c4466. It carries amendments 1 to 7
	 * (`reportedOnly`, `adapter.jsonPath`, `adapter.simulatedValues` and the
	 * `{configKey, jsonPath}` form of `requiredConfig`).
	 *
	 * @var string
	 */
	private const SCHEMA = '/tests/fixtures/Integriq/connections.schema.json';

	/**
	 * The keys the file declares, in declared order.
	 *
	 * @var array<int, string>
	 */
	private const DECLARED_KEYS = ['hibp', 'siem'];

	/**
	 * Each linked anchor, and the settings section component that must carry it.
	 *
	 * @var array<string, string>
	 */
	private const ANCHOR_COMPONENTS = [
		'section-breach-check' => 'BreachCheckSection',
		'section-siem'         => 'SiemSection',
	];

	/**
	 * The repository root.
	 *
	 * @return string
	 */
	private function root(): string {
		return dirname(__DIR__, 3);
	}//end root()

	/**
	 * The contents of one repository file.
	 *
	 * @param string $path The path relative to the repository root.
	 *
	 * @return string
	 */
	private function read(string $path): string {
		$contents = file_get_contents($this->root() . '/' . $path);
		$this->assertIsString(actual: $contents, message: $path . ' must exist');

		return $contents;
	}//end read()

	/**
	 * The decoded declaration.
	 *
	 * @return array<string, mixed>
	 */
	private function declaration(): array {
		$decoded = json_decode($this->read(path: 'lib/Settings/connections.json'), true, 512, JSON_THROW_ON_ERROR);
		$this->assertIsArray(actual: $decoded);

		return $decoded;
	}//end declaration()

	/**
	 * The declared connections, keyed by connection key.
	 *
	 * @return array<string, array<string, mixed>>
	 */
	private function connectionsByKey(): array {
		$byKey = [];
		foreach ($this->declaration()['connections'] as $connection) {
			$byKey[(string) $connection['key']] = $connection;
		}

		return $byKey;
	}//end connectionsByKey()

	/**
	 * The file validates against integriq's JSON Schema.
	 *
	 * @return void
	 */
	public function testTheFileValidatesAgainstIntegriqsSchema(): void {
		$result = (new Validator())->validate(
			json_decode($this->read(path: 'lib/Settings/connections.json')),
			$this->read(path: ltrim(self::SCHEMA, '/'))
		);

		$errors = [];
		if ($result->hasError() === true) {
			$errors = (new ErrorFormatter())->format($result->error());
		}

		$this->assertTrue(condition: $result->isValid(), message: (string) json_encode($errors, JSON_PRETTY_PRINT));
	}//end testTheFileValidatesAgainstIntegriqsSchema()

	/**
	 * The schema check can fail: a misspelled field and a bad key are refused.
	 *
	 * Without this control a validator that accepts everything would pass the
	 * test above as well.
	 *
	 * @return void
	 */
	public function testTheSchemaRefusesAMisspelledFieldAndABadKey(): void {
		$schema = $this->read(path: ltrim(self::SCHEMA, '/'));

		$misspelled = json_decode($this->read(path: 'lib/Settings/connections.json'));
		$misspelled->connections[0]->setingsUrl = '/settings/admin/keepiq#section-breach-check';
		$this->assertFalse(condition: (new Validator())->validate($misspelled, $schema)->isValid());

		$badKey = json_decode($this->read(path: 'lib/Settings/connections.json'));
		$badKey->connections[1]->key = 'SIEM export';
		$this->assertFalse(condition: (new Validator())->validate($badKey, $schema)->isValid());
	}//end testTheSchemaRefusesAMisspelledFieldAndABadKey()

	/**
	 * The file names the app it ships in.
	 *
	 * Integriq refuses a file whose `app` differs from the app it was read
	 * from, and this app was renamed from doriath on 2026-08-21.
	 *
	 * @return void
	 */
	public function testTheFileNamesThisApp(): void {
		$infoXml = simplexml_load_file($this->root() . '/appinfo/info.xml');

		$this->assertNotFalse(condition: $infoXml);
		$this->assertSame(expected: 'keepiq', actual: (string) $infoXml->id);
		$this->assertSame(expected: (string) $infoXml->id, actual: $this->declaration()['app']);
	}//end testTheFileNamesThisApp()

	/**
	 * The keys are unique and in rising order.
	 *
	 * A row is keyed by app and key, so a second entry with the same key
	 * would overwrite the first.
	 *
	 * @return void
	 */
	public function testTheKeysAreUniqueAndOrdered(): void {
		$connections = $this->declaration()['connections'];
		$keys        = array_column($connections, 'key');

		$this->assertSame(expected: array_values(array_unique($keys)), actual: $keys, message: 'a key is declared twice');
		$this->assertSame(expected: self::DECLARED_KEYS, actual: $keys);

		$orders = array_column($connections, 'order');
		$sorted = $orders;
		sort($sorted);
		$this->assertSame(expected: $sorted, actual: $orders);
		$this->assertCount(expectedCount: count($keys), haystack: array_unique($orders));
	}//end testTheKeysAreUniqueAndOrdered()

	/**
	 * No text a reader sees carries an em-dash, and every title is sentence case (voice rule 8).
	 *
	 * SIEM is an abbreviation, so an all-caps word counts as sentence case.
	 *
	 * @return void
	 */
	public function testNoTextBreaksTheVoiceRules(): void {
		$raw = $this->read(path: 'lib/Settings/connections.json');
		$this->assertStringNotContainsString(needle: "\u{2014}", haystack: $raw);
		$this->assertStringNotContainsString(needle: '--', haystack: $raw);

		foreach ($this->declaration()['connections'] as $connection) {
			foreach (array_slice(explode(' ', (string) $connection['title']), 1) as $word) {
				$this->assertTrue(
					condition: $word === mb_strtolower($word) || $word === mb_strtoupper($word),
					message: $connection['key'] . ' title is not sentence case: ' . $word
				);
			}
		}
	}//end testNoTextBreaksTheVoiceRules()

	/**
	 * Every settings link lands on a section that exists and is rendered.
	 *
	 * The admin section id is `keepiq`: info.xml names Sections\SettingsSection,
	 * and Keepiq's own navigation links to the same page. A link into a section
	 * that does not exist scrolls nowhere and logs nothing. An id on a component
	 * that Settings.vue does not render is just as missing.
	 *
	 * @return void
	 */
	public function testEverySettingsLinkPointsAtARenderedSection(): void {
		$this->assertStringContainsString(
			needle: '<admin-section>OCA\Keepiq\Sections\SettingsSection</admin-section>',
			haystack: $this->read(path: 'appinfo/info.xml')
		);
		$this->assertStringContainsString(
			needle: "generateUrl('/settings/admin/keepiq')",
			haystack: $this->read(path: 'src/components/KeepiqAppNav/KeepiqAppNav.vue')
		);

		$settings = $this->read(path: 'src/views/settings/Settings.vue');
		$linked   = [];
		foreach ($this->declaration()['connections'] as $connection) {
			$url = (string) ($connection['settingsUrl'] ?? '');
			$this->assertMatchesRegularExpression(
				pattern: '~^/settings/admin/keepiq#section-[a-z0-9-]+$~',
				string: $url,
				message: $connection['key'] . ' links somewhere other than a Keepiq admin section'
			);

			$anchor = substr($url, ((int) strpos($url, '#') + 1));
			$this->assertArrayHasKey(key: $anchor, array: self::ANCHOR_COMPONENTS, message: $connection['key'] . ' links to an unknown anchor');
			$component = self::ANCHOR_COMPONENTS[$anchor];

			$this->assertMatchesRegularExpression(
				pattern: '/<template>\s*<CnSettingsSection\s+id="' . preg_quote($anchor, '/') . '"/',
				string: $this->read(path: 'src/components/settings/' . $component . '.vue'),
				message: $component . ' does not carry #' . $anchor . ' on its root section'
			);
			$this->assertStringContainsString(needle: '<' . $component . ' />', haystack: $settings, message: $component . ' is not rendered');

			$linked[] = $anchor;
		}

		$this->assertSame(expected: array_keys(self::ANCHOR_COMPONENTS), actual: $linked);
	}//end testEverySettingsLinkPointsAtARenderedSection()

	/**
	 * The breach check requires the one switch that gates every lookup.
	 *
	 * The switch is written as a boolean. Integriq reads a stored `false` as
	 * empty (hydra#676), so a switched-off check reads Not configured.
	 *
	 * @return void
	 */
	public function testTheBreachCheckRequiresItsSwitch(): void {
		$hibp = $this->connectionsByKey()['hibp'];

		$this->assertSame(expected: ['breach_check_enabled'], actual: $hibp['requiredConfig']);
		$this->assertArrayNotHasKey(key: 'reportedOnly', array: $hibp);
		$this->assertArrayNotHasKey(key: 'adapter', array: $hibp);

		$this->assertStringContainsString(
			needle: "getValueBool(Application::APP_ID, 'breach_check_enabled', false) === false",
			haystack: $this->read(path: 'lib/Controller/BreachProxyController.php')
		);
		$this->assertStringContainsString(
			needle: "setValueBool(\$appId, 'breach_check_enabled',",
			haystack: $this->read(path: 'lib/Service/AdminSettingsService.php')
		);
	}//end testTheBreachCheckRequiresItsSwitch()

	/**
	 * SIEM is one reported-only row for every sink, and carries nothing for integriq to guess from.
	 *
	 * @return void
	 */
	public function testSiemIsOneReportedOnlyRow(): void {
		$siem = $this->connectionsByKey()['siem'];

		$this->assertTrue(condition: $siem['reportedOnly']);
		$this->assertArrayNotHasKey(key: 'requiredConfig', array: $siem);
		$this->assertArrayNotHasKey(key: 'adapter', array: $siem);
		$this->assertStringStartsWith(prefix: 'Not checked yet.', string: $siem['unconfiguredMessage']);
	}//end testSiemIsOneReportedOnlyRow()
}//end class
