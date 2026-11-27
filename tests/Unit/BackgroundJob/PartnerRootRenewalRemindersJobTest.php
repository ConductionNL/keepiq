<?php

/**
 * Unit tests for PartnerRootRenewalRemindersJob.
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
 *
 * @spec openspec/changes/partner-root-renewal-announcement/specs/federated-sharing/spec.md#requirement-an-offered-renewal-expires-after-30-days-with-reminders
 */

declare(strict_types=1);

namespace OCA\Keepiq\Tests\Unit\BackgroundJob;

use DateTime;
use OCA\Keepiq\BackgroundJob\PartnerRootRenewalRemindersJob;
use OCA\Keepiq\Service\PartnerRootRenewalOffers;
use OCP\AppFramework\Utility\ITimeFactory;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use ReflectionMethod;
use RuntimeException;

/**
 * The hourly job hands its clock's time to the renewal offers and keeps a failure inside the job.
 *
 * @covers \OCA\Keepiq\BackgroundJob\PartnerRootRenewalRemindersJob
 */
class PartnerRootRenewalRemindersJobTest extends TestCase {

	/**
	 * The job runs hourly, so cron drift cannot skip the 1-day reminder, and passes the time of its clock.
	 *
	 * @return void
	 */
	public function testRunsHourlyWithTheClocksTime(): void {
		$now = new DateTime('2026-12-18T09:00:00Z');
		$time = $this->createMock(ITimeFactory::class);
		$time->method('getDateTime')->willReturn($now);
		$renewals = $this->createMock(PartnerRootRenewalOffers::class);
		$renewals->expects($this->once())->method('remindAndExpire')->with($now);
		$job = new PartnerRootRenewalRemindersJob($time, $renewals, $this->createMock(LoggerInterface::class));

		(new ReflectionMethod($job, 'run'))->invoke($job, null);

		$this->assertSame(3600, $job->getInterval());
	}//end testRunsHourlyWithTheClocksTime()

	/**
	 * A failing pass is logged and does not escape the job.
	 *
	 * @return void
	 */
	public function testAFailingPassIsLoggedNotThrown(): void {
		$time = $this->createMock(ITimeFactory::class);
		$time->method('getDateTime')->willReturn(new DateTime());
		$renewals = $this->createMock(PartnerRootRenewalOffers::class);
		$renewals->method('remindAndExpire')->willThrowException(new RuntimeException('database gone'));
		$logger = $this->createMock(LoggerInterface::class);
		$logger->expects($this->once())->method('error')->with('Keepiq: partner root renewal reminders failed: database gone');
		$job = new PartnerRootRenewalRemindersJob($time, $renewals, $logger);

		(new ReflectionMethod($job, 'run'))->invoke($job, null);
	}//end testAFailingPassIsLoggedNotThrown()
}//end class
