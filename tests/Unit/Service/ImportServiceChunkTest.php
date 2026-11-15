<?php

/**
 * One mixed import chunk, pinned call by call.
 *
 * @category Test
 * @package  OCA\Keepiq\Tests\Unit\Service
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

namespace OCA\Keepiq\Tests\Unit\Service;

use OCA\Keepiq\Db\Folder;
use OCA\Keepiq\Db\Secret;
use OCA\Keepiq\Service\FolderService;
use OCA\Keepiq\Service\ImportService;
use OCA\Keepiq\Service\SecretService;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use RuntimeException;

/**
 * The folder lookups, creates, secret creates, log line and result of one chunk.
 */
class ImportServiceChunkTest extends TestCase {

	private const CIPHER = 'QUJDREVGR0hJSktMTU5PUFFSU1RVVldYWVowMTIzNDU2Nzg5';

	/**
	 * Every item of the chunk is committed or failed in order, folders are
	 * found, created and cached per path, and the chunk is logged.
	 *
	 * @return void
	 */
	public function testMixedChunkIsCommittedInOrder(): void {
		$calls = [];

		$work = new Folder();
		$work->setId('f-work');
		$work->setName('Work');
		$work->setParentId(null);

		$created = 0;
		$folders = $this->createMock(FolderService::class);
		$folders->method('listForUser')->willReturnCallback(static function (string $userId) use (&$calls, $work): array {
			$calls[] = ['listForUser', $userId];
			return [$work];
		});
		$folders->method('create')->willReturnCallback(static function (string $name, ?string $parentId, string $userId) use (&$calls, &$created): Folder {
			$calls[] = ['createFolder', $name, $parentId, $userId];
			$folder = new Folder();
			$folder->setId('f-new-' . (++$created));
			return $folder;
		});

		$secrets = $this->createMock(SecretService::class);
		$secrets->method('assertActiveSuite')->willReturnCallback(static function (string $userId) use (&$calls): void {
			$calls[] = ['assertActiveSuite', $userId];
		});
		$secrets->method('create')->willReturnCallback(static function (array $data, string $userId) use (&$calls): Secret {
			$calls[] = ['createSecret', $data, $userId];
			if ($data['name'] === 'Explodes') {
				throw new RuntimeException('database said no');
			}

			$secret = new Secret();
			$secret->setId('s-' . $data['name']);
			return $secret;
		});

		$logger = $this->createMock(LoggerInterface::class);
		$logger->method('debug')->willReturnCallback(static function (string $message) use (&$calls): void {
			$calls[] = ['debug', $message];
		});

		$items = [
			['name' => 'Root', 'key' => self::CIPHER],
			['name' => 'Deploy', 'key' => self::CIPHER, 'login' => self::CIPHER, 'url' => 'https://ci', 'folderPath' => [' Work ', '', 'CI']],
			['name' => '', 'key' => self::CIPHER, 'folderPath' => ['Never']],
			['name' => 'Again', 'key' => self::CIPHER, 'folderPath' => ['Work', 'CI'], 'typeId' => 'type-1', 'totp' => '', 'passkeys' => null],
			['name' => 'Explodes', 'key' => self::CIPHER, 'folderPath' => ['Home']],
			['name' => 'Fresh', 'key' => self::CIPHER, 'additionalFields' => self::CIPHER, 'folderPath' => ['Home', 'Kids']],
		];

		$service = new ImportService(secretService: $secrets, folderService: $folders, logger: $logger);
		$result = $service->commitChunk($items, 'alice');

		$this->assertSame(
			[
				'results' => [
					['index' => 0, 'status' => 'created', 'secretId' => 's-Root'],
					['index' => 1, 'status' => 'created', 'secretId' => 's-Deploy'],
					['index' => 2, 'status' => 'failed', 'error' => 'Missing name'],
					['index' => 3, 'status' => 'created', 'secretId' => 's-Again'],
					['index' => 4, 'status' => 'failed', 'error' => 'database said no'],
					['index' => 5, 'status' => 'created', 'secretId' => 's-Fresh'],
				],
				'foldersCreated' => ['Work/CI', 'Home', 'Home/Kids'],
			],
			$result
		);

		$row = static fn (string $name, ?string $folderId, array $extra = []): array => array_merge(
			['name' => $name, 'key' => self::CIPHER, 'url' => null, 'folderId' => $folderId, 'login' => null, 'additionalFields' => null, 'typeId' => null, 'totp' => null, 'passkeys' => null],
			$extra
		);
		$this->assertSame(
			[
				['assertActiveSuite', 'alice'],
				['createSecret', $row('Root', null), 'alice'],
				['listForUser', 'alice'],
				['listForUser', 'alice'],
				['createFolder', 'CI', 'f-work', 'alice'],
				['createSecret', $row('Deploy', 'f-new-1', ['url' => 'https://ci', 'login' => self::CIPHER]), 'alice'],
				['createSecret', $row('Again', 'f-new-1', ['typeId' => 'type-1', 'totp' => '']), 'alice'],
				['listForUser', 'alice'],
				['createFolder', 'Home', null, 'alice'],
				['createSecret', $row('Explodes', 'f-new-2'), 'alice'],
				['listForUser', 'alice'],
				['createFolder', 'Kids', 'f-new-2', 'alice'],
				['createSecret', $row('Fresh', 'f-new-3', ['additionalFields' => self::CIPHER]), 'alice'],
				['debug', 'Keepiq: import chunk committed 4 of 6 items for user alice'],
			],
			$calls
		);
	}//end testMixedChunkIsCommittedInOrder()
}//end class
