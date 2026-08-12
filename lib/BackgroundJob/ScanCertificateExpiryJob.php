<?php

/**
 * Doriath Scan Certificate Expiry Job
 *
 * Daily scan of active CA-issued suite/application certificates
 * (certificate-lifecycle §3.1) — the enc_suites domain the stored-
 * secret expiry scan (`ScanExpiringSecretsJob`) structurally cannot
 * reach. Parses notAfter SERVER-side from the cleartext PEM Doriath
 * already stores and reminds the owning user at exact-day thresholds.
 * The daily cadence makes each threshold fire once per certificate —
 * same dedup model as the stored-secret scan. This job only NOTIFIES;
 * it never re-signs or mints keys.
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
use OCA\Doriath\AppInfo\Application;
use OCA\Doriath\Db\EncryptionSuite;
use OCA\Doriath\Db\EncryptionSuiteMapper;
use OCA\Doriath\Service\CertificateLifecycleService;
use OCA\Doriath\Service\NotificationService;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\BackgroundJob\TimedJob;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * Daily suite-certificate expiry scan (notify-only).
 */
class ScanCertificateExpiryJob extends TimedJob {
	/**
	 * Reminder thresholds in days before notAfter.
	 *
	 * @var int[]
	 */
	private const THRESHOLDS = [30, 7, 1];

	/**
	 * Suites fetched per page.
	 *
	 * @var int
	 */
	private const PAGE_SIZE = 200;

	/**
	 * Constructor for ScanCertificateExpiryJob.
	 *
	 * @param ITimeFactory $time The time factory
	 * @param EncryptionSuiteMapper $suiteMapper The suite mapper
	 * @param CertificateLifecycleService $lifecycleService The lifecycle service (server parse)
	 * @param NotificationService $notificationService The notification dispatcher
	 * @param LoggerInterface $logger The logger
	 *
	 * @return void
	 */
	public function __construct(
		ITimeFactory $time,
		private EncryptionSuiteMapper $suiteMapper,
		private CertificateLifecycleService $lifecycleService,
		private NotificationService $notificationService,
		private LoggerInterface $logger,
	) {
		parent::__construct(time: $time);
		$this->setInterval(seconds: 86400);
	}//end __construct()

	/**
	 * Run the scan (fail-soft, per-suite isolation).
	 *
	 * @param mixed $argument Unused job argument
	 *
	 * @return void
	 *
	 * @SuppressWarnings(PHPMD.UnusedFormalParameter) $argument is mandated by
	 *   OCP\BackgroundJob\TimedJob::run(); this job carries no cron payload.
	 *
	 * @spec openspec/changes/certificate-lifecycle/specs/certificate-lifecycle/spec.md#requirement-expiry-monitoring
	 */
	protected function run($argument): void {
		$now = $this->time->getDateTime();
		$offset = 0;
		do {
			$page = $this->suiteMapper->findAllActiveWithLimit(self::PAGE_SIZE, $offset);
			$pageCount = count($page);
			foreach ($page as $suite) {
				try {
					$this->scanOne(suite: $suite, now: $now);
				} catch (Throwable $exception) {
					$this->logger->warning(
						'Doriath: certificate expiry scan failed for suite ' . $suite->getId() . ': ' . $exception->getMessage(),
						['app' => Application::APP_ID]
					);
				}
			}

			$offset += self::PAGE_SIZE;
		} while ($pageCount === self::PAGE_SIZE);
	}//end run()

	/**
	 * Remind the owning user when days-left exactly hits a threshold.
	 * Application-owned suites notify no one directly — their certs are
	 * auto-re-signed on intermediate renewal and surface in CA health.
	 *
	 * @param EncryptionSuite $suite The suite
	 * @param \DateTimeInterface $now The evaluation instant
	 *
	 * @return void
	 */
	private function scanOne(EncryptionSuite $suite, \DateTimeInterface $now): void {
		if ($suite->getOwnerType() !== 'user') {
			return;
		}

		$pem = $suite->getCertificate();
		if ($pem === null || $pem === '') {
			return;
		}

		$parsed = $this->lifecycleService->parseCaCertificate(pem: $pem);
		if ($parsed === null || ($parsed['notAfter'] ?? null) === null) {
			return;
		}

		$notAfter = new DateTime((string)$parsed['notAfter']);
		$daysLeft = (int)$now->diff($notAfter)->format('%r%a');
		if (in_array($daysLeft, self::THRESHOLDS, true) === false) {
			return;
		}

		$this->notificationService->notify(
			subject: 'certificate_expiring',
			recipientId: $suite->getOwnerId(),
			params: [
				'days_left' => $daysLeft,
				'not_after' => $notAfter->format('c'),
			],
			objectType: 'encryption_suite',
			objectId: $suite->getId(),
		);
	}//end scanOne()
}//end class
