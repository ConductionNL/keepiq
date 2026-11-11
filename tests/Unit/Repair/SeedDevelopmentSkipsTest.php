<?php

/**
 * The four development seeds skip the same way: gate, dev suite, dev secrets.
 *
 * @category Test
 * @package  OCA\Keepiq\Tests\Unit\Repair
 *
 * @author    Conduction Development Team <dev@conductio.nl>
 * @copyright 2024 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git-id>
 *
 * @link https://conduction.nl
 */

declare(strict_types=1);

namespace OCA\Keepiq\Tests\Unit\Repair;

use OCA\Keepiq\Db\EncryptionSuite;
use OCA\Keepiq\Db\EncryptionSuiteMapper;
use OCA\Keepiq\Db\GroupShareMapper;
use OCA\Keepiq\Db\LinkShareMapper;
use OCA\Keepiq\Db\SecretDelegationMapper;
use OCA\Keepiq\Db\SecretMapper;
use OCA\Keepiq\Db\SecretRequestMapper;
use OCA\Keepiq\Db\ShareTargetMapper;
use OCA\Keepiq\Repair\SeedDevelopmentLinkShares;
use OCA\Keepiq\Repair\SeedDevelopmentSecretDelegations;
use OCA\Keepiq\Repair\SeedDevelopmentSecretRequests;
use OCA\Keepiq\Repair\SeedDevelopmentShares;
use OCA\Keepiq\Repair\SeedVersionGate;
use OCP\AppFramework\Db\DoesNotExistException;
use OCP\Migration\IOutput;
use OCP\Migration\IRepairStep;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

/**
 * Each seed's skip paths and messages.
 */
class SeedDevelopmentSkipsTest extends TestCase {

	/**
	 * The seeds and the name their skip messages use.
	 *
	 * @return array<string,array{0:string,1:string}>
	 */
	public static function seeds(): array {
		return [
			'link shares' => [SeedDevelopmentLinkShares::class, 'link-share seed'],
			'secret requests' => [SeedDevelopmentSecretRequests::class, 'secret-request seed'],
			'delegations' => [SeedDevelopmentSecretDelegations::class, 'delegation seed'],
			'shares' => [SeedDevelopmentShares::class, 'user-share seed'],
		];
	}//end seeds()

	/**
	 * Run a seed against the arranged state and answer the calls it made.
	 *
	 * @param string $class The seed class
	 * @param string|null $due The due version, null when not due
	 * @param bool $hasSuite Whether the dev user has an active suite
	 *
	 * @return array<int,array<int,mixed>>
	 */
	private function runSeed(string $class, ?string $due, bool $hasSuite): array {
		$calls = [];
		$gate = $this->createMock(SeedVersionGate::class);
		$gate->method('dueVersion')->willReturnCallback(static function (string $key) use (&$calls, $due): ?string {
			$calls[] = ['dueVersion'];
			return $due;
		});
		$gate->expects($this->never())->method('markSeeded');

		$suites = $this->createMock(EncryptionSuiteMapper::class);
		$suites->method('findActiveByOwner')->willReturnCallback(static function (string $type, string $owner) use (&$calls, $hasSuite): EncryptionSuite {
			$calls[] = ['activeSuite', $type, $owner];
			if ($hasSuite === false) {
				throw new DoesNotExistException('');
			}

			$suite = new EncryptionSuite();
			$suite->setId('suite-1');
			return $suite;
		});

		$secrets = $this->createMock(SecretMapper::class);
		$secrets->method('findByOwner')->willReturnCallback(static function (...$args) use (&$calls): array {
			$calls[] = ['secrets', $args[0], $args[1]];
			return [];
		});

		$output = $this->createMock(IOutput::class);
		$output->method('info')->willReturnCallback(static function (string $message) use (&$calls): void {
			$calls[] = ['info', $message];
		});

		$common = [
			'secretMapper' => $secrets,
			'suiteMapper' => $suites,
			'seedGate' => $gate,
			'logger' => $this->createMock(LoggerInterface::class),
		];
		$extra = match ($class) {
			SeedDevelopmentLinkShares::class => ['linkShareMapper' => $this->createMock(LinkShareMapper::class)],
			SeedDevelopmentSecretRequests::class => ['requestMapper' => $this->createMock(SecretRequestMapper::class)],
			SeedDevelopmentSecretDelegations::class => ['delegationMapper' => $this->createMock(SecretDelegationMapper::class)],
			SeedDevelopmentShares::class => ['shareTargetMapper' => $this->createMock(ShareTargetMapper::class), 'groupShareMapper' => $this->createMock(GroupShareMapper::class)],
		};

		/** @var IRepairStep $seed */
		$seed = new $class(...array_merge($common, $extra));
		$seed->run($output);

		return $calls;
	}//end runSeed()

	/**
	 * Not due: nothing is looked up.
	 *
	 * @param string $class The seed class
	 * @param string $name The seed's skip name
	 *
	 * @return void
	 */
	#[DataProvider('seeds')]
	public function testNotDueDoesNothing(string $class, string $name): void {
		$this->assertSame([['dueVersion']], $this->runSeed(class: $class, due: null, hasSuite: true));
	}//end testNotDueDoesNothing()

	/**
	 * Without a dev suite the seed says so and stops.
	 *
	 * @param string $class The seed class
	 * @param string $name The seed's skip name
	 *
	 * @return void
	 */
	#[DataProvider('seeds')]
	public function testWithoutSuiteItSkips(string $class, string $name): void {
		$this->assertSame(
			[['dueVersion'], ['activeSuite', 'user', 'admin'], ['info', 'Keepiq: no dev EncryptionSuite, skipping ' . $name]],
			$this->runSeed(class: $class, due: '1.0', hasSuite: false)
		);
	}//end testWithoutSuiteItSkips()

	/**
	 * Without dev secrets the seed says so and stops.
	 *
	 * @param string $class The seed class
	 * @param string $name The seed's skip name
	 *
	 * @return void
	 */
	#[DataProvider('seeds')]
	public function testWithoutSecretsItSkips(string $class, string $name): void {
		$this->assertSame(
			[['dueVersion'], ['activeSuite', 'user', 'admin'], ['secrets', 'user', 'admin'], ['info', 'Keepiq: no dev secrets, skipping ' . $name]],
			$this->runSeed(class: $class, due: '1.0', hasSuite: true)
		);
	}//end testWithoutSecretsItSkips()
}//end class
