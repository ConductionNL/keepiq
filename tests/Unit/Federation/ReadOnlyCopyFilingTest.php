<?php

/**
 * A recipient may file a read-only copy from another organisation in one of
 * their folders (sharing-federated-recipients task 3.5, decision of 4 Oct
 * 2026): PUT /api/v1/secrets/{id} with only `folderId` is accepted; the
 * value, login, fields, name, URL and type stay refused.
 *
 * @category Test
 * @package  OCA\Keepiq\Tests\Unit\Federation
 *
 * @author    Conduction Development Team <dev@conductio.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git-id>
 *
 * @link https://conduction.nl
 *
 * @spec openspec/specs/federated-sharing/spec.md#scenario-bob-files-his-copy-in-a-folder
 */

declare(strict_types=1);

namespace OCA\Keepiq\Tests\Unit\Federation;

use DateTime;
use OCA\Keepiq\Controller\SecretUpdateController;
use OCA\Keepiq\Db\EncryptionSuiteMapper;
use OCA\Keepiq\Db\Folder;
use OCA\Keepiq\Db\Secret;
use OCA\Keepiq\Db\SecretMapper;
use OCA\Keepiq\Service\FolderOwnershipGuard;
use OCA\Keepiq\Service\LinkShareService;
use OCA\Keepiq\Service\MigrationService;
use OCA\Keepiq\Service\SecretService;
use OCA\Keepiq\Service\SecretTypeService;
use OCP\AppFramework\Http;
use OCP\IRequest;
use OCP\IUser;
use OCP\IUserSession;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

class ReadOnlyCopyFilingTest extends TestCase {
	private Secret $copy;

	private int $writes = 0;

	protected function setUp(): void {
		$this->copy = new Secret();
		$this->copy->setId('copy');
		$this->copy->setName('Supplier portal');
		$this->copy->setUrl('https://portal.example');
		$this->copy->setKey('CIPHER-KEY-FOR-BOB');
		$this->copy->setOwnerType('user');
		$this->copy->setOwnerId('bob');
		$this->copy->setFolderId(null);
		$this->copy->setUpdatedAt(new DateTime('2026-10-01T00:00:00Z'));
		$this->copy->setReadOnly(true);
		$this->copy->setFederatedSource('alice@cloud.city.example');
	}

	/**
	 * PUT /api/v1/secrets/copy as Bob, with these request parameters.
	 *
	 * @param array<string,mixed> $params The request body
	 *
	 * @return \OCP\AppFramework\Http\JSONResponse
	 */
	private function put(array $params) {
		$secrets = $this->createMock(SecretMapper::class);
		$secrets->method('findById')->willReturn($this->copy);
		$secrets->method('update')->willReturnCallback(
			function (Secret $secret): Secret {
				$this->writes++;
				return $secret;
			}
		);

		$folders = $this->createMock(FolderOwnershipGuard::class);
		$folders->method('requireOwned')->willReturn(new Folder());

		$service = new SecretService(
			mapper: $secrets,
			typeService: $this->createMock(SecretTypeService::class),
			suiteMapper: $this->createMock(EncryptionSuiteMapper::class),
			migrationService: $this->createMock(MigrationService::class),
			linkShareService: $this->createMock(LinkShareService::class),
			logger: $this->createMock(LoggerInterface::class),
			folderOwnership: $folders,
		);

		$request = $this->createMock(IRequest::class);
		$request->method('getParam')->willReturnCallback(
			static fn (string $name, mixed $default = null): mixed => array_key_exists($name, $params) ? $params[$name] : $default
		);
		$user = $this->createMock(IUser::class);
		$user->method('getUID')->willReturn('bob');
		$session = $this->createMock(IUserSession::class);
		$session->method('getUser')->willReturn($user);

		return (new SecretUpdateController($request, $service, $session))->update('copy');
	}

	public function testBobFilesHisCopyInAFolder(): void {
		$response = $this->put(['folderId' => 'folder-work']);

		$this->assertSame(Http::STATUS_OK, $response->getStatus());
		$this->assertSame('folder-work', $this->copy->getFolderId());
		$this->assertSame(1, $this->writes);
		// Everything else is as the owner sent it.
		$this->assertSame('Supplier portal', $this->copy->getName());
		$this->assertSame('CIPHER-KEY-FOR-BOB', $this->copy->getKey());
		$this->assertTrue($this->copy->getReadOnly());
	}

	public function testBobMovesHisCopyBackToTheTopOfHisVault(): void {
		$this->copy->setFolderId('folder-work');

		$response = $this->put(['folderId' => null]);

		$this->assertSame(Http::STATUS_OK, $response->getStatus());
		$this->assertNull($this->copy->getFolderId());
	}

	/**
	 * @return array<string,array{0:array<string,mixed>}>
	 */
	public static function changesBesidesTheFolder(): array {
		return [
			'name' => [['folderId' => 'folder-work', 'name' => 'Mine now']],
			'url' => [['folderId' => 'folder-work', 'url' => 'https://evil.example']],
			'type' => [['folderId' => 'folder-work', 'typeId' => 'note']],
			'value' => [['folderId' => 'folder-work', 'key' => 'OTHER-CIPHER']],
			'login' => [['folderId' => 'folder-work', 'login' => 'OTHER-CIPHER']],
			'fields' => [['folderId' => 'folder-work', 'additionalFields' => 'OTHER-CIPHER']],
			'value alone' => [['key' => 'OTHER-CIPHER']],
		];
	}

	/**
	 * @dataProvider changesBesidesTheFolder
	 *
	 * @param array<string,mixed> $params The request body
	 */
	public function testAnythingBesidesTheFolderIsStillRefused(array $params): void {
		$response = $this->put($params);

		$this->assertSame(Http::STATUS_FORBIDDEN, $response->getStatus());
		$this->assertSame('A copy from another organisation is read-only', $response->getData()['message']);
		$this->assertSame(0, $this->writes);
		$this->assertNull($this->copy->getFolderId());
		$this->assertSame('CIPHER-KEY-FOR-BOB', $this->copy->getKey());
	}
}
