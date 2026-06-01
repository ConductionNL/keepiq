<?php

/**
 * Doriath SecretSuiteGuard unit tests.
 *
 * @category Tests
 * @package  OCA\Doriath\Tests\Unit\Service
 *
 * @author    Conduction Development Team <dev@conductio.nl>
 * @copyright 2024 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://conduction.nl
 */

declare(strict_types=1);

namespace OCA\Doriath\Tests\Unit\Service;

use OCA\Doriath\Db\EncryptionSuite;
use OCA\Doriath\Db\EncryptionSuiteMapper;
use OCA\Doriath\Db\Secret;
use OCA\Doriath\Service\SecretSuiteGuard;
use OCP\AppFramework\Db\DoesNotExistException;
use PHPUnit\Framework\TestCase;
use RuntimeException;

class SecretSuiteGuardTest extends TestCase
{
    private EncryptionSuiteMapper $suiteMapper;
    private SecretSuiteGuard $guard;

    protected function setUp(): void
    {
        $this->suiteMapper = $this->createMock(EncryptionSuiteMapper::class);
        $this->guard = new SecretSuiteGuard($this->suiteMapper);
    }

    public function testIsStatusBlocked(): void
    {
        $this->assertTrue($this->guard->isStatusBlocked('revoked'));
        $this->assertTrue($this->guard->isStatusBlocked('compromised'));
        $this->assertFalse($this->guard->isStatusBlocked('active'));
    }

    public function testGetActiveSuiteOrFailThrowsWhenMissing(): void
    {
        $this->suiteMapper->method('findActiveByOwner')
            ->willThrowException(new DoesNotExistException('none'));

        $this->expectException(RuntimeException::class);
        $this->guard->getActiveSuiteOrFail('alice');
    }

    public function testIsSecretBlockedForRevokedSuite(): void
    {
        $suite = new EncryptionSuite();
        $suite->setId('suite-1');
        $suite->setStatus('revoked');
        $this->suiteMapper->method('findById')->willReturn($suite);

        $secret = new Secret();
        $secret->setEncryptionSuiteId('suite-1');

        $this->assertTrue($this->guard->isSecretBlocked($secret));
    }

    public function testIsSecretBlockedForActiveSuite(): void
    {
        $suite = new EncryptionSuite();
        $suite->setId('suite-1');
        $suite->setStatus('active');
        $this->suiteMapper->method('findById')->willReturn($suite);

        $secret = new Secret();
        $secret->setEncryptionSuiteId('suite-1');

        $this->assertFalse($this->guard->isSecretBlocked($secret));
    }

    public function testMissingSuiteCountsAsBlocked(): void
    {
        $this->suiteMapper->method('findById')
            ->willThrowException(new DoesNotExistException('gone'));

        $secret = new Secret();
        $secret->setEncryptionSuiteId('ghost');

        $this->assertTrue($this->guard->isSecretBlocked($secret));
    }
}
