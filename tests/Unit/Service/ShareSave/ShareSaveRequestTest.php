<?php

/**
 * Unit tests for ShareSaveRequest.
 *
 * @category Test
 * @package  OCA\Keepiq\Tests\Unit\Service\ShareSave
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

namespace OCA\Keepiq\Tests\Unit\Service\ShareSave;

use OCA\Keepiq\Db\SecretVersion;
use OCA\Keepiq\Service\ShareSave\ShareSaveRefusal;
use OCA\Keepiq\Service\ShareSave\ShareSaveRequest;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * The parser refuses every malformed body with its own code, before any
 * database access.
 */
class ShareSaveRequestTest extends TestCase {
	private const BASE = '2026-10-01T10:00:00+00:00';

	/**
	 * A valid owner body.
	 *
	 * @return array<string,mixed>
	 */
	private static function body(): array {
		return [
			'id' => 'src',
			'baseUpdatedAt' => self::BASE,
			'patch' => ['key' => 'K', 'login' => null, 'name' => 'Router', 'mergedPending' => 1],
			'copies' => [['secretId' => 'c-bob', 'key' => 'K-BOB', 'login' => null]],
			'skipped' => [['secretId' => 'c-carol', 'reason' => 'no_vault_key']],
			'federated' => [[
				'shareId' => 'fs-1',
				'certFingerprint' => str_repeat('A', 64),
				'key' => 'K-DAVE',
				'login' => 'L',
				'additionalFields' => null,
			]],
			'suspend' => [['shareId' => 'fs-2', 'reason' => 'unverified']],
		];
	}

	public function testAValidBodyIsParsed(): void {
		$request = new ShareSaveRequest(self::body());

		$this->assertSame(strtotime(self::BASE), $request->baseUpdatedAt->getTimestamp());
		$this->assertNull($request->sourceBaseUpdatedAt);
		$this->assertSame(['key' => 'K', 'login' => null, 'name' => 'Router', 'mergedPending' => 1], $request->patch);
		$this->assertSame(['c-bob', 'c-carol'], $request->entries->copyIds());
		$this->assertSame(['fs-1', 'fs-2'], $request->entries->shareIds());
		$this->assertSame(str_repeat('a', 64), $request->entries->federated[0]['certFingerprint']);
		$this->assertNull($request->stageId);
		$this->assertFalse($request->isChunk());
	}

	public function testAChunkNeedsNoPatch(): void {
		$body = ['baseUpdatedAt' => self::BASE, 'stage' => ['final' => false], 'copies' => self::body()['copies']];

		$request = new ShareSaveRequest($body);

		$this->assertTrue($request->isChunk());
		$this->assertNull($request->patch);
	}

	public function testAFinalCallNamesItsStage(): void {
		$body = self::body();
		$body['stage'] = ['id' => '0f8fad5b-d9cb-469f-a165-70867728950e', 'final' => true];

		$request = new ShareSaveRequest($body);

		$this->assertSame('0f8fad5b-d9cb-469f-a165-70867728950e', $request->stageId);
		$this->assertFalse($request->isChunk());
	}

