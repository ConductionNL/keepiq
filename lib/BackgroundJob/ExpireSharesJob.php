<?php

/**
 * Keepiq ExpireSharesJob
 *
 * Every fifteen minutes: warn holders whose access ends within a day, and
 * remove access whose end date passed (sharing-use-only-and-expiring-shares
 * D5). Reads already stop serving an expired copy at its end date; this job
 * only cleans up and notifies.
 *
 * @category BackgroundJob
 * @package  OCA\Keepiq\BackgroundJob
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

namespace OCA\Keepiq\BackgroundJob;

use DateInterval;
use DateTime;
use OCA\Keepiq\AppInfo\Application;
use OCA\Keepiq\Service\ShareExpiryService;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\BackgroundJob\TimedJob;
use OCP\IAppConfig;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * The share expiry sweep.
 */
class ExpireSharesJob extends TimedJob {

	/**
	 * App config key holding the end of the last warning window, so each
	 * holder is warned once however often the job runs.
	 *
	 * @var string
	 */
	public const WARNED_UNTIL_KEY = 'share_access_warned_until';

	/**
	 * Constructor for ExpireSharesJob.
	 *
	 * @param ITimeFactory       $time      The time factory
	 * @param ShareExpiryService $expiry    The expiry service
	 * @param IAppConfig         $appConfig The app config (warning window)
	 * @param LoggerInterface    $logger    The logger
	 *
	 * @return void
	 *
	 * @spec exclude Constructor wiring only.
	 */
	public function __construct(
		ITimeFactory $time,
		private ShareExpiryService $expiry,
		private IAppConfig $appConfig,
		private LoggerInterface $logger,
	) {
		parent::__construct(time: $time);
		$this->setInterval(seconds: 900);
	}//end __construct()

	/**
	 * Warn, then expire. Fail-soft: a failed warning never blocks expiry.
	 *
	 * @param mixed $argument Unused job argument
	 *
	 * @return void
	 *
	 * @SuppressWarnings(PHPMD.UnusedFormalParameter) Mandated by TimedJob::run().
	 *
	 * @spec openspec/changes/sharing-use-only-and-expiring-shares/specs/expiring-shares/spec.md#requirement-a-background-job-removes-expired-access
	 */
	protected function run($argument): void {
		$now = new DateTime();
		$this->warn(now: $now);

		try {
			$ended = $this->expiry->expire(now: $now);
			if ($ended > 0) {
				$this->logger->info('Keepiq: ended access to ' . $ended . ' shared secret(s)', ['app' => 'keepiq']);
			}
		} catch (Throwable $exception) {
			$this->logger->error(
				'Keepiq: share expiry failed: ' . $exception->getMessage(),
				['exception' => $exception, 'app' => 'keepiq']
			);
		}
	}//end run()

	/**
	 * Warn the holders whose access ends between the end of the previous
	 * window and a day from now.
	 *
	 * @param DateTime $now The current time
	 *
	 * @return void
	 *
	 * @spec openspec/changes/sharing-use-only-and-expiring-shares/specs/expiring-shares/spec.md#requirement-people-are-told-before-and-when-access-ends
	 */
	private function warn(DateTime $now): void {
		$until = (clone $now)->add(new DateInterval('P1D'));
		$from  = $now;
		$saved = $this->appConfig->getValueInt(Application::APP_ID, self::WARNED_UNTIL_KEY, 0);
		if ($saved > $now->getTimestamp()) {
			$from = (new DateTime())->setTimestamp($saved);
		}

		try {
			$this->expiry->warnEnding(from: $from, to: $until);
			$this->appConfig->setValueInt(Application::APP_ID, self::WARNED_UNTIL_KEY, $until->getTimestamp());
		} catch (Throwable $exception) {
			$this->logger->error(
				'Keepiq: share expiry warning failed: ' . $exception->getMessage(),
				['exception' => $exception, 'app' => 'keepiq']
			);
		}
	}//end warn()
}//end class
