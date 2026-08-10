<?php

/**
 * Doriath Honey Alert Recorder
 *
 * The alert-row half of the decoy tripwire (honey-credentials §2.2, D5):
 * decides whether an observed access folds onto the accessor's latest alert
 * or raises a new one, and writes that decision to the alert store. A
 * snoozed accessor always collapses, and so does a repeat inside the
 * configurable dedup window. It answers "was a NEW alert raised?" so the
 * caller knows whether to page — it never pages or audits itself.
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
use OCA\Doriath\AppInfo\Application;
use OCA\Doriath\Db\HoneyAlert;
use OCA\Doriath\Db\HoneyAlertMapper;
use OCA\Doriath\Db\HoneyFlag;
use OCP\IAppConfig;
use Ramsey\Uuid\Uuid;

/**
 * Deduplicated persistence of honey (decoy) access alerts.
 */
class HoneyAlertRecorder
{
    /**
     * Default dedup window in seconds (1h).
     *
     * @var int
     */
    private const DEFAULT_DEDUP_WINDOW = 3600;

    /**
     * Constructor for HoneyAlertRecorder.
     *
     * @param HoneyAlertMapper $alertMapper The alert mapper
     * @param IAppConfig       $appConfig   The app config (dedup window)
     *
     * @return void
     *
     * @spec exclude Constructor wiring only — no domain logic.
     */
    public function __construct(
        private HoneyAlertMapper $alertMapper,
        private IAppConfig $appConfig,
    ) {
    }//end __construct()

    /**
     * Record one honey access: collapse it onto the accessor's latest alert
     * when it falls inside the dedup window or the alert is snoozed,
     * otherwise raise a new alert.
     *
     * @param HoneyFlag   $flag         The honey flag of the accessed secret
     * @param string      $secretId     The accessed secret
     * @param string      $accessorType The accessor type
     * @param string|null $accessorId   The accessor id, or null when anonymous
     * @param string      $channel      The access channel
     * @param string|null $remoteIp     The accessor's remote address
     * @param string|null $userAgent    The accessor's user agent
     *
     * @return bool Whether a NEW alert was raised (the caller should page)
     *
     * @spec openspec/changes/honey-credentials/specs/honey-credentials/spec.md
     */
    public function record(
        HoneyFlag $flag,
        string $secretId,
        string $accessorType,
        ?string $accessorId,
        string $channel,
        ?string $remoteIp,
        ?string $userAgent,
    ): bool {
        $now      = new DateTime();
        $window   = $this->appConfig->getValueInt(Application::APP_ID, 'honey_dedup_window_seconds', self::DEFAULT_DEDUP_WINDOW);
        $existing = $this->alertMapper->findLatestForAccessor(
            honeyFlagId: $flag->getId(),
            accessorType: $accessorType,
            accessorId: $accessorId,
            channel: $channel,
        );

        $windowStart = (clone $now)->sub(new DateInterval('PT'.max(0, $window).'S'));
        $collapse    = $this->shouldCollapseAlert(existing: $existing, now: $now, windowStart: $windowStart);
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
        }//end if

        return ($collapse === false);
    }//end record()

    /**
     * Whether a repeat access folds onto the accessor's latest alert rather
     * than raising a new one: a snoozed alert always collapses, and so does
     * an access inside the dedup window.
     *
     * @param HoneyAlert|null $existing    The accessor's latest alert, when any
     * @param DateTime        $now         The access moment
     * @param DateTime        $windowStart The start of the dedup window
     *
     * @return bool
     */
    private function shouldCollapseAlert(?HoneyAlert $existing, DateTime $now, DateTime $windowStart): bool
    {
        if ($existing === null) {
            return false;
        }

        $snoozedUntil = $existing->getSnoozedUntil();
        if ($snoozedUntil !== null && $snoozedUntil > $now) {
            return true;
        }

        $accessedAt = $existing->getAccessedAt();
        return ($accessedAt !== null && $accessedAt > $windowStart);
    }//end shouldCollapseAlert()
}//end class
