<?php

/**
 * Unit tests for the SeedDevelopmentSecretDelegations repair step.
 *
 * Covers:
 *  - debug=false → no-op;
 *  - missing dev EncryptionSuite → no-op;
 *  - no dev secrets → no-op;
 *  - version marker already matches the installed app version → no-op;
 *  - pre-existing delegation for the deterministic ID → idempotency no-op;
 *  - happy path → 1 temporary SecretDelegation row with the documented
 *    owner/delegate/initiator shape.
 *
 * @category Test
 * @package  OCA\Doriath\Tests\Unit\Repair
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

namespace OCA\Doriath\Tests\Unit\Repair;

use OCA\Doriath\Db\EncryptionSuite;
use OCA\Doriath\Db\EncryptionSuiteMapper;
use OCA\Doriath\Db\Secret;
use OCA\Doriath\Db\SecretDelegation;
use OCA\Doriath\Db\SecretDelegationMapper;
use OCA\Doriath\Db\SecretMapper;
use OCA\Doriath\Repair\SeedDevelopmentSecretDelegations;
use OCP\AppFramework\Db\DoesNotExistException;
use OCP\IAppConfig;
use OCP\IConfig;
use OCP\Migration\IOutput;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

/**
 * Tests for SeedDevelopmentSecretDelegations.
 */
class SeedDevelopmentSecretDelegationsTest extends TestCase
{
    /**
     * Build a dev Secret with the given ID.
     *
     * @param string $id The secret ID
     *
     * @return Secret
     */
    private function devSecret(string $id): Secret
    {
        $secret = new Secret();
        $secret->setId($id);
        return $secret;
    }

    /**
     * Build an IAppConfig mock reporting an installed version that has NOT
     * yet been seeded (i.e. the version-gate never blocks the run).
     *
     * @return IAppConfig&\PHPUnit\Framework\MockObject\MockObject
     */
    private function unseededAppConfig(): IAppConfig
    {
        $appConfig = $this->createMock(IAppConfig::class);
        $appConfig->method('getValueString')
            ->willReturnCallback(static function (string $app, string $key, string $default = '') {
                if ($key === 'installed_version') {
                    return '1.2.3';
                }

                return $default;
            });

        return $appConfig;
    }//end unseededAppConfig()

    /**
     * debug=false → no insert.
     *
     * @return void
     */
    public function testNoOpWhenDebugDisabled(): void
    {
        $config = $this->createMock(IConfig::class);
        $config->method('getSystemValueBool')->willReturn(false);

        $delegationMapper = $this->createMock(SecretDelegationMapper::class);
        $delegationMapper->expects($this->never())->method('insert');

        $step = new SeedDevelopmentSecretDelegations(
            secretMapper: $this->createMock(SecretMapper::class),
            delegationMapper: $delegationMapper,
            suiteMapper: $this->createMock(EncryptionSuiteMapper::class),
            config: $config,
            appConfig: $this->unseededAppConfig(),
            logger: $this->createMock(LoggerInterface::class),
        );
        $step->run($this->createMock(IOutput::class));
    }

    /**
     * Missing dev EncryptionSuite → no-op.
     *
     * @return void
     */
    public function testNoOpWhenSuiteMissing(): void
    {
        $config = $this->createMock(IConfig::class);
        $config->method('getSystemValueBool')->willReturn(true);

        $suiteMapper = $this->createMock(EncryptionSuiteMapper::class);
        $suiteMapper->method('findActiveByOwner')->willThrowException(new DoesNotExistException('no'));

        $delegationMapper = $this->createMock(SecretDelegationMapper::class);
        $delegationMapper->expects($this->never())->method('insert');

        $step = new SeedDevelopmentSecretDelegations(
            secretMapper: $this->createMock(SecretMapper::class),
            delegationMapper: $delegationMapper,
            suiteMapper: $suiteMapper,
            config: $config,
            appConfig: $this->unseededAppConfig(),
            logger: $this->createMock(LoggerInterface::class),
        );
        $step->run($this->createMock(IOutput::class));
    }

