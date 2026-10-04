<?php

/**
 * Keepiq RecoverySuiteListener
 *
 * @category Listener
 * @package  OCA\Keepiq\Listener
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

namespace OCA\Keepiq\Listener;

use OCA\Keepiq\Event\EncryptionSuiteRevokedEvent;
use OCA\Keepiq\Event\SuiteMigrationCompletedEvent;
use OCA\Keepiq\Service\RecoveryEnrolmentService;
use OCA\Keepiq\Service\RecoveryRequestService;
use OCP\EventDispatcher\Event;
use OCP\EventDispatcher\IEventListener;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * Enrolments follow the suite (crypto-organisation-account-recovery D7):
 * after a rotation the old suite's enrolment goes (the rotating browser
 * already enrolled the new one), and a revoked suite loses its enrolment
 * and its open requests.
 *
 * @implements IEventListener<Event>
 *
 * @spec openspec/specs/organisation-account-recovery/spec.md#requirement-enrolments-and-officer-copies-follow-the-suite
 */
class RecoverySuiteListener implements IEventListener {

	/**
	 * Constructor for RecoverySuiteListener.
	 *
	 * @param RecoveryEnrolmentService $enrolments The enrolments
	 * @param RecoveryRequestService   $requests   The requests
	 * @param LoggerInterface          $logger     The logger
	 *
	 * @return void
	 *
	 * @spec exclude Constructor wiring only.
	 */
	public function __construct(
		private RecoveryEnrolmentService $enrolments,
		private RecoveryRequestService $requests,
		private LoggerInterface $logger,
	) {
	}//end __construct()

	/**
	 * Remove what no longer matches a live suite.
	 *
	 * @param Event $event The dispatched event
	 *
	 * @return void
	 *
	 * @spec openspec/specs/organisation-account-recovery/spec.md#requirement-enrolments-and-officer-copies-follow-the-suite
	 */
	public function handle(Event $event): void {
		try {
			if ($event instanceof SuiteMigrationCompletedEvent) {
				$this->enrolments->deleteForSuite(suiteId: $event->getOldSuiteId());
				return;
			}

			if ($event instanceof EncryptionSuiteRevokedEvent) {
				$this->enrolments->deleteForSuite(suiteId: $event->getSuiteId());
				$this->requests->endForSuite(suiteId: $event->getSuiteId());
			}
		} catch (Throwable $exception) {
			$this->logger->error(
				'Keepiq: account recovery clean-up after a suite change failed: ' . $exception->getMessage(),
				['exception' => $exception, 'app' => 'keepiq']
			);
		}
	}//end handle()
}//end class
