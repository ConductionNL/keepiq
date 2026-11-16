<?php

/**
 * Body-normalisation tests for ApplicationSecretRequestsController.
 *
 * A requestedFields entry that is neither a name nor a `{field}` object, a
 * list of only blank names, and a folderPath made only of separators are the
 * edge cases of the machine create call.
 *
 * @category Test
 * @package  OCA\Keepiq\Tests\Unit\Controller
 *
 * @author    Conduction Development Team <dev@conductio.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git-id>
 *
 * @link https://conduction.nl
 */

declare(strict_types=1);

namespace OCA\Keepiq\Tests\Unit\Controller;

use DateTime;
use OCA\Keepiq\Controller\ApplicationSecretRequestsController;
use OCA\Keepiq\Db\Application;
use OCA\Keepiq\Db\FolderMapper;
use OCA\Keepiq\Db\SecretRequest;
use OCA\Keepiq\Kernel\Contracts\ApplicationSecretRequestServiceInterface;
use OCP\AppFramework\Http;
use OCP\IRequest;
use OCP\IURLGenerator;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

/**
 * @covers \OCA\Keepiq\Controller\ApplicationSecretRequestsController
 * @uses   \OCA\Keepiq\Controller\ApplicationApiController
 * @uses   \OCA\Keepiq\Db\Application
 * @uses   \OCA\Keepiq\Db\SecretRequest
 * @uses   \OCA\Keepiq\Db\StringPrimaryKey
 * @uses   \OCA\Keepiq\Support\MessageResponse
 */
class ApplicationSecretRequestsControllerFieldsTest extends TestCase {

	/**
	 * The mocked machine request service.
	 *
	 * @var ApplicationSecretRequestServiceInterface&MockObject
	 */
	private ApplicationSecretRequestServiceInterface&MockObject $service;

	/**
	 * The mocked folder mapper.
	 *
	 * @var FolderMapper&MockObject
	 */
	private FolderMapper&MockObject $folderMapper;

	/**
	 * The controller under test.
	 *
	 * @var ApplicationSecretRequestsController
	 */
	private ApplicationSecretRequestsController $controller;

	/**
	 * Body parameters of the mocked request.
	 *
	 * @var array<string,mixed>
	 */
	private array $params = [];

	/**
	 * Wire the controller with an authenticated application.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		parent::setUp();
		$request = $this->createMock(IRequest::class);
		$request->method('getParam')->willReturnCallback(
			fn (string $k, $d = null) => ($this->params[$k] ?? $d)
		);
		$this->service = $this->createMock(ApplicationSecretRequestServiceInterface::class);
		$this->folderMapper = $this->createMock(FolderMapper::class);
		$urlGenerator = $this->createMock(IURLGenerator::class);
		$urlGenerator->method('linkToRoute')->willReturn('/route');
		$urlGenerator->method('getAbsoluteURL')->willReturnCallback(
			static fn (string $path): string => 'https://nc.example' . $path
		);

		$this->controller = new ApplicationSecretRequestsController(
			request: $request,
			secretRequestService: $this->service,
			folderMapper: $this->folderMapper,
			urlGenerator: $urlGenerator,
		);

		$app = new Application();
		$app->setId('app-1');
		$this->controller->setApplication($app);
	}//end setUp()

	/**
	 * An entry that is neither a string nor an object with a string `field` is a 400 and nothing is created.
	 *
	 * @return void
	 */
	public function testAMalformedRequestedFieldsEntryIs400(): void {
		$this->params = ['requestedFields' => ['key', ['visibility' => 'secret']]];
		$this->service->expects($this->never())->method('createForApplicationVault');

		$response = $this->controller->create();

		$this->assertSame(Http::STATUS_BAD_REQUEST, $response->getStatus());
		$this->assertSame(
			['message' => 'Each requestedFields entry must be a name or an object carrying "field"'],
			$response->getData()
		);
	}//end testAMalformedRequestedFieldsEntryIs400()

	/**
	 * A numeric entry is malformed too.
	 *
	 * @return void
	 */
	public function testANumericRequestedFieldsEntryIs400(): void {
		$this->params = ['requestedFields' => [42]];
		$this->service->expects($this->never())->method('createForApplicationVault');

		$this->assertSame(Http::STATUS_BAD_REQUEST, $this->controller->create()->getStatus());
	}//end testANumericRequestedFieldsEntryIs400()

	/**
	 * A list of only blank names names no field and is a 400.
	 *
	 * @return void
	 */
	public function testOnlyBlankNamesIs400(): void {
		$this->params = ['requestedFields' => ['', '   ', ['field' => ' ']]];
		$this->service->expects($this->never())->method('createForApplicationVault');

		$response = $this->controller->create();

		$this->assertSame(Http::STATUS_BAD_REQUEST, $response->getStatus());
		$this->assertSame(['message' => 'requestedFields must name at least one field'], $response->getData());
	}//end testOnlyBlankNamesIs400()

	/**
	 * Duplicate and blank names are dropped and the rest passed on in order.
	 *
	 * @return void
	 */
	public function testNamesAreDeduplicatedAndBlanksDropped(): void {
		$this->params = ['requestedFields' => ['key', '', ['field' => 'url'], 'key']];
		$this->service->expects($this->once())
			->method('createForApplicationVault')
			->with('app-1', ['key', 'url'], null, null, null)
			->willReturn($this->pending());

		$this->assertSame(Http::STATUS_CREATED, $this->controller->create()->getStatus());
	}//end testNamesAreDeduplicatedAndBlanksDropped()

	/**
	 * A folderPath of only separators resolves to no folder, files at the
	 * root, and never walks the folder tree.
	 *
	 * @return void
	 */
	public function testAFolderPathOfOnlySeparatorsMeansNoFolder(): void {
		$this->params = ['requestedFields' => ['key'], 'folderPath' => ' / / ', 'name' => '  Mail relay  '];
		$this->folderMapper->expects($this->never())->method('findRootFolders');
		$this->service->expects($this->once())
			->method('createForApplicationVault')
			->with('app-1', ['key'], 'Mail relay', null, null)
			->willReturn($this->pending());

		$response = $this->controller->create();

		$this->assertSame(Http::STATUS_CREATED, $response->getStatus());
		$this->assertSame('tok-abc', $response->getData()['token']);
	}//end testAFolderPathOfOnlySeparatorsMeansNoFolder()

	/**
	 * A pending request row.
	 *
	 * @return SecretRequest
	 */
	private function pending(): SecretRequest {
		$entity = new SecretRequest();
		$entity->setId('req-1');
		$entity->setSecretId('sec-1');
		$entity->setRequestedFields((string)json_encode(['key']));
		$entity->setStatus(SecretRequest::STATUS_PENDING);
		$entity->setToken('tok-abc');
		$entity->setCreatedAt(new DateTime('2026-08-14T10:00:00+00:00'));
		return $entity;
	}//end pending()
}//end class
