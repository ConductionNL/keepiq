<?php

/**
 * Keepiq Honey Tripwire Service
 *
 * The detection half of honey credentials (honey-credentials §2.2): an
 * access to a flagged decoy raises (or collapses into) an alert, pages the
 * owner and every admin ungated (D3 — a muted tripwire is worthless), and
 * records the distinguished honey.accessed audit marker on EVERY access,
 * snoozed and collapsed ones included (D5/D6). Deception is detection, not
 * prevention: the whole path is fail-soft by contract, because the observed
 * access has already been served and must never be broken by the tripwire.
 *
 * @category Service
 * @package  OCA\Keepiq\Service
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

namespace OCA\Keepiq\Service;

use OCA\Keepiq\AppInfo\Application;
use OCA\Keepiq\Db\HoneyFlagMapper;
use OCA\Keepiq\Event\Audit\AuditEventFactory;
use OCA\Keepiq\Event\Audit\AuditEventTypes;
use OCP\AppFramework\Db\DoesNotExistException;
use OCP\EventDispatcher\IEventDispatcher;
use OCP\IGroupManager;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * Decoy tripwire detection: alert, page and audit a honey access.
 */
class HoneyTripwireService {
	/**
	 * Constructor for HoneyTripwireService.
	 *
	 * @param HoneyFlagMapper $flagMapper The flag mapper
	 * @param HoneyAlertRecorder $alertRecorder The deduplicating alert recorder
	 * @param IGroupManager $groupManager The group manager (admin paging)
	 * @param NotificationService|null $notificationService The notification dispatcher
	 * @param LoggerInterface $logger The logger
	 * @param IEventDispatcher|null $eventDispatcher The audit dispatcher
	 * @param AuditEventFactory $auditEvents The audit-event factory
	 *
	 * @return void
	 *
	 * @spec exclude Constructor wiring only — no domain logic.
	 */
	public function __construct(
		private HoneyFlagMapper $flagMapper,
		private HoneyAlertRecorder $alertRecorder,
		private IGroupManager $groupManager,
		private ?NotificationService $notificationService,
		private LoggerInterface $logger,
		private ?IEventDispatcher $eventDispatcher = null,
		private AuditEventFactory $auditEvents = new AuditEventFactory(),
	) {
	}//end __construct()

	/**
	 * Raise (or collapse into) an alert for an access to a possibly-
	 * flagged secret (§2.2). Fail-soft by contract: any failure is
	 * logged and swallowed — the observed access is already served.
	 *
	 * Dedup (D5): repeats by the same (accessor, channel) within the
	 * configurable window update the existing alert instead of paging
	 * again. A snoozed accessor never pages but IS still audited — the
	 * forensic trail stays complete.
	 *
	 * @param string $secretId The accessed secret UUID
	 * @param string $accessorType user|application|link_visitor|system
	 * @param string|null $accessorId The accessor id (null = anonymous)
	 * @param string $channel ui|machine_api|link|share
	 * @param string|null $remoteIp Remote address when available
	 * @param string|null $userAgent User agent when available
	 *
	 * @return bool Whether the secret was honey-flagged (a tripwire hit)
	 *
	 * @spec openspec/changes/honey-credentials/specs/honey-credentials/spec.md
	 */
	public function raiseAlert(
		string $secretId,
		string $accessorType,
		?string $accessorId,
		string $channel,
		?string $remoteIp = null,
		?string $userAgent = null,
	): bool {
		try {
			$flag = $this->flagMapper->findBySecretId($secretId);
		} catch (DoesNotExistException) {
			return false;
		}

		try {
			$raised = $this->alertRecorder->record(
				flag: $flag,
				secretId: $secretId,
				accessorType: $accessorType,
				accessorId: $accessorId,
				channel: $channel,
				remoteIp: $remoteIp,
				userAgent: $userAgent
			);

			if ($raised === true) {
				$this->pageOwnerAndAdmins(
					ownerId: (string)$flag->getOwnerId(),
					secretId: (string)$flag->getSecretId(),
					channel: $channel,
					accessorLabel: ($accessorId ?? 'anonymous')
				);
			}

			// The distinguished audit marker fires on EVERY honey access —
			// snoozed and collapsed accesses stay in the forensic trail (D5/D6).
			$this->eventDispatcher?->dispatchTyped(
				$this->auditEvents->forSystem(
					eventType: AuditEventTypes::HONEY_ACCESSED,
					objectType: 'secret',
					objectId: $secretId,
					objectName: '',
					metadata: ['channel' => $channel],
				)
			);
		} catch (Throwable $exception) {
			// Fail-soft: the tripwire must never break the audited access.
			$this->logger->error(
				'Keepiq: honey alert failed for secret ' . $secretId . ': ' . $exception->getMessage(),
				['app' => Application::APP_ID]
			);
		}//end try

		return true;
	}//end raiseAlert()

	/**
	 * Page the decoy owner and every admin — ungated (D3): a muted
	 * tripwire is worthless, so honey_access bypasses notify_* prefs.
	 *
	 * @param string $ownerId The decoy owner
	 * @param string $secretId The tripped secret
	 * @param string $channel The access channel
	 * @param string $accessorLabel Human accessor label
	 *
	 * @return void
	 */
	private function pageOwnerAndAdmins(string $ownerId, string $secretId, string $channel, string $accessorLabel): void {
		if ($this->notificationService === null) {
			return;
		}

		$recipients = [$ownerId];
		$adminGroup = $this->groupManager->get('admin');
		if ($adminGroup !== null) {
			foreach ($adminGroup->getUsers() as $admin) {
				$recipients[] = $admin->getUID();
			}
		}

		foreach (array_unique($recipients) as $recipientId) {
			try {
				$this->notificationService->notify(
					subject: 'honey_access',
					recipientId: $recipientId,
					params: [
						'channel' => $channel,
						'accessor' => $accessorLabel,
					],
					objectType: 'secret',
					objectId: $secretId,
				);
			} catch (Throwable $exception) {
				$this->logger->warning(
					'Keepiq: honey page failed for ' . $recipientId . ': ' . $exception->getMessage(),
					['app' => Application::APP_ID]
				);
			}
		}
	}//end pageOwnerAndAdmins()
}//end class
