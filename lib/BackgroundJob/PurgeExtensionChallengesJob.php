<?php

/**
 * Keepiq Purge Extension Challenges Job
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

use OCA\Keepiq\Kernel\Contracts\ExtensionChallengeStoreInterface;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\BackgroundJob\TimedJob;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * Removes expired extension passkey challenges, every hour. An expired
 * challenge is already refused when taken; this only keeps the table small.
 */
class PurgeExtensionChallengesJob extends TimedJob {
	/**
	 * Constructor for PurgeExtensionChallengesJob.
	 *
	 * @param ITimeFactory            $time       The time factory
	 * @param ExtensionChallengeStoreInterface $challenges The challenge store
	 * @param LoggerInterface         $logger     The logger
	 *
	 * @return void
	 *
	 * @spec exclude Constructor wiring only.
	 */
	public function __construct(
		ITimeFactory $time,
		private ExtensionChallengeStoreInterface $challenges,
		private LoggerInterface $logger,
	) {
		parent::__construct(time: $time);
		$this->setInterval(seconds: 3600);
	}//end __construct()

	/**
	 * Remove expired challenges.
	 *
	 * @param mixed $argument Unused job argument
	 *
	 * @return void
	 *
	 * @SuppressWarnings(PHPMD.UnusedFormalParameter) Mandated by TimedJob::run().
	 *
	 * @spec openspec/changes/extension-passkey-enrolment/specs/extension-passkey-trust/spec.md#requirement-extension-challenges-are-server-side-bound-and-single-use
	 */
	protected function run($argument): void {
		try {
			$this->challenges->purgeExpired();
		} catch (Throwable $exception) {
			$this->logger->error(
				'Keepiq: extension passkey challenge purge failed: ' . $exception->getMessage(),
				['exception' => $exception, 'app' => 'keepiq']
			);
		}
	}//end run()
}//end class
