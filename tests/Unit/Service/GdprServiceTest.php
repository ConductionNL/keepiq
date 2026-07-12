<?php

/**
 * Unit tests for GdprService.
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

use OCA\Doriath\Db\EncryptionSuite;
use OCA\Doriath\Db\EncryptionSuiteMapper;
use OCA\Doriath\Db\LinkShareMapper;
use OCA\Doriath\Db\Secret;
use OCA\Doriath\Db\SecretDelegationMapper;
use OCA\Doriath\Db\SecretMapper;
use OCA\Doriath\Db\SecretRequestMapper;
use OCA\Doriath\Db\ShareTargetMapper;
use OCA\Doriath\Service\GdprService;
use OCA\Doriath\Service\SettingsService;
use PHPUnit\Framework\TestCase;

/**
 * Tests for GdprService::collectMetadata.
 */
class GdprServiceTest extends TestCase
{
    /**
     * Build a service with all collaborator mocks.
     *
     * @return array{0:GdprService,1:SecretMapper,2:EncryptionSuiteMapper,3:SettingsService}
     */
    private function build(): array
    {
        $secretMapper     = $this->createMock(SecretMapper::class);
        $shareMapper      = $this->createMock(ShareTargetMapper::class);
        $delegationMapper = $this->createMock(SecretDelegationMapper::class);
        $linkShareMapper  = $this->createMock(LinkShareMapper::class);
        $requestMapper    = $this->createMock(SecretRequestMapper::class);
        $suiteMapper      = $this->createMock(EncryptionSuiteMapper::class);
        $settingsService  = $this->createMock(SettingsService::class);

        $service = new GdprService(
            secretMapper: $secretMapper,
            shareMapper: $shareMapper,
            delegationMapper: $delegationMapper,
            linkShareMapper: $linkShareMapper,
            requestMapper: $requestMapper,
            suiteMapper: $suiteMapper,
            settingsService: $settingsService,
        );

        return [$service, $secretMapper, $suiteMapper, $settingsService];
    }

    /**
     * collectMetadata returns every documented section and is versioned.
     *
     * @return void
     */
    public function testCollectMetadataHasAllSections(): void
    {
        [$service, $secretMapper, $suiteMapper, $settingsService] = $this->build();
        $secretMapper->method('findByOwner')->willReturn([]);
        $suiteMapper->method('findByOwner')->willReturn([]);
        $settingsService->method('getUserPreferences')->willReturn(['default_view' => 'list']);

        $doc = $service->collectMetadata('alice');

        $this->assertSame('doriath-gdpr-metadata', $doc['format']);
        $this->assertSame(1, $doc['version']);
        $this->assertSame('alice', $doc['subject']);
        foreach (['suites', 'sharesGiven', 'sharesReceived', 'delegations', 'linkShares', 'requests', 'settings'] as $section) {
            $this->assertArrayHasKey($section, $doc);
        }
        $this->assertSame(['default_view' => 'list'], $doc['settings']);
    }

    /**
     * Suite records exclude the encrypted private-key blob and carry the
     * exclusion note.
     *
     * @return void
     */
    public function testSuitePrivateKeyExcludedWithNote(): void
    {
        [$service, $secretMapper, $suiteMapper, $settingsService] = $this->build();
        $secretMapper->method('findByOwner')->willReturn([]);
        $settingsService->method('getUserPreferences')->willReturn([]);

        $suite = new EncryptionSuite();
        $suite->setId('suite-1');
        $suite->setOwnerType('user');
        $suite->setOwnerId('alice');
        $suite->setStatus('active');
        $suite->setCertificate('CERT-PEM');
        $suite->setPrivateKey('SUPER-SECRET-ENCRYPTED-KEY');
        $suiteMapper->method('findByOwner')->willReturn([$suite]);

        $doc = $service->collectMetadata('alice');

        $this->assertCount(1, $doc['suites']);
        $this->assertArrayNotHasKey('privateKey', $doc['suites'][0]);
        $this->assertSame('CERT-PEM', $doc['suites'][0]['certificate']);
        // The exclusion is documented inside the package.
        $this->assertArrayHasKey('privateKeyExcluded', $doc['notes']);
        // Defensive: the blob value never appears anywhere in the document.
        $this->assertStringNotContainsString('SUPER-SECRET-ENCRYPTED-KEY', json_encode($doc));
    }

    /**
     * The collector is strictly self-scoped: the userId drives every lookup.
     *
     * @return void
     */
    public function testStrictlySelfScoped(): void
    {
        [$service, $secretMapper, $suiteMapper, $settingsService] = $this->build();
        $secretMapper->expects($this->once())
            ->method('findByOwner')
            ->with('user', 'alice', null, null, 'asc', 100000, 0)
            ->willReturn([]);
        $suiteMapper->expects($this->once())
            ->method('findByOwner')
            ->with('user', 'alice')
            ->willReturn([]);
        $settingsService->expects($this->once())
            ->method('getUserPreferences')
            ->with('alice')
            ->willReturn([]);

        $service->collectMetadata('alice');
    }
}//end class
