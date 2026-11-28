<?php

/**
 * Unit tests for CloudIdForm.
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

use OCA\Keepiq\Service\CloudIdForm;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * Tests for the canonical form of federated cloud ids.
 *
 * @covers \OCA\Keepiq\Service\CloudIdForm
 */
class CloudIdFormTest extends TestCase {
	/**
	 * @return array<string, array{string, string}>
	 */
	public static function canonicalCases(): array {
		return [
			'plain' => ['alice@cloud.example', 'alice@cloud.example'],
			'scheme stripped' => ['alice@https://cloud.example', 'alice@cloud.example'],
			'http scheme uppercase' => ['alice@HTTP://Cloud.Example/', 'alice@cloud.example'],
			'trailing slashes' => ['alice@cloud.example//', 'alice@cloud.example'],
			'user part keeps case' => ['Alice@Cloud.Example', 'Alice@cloud.example'],
			'last at sign splits' => ['a@b@cloud.example', 'a@b@cloud.example'],
			'path kept lowercased' => ['bob@cloud.example/NC', 'bob@cloud.example/nc'],
			'no at sign' => ['alice', ''],
			'leading at sign' => ['@cloud.example', ''],
			'empty remote' => ['alice@', ''],
			'remote only scheme' => ['alice@https:///', ''],
		];
	}

	#[DataProvider('canonicalCases')]
	public function testCanonical(string $input, string $expected): void {
		$this->assertSame($expected, (new CloudIdForm())->canonical($input));
	}

	public function testSameMatchesEquivalentForms(): void {
		$form = new CloudIdForm();

		$this->assertTrue($form->same('alice@https://Cloud.Example/', 'alice@cloud.example'));
	}

	public function testSameIsCaseSensitiveOnTheUserPart(): void {
		$this->assertFalse((new CloudIdForm())->same('Alice@cloud.example', 'alice@cloud.example'));
	}

	public function testSameNeverMatchesTwoInvalidIds(): void {
		$this->assertFalse((new CloudIdForm())->same('alice', 'alice'));
		$this->assertFalse((new CloudIdForm())->same('', ''));
	}
}
