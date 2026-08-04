<?php

/**
 * Doriath Honey Credential Service
 *
 * Decoy tripwire logic (honey-credentials §2): flag/unflag decoy
 * secrets, raise deduplicated alerts on access, page owner + admins
 * (ungated), and record the distinguished honey.accessed audit event.
 * Deception is detection, not prevention — an alert never blocks or
 * delays the observed access, and the flag is never serialized into
 * any secret response shape.
 *
 * @category Service
 * @package  OCA\Doriath\Service
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

namespace OCA\Doriath\Service;

use DateInterval;
use DateTime;
use InvalidArgumentException;
use OCA\Doriath\AppInfo\Application;
use OCA\Doriath\Db\HoneyAlert;
use OCA\Doriath\Db\HoneyAlertMapper;
use OCA\Doriath\Db\HoneyFlag;
use OCA\Doriath\Db\HoneyFlagMapper;
use OCA\Doriath\Db\SecretMapper;
use OCA\Doriath\Event\Audit\AuditEvent;
use OCA\Doriath\Event\Audit\AuditEventFactory;
use OCA\Doriath\Event\Audit\AuditEventTypes;
use OCP\AppFramework\Db\DoesNotExistException;
use OCP\EventDispatcher\IEventDispatcher;
use OCP\IAppConfig;
use OCP\IGroupManager;
use Psr\Log\LoggerInterface;
use Ramsey\Uuid\Uuid;
use Throwable;

/**
 * Business logic for honey (decoy) credentials.
 */
class HoneyCredentialService
{
    /**
     * Default dedup window in seconds (1h).
     *
     * @var int
     */
    private const DEFAULT_DEDUP_WINDOW = 3600;

    /**
     * Constructor for HoneyCredentialService.
     *
     * @param HoneyFlagMapper          $flagMapper          The flag mapper
     * @param HoneyAlertMapper         $alertMapper         The alert mapper
     * @param SecretMapper             $secretMapper        The secret mapper (owner guard)
     * @param IGroupManager            $groupManager        The group manager (admin paging)
     * @param IAppConfig               $appConfig           The app config (dedup window)
     * @param NotificationService|null $notificationService The notification dispatcher
     * @param LoggerInterface          $logger              The logger
     * @param IEventDispatcher|null    $eventDispatcher     The audit dispatcher
     * @param AuditEventFactory        $auditEvents         The audit-event factory
     *
     * @return void
     */
    public function __construct(
        private HoneyFlagMapper $flagMapper,
        private HoneyAlertMapper $alertMapper,
        private SecretMapper $secretMapper,
        private IGroupManager $groupManager,
        private IAppConfig $appConfig,
        private ?NotificationService $notificationService,
        private LoggerInterface $logger,
        private ?IEventDispatcher $eventDispatcher=null,
        private AuditEventFactory $auditEvents=new AuditEventFactory(),
    ) {
    }//end __construct()

    /**
     * Flag a secret as a decoy (owner or admin, §2.1). Upsert — a
     * repeated flag call updates the note.
     *
     * @param string      $secretId The secret UUID
     * @param string      $actorId  The calling user
     * @param bool        $isAdmin  Whether the caller is an admin
     * @param string|null $note     Optional placement note
     *
     * @return HoneyFlag
     *
     * @throws DoesNotExistException    When the secret does not exist
     * @throws InvalidArgumentException When the caller may not flag it
     */
    public function flag(string $secretId, string $actorId, bool $isAdmin, ?string $note=null): HoneyFlag
    {
        $secret = $this->secretMapper->findById($secretId);
        $ownsIt = ($secret->getOwnerType() === 'user' && $secret->getOwnerId() === $actorId);
        if ($ownsIt === false && $isAdmin === false) {
            throw new InvalidArgumentException('Only the owner or an admin may flag a honey secret');
        }

        try {
            $flag = $this->flagMapper->findBySecretId($secretId);
            $flag->setNote($note);

            return $this->flagMapper->update($flag);
        } catch (DoesNotExistException) {
            $flag = new HoneyFlag();
            $flag->setId(Uuid::uuid4()->toString());
            $flag->setSecretId($secretId);
            $flag->setOwnerId($secret->getOwnerId());
            $flag->setNote($note);
            $flag->setCreatedBy($actorId);
            $flag->setCreatedAt(new DateTime());

            return $this->flagMapper->insert($flag);
        }
    }//end flag()

