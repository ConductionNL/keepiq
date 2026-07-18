<?php

/**
 * Doriath Dashboard Service
 *
 * Smallest scaffold for the per-user dashboard preference capability —
 * provides get/set/list methods over DashboardSetting rows. The full
 * fetchSummary() aggregation (totals, shared-counts, migration status,
 * CA health, pending-apps count) and the dashboard widget Vue components
 * ship with the dedicated implement-dashboard-settings build cycle.
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
use OCA\Doriath\Db\DashboardSetting;
use OCA\Doriath\Db\DashboardSettingMapper;
use OCA\Doriath\Db\FolderMapper;
use OCA\Doriath\Db\HoneyAlertMapper;
use OCA\Doriath\Db\RotationFlagMapper;
use OCA\Doriath\Db\SecretMapper;
use OCA\Doriath\Db\ShareTargetMapper;
use OCP\AppFramework\Db\DoesNotExistException;
use Psr\Log\LoggerInterface;
use Ramsey\Uuid\Uuid;
use Throwable;

/**
 * Business logic for per-user dashboard settings (scaffold).
 *
 * Authorization at this layer is intentionally minimal: every method
 * takes an explicit $userId and the caller (controller) is responsible
 * for resolving the current user. Cross-user reads are not exposed.
 */
class DashboardService
{
    /**
     * Allowed setting keys for the scaffold. The full dashboard summary
     * aggregator and admin-vs-user split land with the dedicated build
     * cycle; this scaffold only stores per-user preferences.
     *
     * @var array<string>
     */
    public const ALLOWED_KEYS = [
        'layout',
        'visible_widgets',
        'default_view',
        'default_secret_type',
    ];

    /**
     * Maximum JSON-encoded value length to guard against unbounded text.
     */
    private const MAX_VALUE_LENGTH = 4096;

