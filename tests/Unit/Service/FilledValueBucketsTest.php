<?php

/**
 * Unit tests for FilledValueBuckets.
 *
 * @category Test
 * @package  OCA\Keepiq\Tests\Unit\Service
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

namespace OCA\Keepiq\Tests\Unit\Service;

use InvalidArgumentException;
use OCA\Keepiq\Service\FilledValueBuckets;
use PHPUnit\Framework\TestCase;

/**
 * Tests for splitting a fulfilled request's values into storable buckets.
 *
 * @covers \OCA\Keepiq\Service\FilledValueBuckets
 */
class FilledValueBucketsTest extends TestCase {
	public function testEncryptedKeepsAllowedFieldsInOrder(): void {
		$fields = ['login' => 'c1', 'key' => 'c2', 'additionalFields' => 'c3'];

		$this->assertSame($fields, (new FilledValueBuckets())->encrypted($fields));
	}

	public function testEncryptedAcceptsAnEmptySet(): void {
		$this->assertSame([], (new FilledValueBuckets())->encrypted([]));
	}

	public function testEncryptedRefusesAPlaintextField(): void {
		try {
			(new FilledValueBuckets())->encrypted(['key' => 'c', 'url' => 'c']);
			$this->fail('Expected refusal');
		} catch (InvalidArgumentException $e) {
			$this->assertSame('Field "url" is plaintext metadata and must be sent in plainFields', $e->getMessage());
			$this->assertSame(400, $e->getCode());
		}
	}

	public function testEncryptedRefusesAnUnknownField(): void {
		try {
			(new FilledValueBuckets())->encrypted(['pin' => 'c']);
			$this->fail('Expected refusal');
		} catch (InvalidArgumentException $e) {
			$this->assertStringStartsWith('Field "pin" cannot be stored on a secret.', $e->getMessage());
			$this->assertStringContainsString('"additionalFields" blob', $e->getMessage());
			$this->assertSame(400, $e->getCode());
		}
	}

	public function testEncryptedRefusesANumericFieldName(): void {
		$this->expectException(InvalidArgumentException::class);
		$this->expectExceptionMessage('Field "0" cannot be stored');
		(new FilledValueBuckets())->encrypted(['c']);
	}

	public function testPlainKeepsPlaintextFields(): void {
		$this->assertSame(['url' => 'https://x'], (new FilledValueBuckets())->plain(['url' => 'https://x']));
	}

	public function testPlainRefusesAnEncryptedField(): void {
		try {
			(new FilledValueBuckets())->plain(['url' => 'u', 'key' => 'k']);
			$this->fail('Expected refusal');
		} catch (InvalidArgumentException $e) {
			$this->assertSame('Field "key" is not plaintext metadata and must be encrypted', $e->getMessage());
			$this->assertSame(400, $e->getCode());
		}
	}
}
