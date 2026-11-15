<?php

/**
 * Unit tests for ShareSaveEntryParser.
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

use OCA\Keepiq\Service\ShareSave\ShareSaveEntries;
use OCA\Keepiq\Service\ShareSave\ShareSaveEntryParser;
use OCA\Keepiq\Service\ShareSave\ShareSaveRefusal;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\UsesClass;
use PHPUnit\Framework\TestCase;

/**
 * The entry parser accepts exactly the documented shape of each list and
 * refuses everything else with a named error, before any database access.
 */
#[CoversClass(ShareSaveEntryParser::class)]
#[UsesClass(ShareSaveEntries::class)]
#[UsesClass(ShareSaveRefusal::class)]
class ShareSaveEntryParserTest extends TestCase {
	private ShareSaveEntryParser $parser;

	protected function setUp(): void {
		$this->parser = new ShareSaveEntryParser();
	}

	/**
	 * A valid federated entry.
	 *
	 * @return array<string,mixed>
	 */
	private static function federated(): array {
		return [
			'shareId' => 'fs-1',
			'certFingerprint' => str_repeat('AB', 32),
			'key' => 'K',
			'login' => null,
			'additionalFields' => null,
		];
	}

	/**
	 * Run a parse that must be refused and return the refusal.
	 *
	 * @param array<string,mixed> $params The body.
	 *
	 * @return ShareSaveRefusal
	 */
	private function refusal(array $params): ShareSaveRefusal {
		try {
			$this->parser->entries($params);
		} catch (ShareSaveRefusal $refusal) {
			return $refusal;
		}

		$this->fail('The body was accepted');
	}

	public function testAnEmptyBodyGivesEmptyLists(): void {
		$entries = $this->parser->entries([]);

		$this->assertSame([], $entries->copies);
		$this->assertSame([], $entries->skipped);
		$this->assertSame([], $entries->federated);
		$this->assertSame([], $entries->suspend);
	}

	public function testValidListsAreParsedAndTheFingerprintLowercased(): void {
		$entries = $this->parser->entries([
			'copies' => [['secretId' => 'c-1', 'key' => 'K', 'totp' => null, 'passkeys' => 'P']],
			'skipped' => [['secretId' => 'c-2', 'reason' => 'no_vault_key']],
			'federated' => [self::federated() + ['totp' => 'T']],
			'suspend' => [['shareId' => 'fs-2', 'reason' => 'cert_rotated']],
		]);

		$this->assertSame([['secretId' => 'c-1', 'key' => 'K', 'totp' => null, 'passkeys' => 'P']], $entries->copies);
		$this->assertSame([['secretId' => 'c-2', 'reason' => 'no_vault_key']], $entries->skipped);
		$this->assertSame(str_repeat('ab', 32), $entries->federated[0]['certFingerprint']);
		$this->assertSame('T', $entries->federated[0]['totp']);
		$this->assertSame([['shareId' => 'fs-2', 'reason' => 'cert_rotated']], $entries->suspend);
	}