	/**
	 * Every refusal, with the body change that causes it.
	 *
	 * @return array<string,array{0:string,1:callable(array<string,mixed>):array<string,mixed>}>
	 */
	public static function refusals(): array {
		$set = static fn (string $key, mixed $value): callable => static function (array $body) use ($key, $value): array {
			$body[$key] = $value;
			return $body;
		};
		$copy = static fn (array $entry): callable => static function (array $body) use ($entry): array {
			$body['copies'] = [$entry];
			return $body;
		};
		$federated = static fn (array $change): callable => static function (array $body) use ($change): array {
			$body['federated'] = [array_merge($body['federated'][0], $change)];
			return $body;
		};

		return [
			'no base' => ['base_required', $set('baseUpdatedAt', null)],
			'empty base' => ['base_required', $set('baseUpdatedAt', '')],
			'base not a date' => ['invalid_body', $set('baseUpdatedAt', 'yesterday-ish')],
			'source base not a date' => ['invalid_body', $set('sourceBaseUpdatedAt', ['x'])],
			'patch missing' => ['no_value_change', $set('patch', null)],
			'patch only renames' => ['no_value_change', $set('patch', ['name' => 'New'])],
			'patch a list' => ['invalid_body', $set('patch', ['K'])],
			'patch unknown field' => ['invalid_body', $set('patch', ['key' => 'K', 'encryptedKey' => 'K'])],
			'patch base inside' => ['invalid_body', $set('patch', ['key' => 'K', 'baseUpdatedAt' => self::BASE])],
			'patch key null' => ['invalid_body', $set('patch', ['key' => null])],
			'patch key empty' => ['invalid_key', $set('patch', ['key' => ''])],
			'patch login a number' => ['invalid_body', $set('patch', ['login' => 5])],
			'patch mergedPending negative' => ['invalid_body', $set('patch', ['key' => 'K', 'mergedPending' => -1])],
			'copies not a list' => ['invalid_body', $set('copies', ['secretId' => 'c-bob'])],
			'copy old key name' => ['invalid_body', $copy(['secretId' => 'c-bob', 'encryptedKey' => 'K'])],
			'copy without id' => ['invalid_body', $copy(['key' => 'K', 'login' => null])],
			'copy empty id' => ['invalid_body', $copy(['secretId' => '', 'key' => 'K', 'login' => null])],
			'copy key null' => ['invalid_body', $copy(['secretId' => 'c-bob', 'key' => null, 'login' => null])],
			'copy key empty' => ['invalid_key', $copy(['secretId' => 'c-bob', 'key' => '', 'login' => null])],
			'copy without login' => ['copy_fields_mismatch', $copy(['secretId' => 'c-bob', 'key' => 'K'])],
			'copy keeps login' => ['copy_fields_mismatch', $copy(['secretId' => 'c-bob', 'key' => 'K', 'login' => 'L'])],
			'copy adds fields' => [
				'copy_fields_mismatch',
				$copy(['secretId' => 'c-bob', 'key' => 'K', 'login' => null, 'additionalFields' => 'F']),
			],
			'skip other reason' => ['invalid_body', $set('skipped', [['secretId' => 'c-carol', 'reason' => 'lazy']])],
			'skip twice' => ['duplicate_entry', $set('skipped', [['secretId' => 'c-bob', 'reason' => 'no_vault_key']])],
			'federated without login' => ['invalid_body', static function (array $body): array {
				unset($body['federated'][0]['login']);
				return $body;
			}],
			'federated bad fingerprint' => ['invalid_fingerprint', $federated(['certFingerprint' => 'abc'])],
			'federated empty key' => ['invalid_key', $federated(['key' => ''])],
			'suspend bad reason' => ['invalid_body', $set('suspend', [['shareId' => 'fs-2', 'reason' => 'Not Verified!']])],
			'suspend and write' => ['duplicate_entry', $set('suspend', [['shareId' => 'fs-1', 'reason' => 'unverified']])],
			'stage without final' => ['invalid_body', $set('stage', ['id' => '0f8fad5b-d9cb-469f-a165-70867728950e'])],
			'stage id not a uuid' => ['invalid_body', $set('stage', ['id' => 'x', 'final' => true])],
			'stage final a string' => ['invalid_body', $set('stage', ['final' => 'yes'])],
			'empty chunk' => ['empty_chunk', static fn (array $body): array => [
				'baseUpdatedAt' => self::BASE,
				'stage' => ['final' => false],
			]],
			'patch totp a number' => ['invalid_body', $set('patch', ['totp' => 5])],
			'copy totp a number' => ['invalid_body', $copy(['secretId' => 'c-bob', 'key' => 'K', 'login' => null, 'totp' => 5])],
			'copy adds totp' => ['copy_fields_mismatch', $copy(['secretId' => 'c-bob', 'key' => 'K', 'login' => null, 'totp' => 'T'])],
			'copy adds passkeys' => [
				'copy_fields_mismatch',
				$copy(['secretId' => 'c-bob', 'key' => 'K', 'login' => null, 'passkeys' => null]),
			],
			'copy without the patch totp' => ['copy_fields_mismatch', static function (array $body): array {
				$body['patch']['totp'] = 'T';
				return $body;
			}],
			'copy clears the patch totp' => ['copy_fields_mismatch', static function (array $body): array {
				$body['patch']['totp'] = 'T';
				$body['copies'][0]['totp'] = null;
				return $body;
			}],
			'federated totp a number' => ['invalid_body', $federated(['totp' => 5])],
			'federated unknown field' => ['invalid_body', $federated(['seed' => 'S'])],
			'restore without the whole value' => ['invalid_body', $set('restoredFrom', 'v-1')],
			'restore id not a string' => ['invalid_body', $set('restoredFrom', 7)],
			'restore on a chunk' => ['invalid_body', static fn (array $body): array => [
				...$body,
				'stage' => ['final' => false],
				'restoredFrom' => 'v-1',
			]],
		];
	}

