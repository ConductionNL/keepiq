<?php

/**
 * Keepiq - ExpireSecretRequestsJob tests
 *
 * The job had no test at all, while the task list claimed one. That gap matters
 * more than a coverage number: this is the only caller that deletes vault rows
 * with nobody watching. It runs hourly, up to 500 requests a sweep, and its
 * failures are visible only as log lines. A defect here surfaces as a user's
 * missing credential, weeks later, with no way to reconstruct what happened.
 *
 * The four guarantees below are the ones the spec and design actually promise:
 * only lapsed pending requests are touched, a request with no expiry is never
 * touched, one failure never strands the rest of the batch, and a second run
 * over swept requests does nothing.
 *
 * @category Test
 * @package  OCA\Keepiq\Tests\Unit\BackgroundJob
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

namespace OCA\Keepiq\Tests\Unit\BackgroundJob;

use DateTime;
use OCA\Keepiq\BackgroundJob\ExpireSecretRequestsJob;
use OCA\Keepiq\Db\SecretRequest;
use OCA\Keepiq\Db\SecretRequestMapper;
use OCA\Keepiq\Service\SecretRequestService;
use OCP\AppFramework\Utility\ITimeFactory;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use ReflectionMethod;
use RuntimeException;

/**
 * Tests for the hourly lapsed-request sweep.
 */
class ExpireSecretRequestsJobTest extends TestCase {
	/**
	 * The mapper that selects lapsed rows.
	 *
	 * @var SecretRequestMapper&MockObject
	 */
	private SecretRequestMapper&MockObject $mapper;

	/**
	 * The service that performs the transition.
	 *
	 * @var SecretRequestService&MockObject
	 */
	private SecretRequestService&MockObject $service;

	/**
	 * The logger, asserted on for the fail-soft path.
	 *
	 * @var LoggerInterface&MockObject
	 */
	private LoggerInterface&MockObject $logger;

	/**
	 * The job under test.
	 *
	 * @var ExpireSecretRequestsJob
	 */
	private ExpireSecretRequestsJob $job;

	/**
	 * Wire the job with mocked collaborators.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		parent::setUp();

		$time = $this->createMock(originalClassName: ITimeFactory::class);
		$time->method('getDateTime')->willReturn(new DateTime('2026-08-19T12:00:00Z'));
		$time->method('getTime')->willReturn((new DateTime('2026-08-19T12:00:00Z'))->getTimestamp());

		$this->mapper = $this->createMock(originalClassName: SecretRequestMapper::class);
		$this->service = $this->createMock(originalClassName: SecretRequestService::class);
		$this->logger = $this->createMock(originalClassName: LoggerInterface::class);

		$this->job = new ExpireSecretRequestsJob(
			time: $time,
			mapper: $this->mapper,
			service: $this->service,
			logger: $this->logger,
		);
	}//end setUp()

	/**
	 * Invoke the job's protected run().
	 *
	 * @return void
	 */
	private function runJob(): void {
		$method = new ReflectionMethod($this->job, 'run');
		$method->setAccessible(true);
		$method->invoke($this->job, null);
	}//end runJob()

	/**
	 * Build a lapsed pending request.
	 *
	 * @param string $id The request id
	 *
	 * @return SecretRequest
	 */
	private function lapsed(string $id): SecretRequest {
		$entity = new SecretRequest();
		$entity->setId($id);
		$entity->setStatus(SecretRequest::STATUS_PENDING);
		$entity->setExpiresAt(new DateTime('-1 day'));

		return $entity;
	}//end lapsed()

	/**
	 * Every request the query returned is expired through the service.
	 *
	 * @return void
	 *
	 * @spec openspec/specs/secret-requests/spec.md#requirement-optional-expiry
	 */
	public function testTheSweepExpiresEveryLapsedRequestItFound(): void {
		$first = $this->lapsed('req-1');
		$second = $this->lapsed('req-2');
		$this->mapper->method('findLapsedPending')->willReturn([$first, $second]);

		$seen = [];
		$this->service->expects($this->exactly(2))
			->method('expire')
			->willReturnCallback(function (SecretRequest $request) use (&$seen): SecretRequest {
				$seen[] = $request->getId();
				$request->setStatus(SecretRequest::STATUS_EXPIRED);

				return $request;
			});

		$this->runJob();

		$this->assertSame(['req-1', 'req-2'], $seen);
	}//end testTheSweepExpiresEveryLapsedRequestItFound()