    /**
     * Constructor for DashboardService.
     *
     * The aggregator dependencies (SecretMapper, FolderMapper, ShareTargetMapper,
     * ApplicationMapper) are nullable so callers / unit tests of the pure
     * preference path keep their original two-argument signature. The
     * Nextcloud DI container injects them in production.
     *
     * @param DashboardSettingMapper  $mapper             The dashboard-setting mapper
     * @param LoggerInterface         $logger             The logger
     * @param SecretMapper|null       $secretMapper       Secret counter (summary aggregator)
     * @param FolderMapper|null       $folderMapper       Folder counter (summary aggregator)
     * @param ShareTargetMapper|null  $shareTargetMapper  Shared-with-me counter (summary aggregator)
     * @param ApplicationMapper|null  $applicationMapper  Pending-application counter (summary aggregator)
     * @param RotationFlagMapper|null $rotationFlagMapper Rotation-due counter (rotation-expiry-policies §7.2)
     *
     * @return void
     */
    public function __construct(
        private DashboardSettingMapper $mapper,
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
                fn: fn () => $this->secretMapper?->countByOwner('user', $userId, null) ?? 0,
                metricId: 'total_secrets',
            ),
            'shared_with_me_count' => $this->safeCount(
                fn: $sharedWithMeCount,
                metricId: 'shared_with_me_count',
            ),
            'folders_count'        => $this->safeCount(
                fn: $foldersCount,
                metricId: 'folders_count',
            ),
            'rotation_due_count'   => $this->safeCount(
                fn: fn () => count($this->rotationFlagMapper?->findOpenForOwner($userId) ?? []),
                metricId: 'rotation_due_count',
            ),
            'pending_apps_count'   => null,
            'is_admin'             => $isAdmin,
            'last_updated'         => (new DateTime())->format('c'),
        ];

        if ($isAdmin === true) {
            $summary['pending_apps_count'] = $this->safeCount(
                fn: fn () => $this->applicationMapper?->countPending() ?? 0,
                metricId: 'pending_apps_count',
            );
            $summary['ca_health']          = $this->caHealthCard();
            $summary['honey_alert_count']  = $this->safeCount(
                fn: fn () => $this->honeyAlertMapper?->countUnacknowledged() ?? 0,
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
     * @param callable():int $fn       The counter
     * @param string         $metricId Human-readable metric label for logs
     *
     * @return int
     */
    private function safeCount(callable $fn, string $metricId): int
    {
        try {
            return $fn();
        } catch (Throwable $e) {
            $this->logger->warning(
                'DashboardService::fetchSummary() failed to compute '.$metricId.': '.$e->getMessage(),
                ['app' => 'doriath']
            );
            return 0;
        }
    }//end safeCount()

    /**
     * Get a single setting for a user (returns null if unset).
     *
     * @param string $userId     The Nextcloud user ID
     * @param string $settingKey The setting key
     *
     * @return mixed The decoded value, or null when no row exists.
     *
     * @throws InvalidArgumentException When the key is not whitelisted.
     */
    public function get(string $userId, string $settingKey): mixed
    {
        $this->validateUserId(userId: $userId);
        $this->validateKey(settingKey: $settingKey);

        try {
            $entity = $this->mapper->findByUserAndKey($userId, $settingKey);
        } catch (DoesNotExistException) {
            return null;
        }

        return $entity->getDecodedValue();
    }//end get()

    /**
     * List all settings for a user as a flat key=>value array.
     *
     * @param string $userId The Nextcloud user ID
     *
     * @return array<string,mixed>
     */
    public function listForUser(string $userId): array
    {
        $this->validateUserId(userId: $userId);

        $out = [];
        foreach ($this->mapper->findByUser($userId) as $entity) {
            $out[$entity->getSettingKey()] = $entity->getDecodedValue();
        }

        return $out;
    }//end listForUser()

    /**
     * Set (insert-or-update) a single setting for a user.
     *
     * @param string $userId     The Nextcloud user ID
     * @param string $settingKey The setting key
     * @param mixed  $value      The value to persist (will be JSON-encoded)
     *
     * @return DashboardSetting The persisted row.
     *
     * @throws InvalidArgumentException When validation fails.
     */
    public function set(string $userId, string $settingKey, mixed $value): DashboardSetting
    {
        $this->validateUserId(userId: $userId);
        $this->validateKey(settingKey: $settingKey);

        $encoded = json_encode($value);
        if ($encoded === false) {
            throw new InvalidArgumentException(message: 'Setting value is not JSON-encodable');
        }

        if (strlen($encoded) > self::MAX_VALUE_LENGTH) {
            throw new InvalidArgumentException(message: 'Setting value exceeds maximum length');
        }

        try {
            $entity = $this->mapper->findByUserAndKey($userId, $settingKey);
            $entity->setSettingValue($encoded);
            $entity->setUpdatedAt(new DateTime());
            $persisted = $this->mapper->update($entity);
        } catch (DoesNotExistException) {
            $entity = new DashboardSetting();
            $entity->setId(Uuid::uuid4()->toString());
            $entity->setUserId($userId);
            $entity->setSettingKey($settingKey);
            $entity->setSettingValue($encoded);
            $entity->setCreatedAt(new DateTime());
            $entity->setUpdatedAt(new DateTime());
            $persisted = $this->mapper->insert($entity);
        }

        $this->logger->debug(
            'Saved dashboard setting '.$settingKey.' for user '.$userId,
            ['app' => 'doriath']
        );

        return $persisted;
    }//end set()

    /**
     * Bulk-set a map of settings for a user. Unknown keys raise.
     *
     * @param string              $userId   The Nextcloud user ID
     * @param array<string,mixed> $settings The settings to persist
     *
     * @return array<string,mixed> The full settings map for the user post-update.
     *
     * @throws InvalidArgumentException
     */
    public function setMany(string $userId, array $settings): array
    {
        foreach ($settings as $key => $value) {
            $this->set(userId: $userId, settingKey: (string) $key, value: $value);
        }

        return $this->listForUser(userId: $userId);
    }//end setMany()

    /**
     * Cascade-delete all settings for a user (account deletion hook).
     *
     * @param string $userId The Nextcloud user ID
     *
     * @return void
     */
    public function deleteAllForUser(string $userId): void
    {
        $this->mapper->deleteByUser($userId);
    }//end deleteAllForUser()

    /**
     * Validate the setting key is whitelisted.
     *
     * @param string $settingKey The setting key
     *
     * @return void
     *
     * @throws InvalidArgumentException
     */
    private function validateKey(string $settingKey): void
    {
        if (in_array($settingKey, self::ALLOWED_KEYS, true) === false) {
            throw new InvalidArgumentException(message: 'Unknown dashboard setting key: '.$settingKey);
        }
    }//end validateKey()

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
