<?php

/**
 * Unit tests for the master-password policy round-trip (#192).
 *
 * Every assertion here is on the VALUE THAT WAS STORED, never on the
 * `{"success": true}` envelope the endpoint returns. That distinction is the
 * whole point of the bug: `CONFIG_KEYS` used to list only `register`, so both
 * keys the admin panel posts fell out of the write loop while the response
 * still reported success. A test that asserted on the envelope would have
 * passed against the broken code.
 *
 * @category Test
 * @package  OCA\Doriath\Tests\Unit\Service
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

namespace OCA\Doriath\Tests\Unit\Service;

use InvalidArgumentException;
use OCA\Doriath\Service\SettingsService;
use OCP\App\IAppManager;
use OCP\EventDispatcher\IEventDispatcher;
use OCP\IAppConfig;
use OCP\IConfig;
use OCP\IGroupManager;
use OCP\IUserSession;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;

/**
 * Tests the master-password floors survive a write and reach the read paths.
 */
class SettingsServiceMasterPasswordTest extends TestCase
{
    private SettingsService $service;

    private IAppConfig&MockObject $appConfig;

    /**
     * The mutable fake app-config store, keyed by config key.
     *
     * @var array<string,string>
     */
    private array $store = [];

    /**
     * Build the service over a mutable IAppConfig fake, so a write is
     * observable as a stored value rather than as a mock expectation.
     *
     * @return void
     */
    protected function setUp(): void
    {
        parent::setUp();
        $this->store     = [];
        $this->appConfig = $this->createMock(originalClassName: IAppConfig::class);
        $this->appConfig->method('getValueString')->willReturnCallback(
            fn (string $app, string $key, string $default=''): string => ($this->store[$key] ?? $default)
        );
        $this->appConfig->method('getValueInt')->willReturnCallback(
            fn (string $app, string $key, int $default=0): int => (int) ($this->store[$key] ?? $default)
        );
        $this->appConfig->method('getValueBool')->willReturnCallback(
            fn (string $app, string $key, bool $default=false): bool => (bool) ($this->store[$key] ?? $default)
        );
        $this->appConfig->method('setValueString')->willReturnCallback(
            function (string $app, string $key, string $value): bool {
                $this->store[$key] = $value;
                return true;
            }
        );

        $appManager = $this->createMock(originalClassName: IAppManager::class);
        $appManager->method('isInstalled')->willReturn(true);

        $this->service = new SettingsService(
            appConfig: $this->appConfig,
            config: $this->createMock(originalClassName: IConfig::class),
            appManager: $appManager,
            container: $this->createMock(originalClassName: ContainerInterface::class),
            groupManager: $this->createMock(originalClassName: IGroupManager::class),
            userSession: $this->createMock(originalClassName: IUserSession::class),
            logger: $this->createMock(originalClassName: LoggerInterface::class),
            eventDispatcher: $this->createMock(originalClassName: IEventDispatcher::class),
        );
    }//end setUp()

    /**
     * The two keys the admin panel posts must survive the write.
     *
     * `src/components/settings/PasswordPolicySection.vue::save()` posts exactly
     * these two names; asserting on the stored value is what distinguishes a
     * real write from the unconditional success envelope.
     *
     * @return void
     */
    public function testMasterPasswordFloorsSurviveAWriteAndReadBack(): void
    {
        $this->service->updateSettings(
            data: [
                'master_password_min_length' => '16',
                'master_password_min_score'  => '4',
            ]
        );

        $this->assertSame(
            '16',
            $this->store['master_password_min_length'] ?? null,
            'master_password_min_length must reach IAppConfig — the panel posts it on every change'
        );
        $this->assertSame(
            '4',
            $this->store['master_password_min_score'] ?? null,
            'master_password_min_score must reach IAppConfig'
        );

        $settings = $this->service->getSettings();
        $this->assertSame('16', $settings['master_password_min_length']);
        $this->assertSame('4', $settings['master_password_min_score']);
    }//end testMasterPasswordFloorsSurviveAWriteAndReadBack()

    /**
     * updateSettings() returns the stored map, so the panel can re-render from
     * the server's answer rather than from its own submission.
     *
     * @return void
     */
    public function testUpdateReturnsTheStoredFloors(): void
    {
        $result = $this->service->updateSettings(data: ['master_password_min_length' => '20']);

        $this->assertSame('20', $result['master_password_min_length']);
    }//end testUpdateReturnsTheStoredFloors()