	/**
	 * Bodies refused as invalid_body, with the message naming what was wrong.
	 *
	 * @return array<string,array{0:array<string,mixed>,1:string}>
	 */
	public static function invalidBodies(): array {
		return [
			'list is not an array' => [['copies' => 'x'], 'Invalid a list'],
			'list is a map' => [['copies' => ['a' => ['secretId' => 'c']]], 'Invalid a list'],
			'entry is a scalar' => [['copies' => ['c-1']], 'Invalid an entry'],
			'entry is a list' => [['copies' => [['c-1']]], 'Invalid an entry'],
			'copy misses secretId' => [['copies' => [['key' => 'K']]], 'Invalid the keys of an entry'],
			'copy has an unknown key' => [['copies' => [['secretId' => 'c', 'name' => 'n']]], 'Invalid the keys of an entry'],
			'copy secretId empty' => [['copies' => [['secretId' => '']]], 'Invalid copies.secretId'],
			'copy secretId not a string' => [['copies' => [['secretId' => 5]]], 'Invalid copies.secretId'],
			'copy key null' => [['copies' => [['secretId' => 'c', 'key' => null]]], 'Invalid copies.key'],
			'copy login not a string' => [['copies' => [['secretId' => 'c', 'login' => 3]]], 'Invalid copies.login'],
			'skip with another reason' => [['skipped' => [['secretId' => 'c', 'reason' => 'other']]], 'Invalid skipped.reason'],
			'skip misses reason' => [['skipped' => [['secretId' => 'c']]], 'Invalid the keys of an entry'],
			'federated misses a legacy field' => [['federated' => [array_diff_key(self::federated(), ['login' => 1])]], 'Invalid the keys of an entry'],
			'federated fingerprint not a string' => [['federated' => [['certFingerprint' => 1] + self::federated()]], 'Invalid federated.certFingerprint'],
			'federated shareId empty' => [['federated' => [['shareId' => ''] + self::federated()]], 'Invalid federated.shareId'],
			'federated totp not a string' => [['federated' => [self::federated() + ['totp' => 1]]], 'Invalid federated.totp'],
			'suspend reason with capitals' => [['suspend' => [['shareId' => 's', 'reason' => 'Bad']]], 'Invalid suspend.reason'],
			'suspend reason too long' => [['suspend' => [['shareId' => 's', 'reason' => str_repeat('a', 33)]]], 'Invalid suspend.reason'],
			'suspend reason not a string' => [['suspend' => [['shareId' => 's', 'reason' => 1]]], 'Invalid suspend.reason'],
		];
	}

	/**
	 * @param array<string,mixed> $params  The body.
	 * @param string              $message The expected message.
	 */
	#[DataProvider('invalidBodies')]
	public function testAnInvalidBodyIsRefused(array $params, string $message): void {
		$refusal = $this->refusal($params);

		$this->assertSame('invalid_body', $refusal->getError());
		$this->assertSame($message, $refusal->getMessage());
		$this->assertSame(400, $refusal->getStatus());
	}

	public function testAShortFingerprintIsRefusedAsInvalidFingerprint(): void {
		$refusal = $this->refusal(['federated' => [['certFingerprint' => str_repeat('a', 63)] + self::federated()]]);

		$this->assertSame('invalid_fingerprint', $refusal->getError());
		$this->assertSame('certFingerprint must be 64 hex characters', $refusal->getMessage());
	}

	public function testANonHexFingerprintIsRefused(): void {
		$refusal = $this->refusal(['federated' => [['certFingerprint' => str_repeat('g', 64)] + self::federated()]]);

		$this->assertSame('invalid_fingerprint', $refusal->getError());
	}

	public function testAnEmptyKeyIsRefusedAsInvalidKey(): void {
		$copy = $this->refusal(['copies' => [['secretId' => 'c', 'key' => '']]]);
		$federated = $this->refusal(['federated' => [['key' => ''] + self::federated()]]);

		$this->assertSame('invalid_key', $copy->getError());
		$this->assertSame('A key may not be empty', $copy->getMessage());
		$this->assertSame('invalid_key', $federated->getError());
	}

	public function testEntryAcceptsAnEmptyMapWhenNothingIsRequired(): void {
		$this->assertSame([], $this->parser->entry([], [], ['a']));
		$this->assertSame(['a' => 1], $this->parser->entry(['a' => 1], [], ['a']));
	}

	public function testAssertKeyNotEmptyIgnoresAnEntryWithoutAKey(): void {
		$this->parser->assertKeyNotEmpty(['login' => '']);
		$this->parser->assertKeyNotEmpty(['key' => 'K']);

		$this->expectException(ShareSaveRefusal::class);
		$this->parser->assertKeyNotEmpty(['key' => '']);
	}

	public function testInvalidBodyBuildsTheRefusal(): void {
		$refusal = $this->parser->invalidBody('the thing');

		$this->assertSame(['error' => 'invalid_body', 'message' => 'Invalid the thing'], $refusal->getBody());
	}
}
