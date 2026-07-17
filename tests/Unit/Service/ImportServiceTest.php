<?php

/**
 * Unit tests for ImportService.
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

use OCA\Doriath\Db\Folder;
use OCA\Doriath\Db\Secret;
use OCA\Doriath\Exception\SuiteBlockedException;
use OCA\Doriath\Service\FolderService;
use OCA\Doriath\Service\ImportService;
use OCA\Doriath\Service\SecretService;
use Psr\Log\LoggerInterface;
use PHPUnit\Framework\TestCase;

/**
 * Tests for the chunked encrypted-import commit service.
 */
class ImportServiceTest extends TestCase
{
    /**
     * A 32-byte-plus base64-ish ciphertext stand-in (passes the envelope check).
     *
     * @var string
     */
    private const CIPHER = 'QUJDREVGR0hJSktMTU5PUFFSU1RVVldYWVowMTIzNDU2Nzg5';

    /**
     * Build a Secret stub with an id.
     *
     * @param string $id The id
     *
     * @return Secret
     */
    private function secret(string $id): Secret
    {
        $secret = new Secret();
        $secret->setId($id);
        $secret->setName('x');
        return $secret;
    }//end secret()

    /**
     * Build a Folder stub.
     *
     * @param string      $id       The id
     * @param string      $name     The name
     * @param string|null $parentId The parent id
     *
     * @return Folder
     */
    private function folder(string $id, string $name, ?string $parentId): Folder
    {
        $folder = new Folder();
        $folder->setId($id);
        $folder->setName($name);
        $folder->setParentId($parentId);
        $folder->setOwnerType('user');
        $folder->setOwnerId('alice');
        return $folder;
    }//end folder()

    /**
     * A valid encrypted item.
     *
     * @param string      $name   The name
     * @param string|null $folder The folder path '/'-joined, or null
     *
     * @return array<string,mixed>
     */
    private function item(string $name, ?string $folder=null): array
    {
        $item = ['name' => $name, 'key' => self::CIPHER, 'login' => self::CIPHER];
        if ($folder !== null) {
            $item['folderPath'] = explode('/', $folder);
        }

        return $item;
    }//end item()

    /**
     * Commit returns per-index results; one bad item never fails its neighbours.
     *
     * @return void
     */
    public function testPartialFailureReturnsPerIndexResults(): void
    {
        $secretService = $this->createMock(SecretService::class);
        $folderService = $this->createMock(FolderService::class);
        $folderService->method('listForUser')->willReturn([]);

        // The first item creates; the second has a missing name -> rejected.
        $secretService->method('assertActiveSuite');
        $secretService->method('create')->willReturnCallback(
            fn (array $data, string $userId) => $this->secret('s-'.$data['name'])
        );

        $service = new ImportService($secretService, $folderService, $this->createMock(LoggerInterface::class));

        $items   = [$this->item('Good'), ['key' => self::CIPHER, 'name' => '']];
        $result  = $service->commitChunk($items, 'alice');
        $results = $result['results'];

        $this->assertSame('created', $results[0]['status']);
        $this->assertSame('failed', $results[1]['status']);
        $this->assertSame(1, $results[1]['index']);
        $this->assertStringContainsStringIgnoringCase('name', $results[1]['error']);
    }//end testPartialFailureReturnsPerIndexResults()

    /**
     * The owner is the supplied session user; no owner param is honoured.
     *
     * @return void
     */
    public function testOwnerDerivedFromSessionUser(): void
    {
        $secretService = $this->createMock(SecretService::class);
        $folderService = $this->createMock(FolderService::class);
        $folderService->method('listForUser')->willReturn([]);
        $secretService->method('assertActiveSuite');

        $secretService->expects($this->once())
            ->method('create')
            ->with($this->anything(), 'alice')
            ->willReturn($this->secret('s1'));

        $service = new ImportService($secretService, $folderService, $this->createMock(LoggerInterface::class));
        // An attacker-supplied ownerId in the item must be ignored.
        $item            = $this->item('Thing');
        $item['ownerId'] = 'mallory';
        $service->commitChunk([$item], 'alice');
    }//end testOwnerDerivedFromSessionUser()

