<?php

/**
 * Unit tests for SecretVersionService (secret-version-history §8).
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
use OCA\Doriath\Db\EncryptionSuite;
use OCA\Doriath\Db\EncryptionSuiteMapper;
use OCA\Doriath\Db\Secret;
use OCA\Doriath\Db\SecretMapper;
use OCA\Doriath\Db\SecretVersion;
use OCA\Doriath\Db\SecretVersionMapper;
use OCA\Doriath\Service\SecretVersionAccessGuard;
use OCA\Doriath\Service\SecretVersionService;
use OCP\AppFramework\Db\DoesNotExistException;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

/**
 * Tests for SecretVersionService.
 */
class SecretVersionServiceTest extends TestCase
{
    private SecretVersionService $service;

    private SecretVersionMapper&MockObject $mapper;

    private SecretMapper&MockObject $secretMapper;

    private EncryptionSuiteMapper&MockObject $suiteMapper;

    /**
     * Build the service with fresh mocks.
     *
     * @return void
     */
    protected function setUp(): void
    {
        parent::setUp();
        $this->mapper       = $this->createMock(originalClassName: SecretVersionMapper::class);
        $this->secretMapper = $this->createMock(originalClassName: SecretMapper::class);
        $this->suiteMapper  = $this->createMock(originalClassName: EncryptionSuiteMapper::class);

        $this->service = new SecretVersionService(
            mapper: $this->mapper,
            secretMapper: $this->secretMapper,
            accessGuard: new SecretVersionAccessGuard(
                mapper: $this->mapper,
                secretMapper: $this->secretMapper,
                suiteMapper: $this->suiteMapper,
            ),
            logger: $this->createMock(originalClassName: LoggerInterface::class),
            eventDispatcher: null,
        );
    }//end setUp()

    /**
     * Build an owned Secret with ciphertext fields.
     *
     * @param string $ownerId The owner
     *
     * @return Secret
     */
    private function ownedSecret(string $ownerId='alice'): Secret
    {
        $secret = new Secret();
        $secret->setId('sec-1');
        $secret->setName('Wiki');
        $secret->setUrl('https://wiki.example.com');
        $secret->setKey('HEAD_CIPHERTEXT');
        $secret->setLogin('HEAD_LOGIN_CIPHERTEXT');
        $secret->setEncryptionSuiteId('suite-1');
        $secret->setOwnerType('user');
        $secret->setOwnerId($ownerId);
        return $secret;
    }//end ownedSecret()

    /**
     * snapshot copies the pre-update ciphertext verbatim with the next
     * version number — no decryption anywhere.
     *
     * @return void
     */
    public function testSnapshotCopiesCiphertextVerbatim(): void
    {
        $this->mapper->method('nextVersionNumber')->willReturn(3);
        $inserted = null;
        $this->mapper->method('insert')->willReturnCallback(
            static function (SecretVersion $version) use (&$inserted) {
                $inserted = $version;
                return $version;
            }
        );

        $version = $this->service->snapshot(preUpdate: $this->ownedSecret(), actorType: 'user', actorId: 'alice');

        $this->assertSame(3, $version->getVersionNumber());
        $this->assertSame('HEAD_CIPHERTEXT', $inserted->getKey());
        $this->assertSame('HEAD_LOGIN_CIPHERTEXT', $inserted->getLogin());
        $this->assertSame('suite-1', $inserted->getEncryptionSuiteId());
        $this->assertSame('user', $inserted->getActorType());
    }//end testSnapshotCopiesCiphertextVerbatim()

    /**
     * list is owner-only and yields an empty list for inaccessible
     * secrets (no existence oracle).
     *
     * @return void
     */
    public function testListOwnerOnlyNoOracle(): void
    {
        $this->secretMapper->method('findById')->willReturn($this->ownedSecret(ownerId: 'alice'));
        $this->mapper->method('findBySecret')->willReturn([new SecretVersion()]);

        $this->assertCount(1, $this->service->list(secretId: 'sec-1', userId: 'alice'));
        $this->assertSame([], $this->service->list(secretId: 'sec-1', userId: 'mallory'));
    }//end testListOwnerOnlyNoOracle()