	/**
	 * The job never widens the selection itself.
	 *
	 * A request with no `expires_at` is protected by the mapper's predicate, so
	 * the guarantee the job has to keep is that it expires ONLY what the query
	 * handed it and never re-reads the table with a looser filter. Asserted by
	 * giving it an empty result and requiring that nothing is expired — the same
	 * shape the no-expiry case produces.
	 *
	 * @return void
	 *
	 * @spec openspec/specs/secret-requests/spec.md#requirement-optional-expiry
	 */
	public function testARequestTheQueryDidNotReturnIsNeverExpired(): void {
		$this->mapper->expects($this->once())
			->method('findLapsedPending')
			->with($this->isInstanceOf(DateTime::class), 500)
			->willReturn([]);

		$this->service->expects($this->never())->method('expire');

		$this->runJob();
	}//end testARequestTheQueryDidNotReturnIsNeverExpired()

	/**
	 * One failing request does not strand the rest of the batch.
	 *
	 * The batch is up to 500 requests and nobody reads the outcome. If a single
	 * undeletable placeholder aborted the sweep, every later request in that batch
	 * would stay pending indefinitely — and the next run would hit the same row
	 * first and abort again, so the sweep would never make progress.
	 *
	 * @return void
	 *
	 * @spec openspec/specs/secret-requests/spec.md#requirement-optional-expiry
	 */
	public function testAFailingRequestDoesNotStopTheBatch(): void {
		$this->mapper->method('findLapsedPending')->willReturn([
			$this->lapsed('req-bad'),
			$this->lapsed('req-good'),
		]);

		$expired = [];
		$this->service->method('expire')->willReturnCallback(
			function (SecretRequest $request) use (&$expired): SecretRequest {
				if ($request->getId() === 'req-bad') {
					throw new RuntimeException('placeholder locked');
				}

				$expired[] = $request->getId();

				return $request;
			}
		);

		$this->logger->expects($this->atLeastOnce())->method('error');

		$this->runJob();

		$this->assertSame(['req-good'], $expired, 'the request after the failure must still be swept');
	}//end testAFailingRequestDoesNotStopTheBatch()

	/**
	 * A failing selection ends the run quietly instead of throwing.
	 *
	 * A TimedJob that throws is retried by Nextcloud's job runner; a transient
	 * database error would turn into a loop of failing runs rather than one logged
	 * miss and a retry an hour later.
	 *
	 * @return void
	 *
	 * @spec openspec/specs/secret-requests/spec.md#requirement-optional-expiry
	 */
	public function testAFailingSelectionIsLoggedAndNotThrown(): void {
		$this->mapper->method('findLapsedPending')
			->willThrowException(new RuntimeException('db unavailable'));

		$this->service->expects($this->never())->method('expire');
		$this->logger->expects($this->once())->method('error');

		$this->runJob();
	}//end testAFailingSelectionIsLoggedAndNotThrown()

	/**
	 * A second run over already-swept requests does nothing.
	 *
	 * Idempotency is a property of the query rather than of the loop: `expired` is
	 * not `pending`, so a swept request cannot come back. Pinned by running twice
	 * against a mapper that returns the batch first and nothing after, and
	 * requiring that the second run expires nothing.
	 *
	 * @return void
	 *
	 * @spec openspec/specs/secret-requests/spec.md#requirement-optional-expiry
	 */
	public function testASecondRunSweepsNothingFurther(): void {
		$this->mapper->method('findLapsedPending')
			->willReturnOnConsecutiveCalls([$this->lapsed('req-1')], []);

		$this->service->expects($this->once())
			->method('expire')
			->willReturnCallback(static function (SecretRequest $request): SecretRequest {
				$request->setStatus(SecretRequest::STATUS_EXPIRED);

				return $request;
			});

		$this->runJob();
		$this->runJob();
	}//end testASecondRunSweepsNothingFurther()

	/**
	 * A request the service declined to expire is not counted as swept.
	 *
	 * `expire()` returns null when the request is no longer pending — the race
	 * where somebody filled or revoked it between the query and the transition.
	 * The sweep must treat that as "not mine to report", not as a success.
	 *
	 * @return void
	 *
	 * @spec openspec/specs/secret-requests/spec.md#requirement-optional-expiry
	 */
	public function testARequestTheServiceRefusesIsNotReportedAsExpired(): void {
		$this->mapper->method('findLapsedPending')->willReturn([$this->lapsed('req-raced')]);
		$this->service->method('expire')->willReturn(null);

		// Nothing was swept, so the run must not claim a count.
		$this->logger->expects($this->never())->method('info');

		$this->runJob();
	}//end testARequestTheServiceRefusesIsNotReportedAsExpired()
}//end class
