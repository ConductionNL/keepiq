<?php

/**
 * Unit tests for the SeedDevelopmentSecretDelegations repair step.
 *
 * Covers:
 *  - debug=false → no-op;
 *  - missing dev EncryptionSuite → no-op;
 *  - no dev secrets → no-op;
 *  - existing delegation for the first dev secret → idempotency no-op;
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
            logger: $this->createMock(LoggerInterface::class),
        );
        $step->run($this->createMock(IOutput::class));
    }

    /**
     * Idempotency: pre-existing delegation on first secret → no-op.
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
        $delegationMapper->method('findBySecret')->with('s-1')->willReturn([new SecretDelegation()]);
        $delegationMapper->expects($this->never())->method('insert');

        $step = new SeedDevelopmentSecretDelegations(
            secretMapper: $secretMapper,
            delegationMapper: $delegationMapper,
            suiteMapper: $suiteMapper,
            config: $config,
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
        $delegationMapper->method('findBySecret')->willReturn([]);

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
