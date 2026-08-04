<?php

/**
 * Doriath Dashboard Service
 *
 * Per-user dashboard preference storage — provides get/set/list methods
 * over DashboardSetting rows.
 *
 * The summary aggregation (totals, shared-counts, rotation-due, CA health,
 * pending-apps and honey-alert counts) lives in DashboardSummaryService;
 * the two halves share no state and no caller.
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
use OCA\Doriath\Db\DashboardSetting;
use OCA\Doriath\Db\DashboardSettingMapper;
use OCP\AppFramework\Db\DoesNotExistException;
use Psr\Log\LoggerInterface;
use Ramsey\Uuid\Uuid;

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
     * The summary aggregation and its seven counter dependencies live in
     * {@see DashboardSummaryService}; this class owns per-user preference
     * storage only.
     *
     * @param DashboardSettingMapper $mapper The dashboard-setting mapper
     * @param LoggerInterface        $logger The logger
     *
     * @return void
     */
    public function __construct(
        private DashboardSettingMapper $mapper,
        private LoggerInterface $logger,
    ) {
    }//end __construct()

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