	public function testARestoreCarriesTheWholeValue(): void {
		$body = self::body();
		$body['restoredFrom'] = 'v-1';
		$body['patch'] = ['key' => 'K', 'login' => null, 'additionalFields' => null];
		$body['copies'] = [['secretId' => 'c-bob', 'key' => 'K-BOB', 'login' => null, 'additionalFields' => null]];

		$this->assertSame('v-1', (new ShareSaveRequest($body))->restoredFrom);
	}

	/**
	 * A version holding this `totp` and `passkeys`, none by default.
	 *
	 * @param string|null $totp The version's TOTP ciphertext
	 * @param string|null $passkeys The version's passkeys ciphertext
	 *
	 * @return SecretVersion
	 */
	private function version(?string $totp = null, ?string $passkeys = null): SecretVersion {
		$version = new SecretVersion();
		$version->setId('v-1');
		$version->setTotp($totp);
		$version->setPasskeys($passkeys);

		return $version;
	}

	/**
	 * A restore body whose patch, copy and partner share carry these new fields.
	 *
	 * @param array<string,mixed> $fields The `totp` and `passkeys` sent
	 *
	 * @return array<string,mixed>
	 */
	private static function restoreBody(array $fields): array {
		$body = self::body();
		$body['restoredFrom'] = 'v-1';
		$body['patch'] = ['key' => 'K', 'login' => null, 'additionalFields' => null] + $fields;
		$body['copies'] = [['secretId' => 'c-bob', 'key' => 'K-BOB', 'login' => null, 'additionalFields' => null] + $fields];
		$body['federated'][0] += $fields;

		return $body;
	}

	public function testARestoreFromAnOlderClientNeedsOnlyTheLegacyFields(): void {
		$request = new ShareSaveRequest(self::restoreBody(fields: []));

		[$patch, $entries] = $request->restoring(version: $this->version(), entries: $request->entries);

		$this->assertSame('v-1', $request->restoredFrom);
		$this->assertSame($request->patch, $patch);
		$this->assertSame($request->entries->copies, $entries->copies);
		$this->assertSame($request->entries->federated, $entries->federated);
		$this->assertSame($request->entries->skipped, $entries->skipped);
		$this->assertSame($request->entries->suspend, $entries->suspend);
	}

	/**
	 * A field the version does not hold, null or empty, leaves the head's and every copy's value as it is,
	 * as a plain restore does. A partner share keeps the whole value it was sent.
	 */
	public function testARestoreLeavesOutAFieldTheVersionDoesNotHold(): void {
		foreach ([null, ''] as $none) {
			$request = new ShareSaveRequest(self::restoreBody(fields: ['totp' => 'T', 'passkeys' => 'P']));

			[$patch, $entries] = $request->restoring(version: $this->version(totp: $none, passkeys: 'V-P'), entries: $request->entries);

			$this->assertSame(['key' => 'K', 'login' => null, 'additionalFields' => null, 'passkeys' => 'P'], $patch);
			$this->assertArrayNotHasKey('totp', $entries->copies[0]);
			$this->assertSame('P', $entries->copies[0]['passkeys']);
			$this->assertSame($request->entries->federated, $entries->federated);
		}
	}

	/**
	 * A copy's `totp` or `passkeys` holds no value when it is null or empty, as a partner share's does.
	 */
	public function testAnEmptyNewFieldOnACopyCountsAsNoValue(): void {
		$body = self::body();
		$body['patch'] += ['totp' => null, 'passkeys' => ''];
		$body['copies'][0] += ['totp' => '', 'passkeys' => null];
		$this->assertSame('', (new ShareSaveRequest($body))->entries->copies[0]['totp']);

		$body['patch']['totp'] = 'T';
		try {
			new ShareSaveRequest($body);
			$this->fail('A copy with an empty totp next to a patch value was accepted');
		} catch (ShareSaveRefusal $refusal) {
			$this->assertSame('copy_fields_mismatch', $refusal->getError());
		}
	}

	public function testARestoreCarryingTheFieldsTheVersionHoldsKeepsThem(): void {
		$request = new ShareSaveRequest(self::restoreBody(fields: ['totp' => 'T', 'passkeys' => 'P']));

		[$patch, $entries] = $request->restoring(version: $this->version(totp: 'V-T', passkeys: 'V-P'), entries: $request->entries);

		$this->assertSame($request->patch, $patch);
		$this->assertSame($request->entries->copies, $entries->copies);
		$this->assertSame($request->entries->federated, $entries->federated);
	}

