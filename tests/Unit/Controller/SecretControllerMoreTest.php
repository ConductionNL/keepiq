<?php

/**
 * Further unit tests for SecretController: anonymous callers, list argument
 * forwarding and search, and the create-time validation refusals.
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

use InvalidArgumentException;
use OCA\Keepiq\Controller\SecretController;
use OCA\Keepiq\Exception\NotFoundException;
use OCA\Keepiq\Service\SecretService;
use OCP\AppFramework\Http;
use OCP\IRequest;
use OCP\IUser;
use OCP\IUserSession;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

/**
 * Unit tests for the remaining branches of SecretController.
 *
 * @covers \OCA\Keepiq\Controller\SecretController
 */
class SecretControllerMoreTest extends TestCase {

	/**
	 * The mocked secret service.
	 *
	 * @var SecretService&MockObject
	 */
	private SecretService&MockObject $secretService;

	/**
	 * Set up the mocks shared by every test.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		parent::setUp();

		$this->secretService = $this->createMock(originalClassName: SecretService::class);
	}//end setUp()

	/**
	 * Build the controller with the session resolving to the given user.
	 *
	 * @param string|null $userId The session user, or null for an anonymous caller
	 *
	 * @return SecretController
	 */
	private function controller(?string $userId = 'alice'): SecretController {
		$user = null;
		if ($userId !== null) {
			$user = $this->createMock(originalClassName: IUser::class);
			$user->method('getUID')->willReturn($userId);
		}

		$session = $this->createMock(originalClassName: IUserSession::class);
		$session->method('getUser')->willReturn($user);

		return new SecretController(
			request: $this->createMock(originalClassName: IRequest::class),
			secretService: $this->secretService,
			userSession: $session,
		);
	}//end controller()

	/**
	 * The three session-scoped endpoints, as calls on the controller.
	 *
	 * @return array<string,array{0:callable}>
	 */
	public static function endpointProvider(): array {
		return [
			'index'  => [fn (SecretController $c) => $c->index()],
			'show'   => [fn (SecretController $c) => $c->show('s-1')],
			'create' => [fn (SecretController $c) => $c->create(name: 'GitHub', key: 'CIPHER')],
		];
	}//end endpointProvider()

	/**
	 * Without a session user every endpoint answers 401 and the service is
	 * never consulted.
	 *
	 * @param callable $call The endpoint invocation
	 *
	 * @dataProvider endpointProvider
	 *
	 * @return void
	 */
	public function testEveryEndpointRefusesAnAnonymousCaller(callable $call): void {
		$this->secretService->expects($this->never())->method($this->anything());

		$response = $call($this->controller(userId: null));

		$this->assertSame(expected: Http::STATUS_UNAUTHORIZED, actual: $response->getStatus());
		$this->assertSame(expected: ['message' => 'Unauthorized'], actual: $response->getData());
	}//end testEveryEndpointRefusesAnAnonymousCaller()

	/**
	 * index() forwards every filter positionally, turns `favourite=true` into
	 * a boolean, and answers with the service's page.
	 *
	 * @return void
	 */
	public function testIndexForwardsEveryFilterToTheList(): void {
		$page = ['results' => [['id' => 's-1']], 'total' => 1];

		$this->secretService->expects($this->once())
			->method('list')
			->with('alice', 'folder-1', 'name', 'desc', 2, 10, 'login', 'live', true, 'work')
			->willReturn($page);
		$this->secretService->expects($this->never())->method('search');

		$response = $this->controller()->index(
			folderId: 'folder-1',
			search: '   ',
			sort: 'name',
			direction: 'desc',
			page: 2,
			limit: 10,
			typeId: 'login',
			state: 'live',
			favourite: 'TRUE',
			tag: 'work',
		);

		$this->assertSame(expected: Http::STATUS_OK, actual: $response->getStatus());
		$this->assertSame(expected: $page, actual: $response->getData());
	}//end testIndexForwardsEveryFilterToTheList()

	/**
	 * A non-blank search on the live list replaces the page with the search
	 * result for the same user, page and limit.
	 *
	 * @return void
	 */
	public function testIndexWithASearchOnTheLiveListAnswersWithTheSearch(): void {
		$found = ['results' => [['id' => 's-9']], 'total' => 1];

		$this->secretService->expects($this->once())
			->method('list')
			->with('alice', null, null, 'asc', 3, 5, null, 'live', false, null)
			->willReturn(['results' => [], 'total' => 0]);
		$this->secretService->expects($this->once())
			->method('search')
			->with('alice', 'git', 3, 5)
			->willReturn($found);

		$response = $this->controller()->index(search: 'git', page: 3, limit: 5);

		$this->assertSame(expected: Http::STATUS_OK, actual: $response->getStatus());
		$this->assertSame(expected: $found, actual: $response->getData());
	}//end testIndexWithASearchOnTheLiveListAnswersWithTheSearch()