    /**
     * An unwritten vault reports the app defaults, not an empty string.
     *
     * An empty string is what the panel used to receive; `parseInt('')` is NaN,
     * which its `|| 12` fallback silently masked into "looks configured".
     *
     * @return void
     */
    public function testUnwrittenFloorsReportTheSeededDefaults(): void
    {
        $settings = $this->service->getSettings();

        $this->assertSame('12', $settings['master_password_min_length']);
        $this->assertSame('3', $settings['master_password_min_score']);
    }//end testUnwrittenFloorsReportTheSeededDefaults()

    /**
     * `register` keeps its historical empty-string default.
     *
     * @return void
     */
    public function testRegisterKeepsItsEmptyDefaultAndStillWrites(): void
    {
        $this->assertSame('', $this->service->getSettings()['register']);

        $this->service->updateSettings(data: ['register' => 'reg-1']);

        $this->assertSame('reg-1', $this->store['register'] ?? null);
    }//end testRegisterKeepsItsEmptyDefaultAndStillWrites()

    /**
     * A length below the app minimum is rejected, not stored.
     *
     * The browser clamps to 12-20, so an out-of-range value can only arrive
     * from a caller that bypassed the UI — the bound is a rule, not a hint.
     *
     * @return void
     */
    public function testRejectsALengthBelowTheAppMinimum(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('master_password_min_length must be a whole number between 12 and 20');

        $this->service->updateSettings(data: ['master_password_min_length' => '4']);
    }//end testRejectsALengthBelowTheAppMinimum()

    /**
     * A rejected write stores nothing at all.
     *
     * @return void
     */
    public function testARejectedWriteLeavesTheStoredFloorUntouched(): void
    {
        $this->service->updateSettings(data: ['master_password_min_length' => '18']);

        try {
            $this->service->updateSettings(data: ['master_password_min_length' => '99']);
            $this->fail('an out-of-range length must throw');
        } catch (InvalidArgumentException) {
            // Expected.
        }

        $this->assertSame(
            '18',
            $this->store['master_password_min_length'],
            'the previously stored floor must survive a rejected write'
        );
    }//end testARejectedWriteLeavesTheStoredFloorUntouched()

    /**
     * A score outside 3-4 is rejected.
     *
     * @return void
     */
    public function testRejectsAnOutOfRangeScore(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('master_password_min_score must be a whole number between 3 and 4');

        $this->service->updateSettings(data: ['master_password_min_score' => '2']);
    }//end testRejectsAnOutOfRangeScore()

    /**
     * A non-numeric value is rejected rather than cast to 0.
     *
     * @return void
     */
    public function testRejectsANonNumericFloor(): void
    {
        $this->expectException(InvalidArgumentException::class);

        $this->service->updateSettings(data: ['master_password_min_length' => 'twelve']);
    }//end testRejectsANonNumericFloor()

    /**
     * Keys outside the whitelist are still ignored.
     *
     * @return void
     */
    public function testUnknownKeysAreStillNotWritten(): void
    {
        $this->service->updateSettings(data: ['definitely_not_a_setting' => 'x']);

        $this->assertArrayNotHasKey('definitely_not_a_setting', $this->store);
    }//end testUnknownKeysAreStillNotWritten()

    /**
     * getPolicy() republishes the stored floors, as integers.
     *
     * This is the only endpoint every authenticated user may read, and
     * `PasswordStrengthMeter` has no other source for the floors — without it
     * the admin setting would persist and still change nothing.
     *
     * @return void
     */
    public function testPolicyExposesTheStoredMasterPasswordFloors(): void
    {
        $this->service->updateSettings(
            data: [
                'master_password_min_length' => '18',
                'master_password_min_score'  => '4',
            ]
        );

        $policy = $this->service->getPolicy();

        $this->assertSame(18, $policy['master_password_min_length']);
        $this->assertSame(4, $policy['master_password_min_score']);
    }//end testPolicyExposesTheStoredMasterPasswordFloors()

    /**
     * getPolicy() falls back to the app defaults on an unwritten vault.
     *
     * @return void
     */
    public function testPolicyFallsBackToTheAppDefaultFloors(): void
    {
        $policy = $this->service->getPolicy();

        $this->assertSame(12, $policy['master_password_min_length']);
        $this->assertSame(3, $policy['master_password_min_score']);
    }//end testPolicyFallsBackToTheAppDefaultFloors()
}//end class
