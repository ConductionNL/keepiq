<?php

/**
 * Contract tests for listing, updating and deleting secret types.
 *
 * A user manages their own secret types; global types need the general admin
 * area, which the service decides from the area answer the controller passes.
 * The controller's contract: anonymous callers are refused, the SESSION user
 * and the area answer reach the service, and refusals map to 403 and 400.
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
use OCA\Keepiq\Controller\SecretTypeController;
use OCA\Keepiq\Db\SecretType;
use OCA\Keepiq\Exception\ConflictException;
use OCA\Keepiq\Exception\ForbiddenException;
use OCA\Keepiq\Service\AdminAreaAuthorizer;
use OCA\Keepiq\Service\SecretTypeService;
use OCA\Keepiq\Settings\AdminSettings;
use OCP\AppFramework\Http;
use OCP\IRequest;
use OCP\IUser;
use OCP\IUserSession;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

class SecretTypeControllerManageTest extends TestCase {

	/**
	 * The mocked secret type service.
	 *
	 * @var SecretTypeService&MockObject
	 */
	private SecretTypeService&MockObject $service;

	/**
	 * The mocked user session.
	 *
	 * @var IUserSession&MockObject
	 */
	private IUserSession&MockObject $session;

	/**
	 * The mocked admin area authorizer.
	 *
	 * @var AdminAreaAuthorizer&MockObject
	 */
	private AdminAreaAuthorizer&MockObject $areas;

	/**
	 * The controller under test.
	 *
	 * @var SecretTypeController
	 */
	private SecretTypeController $controller;

	/**
	 * Build the controller over mocks.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		parent::setUp();
		$this->service = $this->createMock(originalClassName: SecretTypeService::class);
		$this->session = $this->createMock(originalClassName: IUserSession::class);
		$this->areas = $this->createMock(originalClassName: AdminAreaAuthorizer::class);
		$this->controller = new SecretTypeController(
			request: $this->createMock(originalClassName: IRequest::class),
			typeService: $this->service,
			userSession: $this->session,
			areas: $this->areas,
		);
	}//end setUp()

	/**
	 * Sign alice in, holding the general admin area or not.
	 *
	 * @param bool $isAdmin Whether alice holds the area.
	 *
	 * @return void
	 */
	private function signIn(bool $isAdmin): void {
		$user = $this->createMock(originalClassName: IUser::class);
		$user->method('getUID')->willReturn('alice');
		$this->session->method('getUser')->willReturn($user);
		$this->areas->method('holds')->with('alice', AdminSettings::class)->willReturn($isAdmin);
	}//end signIn()

	/**
	 * A secret type row.
	 *
	 * @return SecretType
	 */
	private function type(): SecretType {
		$type = new SecretType();
		$type->setId('type-1');
		return $type;
	}//end type()

	/**
	 * Every endpoint, called with valid arguments.
	 *
	 * @return array<string, array{0: callable(SecretTypeController): mixed}>
	 */
	public static function endpoints(): array {
		return [
			'index'   => [static fn (SecretTypeController $c) => $c->index()],
			'create'  => [static fn (SecretTypeController $c) => $c->create(name: 'wifi', label: 'Wi-Fi')],
			'update'  => [static fn (SecretTypeController $c) => $c->update(id: 'type-1', label: 'Wi-Fi')],
			'destroy' => [static fn (SecretTypeController $c) => $c->destroy(id: 'type-1')],
		];
	}//end endpoints()

	/**
	 * An anonymous caller is refused before the service runs.
	 *
	 * @param callable $call The endpoint call.
	 *
	 * @dataProvider endpoints
	 *
	 * @return void
	 */
	public function testAnAnonymousCallerIsRefused(callable $call): void {
		$this->session->method('getUser')->willReturn(null);
		foreach (['getAvailableTypes', 'createType', 'updateType', 'deleteType'] as $method) {
			$this->service->expects($this->never())->method($method);
		}

		$this->assertSame(expected: Http::STATUS_UNAUTHORIZED, actual: $call($this->controller)->getStatus());
	}//end testAnAnonymousCallerIsRefused()

	/**
	 * The index lists the types available to the session user.
	 *
	 * @return void
	 */
	public function testIndexListsTheSessionUsersTypes(): void {
		$this->signIn(isAdmin: false);
		$this->service->expects($this->once())->method('getAvailableTypes')->with('alice')->willReturn([$this->type()]);

		$this->assertSame(expected: 'type-1', actual: $this->controller->index()->getData()[0]['id']);
	}//end testIndexListsTheSessionUsersTypes()

	/**
	 * A name already taken is a 409.
	 *
	 * @return void
	 */
	public function testCreateWithATakenNameIs409(): void {
		$this->signIn(isAdmin: false);
		$this->service->method('createType')->willThrowException(new ConflictException(message: 'Type exists'));

		$response = $this->controller->create(name: 'wifi', label: 'Wi-Fi');

		$this->assertSame(expected: Http::STATUS_CONFLICT, actual: $response->getStatus());
		$this->assertSame(expected: ['message' => 'Type exists'], actual: $response->getData());
	}//end testCreateWithATakenNameIs409()

	/**
	 * An update carries the session user and area answer.
	 *
	 * @return void
	 */
	public function testUpdateCarriesTheCallerAndAreaAnswer(): void {
		$this->signIn(isAdmin: true);
		$fields = [['name' => 'ssid', 'type' => 'text']];
		$this->service->expects($this->once())->method('updateType')
			->with('type-1', 'Wi-Fi', 'alice', true, $fields)
			->willReturn($this->type());

		$response = $this->controller->update(id: 'type-1', label: 'Wi-Fi', fields: $fields);

		$this->assertSame(expected: Http::STATUS_OK, actual: $response->getStatus());
		$this->assertSame(expected: 'type-1', actual: $response->getData()['id']);
	}//end testUpdateCarriesTheCallerAndAreaAnswer()

	/**
	 * A foreign type is a 403 and an invalid field list a 400 on update.
	 *
	 * @return void
	 */
	public function testUpdateRefusals(): void {
		$this->signIn(isAdmin: false);
		$this->service->method('updateType')->willReturnOnConsecutiveCalls(
			$this->throwException(exception: new ForbiddenException(message: 'Global types are admin-only')),
			$this->throwException(exception: new InvalidArgumentException(message: 'Unknown field type'))
		);

		$this->assertSame(expected: Http::STATUS_FORBIDDEN, actual: $this->controller->update(id: 'type-1', label: 'x')->getStatus());
		$this->assertSame(expected: Http::STATUS_BAD_REQUEST, actual: $this->controller->update(id: 'type-1', label: 'x')->getStatus());
	}//end testUpdateRefusals()

	/**
	 * A delete carries the caller and area answer; a foreign type is a 403.
	 *
	 * @return void
	 */
	public function testDestroy(): void {
		$this->signIn(isAdmin: false);
		$this->service->expects($this->exactly(count: 2))->method('deleteType')->with('type-1', 'alice', false)
			->willReturnOnConsecutiveCalls(
				null,
				$this->throwException(exception: new ForbiddenException(message: 'Not yours'))
			);

		$this->assertSame(expected: ['status' => 'deleted'], actual: $this->controller->destroy(id: 'type-1')->getData());
		$refused = $this->controller->destroy(id: 'type-1');
		$this->assertSame(expected: Http::STATUS_FORBIDDEN, actual: $refused->getStatus());
		$this->assertSame(expected: ['message' => 'Not yours'], actual: $refused->getData());
	}//end testDestroy()
}//end class