	/**
	 * A restore that leaves out or clears a field the version holds would leave the secret half restored.
	 */
	public function testARestoreWithoutAFieldTheVersionHoldsIsRefused(): void {
		foreach ([[], ['totp' => null], ['totp' => '']] as $fields) {
			$request = new ShareSaveRequest(self::restoreBody(fields: $fields));
			try {
				$request->restoring(version: $this->version(totp: 'V-T'), entries: $request->entries);
				$this->fail('A restore without the version\'s totp was accepted');
			} catch (ShareSaveRefusal $refusal) {
				$this->assertSame(['invalid_body', 400, 'totp'], [$refusal->getError(), $refusal->getStatus(), $refusal->getBody()['field']]);
			}
		}
	}

	/**
	 * Whether a partner share must mirror the patch's new fields depends on its partner, which the write checks
	 * (FederatedValueFieldsTest), so the body itself is accepted either way.
	 */
	public function testAFederatedEntryIsNotHeldToThePatchsNewFieldsHere(): void {
		$body = self::body();
		$body['patch'] += ['totp' => 'T', 'passkeys' => null];
		$body['copies'][0] += ['totp' => 'T-BOB', 'passkeys' => null];
		$body['federated'][0]['totp'] = null;
		$request = new ShareSaveRequest($body);

		$this->assertNull($request->entries->federated[0]['totp']);
		$this->assertArrayNotHasKey('passkeys', $request->entries->federated[0]);

		$body['federated'][0]['passkeys'] = 'P-DAVE';
		$this->assertSame('P-DAVE', (new ShareSaveRequest($body))->entries->federated[0]['passkeys']);
	}

	public function testAPatchOfOnlyTheNewFieldsIsAValueChange(): void {
		$body = self::body();
		$body['patch'] = ['totp' => 'T', 'passkeys' => null];
		$body['copies'] = [['secretId' => 'c-bob', 'totp' => 'T-BOB', 'passkeys' => null]];
		$body['federated'][0] += ['totp' => 'T-DAVE', 'passkeys' => null];

		$request = new ShareSaveRequest($body);

		$this->assertSame(['totp' => 'T', 'passkeys' => null], $request->patch);
		$this->assertSame('T-BOB', $request->entries->copies[0]['totp']);
		$this->assertSame('T-DAVE', $request->entries->federated[0]['totp']);
		$this->assertNull($request->entries->federated[0]['passkeys']);
	}

	public function testAFederatedEntryOfAnOlderClientCarriesOnlyTheLegacyFields(): void {
		$request = new ShareSaveRequest(self::body());

		$this->assertSame(
			['shareId', 'certFingerprint', 'key', 'login', 'additionalFields'],
			array_keys($request->entries->federated[0])
		);
	}

	/**
	 * @param string $error The expected code
	 * @param callable(array<string,mixed>):array<string,mixed> $change The body change
	 *
	 * @return void
	 */
	#[DataProvider('refusals')]
	public function testAMalformedBodyIsRefusedWithItsCode(string $error, callable $change): void {
		try {
			new ShareSaveRequest($change(self::body()));
		} catch (ShareSaveRefusal $refusal) {
			$this->assertSame($error, $refusal->getError());
			$this->assertSame(400, $refusal->getStatus());
			$this->assertSame($error, $refusal->getBody()['error']);
			return;
		}

		$this->fail('The body was accepted');
	}

	public function testMoreThanAHundredEntriesPerCallAreRefused(): void {
		$body = self::body();
		$body['copies'] = [];
		$body['skipped'] = [];
		for ($i = 0; $i <= ShareSaveRequest::MAX_ENTRIES; $i++) {
			$body['copies'][] = ['secretId' => 'c-'.$i, 'key' => 'K', 'login' => null];
		}

		try {
			new ShareSaveRequest($body);
		} catch (ShareSaveRefusal $refusal) {
			$this->assertSame('too_many_entries', $refusal->getError());
			return;
		}

		$this->fail('The body was accepted');
	}

	public function testAHundredEntriesAreAllowed(): void {
		$body = self::body();
		$body['copies'] = [];
		$body['skipped'] = [];
		for ($i = 0; $i < ShareSaveRequest::MAX_ENTRIES; $i++) {
			$body['copies'][] = ['secretId' => 'c-'.$i, 'key' => 'K', 'login' => null];
		}

		$this->assertSame(ShareSaveRequest::MAX_ENTRIES, (new ShareSaveRequest($body))->entries->copyCount());
	}
}
