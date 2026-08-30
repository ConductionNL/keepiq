<?php

/**
 * Unit tests for the SeedDevelopmentShares repair step.
 *
 * Covers:
 *  - debug=false → no-op (no ShareTarget/GroupShare insert);
 *  - missing dev EncryptionSuite → no-op;
 *  - no dev secrets → no-op;
 *  - version marker already matches the installed app version → no-op;
 *  - pre-existing ShareTarget for a deterministic ID → idempotency no-op;
 *  - happy path (debug=true + suite + 3 secrets) → 2 direct ShareTargets +
 *    1 GroupShare + 1 fan-out ShareTarget (4 inserts total) with the
 *    documented owner/recipient/group shape.
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
use OCA\Keepiq\Db\GroupShare;
use OCA\Keepiq\Db\GroupShareMapper;
use OCA\Keepiq\Db\Secret;
use OCA\Keepiq\Db\SecretMapper;
use OCA\Keepiq\Db\ShareTarget;
use OCA\Keepiq\Db\ShareTargetMapper;
use OCA\Keepiq\Repair\SeedDevelopmentShares;
use OCP\AppFramework\Db\DoesNotExistException;
use OCP\IAppConfig;
use OCP\IConfig;
use OCP\Migration\IOutput;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

/**
 * Tests for SeedDevelopmentShares.
 */
class SeedDevelopmentSharesTest extends TestCase {
	/**
	 * Build a dev Secret with the given ID.
	 *
	 * @param string $id The secret ID
	 *
	 * @return Secret
	 */
	private function devSecret(string $id): Secret {
		$secret = new Secret();
		$secret->setId($id);
		return $secret;
	}//end devSecret()

	/**
	 * Build an IAppConfig mock reporting an installed version that has NOT
	 * yet been seeded (i.e. the version-gate never blocks the run).
	 *
	 * @return IAppConfig&\PHPUnit\Framework\MockObject\MockObject
	 */
	private function unseededAppConfig(): IAppConfig {
		$appConfig = $this->createMock(IAppConfig::class);
		$appConfig->method('getValueString')
			->willReturnCallback(
				static function (string $app, string $key, string $default = '') {
					if ($key === 'installed_version') {
						return '1.2.3';
					}

					return $default;
				}
			);

		return $appConfig;
	}//end unseededAppConfig()

	/**
	 * debug=false → no insert, no lookups.
	 *
	 * @return void
	 */
	public function testNoOpWhenDebugDisabled(): void {
		$config = $this->createMock(IConfig::class);
		$config->method('getSystemValueBool')->with('debug', false)->willReturn(false);

		$shareTargetMapper = $this->createMock(ShareTargetMapper::class);
		$groupShareMapper = $this->createMock(GroupShareMapper::class);
		$secretMapper = $this->createMock(SecretMapper::class);
		$suiteMapper = $this->createMock(EncryptionSuiteMapper::class);

		$shareTargetMapper->expects($this->never())->method('insert');
		$groupShareMapper->expects($this->never())->method('insert');

		$step = new SeedDevelopmentShares(
			secretMapper: $secretMapper,
			shareTargetMapper: $shareTargetMapper,
			groupShareMapper: $groupShareMapper,
			suiteMapper: $suiteMapper,
			config: $config,
			appConfig: $this->unseededAppConfig(),
			logger: $this->createMock(LoggerInterface::class),
		);
		$step->run($this->createMock(IOutput::class));
	}//end testNoOpWhenDebugDisabled()

	/**
	 * Missing dev EncryptionSuite → no-op.
	 *
	 * @return void
	 */
	public function testNoOpWhenSuiteMissing(): void {
		$config = $this->createMock(IConfig::class);
		$config->method('getSystemValueBool')->willReturn(true);

		$suiteMapper = $this->createMock(EncryptionSuiteMapper::class);
		$suiteMapper->method('findActiveByOwner')->willThrowException(new DoesNotExistException('no'));

		$shareTargetMapper = $this->createMock(ShareTargetMapper::class);
		$groupShareMapper = $this->createMock(GroupShareMapper::class);
		$secretMapper = $this->createMock(SecretMapper::class);

		$shareTargetMapper->expects($this->never())->method('insert');
		$groupShareMapper->expects($this->never())->method('insert');
		$secretMapper->expects($this->never())->method('findByOwner');

		$step = new SeedDevelopmentShares(
			secretMapper: $secretMapper,
			shareTargetMapper: $shareTargetMapper,
			groupShareMapper: $groupShareMapper,
			suiteMapper: $suiteMapper,
			config: $config,
			appConfig: $this->unseededAppConfig(),
			logger: $this->createMock(LoggerInterface::class),
		);
		$step->run($this->createMock(IOutput::class));
	}//end testNoOpWhenSuiteMissing()

	/**
	 * Version marker already matches the installed app version → the
	 * repair step short-circuits before touching any mapper.
	 *
	 * @return void
	 */
	public function testNoOpWhenVersionMarkerMatchesInstalledVersion(): void {
		$config = $this->createMock(IConfig::class);
		$config->method('getSystemValueBool')->willReturn(true);

		$appConfig = $this->createMock(IAppConfig::class);
		$appConfig->method('getValueString')
			->willReturnCallback(
				static function (string $app, string $key, string $default = '') {
					if ($key === 'installed_version') {
						return '1.2.3';
					}

					if ($key === 'dev_seed_user_shares_version') {
						return '1.2.3';
					}

					return $default;
				}
			);

		$suiteMapper = $this->createMock(EncryptionSuiteMapper::class);
		$suiteMapper->expects($this->never())->method('findActiveByOwner');

		$shareTargetMapper = $this->createMock(ShareTargetMapper::class);
		$groupShareMapper = $this->createMock(GroupShareMapper::class);
		$shareTargetMapper->expects($this->never())->method('insert');
		$groupShareMapper->expects($this->never())->method('insert');

		$step = new SeedDevelopmentShares(
			secretMapper: $this->createMock(SecretMapper::class),
			shareTargetMapper: $shareTargetMapper,
			groupShareMapper: $groupShareMapper,
			suiteMapper: $suiteMapper,
			config: $config,
			appConfig: $appConfig,
			logger: $this->createMock(LoggerInterface::class),
		);
		$step->run($this->createMock(IOutput::class));
	}//end testNoOpWhenVersionMarkerMatchesInstalledVersion()