    /**
     * Remove the decoy flag (owner or admin). Alert rows are KEPT — the
     * forensic trail survives unflagging.
     *
     * @param string $secretId The secret UUID
     * @param string $actorId  The calling user
     * @param bool   $isAdmin  Whether the caller is an admin
     *
     * @return void
     *
     * @throws DoesNotExistException    When the secret is not flagged
     * @throws InvalidArgumentException When the caller may not unflag it
     */
    public function unflag(string $secretId, string $actorId, bool $isAdmin): void
    {
        $flag = $this->flagMapper->findBySecretId($secretId);
        if ($flag->getOwnerId() !== $actorId && $isAdmin === false) {
            throw new InvalidArgumentException('Only the owner or an admin may unflag a honey secret');
        }

        $this->flagMapper->delete($flag);
    }//end unflag()

    /**
     * The flag of a secret for the owner/admin detail view, or null.
     *
     * @param string $secretId The secret UUID
     * @param string $actorId  The calling user
     * @param bool   $isAdmin  Whether the caller is an admin
     *
     * @return HoneyFlag|null
     *
     * @throws InvalidArgumentException When the caller may not see it
     */
    public function getFlag(string $secretId, string $actorId, bool $isAdmin): ?HoneyFlag
    {
        try {
            $flag = $this->flagMapper->findBySecretId($secretId);
        } catch (DoesNotExistException) {
            return null;
        }

        if ($flag->getOwnerId() !== $actorId && $isAdmin === false) {
            throw new InvalidArgumentException('Only the owner or an admin may inspect a honey flag');
        }

        return $flag;
    }//end getFlag()

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
     * @param string      $secretId     The accessed secret UUID
     * @param string      $accessorType user|application|link_visitor|system
     * @param string|null $accessorId   The accessor id (null = anonymous)
     * @param string      $channel      ui|machine_api|link|share
     * @param string|null $remoteIp     Remote address when available
     * @param string|null $userAgent    User agent when available
     *
     * @return bool Whether the secret was honey-flagged (a tripwire hit)
     *
     * @spec openspec/changes/honey-credentials/specs/honey-credentials/spec.md#requirement-tripwire-alerting
     */
    public function raiseAlert(
        string $secretId,
        string $accessorType,
        ?string $accessorId,
        string $channel,
        ?string $remoteIp=null,
        ?string $userAgent=null,
    ): bool {
        try {
            $flag = $this->flagMapper->findBySecretId($secretId);
        } catch (DoesNotExistException) {
            return false;
        }

        try {
            $now      = new DateTime();
            $window   = $this->appConfig->getValueInt(Application::APP_ID, 'honey_dedup_window_seconds', self::DEFAULT_DEDUP_WINDOW);
            $existing = $this->alertMapper->findLatestForAccessor(
                honeyFlagId: $flag->getId(),
                accessorType: $accessorType,
                accessorId: $accessorId,
                channel: $channel,
            );

            $windowStart = (clone $now)->sub(new DateInterval('PT'.max(0, $window).'S'));
            $snoozed     = ($existing !== null && $existing->getSnoozedUntil() !== null && $existing->getSnoozedUntil() > $now);
            $inWindow    = ($existing !== null && $existing->getAccessedAt() !== null && $existing->getAccessedAt() > $windowStart);

            $collapse = ($existing !== null && ($snoozed === true || $inWindow === true));
            if ($collapse === true && $existing !== null) {
                // Collapse: update the existing alert, no new page.
                $existing->setAccessCount($existing->getAccessCount() + 1);
                $existing->setAccessedAt($now);
                $this->alertMapper->update($existing);
            }

            if ($collapse === false) {
                $alert = new HoneyAlert();
                $alert->setId(Uuid::uuid4()->toString());
                $alert->setHoneyFlagId($flag->getId());
                $alert->setSecretId($secretId);
                $alert->setAccessorType($accessorType);
                $alert->setAccessorId($accessorId);
                $alert->setChannel($channel);
                $alert->setIp($remoteIp);
                $alert->setUserAgent($userAgent);
                $alert->setAccessCount(1);
                $alert->setAccessedAt($now);
                $this->alertMapper->insert($alert);

                $this->pageOwnerAndAdmins(flag: $flag, channel: $channel, accessorLabel: ($accessorId ?? 'anonymous'));
            }//end if

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
                'Doriath: honey alert failed for secret '.$secretId.': '.$exception->getMessage(),
                ['app' => Application::APP_ID]
            );
        }//end try

