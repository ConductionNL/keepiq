<?php

/**
 * Doriath Dashboard Summary Service
 *
 * Aggregates the read-only dashboard summary card (totals, shared-counts,
 * rotation-due, CA health, pending-apps and honey-alert counts).
 *
 * Extracted from DashboardService, which had accumulated two unrelated
 * responsibilities behind one constructor: per-user preference storage
 * (DashboardSettingMapper) and this cross-domain read-only aggregation
 * (seven counter dependencies). The two halves shared no state and no
 * caller — the preference methods serve DashboardSettingsController, this
 * aggregator serves DashboardController — so splitting them is a pure
 * move with no behaviour change.
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

use DateTime;
use InvalidArgumentException;
use OCA\Doriath\Db\ApplicationMapper;
use OCA\Doriath\Db\FolderMapper;
use OCA\Doriath\Db\HoneyAlertMapper;
use OCA\Doriath\Db\RotationFlagMapper;
use OCA\Doriath\Db\SecretMapper;
use OCA\Doriath\Db\ShareTargetMapper;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * Read-only aggregation for the dashboard summary card.
 *
 * Authorization at this layer is intentionally minimal: fetchSummary()
 * takes an explicit $userId and $isAdmin and the caller (controller) is
 * responsible for resolving the current user and its admin status.
 * Every counter is fail-soft — a mapper outage degrades that single
 * metric to zero rather than breaking the whole dashboard render.
 */
class DashboardSummaryService
{
    /**
     * Constructor for DashboardSummaryService.
     *
     * The counter dependencies are nullable so unit tests can wire only the
     * mappers a given case exercises; a null mapper reports zero for its
     * metric. The Nextcloud DI container injects them all in production.
     *
     * @param LoggerInterface                  $logger             The logger
     * @param SecretMapper|null                $secretMapper       Secret counter
     * @param FolderMapper|null                $folderMapper       Folder counter
     * @param ShareTargetMapper|null           $shareTargetMapper  Shared-with-me counter
     * @param ApplicationMapper|null           $applicationMapper  Pending-application counter
     * @param RotationFlagMapper|null          $rotationFlagMapper Rotation-due counter (rotation-expiry-policies
     *                                                             §7.2)
     * @param CertificateAuthorityService|null $caService          The CA service (admin CA-health card,
     *                                                             certificate-lifecycle §5.1)
     * @param HoneyAlertMapper|null            $honeyAlertMapper   Open honey-alert counter (honey-credentials)
     *
     * @return void
     */
    public function __construct(
        private LoggerInterface $logger,
        private ?SecretMapper $secretMapper=null,
        private ?FolderMapper $folderMapper=null,
        private ?ShareTargetMapper $shareTargetMapper=null,
        private ?ApplicationMapper $applicationMapper=null,
        private ?RotationFlagMapper $rotationFlagMapper=null,
        private ?CertificateAuthorityService $caService=null,
        private ?HoneyAlertMapper $honeyAlertMapper=null,
    ) {
    }//end __construct()

    /**
     * Aggregate the dashboard summary for the given user.
     *
     * The shape matches the OpenAPI contract sketched in
     * implement-dashboard-settings (§D6 fetchSummary):
     *
     * - total_secrets: count of secrets owned by the user
     * - shared_with_me_count: count of secret shares targeting the user
     * - folders_count: count of folders owned by the user
     * - pending_apps_count: admin-only, omitted for non-admins (null)
     * - last_updated: ISO-8601 timestamp of the aggregation
     *
     * Mapper failures are logged and degraded to zero so a partial DB
     * outage cannot wipe the dashboard render.
     *
     * @param string $userId  The Nextcloud user ID
     * @param bool   $isAdmin Whether the caller is an admin
     *
     * @return array<string,mixed>
     *
     * @spec openspec/specs/dashboard/spec.md#requirement-vault-summary-cards-mvp
     * @spec openspec/specs/dashboard/spec.md#requirement-pending-applications-counter-admin-mvp
     */
    public function fetchSummary(string $userId, bool $isAdmin): array
    {
        $this->validateUserId(userId: $userId);

        $sharedWithMeCount = function () use ($userId): int {
            if ($this->shareTargetMapper === null) {
                return 0;
            }

            return count($this->shareTargetMapper->findByTargetUser($userId));
        };

        $foldersCount = function () use ($userId): int {
            if ($this->folderMapper === null) {
                return 0;
            }

            return count($this->folderMapper->findByOwner('user', $userId));
        };

        $summary = [
            'total_secrets'        => $this->safeCount(
                counter: fn () => $this->secretMapper?->countByOwner('user', $userId, null) ?? 0,
                metricId: 'total_secrets',
            ),
            'shared_with_me_count' => $this->safeCount(
                counter: $sharedWithMeCount,
                metricId: 'shared_with_me_count',
            ),
            'folders_count'        => $this->safeCount(
                counter: $foldersCount,
                metricId: 'folders_count',
            ),
            'rotation_due_count'   => $this->safeCount(
                counter: fn () => count($this->rotationFlagMapper?->findOpenForOwner($userId) ?? []),
                metricId: 'rotation_due_count',
            ),
            'pending_apps_count'   => null,
            'is_admin'             => $isAdmin,
            'last_updated'         => (new DateTime())->format('c'),
        ];

        if ($isAdmin === true) {
            $summary['pending_apps_count'] = $this->safeCount(
                counter: fn () => $this->applicationMapper?->countPending() ?? 0,
                metricId: 'pending_apps_count',
            );
            $summary['ca_health']          = $this->caHealthCard();
            $summary['honey_alert_count']  = $this->safeCount(
                counter: fn () => $this->honeyAlertMapper?->countUnacknowledged() ?? 0,
                metricId: 'honey_alert_count',
            );
        }

        return $summary;
    }//end fetchSummary()

    /**
     * The admin-only CA-health card (certificate-lifecycle §5.1):
     * status + root/intermediate expiry + issued-certificate counts.
     * Fail-soft — a CA error yields null rather than breaking the
     * whole summary.
     *
     * @return array<string,mixed>|null
     */
    private function caHealthCard(): ?array
    {
        if ($this->caService === null) {
            return null;
        }

        try {
            $status = $this->caService->getStatus();

            return [
                'status'                => $status['status'],
                'rootExpiresAt'         => $status['root']['expiresAt'] ?? null,
                'intermediateExpiresAt' => $status['intermediate']['expiresAt'] ?? null,
                'issued'                => ($status['issued'] ?? null),
            ];
        } catch (Throwable $exception) {
            $this->logger->warning(
                'Doriath: CA-health card unavailable: '.$exception->getMessage(),
                ['app' => 'doriath']
            );

            return null;
        }
    }//end caHealthCard()

    /**
     * Run a counter callback, logging+degrading to zero on failure.
     *
     * @param callable():int $counter  The counter
     * @param string         $metricId Human-readable metric label for logs
     *
     * @return int
     */
    private function safeCount(callable $counter, string $metricId): int
    {
        try {
            return $counter();
        } catch (Throwable $e) {
            $this->logger->warning(
                'DashboardSummaryService::fetchSummary() failed to compute '.$metricId.': '.$e->getMessage(),
                ['app' => 'doriath']
            );
            return 0;
        }
    }//end safeCount()

    /**
     * Validate the user ID is non-empty.
     *
     * @param string $userId The user ID
     *
     * @return void
     *
     * @throws InvalidArgumentException
     */
    private function validateUserId(string $userId): void
    {
        if ($userId === '') {
            throw new InvalidArgumentException(message: 'userId is required');
        }
    }//end validateUserId()
}//end class
