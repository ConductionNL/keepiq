<?php

/**
 * Doriath Background Job - Expire lapsed secret requests
 *
 * Expiry used to be checked only when someone opened the fill link. Nothing
 * swept, so a request whose expiry passed months ago still sat `pending`, still
 * held its token row, and — when it had created one — still held an unfilled
 * placeholder Secret that could never be filled. Since the machine surface began
 * auto-creating those placeholders, abandoned requests accumulated empty Secrets
 * in application vaults with nothing to clean them up.
 *
 * This job is CLEANUP, never enforcement. The access gate
 * (`SecretRequestPolicy::requireOpenByToken`) evaluates `expires_at` itself on
 * every open, so a request that lapsed since the last run is already refused —
 * which is why an hourly interval is adequate for something as time-sensitive as
 * an expiry. Read that as a statement about this job's SCOPE, not as a claim that
 * the gate ever depended on it: the gate checked expiry before this job existed.
 *
 * Requests with NO `expires_at` are never touched. Optional Expiry promises they
 * "remain open until fulfilled or manually revoked", and sweeping them would
 * delete vault rows nobody agreed to give up.
 *
 * @category BackgroundJob
 * @package  OCA\Doriath\BackgroundJob
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

namespace OCA\Doriath\BackgroundJob;

use DateTime;
use OCA\Doriath\Db\SecretRequestMapper;
use OCA\Doriath\Service\SecretRequestService;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\BackgroundJob\TimedJob;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * Hourly sweep of lapsed secret requests.
 */
class ExpireSecretRequestsJob extends TimedJob {
	/**
	 * Rows swept per run.
	 *
	 * The first run on an established instance may find a large backlog, since
	 * every request that lapsed in the past becomes eligible at once. A bound
	 * keeps that first sweep from turning into one enormous transaction; the
	 * remainder is picked up an hour later.
	 *
	 * @var int
	 */
	private const BATCH = 500;

	/**
	 * Constructor for ExpireSecretRequestsJob.
	 *
	 * @param ITimeFactory $time The time factory
	 * @param SecretRequestMapper $mapper Selects the lapsed rows
	 * @param SecretRequestService $service Performs the transition
	 * @param LoggerInterface $logger The logger
	 *
	 * @return void
	 */
	public function __construct(
		ITimeFactory $time,
		private SecretRequestMapper $mapper,
		private SecretRequestService $service,
		private LoggerInterface $logger,
	) {
		parent::__construct(time: $time);
		// Matches ExpireMachineLeasesJob. An hour is coarse for an expiry, which
		// is exactly why the gate refuses a lapsed request on its own.
		$this->setInterval(seconds: 3600);
	}//end __construct()

	/**
	 * Run the expiry sweep, fail-soft per request.
	 *
	 * One failing request must not strand the rest of the batch: a placeholder
	 * that cannot be deleted is untidy, while an aborted sweep leaves every later
	 * request in the batch pending indefinitely.
	 *
	 * @param mixed $argument Unused job argument
	 *
	 * @return void
	 *
	 * @SuppressWarnings(PHPMD.UnusedFormalParameter) $argument is mandated by
	 *   OCP\BackgroundJob\TimedJob::run(); this job carries no cron payload.
	 *
	 * @spec openspec/specs/secret-requests/spec.md#requirement-optional-expiry
	 */
	protected function run($argument): void {
		try {
			$lapsed = $this->mapper->findLapsedPending(now: new DateTime(), limit: self::BATCH);
		} catch (Throwable $exception) {
			$this->logger->error(
				'Doriath: could not select lapsed secret requests: ' . $exception->getMessage(),
				['exception' => $exception, 'app' => 'doriath']
			);

			return;
		}

		$expired = 0;
		foreach ($lapsed as $request) {
			try {
				if ($this->service->expire(request: $request) !== null) {
					$expired++;
				}
			} catch (Throwable $exception) {
				$this->logger->error(
					'Doriath: could not expire secret request ' . $request->getId()
					. ': ' . $exception->getMessage(),
					['exception' => $exception, 'app' => 'doriath']
				);
			}
		}

		if ($expired > 0) {
			$this->logger->info(
				'Doriath: expired ' . $expired . ' lapsed secret request(s)',
				['app' => 'doriath']
			);
		}
	}//end run()
}//end class
