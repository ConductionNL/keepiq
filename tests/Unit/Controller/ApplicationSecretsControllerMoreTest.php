<?php

/**
 * Further unit tests for ApplicationSecretsController.
 *
 * Covers the defence-in-depth 401 on every endpoint when no application was
 * attached by the Bearer middleware, the unfiltered list query, the 422 for an
 * unexpected write failure and a folder path that normalises to nothing.
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
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Keepiq\Tests\Unit\Controller;

use OCA\Keepiq\Controller\ApplicationSecretsController;
use OCA\Keepiq\Db\Application;
use OCA\Keepiq\Db\FolderMapper;
use OCA\Keepiq\Db\Secret;
use OCA\Keepiq\Db\SecretMapper;
use OCA\Keepiq\Service\MachineSecretEnvelopeService;
use OCA\Keepiq\Service\MachineSecretResponseService;
use OCA\Keepiq\Service\SecretService;
use OCP\AppFramework\Http;
use OCP\IRequest;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use RuntimeException;

/**
 * Unit tests for the remaining branches of ApplicationSecretsController.
 *
 * @covers \OCA\Keepiq\Controller\ApplicationSecretsController
 * @uses   \OCA\Keepiq\Controller\ApplicationApiController
 * @uses   \OCA\Keepiq\Db\Application
 * @uses   \OCA\Keepiq\Db\Secret
 * @uses   \OCA\Keepiq\Db\StringPrimaryKey
 * @uses   \OCA\Keepiq\Support\MessageResponse
 */
class ApplicationSecretsControllerMoreTest extends TestCase {

	/**
	 * Mocked request.
	 *
	 * @var IRequest&MockObject
	 */
	private IRequest&MockObject $request;

	/**
	 * Mocked secret mapper.
	 *
	 * @var SecretMapper&MockObject
	 */
	private SecretMapper&MockObject $secretMapper;

	/**
	 * Mocked folder mapper.
	 *
	 * @var FolderMapper&MockObject
	 */
	private FolderMapper&MockObject $folderMapper;

	/**
	 * Mocked secret write service.
	 *
	 * @var SecretService&MockObject
	 */
	private SecretService&MockObject $secretService;

	/**
	 * Mocked envelope serializer.
	 *
	 * @var MachineSecretEnvelopeService&MockObject
	 */
	private MachineSecretEnvelopeService&MockObject $envelopeService;

	/**
	 * Mocked single-secret response builder.
	 *
	 * @var MachineSecretResponseService&MockObject
	 */
	private MachineSecretResponseService&MockObject $responseService;

	/**
	 * Request parameters the mocked request answers with.
	 *
	 * @var array<string,mixed>
	 */
	private array $params = [];

	/**
	 * Set up the mocks shared by every test.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		parent::setUp();

		$this->request = $this->createMock(originalClassName: IRequest::class);
		$this->secretMapper = $this->createMock(originalClassName: SecretMapper::class);
		$this->folderMapper = $this->createMock(originalClassName: FolderMapper::class);
		$this->secretService = $this->createMock(originalClassName: SecretService::class);
		$this->envelopeService = $this->createMock(originalClassName: MachineSecretEnvelopeService::class);
		$this->responseService = $this->createMock(originalClassName: MachineSecretResponseService::class);

		$this->request->method('getParam')->willReturnCallback(
			fn (string $key, $default = null) => ($this->params[$key] ?? $default)
		);
		$this->request->method('getHeader')->willReturn('');
	}//end setUp()

	/**
	 * Build the controller, optionally with an authenticated application.
	 *
	 * @param bool $authenticated Whether the middleware attached an application
	 *
	 * @return ApplicationSecretsController
	 */
	private function controller(bool $authenticated = true): ApplicationSecretsController {
		$controller = new ApplicationSecretsController(
			request: $this->request,
			secretMapper: $this->secretMapper,
			folderMapper: $this->folderMapper,
			secretService: $this->secretService,
			envelopeService: $this->envelopeService,
			responseService: $this->responseService,
		);

		if ($authenticated === true) {
			$application = new Application();
			$application->setId('app-1');
			$controller->setApplication($application);
		}

		return $controller;
	}//end controller()

	/**
	 * Every endpoint of the surface, as a call on the controller.
	 *
	 * @return array<string,array{0:callable}>
	 */
	public static function endpointProvider(): array {
		return [
			'index'  => [fn (ApplicationSecretsController $c) => $c->index()],
			'show'   => [fn (ApplicationSecretsController $c) => $c->show('s-1')],
			'byName' => [fn (ApplicationSecretsController $c) => $c->byName('db-password')],
			'create' => [fn (ApplicationSecretsController $c) => $c->create()],
			'update' => [fn (ApplicationSecretsController $c) => $c->update('s-1')],
		];
	}//end endpointProvider()

