<?php

/**
 * Unit tests for the daily trash purge (vault-trash-and-archive D4).
 *
 * @category Test
 * @package  OCA\Keepiq\Tests\Unit\BackgroundJob
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

namespace OCA\Keepiq\Tests\Unit\BackgroundJob;

use DateTime;
use OCA\Keepiq\BackgroundJob\PurgeTrashedSecretsJob;
use OCA\Keepiq\Db\Secret;
use OCA\Keepiq\Db\SecretTrashMapper;
use OCA\Keepiq\Service\SecretService;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\IAppConfig;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use RuntimeException;

/**
 * The job purges what is past the retention and nothing inside it.
 */
class PurgeTrashedSecretsJobTest extends TestCase {
	/** @var SecretTrashMapper&MockObject */
	private SecretTrashMapper $trashMapper;

	/** @var SecretService&MockObject */
	private SecretService $secretService;

	/** @var IAppConfig&MockObject */
	private IAppConfig $appConfig;

	private PurgeTrashedSecretsJob $job;

	/**
	 * Build the real job.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		$this->trashMapper = $this->createMock(SecretTrashMapper::class);
		$this->secretService = $this->createMock(SecretService::class);
		$this->appConfig = $this->createMock(IAppConfig::class);
		$this->job = new PurgeTrashedSecretsJob(
			time: $this->createMock(ITimeFactory::class),
			trashMapper: $this->trashMapper,
			secretService: $this->secretService,
			appConfig: $this->appConfig,
			logger: $this->createMock(LoggerInterface::class),
		);
	}//end setUp()

	/**
	 * A trashed secret of bob's.
	 *
	 * @param string $id The secret id
	 *
	 * @return Secret
	 */
	private function trashed(string $id): Secret {
		$secret = new Secret();
		$secret->setId($id);
		$secret->setOwnerType('user');
		$secret->setOwnerId('bob');
		$secret->setTrashedAt(new DateTime('2026-08-01'));
		return $secret;
	}//end trashed()

	/**
	 * The cutoff is now minus the retention; each row is purged as its
	 * owner's with the retention reason.
	 *
	 * @return void
	 */
	public function testPurgesPastTheRetention(): void {
		$this->appConfig->method('getValueInt')->willReturn(30);
		$this->trashMapper->expects($this->once())->method('findTrashedBefore')
			->with($this->callback(static fn (DateTime $c) => $c->format('Y-m-d') === '2026-08-31'), 500)
			->willReturn([$this->trashed('a'), $this->trashed('b')]);
		$purged = [];
		$this->secretService->method('delete')->willReturnCallback(
			static function (string $id, string $owner, ?string $reason) use (&$purged): void {
				$purged[] = [$id, $owner, $reason];
			}
		);

		$count = $this->job->purgeExpired(new DateTime('2026-09-30 12:00'));

		$this->assertSame(2, $count);
		$this->assertSame([['a', 'bob', 'retention'], ['b', 'bob', 'retention']], $purged);
	}//end testPurgesPastTheRetention()

	/**
	 * A failing row is skipped and the next is still purged.
	 *
	 * @return void
	 */
	public function testAFailureIsSkipped(): void {
		$this->appConfig->method('getValueInt')->willReturn(30);
		$this->trashMapper->method('findTrashedBefore')->willReturn([$this->trashed('a'), $this->trashed('b')]);
		$this->secretService->method('delete')->willReturnCallback(
			static function (string $id): void {
				if ($id === 'a') {
					throw new RuntimeException('db');
				}
			}
		);

		$this->assertSame(1, $this->job->purgeExpired(new DateTime()));
	}//end testAFailureIsSkipped()

	/**
	 * A stored retention outside 1 to 365 is clamped.
	 *
	 * @return void
	 */
	public function testRetentionIsClamped(): void {
		$this->appConfig->method('getValueInt')->willReturnOnConsecutiveCalls(0, 9999);

		$this->assertSame(1, $this->job->retentionDays());
		$this->assertSame(365, $this->job->retentionDays());
	}//end testRetentionIsClamped()
}//end class
