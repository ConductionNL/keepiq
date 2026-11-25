<?php

/**
 * Unit tests for SecretRequestArguments.
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
use OCA\Keepiq\Service\RequestedFields;
use OCA\Keepiq\Service\SecretRequestArguments;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * Tests for refusing a new secret request that misses an argument.
 *
 * @covers \OCA\Keepiq\Service\SecretRequestArguments
 */
class SecretRequestArgumentsTest extends TestCase {
	public function testCompleteArgumentsPass(): void {
		(new SecretRequestArguments())->requireComplete(
			secretId: 's1',
			encryptionSuiteId: 'suite-1',
			requestedFields: new RequestedFields(names: ['key']),
			userId: 'alice'
		);

		$this->addToAssertionCount(1);
	}

	/**
	 * Each empty argument, and the refusal it gets. With several empty, the
	 * first in this order is the one refused.
	 *
	 * @return array<string,array{string,string,list<string>,string,string}>
	 */
	public static function missingArguments(): array {
		return [
			'secret' => ['', 'suite-1', ['key'], 'alice', 'secretId is required'],
			'suite' => ['s1', '', ['key'], 'alice', 'encryptionSuiteId is required'],
			'fields' => ['s1', 'suite-1', [], 'alice', 'requestedFields cannot be empty'],
			'user' => ['s1', 'suite-1', ['key'], '', 'userId is required'],
			'all, secret first' => ['', '', [], '', 'secretId is required'],
			'suite before fields and user' => ['s1', '', [], '', 'encryptionSuiteId is required'],
			'fields before user' => ['s1', 'suite-1', [], '', 'requestedFields cannot be empty'],
		];
	}

	/**
	 * @param list<string> $names
	 */
	#[DataProvider('missingArguments')]
	public function testTheFirstMissingArgumentIsRefused(string $secretId, string $suiteId, array $names, string $userId, string $message): void {
		$this->expectException(InvalidArgumentException::class);
		$this->expectExceptionMessage($message);

		(new SecretRequestArguments())->requireComplete(
			secretId: $secretId,
			encryptionSuiteId: $suiteId,
			requestedFields: new RequestedFields(names: $names),
			userId: $userId
		);
	}
}