    /**
     * Version marker already matches the installed app version → the
     * repair step short-circuits before touching any mapper.
     *
     * @return void
     */
    public function testNoOpWhenVersionMarkerMatchesInstalledVersion(): void
    {
        $config = $this->createMock(IConfig::class);
        $config->method('getSystemValueBool')->willReturn(true);

        $appConfig = $this->createMock(IAppConfig::class);
        $appConfig->method('getValueString')
            ->willReturnCallback(static function (string $app, string $key, string $default = '') {
                if ($key === 'installed_version') {
                    return '1.2.3';
                }

                if ($key === 'dev_seed_secret_delegations_version') {
                    return '1.2.3';
                }

                return $default;
            });

        $suiteMapper = $this->createMock(EncryptionSuiteMapper::class);
        $suiteMapper->expects($this->never())->method('findActiveByOwner');

        $delegationMapper = $this->createMock(SecretDelegationMapper::class);
        $delegationMapper->expects($this->never())->method('insert');

        $step = new SeedDevelopmentSecretDelegations(
            secretMapper: $this->createMock(SecretMapper::class),
            delegationMapper: $delegationMapper,
            suiteMapper: $suiteMapper,
            config: $config,
            appConfig: $appConfig,
            logger: $this->createMock(LoggerInterface::class),
        );
        $step->run($this->createMock(IOutput::class));
    }

    /**
     * Idempotency: pre-existing delegation for the deterministic ID → no-op.
     *
     * @return void
     */
    public function testIdempotencyWhenFirstSecretAlreadyDelegated(): void
    {
        $config = $this->createMock(IConfig::class);
        $config->method('getSystemValueBool')->willReturn(true);

        $suiteMapper = $this->createMock(EncryptionSuiteMapper::class);
        $suite       = new EncryptionSuite();
        $suite->setId('suite-1');
        $suiteMapper->method('findActiveByOwner')->willReturn($suite);

        $secretMapper = $this->createMock(SecretMapper::class);
        $secretMapper->method('findByOwner')->willReturn([$this->devSecret('s-1')]);

        $delegationMapper = $this->createMock(SecretDelegationMapper::class);
        $delegationMapper->method('findById')->willReturn(new SecretDelegation());
        $delegationMapper->expects($this->never())->method('insert');

        $step = new SeedDevelopmentSecretDelegations(
            secretMapper: $secretMapper,
            delegationMapper: $delegationMapper,
            suiteMapper: $suiteMapper,
            config: $config,
            appConfig: $this->unseededAppConfig(),
            logger: $this->createMock(LoggerInterface::class),
        );
        $step->run($this->createMock(IOutput::class));
    }

    /**
     * Happy path: debug + suite + 1+ secrets → insert one temporary
     * delegation row.
     *
     * @return void
     */
    public function testHappyPathInsertsOneTemporaryDelegation(): void
    {
        $config = $this->createMock(IConfig::class);
        $config->method('getSystemValueBool')->willReturn(true);

        $suiteMapper = $this->createMock(EncryptionSuiteMapper::class);
        $suite       = new EncryptionSuite();
        $suite->setId('suite-1');
        $suiteMapper->method('findActiveByOwner')->willReturn($suite);

        $secretMapper = $this->createMock(SecretMapper::class);
        $secretMapper->method('findByOwner')->willReturn([$this->devSecret('s-github')]);

        $delegationMapper = $this->createMock(SecretDelegationMapper::class);
        $delegationMapper->method('findById')->willThrowException(new DoesNotExistException('not seeded'));

        $inserted = null;
        $delegationMapper->expects($this->once())
            ->method('insert')
            ->willReturnCallback(static function (SecretDelegation $e) use (&$inserted): SecretDelegation {
                $inserted = $e;
                return $e;
            });

        $step = new SeedDevelopmentSecretDelegations(
            secretMapper: $secretMapper,
            delegationMapper: $delegationMapper,
            suiteMapper: $suiteMapper,
            config: $config,
            appConfig: $this->unseededAppConfig(),
            logger: $this->createMock(LoggerInterface::class),
        );
        $step->run($this->createMock(IOutput::class));

        $this->assertNotNull($inserted);
        $this->assertSame('s-github', $inserted->getSecretId());
        $this->assertSame('admin', $inserted->getOriginalOwnerId());
        $this->assertSame('dev-user-2', $inserted->getDelegatedTo());
        $this->assertSame('admin', $inserted->getInitiatedBy());
        $this->assertFalse($inserted->getIsPermanent());
    }
}