	/**
	 * A search outside the live state (e.g. the trash) is not run: the
	 * state-filtered list is the answer.
	 *
	 * @return void
	 */
	public function testIndexDoesNotSearchOutsideTheLiveList(): void {
		$trash = ['results' => [['id' => 's-3']], 'total' => 1];

		$this->secretService->method('list')->willReturn($trash);
		$this->secretService->expects($this->never())->method('search');

		$response = $this->controller()->index(search: 'git', state: 'trash');

		$this->assertSame(expected: $trash, actual: $response->getData());
	}//end testIndexDoesNotSearchOutsideTheLiveList()

	/**
	 * A list refusal (e.g. an unknown sort column) is a 400 with its message.
	 *
	 * @return void
	 */
	public function testIndexReportsARefusedFilterAsABadRequest(): void {
		$this->secretService->method('list')
			->willThrowException(new InvalidArgumentException('Invalid sort column'));
		$this->secretService->expects($this->never())->method('search');

		$response = $this->controller()->index(sort: 'nope', search: 'git');

		$this->assertSame(expected: Http::STATUS_BAD_REQUEST, actual: $response->getStatus());
		$this->assertSame(expected: ['message' => 'Invalid sort column'], actual: $response->getData());
	}//end testIndexReportsARefusedFilterAsABadRequest()

	/**
	 * Requests missing a required field, each with its refusal message.
	 *
	 * @return array<string,array{0:array<string,string|null>,1:string}>
	 */
	public static function incompleteCreateProvider(): array {
		$missing = 'Missing required parameters: name and key are required';

		return [
			'no name'                    => [['name' => null, 'key' => 'CIPHER'], $missing],
			'empty name'                 => [['name' => '', 'key' => 'CIPHER'], $missing],
			'no key'                     => [['name' => 'GitHub', 'key' => null], $missing],
			'empty key'                  => [['name' => 'GitHub', 'key' => ''], $missing],
			'application without owner'  => [
				['name' => 'GitHub', 'key' => 'CIPHER', 'ownerType' => 'application', 'ownerId' => ''],
				'ownerId is required when ownerType=application',
			],
		];
	}//end incompleteCreateProvider()

	/**
	 * create() refuses an incomplete request with 400 before any write.
	 *
	 * @param array<string,string|null> $args The create() arguments
	 * @param string $message The expected refusal message
	 *
	 * @dataProvider incompleteCreateProvider
	 *
	 * @return void
	 */
	public function testCreateRefusesAnIncompleteRequestWithoutWriting(array $args, string $message): void {
		$this->secretService->expects($this->never())->method('create');
		$this->secretService->expects($this->never())->method('createForApplication');

		$response = $this->controller()->create(...$args);

		$this->assertSame(expected: Http::STATUS_BAD_REQUEST, actual: $response->getStatus());
		$this->assertSame(expected: ['message' => $message], actual: $response->getData());
	}//end testCreateRefusesAnIncompleteRequestWithoutWriting()

	/**
	 * A folder that does not exist is reported as 404 with the service's
	 * message.
	 *
	 * @return void
	 */
	public function testCreateIntoAnUnknownFolderIsNotFound(): void {
		$this->secretService->expects($this->once())
			->method('create')
			->with(
				[
					'name' => 'GitHub',
					'key' => 'CIPHER',
					'url' => null,
					'typeId' => null,
					'folderId' => 'missing-folder',
					'login' => null,
					'additionalFields' => null,
					'totp' => null,
					'passkeys' => null,
				],
				'alice'
			)
			->willThrowException(new NotFoundException(message: 'Folder not found'));

		$response = $this->controller()->create(name: 'GitHub', key: 'CIPHER', folderId: 'missing-folder');

		$this->assertSame(expected: Http::STATUS_NOT_FOUND, actual: $response->getStatus());
		$this->assertSame(expected: ['message' => 'Folder not found'], actual: $response->getData());
	}//end testCreateIntoAnUnknownFolderIsNotFound()
}//end class
