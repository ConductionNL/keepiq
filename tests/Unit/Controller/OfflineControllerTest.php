<?php

/**
 * Unit tests for OfflineController (offline-readonly-cache §5.1).
 *
 * @category Test
 * @package  OCA\Doriath\Tests\Unit\Controller
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

namespace OCA\Doriath\Tests\Unit\Controller;

use OCA\Doriath\Controller\OfflineController;
use OCA\Doriath\Db\EncryptionSuite;
use OCA\Doriath\Db\EncryptionSuiteMapper;
use OCA\Doriath\Db\Folder;
use OCA\Doriath\Db\FolderMapper;
use OCA\Doriath\Db\Secret;
use OCA\Doriath\Db\SecretMapper;
use OCA\Doriath\Db\SecretTypeMapper;
use OCP\AppFramework\Http;
use OCP\IAppConfig;
use OCP\IRequest;
use OCP\IUser;
use OCP\IUserSession;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

/**
 * Tests for the offline-cache manifest endpoint.
 */
class OfflineControllerTest extends TestCase
{
    private OfflineController $controller;

    private EncryptionSuiteMapper&MockObject $suiteMapper;

    private SecretMapper&MockObject $secretMapper;

    private FolderMapper&MockObject $folderMapper;

    private SecretTypeMapper&MockObject $typeMapper;

    private IAppConfig&MockObject $appConfig;

    private IUserSession&MockObject $userSession;

    /**
     * Build the controller over mocked mappers.
     *
     * @return void
     */
    protected function setUp(): void
    {
        parent::setUp();
        $this->suiteMapper  = $this->createMock(originalClassName: EncryptionSuiteMapper::class);
        $this->secretMapper = $this->createMock(originalClassName: SecretMapper::class);
        $this->folderMapper = $this->createMock(originalClassName: FolderMapper::class);
        $this->typeMapper   = $this->createMock(originalClassName: SecretTypeMapper::class);
        $this->appConfig    = $this->createMock(originalClassName: IAppConfig::class);
        $this->userSession  = $this->createMock(originalClassName: IUserSession::class);

        $user = $this->createMock(originalClassName: IUser::class);
        $user->method('getUID')->willReturn('alice');
        $this->userSession->method('getUser')->willReturn($user);

        $this->controller = new OfflineController(
            request: $this->createMock(originalClassName: IRequest::class),
            suiteMapper: $this->suiteMapper,
            secretMapper: $this->secretMapper,
            folderMapper: $this->folderMapper,
            typeMapper: $this->typeMapper,
            appConfig: $this->appConfig,
            userSession: $this->userSession,
        );
    }//end setUp()

    /**
     * The manifest 403s when the admin org-wide switch is off (§5.1).
     *
     * @return void
     */
    public function testManifestForbiddenWhenDisabled(): void
    {
        $this->appConfig->method('getValueBool')->willReturn(false);
        $this->suiteMapper->expects($this->never())->method('findActiveByOwner');

        $response = $this->controller->manifest();

        $this->assertSame(Http::STATUS_FORBIDDEN, $response->getStatus());
    }//end testManifestForbiddenWhenDisabled()

    /**
     * The manifest is a ciphertext-only owner-scoped snapshot: it reads the
     * caller's active suite + own secrets + own folders and never decrypts
     * (§5.1). The secret ciphertext fields are passed through verbatim.
     *
     * @return void
     */
    public function testManifestReturnsOwnerScopedCiphertextSnapshot(): void
    {
        $this->appConfig->method('getValueBool')->willReturn(true);

        $suite = new EncryptionSuite();
        $suite->setId('suite-1');
        $suite->setOwnerType('user');
        $suite->setOwnerId('alice');
        $suite->setCertificate('CERT-PEM');
        $suite->setPrivateKey('ENVELOPE-BLOB');
        $suite->setStatus('active');
        $this->suiteMapper->expects($this->once())->method('findActiveByOwner')
            ->with('user', 'alice')->willReturn($suite);

        $secret = new Secret();
        $secret->setId('sec-1');
        $secret->setName('Prod key');
        $secret->setOwnerType('user');
        $secret->setOwnerId('alice');
        $secret->setKey('RSA-CIPHERTEXT');
        $this->secretMapper->expects($this->once())->method('findByOwner')
            ->with('user', 'alice')->willReturn([$secret]);

        $folder = new Folder();
        $folder->setId('f-1');
        $folder->setName('Servers');
        $this->folderMapper->expects($this->once())->method('findByOwner')
            ->with('user', 'alice')->willReturn([$folder]);

        $this->typeMapper->expects($this->once())->method('findAvailableForUser')
            ->with('alice')->willReturn([]);

        $data = $this->controller->manifest()->getData();

        $this->assertSame('suite-1', $data['suite']['id']);
        $this->assertSame('ENVELOPE-BLOB', $data['suite']['privateKey']);
        $this->assertCount(1, $data['secrets']);
        $this->assertSame('RSA-CIPHERTEXT', $data['secrets'][0]['key']);
        $this->assertCount(1, $data['folders']);
        $this->assertArrayHasKey('syncedAt', $data);
    }//end testManifestReturnsOwnerScopedCiphertextSnapshot()
}//end class