        return true;
    }//end raiseAlert()

    /**
     * List alerts: owner sees own decoys' alerts; admins instance-wide.
     *
     * @param string $actorId The calling user
     * @param bool   $isAdmin Whether the caller is an admin
     *
     * @return HoneyAlert[]
     */
    public function listAlerts(string $actorId, bool $isAdmin): array
    {
        if ($isAdmin === true) {
            return $this->alertMapper->findAll();
        }

        $flagIds = array_map(
            static fn (HoneyFlag $flag): string => $flag->getId(),
            $this->flagMapper->findByOwner($actorId)
        );

        return $this->alertMapper->findByFlagIds(flagIds: $flagIds);
    }//end listAlerts()

    /**
     * Acknowledge an alert (owner of the decoy or admin).
     *
     * @param string $alertId The alert UUID
     * @param string $actorId The calling user
     * @param bool   $isAdmin Whether the caller is an admin
     *
     * @return HoneyAlert
     *
     * @throws DoesNotExistException    When the alert is missing
     * @throws InvalidArgumentException When the caller may not act on it
     */
    public function acknowledge(string $alertId, string $actorId, bool $isAdmin): HoneyAlert
    {
        $alert = $this->guardedAlert(alertId: $alertId, actorId: $actorId, isAdmin: $isAdmin);
        $alert->setAcknowledgedAt(new DateTime());
        $alert->setAcknowledgedBy($actorId);

        return $this->alertMapper->update($alert);
    }//end acknowledge()

    /**
     * Snooze future paging for the alert's accessor (owner or admin).
     * A snoozed accessor stops paging but every access is still audited.
     *
     * @param string $alertId The alert UUID
     * @param string $actorId The calling user
     * @param bool   $isAdmin Whether the caller is an admin
     * @param int    $hours   Snooze duration in hours
     *
     * @return HoneyAlert
     *
     * @throws DoesNotExistException    When the alert is missing
     * @throws InvalidArgumentException When the caller may not act on it
     */
    public function snooze(string $alertId, string $actorId, bool $isAdmin, int $hours=24): HoneyAlert
    {
        $alert = $this->guardedAlert(alertId: $alertId, actorId: $actorId, isAdmin: $isAdmin);
        $alert->setSnoozedUntil((new DateTime())->add(new DateInterval('PT'.max(1, $hours).'H')));

        return $this->alertMapper->update($alert);
    }//end snooze()

    /**
     * Load an alert and enforce the owner-or-admin guard.
     *
     * @param string $alertId The alert UUID
     * @param string $actorId The calling user
     * @param bool   $isAdmin Whether the caller is an admin
     *
     * @return HoneyAlert
     *
     * @throws DoesNotExistException    When the alert is missing
     * @throws InvalidArgumentException When the caller may not act on it
     */
    private function guardedAlert(string $alertId, string $actorId, bool $isAdmin): HoneyAlert
    {
        $alert = $this->alertMapper->findById($alertId);
        if ($isAdmin === true) {
            return $alert;
        }

        try {
            $flag = $this->flagMapper->findBySecretId($alert->getSecretId());
            if ($flag->getOwnerId() === $actorId) {
                return $alert;
            }
        } catch (DoesNotExistException) {
            // Flag removed — only admins may act on orphaned alerts.
        }

        throw new InvalidArgumentException('Only the decoy owner or an admin may act on this alert');
    }//end guardedAlert()

    /**
     * Page the decoy owner and every admin — ungated (D3): a muted
     * tripwire is worthless, so honey_access bypasses notify_* prefs.
     *
     * @param HoneyFlag $flag          The tripped flag
     * @param string    $channel       The access channel
     * @param string    $accessorLabel Human accessor label
     *
     * @return void
     */
    private function pageOwnerAndAdmins(HoneyFlag $flag, string $channel, string $accessorLabel): void
    {
        if ($this->notificationService === null) {
            return;
        }

        $recipients = [$flag->getOwnerId()];
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
                        'channel'  => $channel,
                        'accessor' => $accessorLabel,
                    ],
                    objectType: 'secret',
                    objectId: $flag->getSecretId(),
                );
            } catch (Throwable $exception) {
                $this->logger->warning(
                    'Doriath: honey page failed for '.$recipientId.': '.$exception->getMessage(),
                    ['app' => Application::APP_ID]
                );
            }
        }
    }//end pageOwnerAndAdmins()
}//end class