    /**
     * No active suite blocks the whole chunk (412-equivalent).
     *
     * @return void
     */
    public function testNoActiveSuiteThrows(): void
    {
        $secretService = $this->createMock(SecretService::class);
        $folderService = $this->createMock(FolderService::class);
        $secretService->method('assertActiveSuite')
            ->willThrowException(new SuiteBlockedException('No active encryption suite'));

        $service = new ImportService($secretService, $folderService, $this->createMock(LoggerInterface::class));

        $this->expectException(SuiteBlockedException::class);
        $service->commitChunk([$this->item('X')], 'alice');
    }//end testNoActiveSuiteThrows()

    /**
     * A chunk over the item cap is rejected (413-equivalent).
     *
     * @return void
     */
    public function testChunkOverCapThrows(): void
    {
        $service = new ImportService(
            $this->createMock(SecretService::class),
            $this->createMock(FolderService::class),
            $this->createMock(LoggerInterface::class)
        );

        $items = array_fill(0, (ImportService::MAX_ITEMS + 1), $this->item('X'));
        $this->expectException(\InvalidArgumentException::class);
        $service->commitChunk($items, 'alice');
    }//end testChunkOverCapThrows()

    /**
     * Plaintext-shaped sensitive fields are rejected by the envelope check.
     *
     * @return void
     */
    public function testPlaintextSensitiveFieldRejected(): void
    {
        $secretService = $this->createMock(SecretService::class);
        $folderService = $this->createMock(FolderService::class);
        $folderService->method('listForUser')->willReturn([]);
        $secretService->method('assertActiveSuite');
        $secretService->method('create')->willReturn($this->secret('s'));

        $service = new ImportService($secretService, $folderService, $this->createMock(LoggerInterface::class));

        // 'hunter2' is short/plaintext -> not a ciphertext envelope.
        $result = $service->commitChunk([['name' => 'X', 'key' => 'hunter2']], 'alice');
        $this->assertSame('failed', $result['results'][0]['status']);
        $this->assertStringContainsStringIgnoringCase('ciphertext', $result['results'][0]['error']);
    }//end testPlaintextSensitiveFieldRejected()

    /**
     * Folder paths are ensured idempotently: an existing folder is reused, a
     * nested path is created, and a deeper path created once is cached.
     *
     * @return void
     */
    public function testIdempotentNestedFolderEnsuring(): void
    {
        $secretService = $this->createMock(SecretService::class);
        $folderService = $this->createMock(FolderService::class);
        $secretService->method('assertActiveSuite');
        $secretService->method('create')->willReturn($this->secret('s'));

        // "Work" already exists; "CI" under it must be created exactly once.
        $existingWork = $this->folder('w1', 'Work', null);
        $folderService->method('listForUser')->willReturn([$existingWork]);

        $created = [];
        $folderService->method('create')->willReturnCallback(
            function (string $name, ?string $parentId, string $userId) use (&$created) {
                $created[] = $name;
                return $this->folder('c-'.$name, $name, $parentId);
            }
        );

        $service = new ImportService($secretService, $folderService, $this->createMock(LoggerInterface::class));

        // Two items both in Work/CI: CI created once, Work never (it exists).
        $result = $service->commitChunk(
            [$this->item('A', 'Work/CI'), $this->item('B', 'Work/CI')],
            'alice'
        );

        $this->assertSame(['CI'], $created, 'CI created once; Work reused');
        $this->assertContains('Work/CI', $result['foldersCreated']);
    }//end testIdempotentNestedFolderEnsuring()

    /**
     * A root-level item (no folder path) creates no folders.
     *
     * @return void
     */
    public function testRootLevelItemCreatesNoFolders(): void
    {
        $secretService = $this->createMock(SecretService::class);
        $folderService = $this->createMock(FolderService::class);
        $secretService->method('assertActiveSuite');
        $secretService->method('create')->willReturn($this->secret('s'));
        $folderService->expects($this->never())->method('create');
        $folderService->method('listForUser')->willReturn([]);

        $service = new ImportService($secretService, $folderService, $this->createMock(LoggerInterface::class));
        $result  = $service->commitChunk([$this->item('Root')], 'alice');
        $this->assertSame([], $result['foldersCreated']);
    }//end testRootLevelItemCreatesNoFolders()
}//end class