	/**
	 * Without an application attached, every endpoint answers 401 and touches
	 * no vault data at all.
	 *
	 * @param callable $call The endpoint invocation
	 *
	 * @dataProvider endpointProvider
	 *
	 * @return void
	 */
	public function testEveryEndpointRefusesACallWithoutAnApplication(callable $call): void {
		$this->params = ['updated_since' => '2026-01-01T00:00:00+00:00', 'folder' => 'ops', 'name' => 'x'];

		$this->secretMapper->expects($this->never())->method($this->anything());
		$this->folderMapper->expects($this->never())->method($this->anything());
		$this->secretService->expects($this->never())->method($this->anything());
		$this->responseService->expects($this->never())->method($this->anything());

		$response = $call($this->controller(authenticated: false));

		$this->assertSame(expected: Http::STATUS_UNAUTHORIZED, actual: $response->getStatus());
		$this->assertSame(expected: ['message' => 'Bearer token required'], actual: $response->getData());
	}//end testEveryEndpointRefusesACallWithoutAnApplication()

	/**
	 * An absent or empty `updated_since` lists the application's whole vault
	 * with the unfiltered query, never the since-query.
	 *
	 * @return array<string,array{0:string|null}>
	 */
	public static function noSinceProvider(): array {
		return [
			'absent' => [null],
			'empty'  => [''],
		];
	}//end noSinceProvider()

	/**
	 * List without a lower bound runs the owner query for this application.
	 *
	 * @param string|null $since The raw updated_since parameter
	 *
	 * @dataProvider noSinceProvider
	 *
	 * @return void
	 */
	public function testIndexWithoutUpdatedSinceListsTheWholeOwnedVault(?string $since): void {
		$this->params = ['updated_since' => $since];

		$first = new Secret();
		$first->setId('s-1');
		$second = new Secret();
		$second->setId('s-2');

		$this->secretMapper->expects($this->never())->method('findByOwnerUpdatedSince');
		$this->secretMapper->expects($this->once())
			->method('findByOwner')
			->with('application', 'app-1')
			->willReturn([$first, $second]);
		$this->envelopeService->method('serialize')->willReturnCallback(
			static fn (Secret $secret): array => ['id' => $secret->getId()]
		);

		$response = $this->controller()->index();

		$this->assertSame(expected: Http::STATUS_OK, actual: $response->getStatus());
		$this->assertSame(
			expected: [
				'format' => MachineSecretEnvelopeService::FORMAT,
				'items' => [['id' => 's-1'], ['id' => 's-2']],
				'total' => 2,
			],
			actual: $response->getData()
		);
	}//end testIndexWithoutUpdatedSinceListsTheWholeOwnedVault()

	/**
	 * A write that fails for any reason other than a validation refusal is
	 * reported as 422 with the service's message, and nothing is serialized.
	 *
	 * @return void
	 */
	public function testCreateMapsAnUnexpectedFailureTo422(): void {
		$this->params = ['name' => 'db-password', 'key' => 'CIPHER', 'typeId' => 'api_key'];

		$this->secretService->expects($this->once())
			->method('createByApplication')
			->with(['name' => 'db-password', 'typeId' => 'api_key', 'key' => 'CIPHER'], 'app-1')
			->willThrowException(new RuntimeException('Storage unavailable'));
		$this->envelopeService->expects($this->never())->method('serialize');

		$response = $this->controller()->create();

		$this->assertSame(expected: Http::STATUS_UNPROCESSABLE_ENTITY, actual: $response->getStatus());
		$this->assertSame(expected: ['message' => 'Storage unavailable'], actual: $response->getData());
	}//end testCreateMapsAnUnexpectedFailureTo422()

	/**
	 * A folder path that is only slashes names no folder: the lookup answers
	 * 404 without loading folders or searching secrets.
	 *
	 * @return void
	 */
	public function testByNameWithASlashOnlyFolderPathIsNotFound(): void {
		$this->params = ['folder' => '//'];

		$this->folderMapper->expects($this->never())->method('findByOwner');
		$this->secretMapper->expects($this->never())->method('findByName');

		$response = $this->controller()->byName('db-password');

		$this->assertSame(expected: Http::STATUS_NOT_FOUND, actual: $response->getStatus());
		$this->assertSame(expected: ['message' => 'Secret not found'], actual: $response->getData());
	}//end testByNameWithASlashOnlyFolderPathIsNotFound()
}//end class