	/**
	 * Pre-existing ShareTarget for a deterministic ID → idempotency no-op.
	 *
	 * @return void
	 */
	public function testIdempotencyWhenFirstSecretAlreadyShared(): void {
		$config = $this->createMock(IConfig::class);
		$config->method('getSystemValueBool')->willReturn(true);

		$suiteMapper = $this->createMock(EncryptionSuiteMapper::class);
		$suite = new EncryptionSuite();
		$suite->setId('suite-1');
		$suiteMapper->method('findActiveByOwner')->willReturn($suite);

		$secretMapper = $this->createMock(SecretMapper::class);
		$secretMapper->method('findByOwner')->willReturn([$this->devSecret('s-1'), $this->devSecret('s-2')]);

		$shareTargetMapper = $this->createMock(ShareTargetMapper::class);
		$shareTargetMapper->method('findById')->willReturn(new ShareTarget());

		$shareTargetMapper->expects($this->never())->method('insert');
		$groupShareMapper = $this->createMock(GroupShareMapper::class);
		$groupShareMapper->method('findById')->willReturn(new GroupShare());
		$groupShareMapper->expects($this->never())->method('insert');

		$step = new SeedDevelopmentShares(
			secretMapper: $secretMapper,
			shareTargetMapper: $shareTargetMapper,
			groupShareMapper: $groupShareMapper,
			suiteMapper: $suiteMapper,
			config: $config,
			appConfig: $this->unseededAppConfig(),
			logger: $this->createMock(LoggerInterface::class),
		);
		$step->run($this->createMock(IOutput::class));
	}//end testIdempotencyWhenFirstSecretAlreadyShared()

	/**
	 * Happy path: debug + suite + 3 secrets → 2 direct ShareTargets +
	 * 1 GroupShare + 1 fan-out ShareTarget = 3 ShareTarget inserts and
	 * 1 GroupShare insert.
	 *
	 * @return void
	 */
	public function testHappyPathInsertsAllRows(): void {
		$config = $this->createMock(IConfig::class);
		$config->method('getSystemValueBool')->willReturn(true);

		$suiteMapper = $this->createMock(EncryptionSuiteMapper::class);
		$suite = new EncryptionSuite();
		$suite->setId('suite-1');
		$suiteMapper->method('findActiveByOwner')->willReturn($suite);

		$secretMapper = $this->createMock(SecretMapper::class);
		$secretMapper->method('findByOwner')->willReturn(
			[
				$this->devSecret('s-github'),
				$this->devSecret('s-aws'),
				$this->devSecret('s-db'),
			]
		);

		$shareTargetMapper = $this->createMock(ShareTargetMapper::class);
		$shareTargetMapper->method('findById')->willThrowException(new DoesNotExistException('not seeded'));

		$inserted = [];
		$shareTargetMapper->expects($this->exactly(3))
			->method('insert')
			->willReturnCallback(
				static function (ShareTarget $e) use (&$inserted): ShareTarget {
					$inserted[] = $e;
					return $e;
				}
			);

		$groupShareInserted = [];
		$groupShareMapper = $this->createMock(GroupShareMapper::class);
		$groupShareMapper->method('findById')->willThrowException(new DoesNotExistException('not seeded'));
		$groupShareMapper->expects($this->once())
			->method('insert')
			->willReturnCallback(
				static function (GroupShare $e) use (&$groupShareInserted): GroupShare {
					$groupShareInserted[] = $e;
					return $e;
				}
			);

		$step = new SeedDevelopmentShares(
			secretMapper: $secretMapper,
			shareTargetMapper: $shareTargetMapper,
			groupShareMapper: $groupShareMapper,
			suiteMapper: $suiteMapper,
			config: $config,
			appConfig: $this->unseededAppConfig(),
			logger: $this->createMock(LoggerInterface::class),
		);
		$step->run($this->createMock(IOutput::class));

		// The two direct shares carry no groupShareId, the fan-out row
		// carries the seeded GroupShare id.
		$directShares = array_values(array_filter($inserted, static fn (ShareTarget $r): bool => $r->getGroupShareId() === null));
		$groupShareRow = array_values(array_filter($inserted, static fn (ShareTarget $r): bool => $r->getGroupShareId() !== null));
		$this->assertCount(2, $directShares);
		$this->assertCount(1, $groupShareRow);
		$this->assertSame('admin', $directShares[0]->getCreatedBy());
		$this->assertSame('dev-user-2', $directShares[0]->getTargetUserId());

		// The GroupShare uses the placeholder group + dev owner.
		$this->assertCount(1, $groupShareInserted);
		$this->assertSame('dev-group-1', $groupShareInserted[0]->getGroupId());
		$this->assertSame('admin', $groupShareInserted[0]->getCreatedBy());

		// The fan-out ShareTarget references the GroupShare row.
		$this->assertSame($groupShareInserted[0]->getId(), $groupShareRow[0]->getGroupShareId());
		$this->assertSame('dev-user-3', $groupShareRow[0]->getTargetUserId());
	}//end testHappyPathInsertsAllRows()
}//end class