    /**
     * A version wrapped by a revoked suite is refused (matching head-read).
     *
     * @return void
     */
    public function testRevokedSuiteVersionReadRefused(): void
    {
        $version = new SecretVersion();
        $version->setId('v-1');
        $version->setSecretId('sec-1');
        $version->setEncryptionSuiteId('suite-old');
        $this->mapper->method('findById')->willReturn($version);
        $this->secretMapper->method('findById')->willReturn($this->ownedSecret());

        $suite = new EncryptionSuite();
        $suite->setId('suite-old');
        $suite->setStatus('revoked');
        $this->suiteMapper->method('findById')->willReturn($suite);

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessageMatches('/locked/');
        $this->service->getVersion(versionId: 'v-1', userId: 'alice');
    }//end testRevokedSuiteVersionReadRefused()

    /**
     * restore snapshots the current head FIRST, then sets the head's
     * fields to the version's stored ciphertext.
     *
     * @return void
     */
    public function testRestoreSnapshotsHeadThenSetsFields(): void
    {
        $version = new SecretVersion();
        $version->setId('v-1');
        $version->setSecretId('sec-1');
        $version->setVersionNumber(2);
        $version->setName('Old wiki');
        $version->setKey('OLD_CIPHERTEXT');
        $version->setEncryptionSuiteId('suite-1');
        $this->mapper->method('findById')->willReturn($version);

        $suite = new EncryptionSuite();
        $suite->setId('suite-1');
        $suite->setStatus('active');
        $this->suiteMapper->method('findById')->willReturn($suite);

        $head = $this->ownedSecret();
        $this->secretMapper->method('findById')->willReturn($head);

        $snapshots = [];
        $this->mapper->method('nextVersionNumber')->willReturn(5);
        $this->mapper->method('insert')->willReturnCallback(
            static function (SecretVersion $snap) use (&$snapshots) {
                $snapshots[] = $snap;
                return $snap;
            }
        );
        $updated = null;
        $this->secretMapper->method('update')->willReturnCallback(
            static function (Secret $secret) use (&$updated) {
                $updated = $secret;
                return $secret;
            }
        );

        $result = $this->service->restore(versionId: 'v-1', userId: 'alice');

        // The head's PRE-restore ciphertext was snapshotted first.
        $this->assertCount(1, $snapshots);
        $this->assertSame('HEAD_CIPHERTEXT', $snapshots[0]->getKey());
        // The head now carries the version's stored ciphertext.
        $this->assertSame('OLD_CIPHERTEXT', $updated->getKey());
        $this->assertSame('Old wiki', $result->getName());
    }//end testRestoreSnapshotsHeadThenSetsFields()

    /**
     * deleteForSecret cascades to the mapper (idempotent by contract).
     *
     * @return void
     */
    public function testDeleteForSecretCascades(): void
    {
        $this->mapper->expects($this->once())->method('deleteBySecret')->with(secretId: 'sec-1');
        $this->service->deleteForSecret(secretId: 'sec-1');
    }//end testDeleteForSecretCascades()

    /**
     * A version of a foreign or missing secret is indistinguishable from
     * a missing version.
     *
     * @return void
     */
    public function testForeignVersionNotFound(): void
    {
        $version = new SecretVersion();
        $version->setId('v-1');
        $version->setSecretId('sec-1');
        $this->mapper->method('findById')->willReturn($version);
        $this->secretMapper->method('findById')->willThrowException(new DoesNotExistException(''));

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Version not found');
        $this->service->getVersion(versionId: 'v-1', userId: 'alice');
    }//end testForeignVersionNotFound()
}//end class
