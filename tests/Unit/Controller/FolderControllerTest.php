<?php

/**
 * Contract tests for FolderController.
 *
 * Every endpoint is `#[NoAdminRequired]`; the per-folder owner check lives in
 * FolderService. The controller's contract is that an anonymous caller is
 * refused before the service runs, that the SESSION user reaches the service,
 * and that each service refusal maps to its own HTTP status.
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
use OCA\Keepiq\Controller\FolderController;
use OCA\Keepiq\Db\Folder;
use OCA\Keepiq\Exception\ConflictException;
use OCA\Keepiq\Exception\ForbiddenException;
use OCA\Keepiq\Exception\NotFoundException;
use OCA\Keepiq\Service\FolderService;
use OCP\AppFramework\Http;
use OCP\IRequest;
use OCP\IUser;
use OCP\IUserSession;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Throwable;

class FolderControllerTest extends TestCase {

	/**
	 * The mocked request.
	 *
	 * @var IRequest&MockObject
	 */
	private IRequest&MockObject $request;

	/**
	 * The mocked folder service.
	 *
	 * @var FolderService&MockObject
	 */
	private FolderService&MockObject $folderService;

	/**
	 * The mocked user session.
	 *
	 * @var IUserSession&MockObject
	 */
	private IUserSession&MockObject $userSession;

	/**
	 * The controller under test.
	 *
	 * @var FolderController
	 */
	private FolderController $controller;

	/**
	 * Build the controller over mocks.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		parent::setUp();
		$this->request = $this->createMock(originalClassName: IRequest::class);
		$this->folderService = $this->createMock(originalClassName: FolderService::class);
		$this->userSession = $this->createMock(originalClassName: IUserSession::class);
		$this->controller = new FolderController(
			request: $this->request,
			folderService: $this->folderService,
			userSession: $this->userSession,
		);
	}//end setUp()

	/**
	 * Sign a user in on the mocked session.
	 *
	 * @param string $uid The user id.
	 *
	 * @return void
	 */
	private function signIn(string $uid='alice'): void {
		$user = $this->createMock(originalClassName: IUser::class);
		$user->method('getUID')->willReturn($uid);
		$this->userSession->method('getUser')->willReturn($user);
	}//end signIn()

	/**
	 * A folder entity with a name.
	 *
	 * @param string $name The folder name.
	 *
	 * @return Folder
	 */
	private function folder(string $name): Folder {
		$folder = new Folder();
		$folder->setName($name);
		return $folder;
	}//end folder()

	/**
	 * Every endpoint, called with valid arguments.
	 *
	 * @return array<string, array{0: callable(FolderController): mixed}>
	 */
	public static function endpoints(): array {
		return [
			'index'    => [static fn (FolderController $c) => $c->index()],
			'create'   => [static fn (FolderController $c) => $c->create(name: 'Work')],
			'update'   => [static fn (FolderController $c) => $c->update(id: 'f1', name: 'Work')],
			'children' => [static fn (FolderController $c) => $c->children(id: 'f1')],
			'destroy'  => [static fn (FolderController $c) => $c->destroy(id: 'f1')],
		];
	}//end endpoints()

	/**
	 * An anonymous caller is refused before the service is asked anything.
	 *
	 * @param callable $call The endpoint call.
	 *
	 * @dataProvider endpoints
	 *
	 * @return void
	 */
	public function testAnAnonymousCallerIsRefusedBeforeTheService(callable $call): void {
		$this->userSession->method('getUser')->willReturn(null);
		foreach (['listForUser', 'create', 'rename', 'getChildren', 'delete', 'getOwned'] as $method) {
			$this->folderService->expects($this->never())->method($method);
		}

		$response = $call($this->controller);

		$this->assertSame(expected: Http::STATUS_UNAUTHORIZED, actual: $response->getStatus());
		$this->assertSame(expected: ['message' => 'Unauthorized'], actual: $response->getData());
	}//end testAnAnonymousCallerIsRefusedBeforeTheService()

	/**
	 * The index lists the session user's folders.
	 *
	 * @return void
	 */
	public function testIndexListsTheSessionUsersFolders(): void {
		$this->signIn(uid: 'alice');
		$this->folderService->expects($this->once())->method('listForUser')->with('alice')
			->willReturn([$this->folder(name: 'Work'), $this->folder(name: 'Home')]);

		$data = $this->controller->index()->getData();

		$this->assertSame(expected: ['Work', 'Home'], actual: array_column($data, 'name'));
	}//end testIndexListsTheSessionUsersFolders()

	/**
	 * Create passes the session user as owner and answers 201.
	 *
	 * @return void
	 */
	public function testCreateOwnsTheFolderBySessionUserAndAnswers201(): void {
		$this->signIn(uid: 'alice');
		$this->folderService->expects($this->once())->method('create')
			->with('Work', 'parent-1', 'alice', 'icon-key', '#ff0000')
			->willReturn($this->folder(name: 'Work'));

		$response = $this->controller->create(
			name: 'Work',
			parentId: 'parent-1',
			customIcon: 'icon-key',
			customColor: '#ff0000'
		);

		$this->assertSame(expected: Http::STATUS_CREATED, actual: $response->getStatus());
		$this->assertSame(expected: 'Work', actual: $response->getData()['name']);
	}//end testCreateOwnsTheFolderBySessionUserAndAnswers201()

	/**
	 * Service refusals on create, with the status each maps to.
	 *
	 * @return array<string, array{0: Throwable, 1: int}>
	 */
	public static function createRefusals(): array {
		return [
			'not the parent owner' => [new ForbiddenException(message: 'Not yours'), Http::STATUS_FORBIDDEN],
			'name taken'           => [new ConflictException(message: 'Exists'), Http::STATUS_CONFLICT],
			'invalid name'         => [new InvalidArgumentException(message: 'Empty name'), Http::STATUS_BAD_REQUEST],
		];
	}//end createRefusals()

	/**
	 * Each create refusal answers its own status with the service's message.
	 *
	 * @param Throwable $refusal The service exception.
	 * @param int       $status  The expected status.
	 *
	 * @dataProvider createRefusals
	 *
	 * @return void
	 */
	public function testCreateMapsEachRefusal(Throwable $refusal, int $status): void {
		$this->signIn();
		$this->folderService->method('create')->willThrowException($refusal);

		$response = $this->controller->create(name: 'Work');

		$this->assertSame(expected: $status, actual: $response->getStatus());
		$this->assertSame(expected: ['message' => $refusal->getMessage()], actual: $response->getData());
	}//end testCreateMapsEachRefusal()

	/**
	 * An update that changes nothing reads the folder through the owner check.
	 *
	 * @return void
	 */
	public function testAnEmptyUpdateReadsTheFolderThroughTheOwnerCheck(): void {
		$this->signIn(uid: 'alice');
		$this->request->method('getParams')->willReturn([]);
		$this->folderService->expects($this->never())->method('rename');
		$this->folderService->expects($this->never())->method('move');
		$this->folderService->expects($this->never())->method('updateAttributes');
		$this->folderService->expects($this->once())->method('getOwned')->with('f1', 'alice')
			->willReturn($this->folder(name: 'Work'));

		$response = $this->controller->update(id: 'f1');

		$this->assertSame(expected: Http::STATUS_OK, actual: $response->getStatus());
		$this->assertSame(expected: 'Work', actual: $response->getData()['name']);
	}//end testAnEmptyUpdateReadsTheFolderThroughTheOwnerCheck()

	/**
	 * Rename, move and attribute changes all run, and the last answer is returned.
	 *
	 * @return void
	 */
	public function testAFullUpdateRenamesMovesAndRestyles(): void {
		$this->signIn(uid: 'alice');
		// Only a key present in the request body counts as a change, so an
		// explicit null clears the colour while the icon is left alone.
		$this->request->method('getParams')->willReturn(['customColor' => null]);
		$this->folderService->expects($this->once())->method('rename')->with('f1', 'New', 'alice')
			->willReturn($this->folder(name: 'New'));
		$this->folderService->expects($this->once())->method('move')->with('f1', null, 'alice')
			->willReturn($this->folder(name: 'Moved'));
		$this->folderService->expects($this->once())->method('updateAttributes')
			->with('f1', ['customColor' => null], 'alice')
			->willReturn($this->folder(name: 'Restyled'));
		$this->folderService->expects($this->never())->method('getOwned');

		$response = $this->controller->update(id: 'f1', name: 'New', parentId: null, move: true);

		$this->assertSame(expected: 'Restyled', actual: $response->getData()['name']);
	}//end testAFullUpdateRenamesMovesAndRestyles()

	/**
	 * Service refusals on update and delete, with the status each maps to.
	 *
	 * @return array<string, array{0: Throwable, 1: int}>
	 */
	public static function writeRefusals(): array {
		return [
			'missing'          => [new NotFoundException(message: 'No folder'), Http::STATUS_NOT_FOUND],
			'not the owner'    => [new ForbiddenException(message: 'Not yours'), Http::STATUS_FORBIDDEN],
			'conflict'         => [new ConflictException(message: 'Cycle'), Http::STATUS_CONFLICT],
			'invalid argument' => [new InvalidArgumentException(message: 'Bad cascade'), Http::STATUS_BAD_REQUEST],
		];
	}//end writeRefusals()

	/**
	 * Each update refusal answers its own status.
	 *
	 * @param Throwable $refusal The service exception.
	 * @param int       $status  The expected status.
	 *
	 * @dataProvider writeRefusals
	 *
	 * @return void
	 */
	public function testUpdateMapsEachRefusal(Throwable $refusal, int $status): void {
		$this->signIn();
		$this->request->method('getParams')->willReturn([]);
		$this->folderService->method('rename')->willThrowException($refusal);

		$response = $this->controller->update(id: 'f1', name: 'New');

		$this->assertSame(expected: $status, actual: $response->getStatus());
		$this->assertSame(expected: ['message' => $refusal->getMessage()], actual: $response->getData());
	}//end testUpdateMapsEachRefusal()

	/**
	 * Each delete refusal answers its own status.
	 *
	 * @param Throwable $refusal The service exception.
	 * @param int       $status  The expected status.
	 *
	 * @dataProvider writeRefusals
	 *
	 * @return void
	 */
	public function testDestroyMapsEachRefusal(Throwable $refusal, int $status): void {
		$this->signIn();
		$this->folderService->method('delete')->willThrowException($refusal);

		$response = $this->controller->destroy(id: 'f1', cascade: 'all');

		$this->assertSame(expected: $status, actual: $response->getStatus());
		$this->assertSame(expected: ['message' => $refusal->getMessage()], actual: $response->getData());
	}//end testDestroyMapsEachRefusal()

	/**
	 * Children are read through the owner check; a refusal maps to 404 or 403.
	 *
	 * @return void
	 */
	public function testChildrenAreReadForTheSessionUser(): void {
		$this->signIn(uid: 'alice');
		$this->folderService->expects($this->once())->method('getChildren')->with('f1', 'alice')
			->willReturn(['folders' => [], 'secrets' => []]);

		$response = $this->controller->children(id: 'f1');

		$this->assertSame(expected: Http::STATUS_OK, actual: $response->getStatus());
		$this->assertSame(expected: ['folders' => [], 'secrets' => []], actual: $response->getData());
	}//end testChildrenAreReadForTheSessionUser()

	/**
	 * Children refusals map to 404 and 403.
	 *
	 * @return void
	 */
	public function testChildrenMapRefusals(): void {
		$this->signIn();
		$this->folderService->method('getChildren')->willReturnOnConsecutiveCalls(
			$this->throwException(exception: new NotFoundException(message: 'No folder')),
			$this->throwException(exception: new ForbiddenException(message: 'Not yours'))
		);

		$this->assertSame(expected: Http::STATUS_NOT_FOUND, actual: $this->controller->children(id: 'f1')->getStatus());
		$this->assertSame(expected: Http::STATUS_FORBIDDEN, actual: $this->controller->children(id: 'f1')->getStatus());
	}//end testChildrenMapRefusals()

	/**
	 * A delete without a resolution plan passes null; with one, the plan and its default.
	 *
	 * @return void
	 */
	public function testDestroyPassesTheResolutionPlanFromTheBody(): void {
		$this->signIn(uid: 'alice');
		$this->request->method('getParam')->willReturnMap([
			['subfolders', null, 'promote'],
			['directSecrets', 'move', 'move'],
		]);
		$this->folderService->expects($this->once())->method('delete')
			->with('f1', null, ['subfolders' => 'promote', 'directSecrets' => 'move'], 'alice');

		$response = $this->controller->destroy(id: 'f1');

		$this->assertSame(expected: ['status' => 'deleted'], actual: $response->getData());
	}//end testDestroyPassesTheResolutionPlanFromTheBody()

	/**
	 * A delete without subfolders in the body has no resolution plan.
	 *
	 * @return void
	 */
	public function testDestroyWithoutAPlanPassesNull(): void {
		$this->signIn(uid: 'alice');
		$this->request->method('getParam')->willReturn(null);
		$this->folderService->expects($this->once())->method('delete')
			->with('f1', 'all', null, 'alice');

		$this->assertSame(expected: ['status' => 'deleted'], actual: $this->controller->destroy(id: 'f1', cascade: 'all')->getData());
	}//end testDestroyWithoutAPlanPassesNull()
}//end class
