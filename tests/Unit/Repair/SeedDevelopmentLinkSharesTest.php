<?php

/**
 * Unit tests for the SeedDevelopmentLinkShares repair step.
 *
 * Covers:
 *  - debug=false → no-op (no LinkShare insert);
 *  - missing dev EncryptionSuite → no-op;
 *  - no dev secrets → no-op;
 *  - version marker already matches the installed app version → no-op;
 *  - pre-existing row for a deterministic ID → per-row idempotency no-op;
 *  - happy path (debug=true + suite + 3 secrets) → 3 LinkShare inserts
 *    with the documented usage-limit / usage-count / expiry shape.
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
use OCA\Doriath\Db\LinkShare;
use OCA\Doriath\Db\LinkShareMapper;
use OCA\Doriath\Db\Secret;
use OCA\Doriath\Db\SecretMapper;
use OCA\Doriath\Repair\SeedDevelopmentLinkShares;
use OCP\AppFramework\Db\DoesNotExistException;
use OCP\IAppConfig;
use OCP\IConfig;
use OCP\Migration\IOutput;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

/**
 * Tests for SeedDevelopmentLinkShares.
 */
class SeedDevelopmentLinkSharesTest extends TestCase
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
    }//end devSecret()

    /**
     * Build a dev EncryptionSuite with the given ID.
     *
     * @param string $id The suite ID
     *
     * @return EncryptionSuite
     */
    private function devSuite(string $id): EncryptionSuite
    {
        $suite = new EncryptionSuite();
        $suite->setId($id);
        return $suite;
    }//end devSuite()

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
            ->willReturnCallback(
                    static function (string $app, string $key, string $default='') {
                        if ($key === 'installed_version') {
                            return '1.2.3';
                        }

                        return $default;
                    }
                    );

        return $appConfig;
    }//end unseededAppConfig()

    /**
     * debug=false → no insert, no lookups.
     *
     * @return void
     */
    public function testNoOpWhenDebugDisabled(): void
    {
        $config = $this->createMock(IConfig::class);
        $config->method('getSystemValueBool')->with('debug', false)->willReturn(false);

        $linkShareMapper = $this->createMock(LinkShareMapper::class);
        $secretMapper    = $this->createMock(SecretMapper::class);
        $suiteMapper     = $this->createMock(EncryptionSuiteMapper::class);

        $linkShareMapper->expects($this->never())->method('insert');
        $secretMapper->expects($this->never())->method('findByOwner');
        $suiteMapper->expects($this->never())->method('findActiveByOwner');

        $step = new SeedDevelopmentLinkShares(
            linkShareMapper: $linkShareMapper,
            secretMapper: $secretMapper,
            suiteMapper: $suiteMapper,
            config: $config,
            appConfig: $this->unseededAppConfig(),
            logger: $this->createMock(LoggerInterface::class),
        );

        $step->run($this->createMock(IOutput::class));
    }//end testNoOpWhenDebugDisabled()

    /**
     * Missing dev EncryptionSuite → no insert.
     *
     * @return void
     */
    public function testNoOpWhenSuiteMissing(): void
    {
        $config = $this->createMock(IConfig::class);
        $config->method('getSystemValueBool')->willReturn(true);

        $suiteMapper = $this->createMock(EncryptionSuiteMapper::class);
        $suiteMapper->method('findActiveByOwner')->willThrowException(new DoesNotExistException('none'));

        $linkShareMapper = $this->createMock(LinkShareMapper::class);
        $linkShareMapper->expects($this->never())->method('insert');

        $step = new SeedDevelopmentLinkShares(
            linkShareMapper: $linkShareMapper,
            secretMapper: $this->createMock(SecretMapper::class),
            suiteMapper: $suiteMapper,
            config: $config,
            appConfig: $this->unseededAppConfig(),
            logger: $this->createMock(LoggerInterface::class),
        );

        $step->run($this->createMock(IOutput::class));
    }//end testNoOpWhenSuiteMissing()

    /**
     * No dev secrets → no insert.
     *
     * @return void
     */
    public function testNoOpWhenNoDevSecrets(): void
    {
        $config = $this->createMock(IConfig::class);
        $config->method('getSystemValueBool')->willReturn(true);

        $suiteMapper = $this->createMock(EncryptionSuiteMapper::class);
        $suiteMapper->method('findActiveByOwner')->willReturn($this->devSuite('suite-1'));

        $secretMapper = $this->createMock(SecretMapper::class);
        $secretMapper->method('findByOwner')->willReturn([]);

        $linkShareMapper = $this->createMock(LinkShareMapper::class);
        $linkShareMapper->expects($this->never())->method('insert');

        $step = new SeedDevelopmentLinkShares(
            linkShareMapper: $linkShareMapper,
            secretMapper: $secretMapper,
            suiteMapper: $suiteMapper,
            config: $config,
            appConfig: $this->unseededAppConfig(),
            logger: $this->createMock(LoggerInterface::class),
        );

        $step->run($this->createMock(IOutput::class));
    }//end testNoOpWhenNoDevSecrets()

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
            ->willReturnCallback(
                    static function (string $app, string $key, string $default='') {
                        if ($key === 'installed_version') {
                            return '1.2.3';
                        }

                        if ($key === 'dev_seed_link_shares_version') {
                            return '1.2.3';
                        }

                        return $default;
                    }
                    );

        $suiteMapper = $this->createMock(EncryptionSuiteMapper::class);
        $suiteMapper->expects($this->never())->method('findActiveByOwner');

        $linkShareMapper = $this->createMock(LinkShareMapper::class);
        $linkShareMapper->expects($this->never())->method('insert');

        $step = new SeedDevelopmentLinkShares(
            linkShareMapper: $linkShareMapper,
            secretMapper: $this->createMock(SecretMapper::class),
            suiteMapper: $suiteMapper,
            config: $config,
            appConfig: $appConfig,
            logger: $this->createMock(LoggerInterface::class),
        );

        $step->run($this->createMock(IOutput::class));
    }//end testNoOpWhenVersionMarkerMatchesInstalledVersion()

    /**
     * Pre-existing row for a deterministic ID → per-row idempotency no-op.
     *
     * @return void
     */
    public function testIdempotentWhenAlreadySeeded(): void
    {
        $config = $this->createMock(IConfig::class);
        $config->method('getSystemValueBool')->willReturn(true);

        $suiteMapper = $this->createMock(EncryptionSuiteMapper::class);
        $suiteMapper->method('findActiveByOwner')->willReturn($this->devSuite('suite-1'));

        $secretMapper = $this->createMock(SecretMapper::class);
        $secretMapper->method('findByOwner')->willReturn([$this->devSecret('secret-1')]);

        $existing = new LinkShare();
        $existing->setId('existing-link');
        $existing->setSecretId('secret-1');

        $linkShareMapper = $this->createMock(LinkShareMapper::class);
        $linkShareMapper->method('findById')->willReturn($existing);
        $linkShareMapper->expects($this->never())->method('insert');

        $step = new SeedDevelopmentLinkShares(
            linkShareMapper: $linkShareMapper,
            secretMapper: $secretMapper,
            suiteMapper: $suiteMapper,
            config: $config,
            appConfig: $this->unseededAppConfig(),
            logger: $this->createMock(LoggerInterface::class),
        );

        $step->run($this->createMock(IOutput::class));
    }//end testIdempotentWhenAlreadySeeded()

    /**
     * Happy path: debug=true + suite + 3 dev secrets → 3 LinkShare inserts
     * with the documented single-use, multi-use, expired shapes.
     *
     * @return void
     */
    public function testSeedsThreeLinkSharesOnHappyPath(): void
    {
        $config = $this->createMock(IConfig::class);
        $config->method('getSystemValueBool')->willReturn(true);

        $suiteMapper = $this->createMock(EncryptionSuiteMapper::class);
        $suiteMapper->method('findActiveByOwner')->willReturn($this->devSuite('suite-1'));

        $secretMapper = $this->createMock(SecretMapper::class);
        $secretMapper->method('findByOwner')->willReturn(
                [
                    $this->devSecret('secret-1'),
                    $this->devSecret('secret-2'),
                    $this->devSecret('secret-3'),
                ]
                );

        $linkShareMapper = $this->createMock(LinkShareMapper::class);
        $linkShareMapper->method('findById')->willThrowException(new DoesNotExistException('not seeded'));

        /*
         * @var list<LinkShare> $inserted
         */
        $inserted = [];
        $linkShareMapper->expects($this->exactly(3))
            ->method('insert')
            ->willReturnCallback(
                    function (LinkShare $linkShare) use (&$inserted): LinkShare {
                        $inserted[] = $linkShare;
                        return $linkShare;
                    }
                    );

        $step = new SeedDevelopmentLinkShares(
            linkShareMapper: $linkShareMapper,
            secretMapper: $secretMapper,
            suiteMapper: $suiteMapper,
            config: $config,
            appConfig: $this->unseededAppConfig(),
            logger: $this->createMock(LoggerInterface::class),
        );

        $step->run($this->createMock(IOutput::class));

        $this->assertCount(3, $inserted);

        // Single-use link, secret 1, future expiry.
        $this->assertSame('secret-1', $inserted[0]->getSecretId());
        $this->assertSame(1, $inserted[0]->getUsageLimit());
        $this->assertSame(0, $inserted[0]->getUsageCount());
        $this->assertNotNull($inserted[0]->getExpiresAt());
        $this->assertGreaterThan(new \DateTime(), $inserted[0]->getExpiresAt());

        // Multi-use link, secret 2, one access recorded.
        $this->assertSame('secret-2', $inserted[1]->getSecretId());
        $this->assertSame(5, $inserted[1]->getUsageLimit());
        $this->assertSame(1, $inserted[1]->getUsageCount());

        // Expired single-use link, secret 3.
        $this->assertSame('secret-3', $inserted[2]->getSecretId());
        $this->assertSame(1, $inserted[2]->getUsageLimit());
        $this->assertLessThan(new \DateTime(), $inserted[2]->getExpiresAt());

        // Every row carries the dev creator + non-empty placeholder blobs +
        // a unique token so the unique index does not collide.
        $tokens = [];
        foreach ($inserted as $row) {
            $this->assertSame('admin', $row->getCreatedBy());
            $this->assertSame('suite-1', $row->getEncryptionSuiteId());
            $this->assertNotSame('', $row->getEncryptedSecretSnapshot());
            $this->assertNotSame('', $row->getArgon2idSalt());
            $this->assertNotSame('', $row->getToken());
            $tokens[] = $row->getToken();
        }

        $this->assertCount(3, array_unique($tokens));
    }//end testSeedsThreeLinkSharesOnHappyPath()
}//end class
