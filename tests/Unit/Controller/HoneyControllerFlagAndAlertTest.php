<?php

/**
 * Contract tests for the honey credential flag, status and alert actions.
 *
 * Flagging a secret as a honey credential and handling its alerts is scoped
 * by the service to the secret's owner, or instance wide for an audit area
 * holder. The controller's contract: an unauthenticated caller is refused
 * with 403, the SESSION user and the area answer reach the service, an empty
 * note is stored as none, and missing rows and refusals map to 404 and 403.
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
use OCA\Keepiq\Controller\HoneyController;
use OCA\Keepiq\Db\HoneyAlert;
use OCA\Keepiq\Db\HoneyFlag;
use OCA\Keepiq\Service\AdminAreaAuthorizer;
use OCA\Keepiq\Service\HoneyCredentialService;
use OCA\Keepiq\Settings\AuditAdminSettings;
use OCP\AppFramework\Db\DoesNotExistException;
use OCP\AppFramework\Http;
use OCP\IRequest;
use OCP\IUser;
use OCP\IUserSession;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

class HoneyControllerFlagAndAlertTest extends TestCase {

	/**
	 * The mocked honey credential service.
	 *
	 * @var HoneyCredentialService&MockObject
	 */
	private HoneyCredentialService&MockObject $service;

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
	 * @var HoneyController
	 */
	private HoneyController $controller;

	/**
	 * Build the controller over mocks.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		parent::setUp();
		$this->service = $this->createMock(originalClassName: HoneyCredentialService::class);
		$this->session = $this->createMock(originalClassName: IUserSession::class);
		$this->areas = $this->createMock(originalClassName: AdminAreaAuthorizer::class);
		$this->controller = new HoneyController(
			request: $this->createMock(originalClassName: IRequest::class),
			service: $this->service,
			userSession: $this->session,
			areas: $this->areas,
		);
	}//end setUp()

	/**
	 * Sign alice in, holding the audit area or not.
	 *
	 * @param bool $isAdmin Whether alice holds the audit area.
	 *
	 * @return void
	 */
	private function signIn(bool $isAdmin): void {
		$user = $this->createMock(originalClassName: IUser::class);
		$user->method('getUID')->willReturn('alice');
		$this->session->method('getUser')->willReturn($user);
		$this->areas->method('holds')->with('alice', AuditAdminSettings::class)->willReturn($isAdmin);
	}//end signIn()

	/**
	 * A flag row for a secret.
	 *
	 * @return HoneyFlag
	 */
	private function flagRow(): HoneyFlag {
		$flag = new HoneyFlag();
		$flag->setId('flag-1');
		$flag->setSecretId('secret-1');
		return $flag;
	}//end flagRow()

	/**
	 * An alert row.
	 *
	 * @return HoneyAlert
	 */
	private function alertRow(): HoneyAlert {
		$alert = new HoneyAlert();
		$alert->setId('alert-1');
		$alert->setSecretId('secret-1');
		return $alert;
	}//end alertRow()

	/**
	 * The flag and alert endpoints, called with valid arguments.
	 *
	 * @return array<string, array{0: callable(HoneyController): mixed}>
	 */
	public static function endpoints(): array {
		return [
			'flag'        => [static fn (HoneyController $c) => $c->flag(id: 'secret-1')],
			'status'      => [static fn (HoneyController $c) => $c->status(id: 'secret-1')],
			'acknowledge' => [static fn (HoneyController $c) => $c->acknowledge(id: 'alert-1')],
			'snooze'      => [static fn (HoneyController $c) => $c->snooze(id: 'alert-1')],
		];
	}//end endpoints()

	/**
	 * An unauthenticated caller is refused with 403 before the service runs.
	 *
	 * @param callable $call The endpoint call.
	 *
	 * @dataProvider endpoints
	 *
	 * @return void
	 */
	public function testAnUnauthenticatedCallerIsRefused(callable $call): void {
		$this->session->method('getUser')->willReturn(null);
		foreach (['flag', 'getFlag', 'acknowledge', 'snooze'] as $method) {
			$this->service->expects($this->never())->method($method);
		}

		$response = $call($this->controller);

		$this->assertSame(expected: Http::STATUS_FORBIDDEN, actual: $response->getStatus());
		$this->assertSame(expected: ['message' => 'Unauthenticated'], actual: $response->getData());
	}//end testAnUnauthenticatedCallerIsRefused()

	/**
	 * A flag carries the session user, the area answer and the note; it answers 201.
	 *
	 * @return void
	 */
	public function testFlagCarriesTheCallerAndTheNote(): void {
		$this->signIn(isAdmin: true);
		$this->service->expects($this->once())->method('flag')
			->with('secret-1', 'alice', true, 'Planted for the red team')
			->willReturn($this->flagRow());

		$response = $this->controller->flag(id: 'secret-1', note: 'Planted for the red team');

		$this->assertSame(expected: Http::STATUS_CREATED, actual: $response->getStatus());
		$this->assertSame(expected: 'flag-1', actual: $response->getData()['id']);
	}//end testFlagCarriesTheCallerAndTheNote()

	/**
	 * An empty note is stored as no note.
	 *
	 * @return void
	 */
	public function testAnEmptyNoteIsStoredAsNone(): void {
		$this->signIn(isAdmin: false);
		$this->service->expects($this->once())->method('flag')
			->with('secret-1', 'alice', false, null)
			->willReturn($this->flagRow());

		$this->controller->flag(id: 'secret-1');
	}//end testAnEmptyNoteIsStoredAsNone()

	/**
	 * A missing secret answers 404 and a refusal 403.
	 *
	 * @return void
	 */
	public function testFlagMapsAMissingSecretAndARefusal(): void {
		$this->signIn(isAdmin: false);
		$this->service->method('flag')->willReturnOnConsecutiveCalls(
			$this->throwException(exception: new DoesNotExistException(msg: 'gone')),
			$this->throwException(exception: new InvalidArgumentException(message: 'Only the owner can flag'))
		);

		$missing = $this->controller->flag(id: 'secret-1');
		$refused = $this->controller->flag(id: 'secret-1');

		$this->assertSame(expected: Http::STATUS_NOT_FOUND, actual: $missing->getStatus());
		$this->assertSame(expected: ['message' => 'Secret not found'], actual: $missing->getData());
		$this->assertSame(expected: Http::STATUS_FORBIDDEN, actual: $refused->getStatus());
		$this->assertSame(expected: ['message' => 'Only the owner can flag'], actual: $refused->getData());
	}//end testFlagMapsAMissingSecretAndARefusal()

	/**
	 * Status reports a flagged and an unflagged secret, and 403 on refusal.
	 *
	 * @return void
	 */
	public function testStatus(): void {
		$this->signIn(isAdmin: false);
		$this->service->method('getFlag')->willReturnOnConsecutiveCalls(
			$this->flagRow(),
			null,
			$this->throwException(exception: new InvalidArgumentException(message: 'Not yours'))
		);

		$flagged = $this->controller->status(id: 'secret-1')->getData();
		$unflagged = $this->controller->status(id: 'secret-1')->getData();
		$refused = $this->controller->status(id: 'secret-1');

		$this->assertTrue(condition: $flagged['flagged']);
		$this->assertSame(expected: 'flag-1', actual: $flagged['flag']['id']);
		$this->assertSame(expected: ['flagged' => false, 'flag' => null], actual: $unflagged);
		$this->assertSame(expected: Http::STATUS_FORBIDDEN, actual: $refused->getStatus());
	}//end testStatus()

	/**
	 * Acknowledge and snooze act as the caller, snooze with the hours asked.
	 *
	 * @return void
	 */
	public function testAcknowledgeAndSnoozeActAsTheCaller(): void {
		$this->signIn(isAdmin: true);
		$this->service->expects($this->once())->method('acknowledge')->with('alert-1', 'alice', true)
			->willReturn($this->alertRow());
		$this->service->expects($this->once())->method('snooze')->with('alert-1', 'alice', true, 4)
			->willReturn($this->alertRow());

		$this->assertSame(expected: 'alert-1', actual: $this->controller->acknowledge(id: 'alert-1')->getData()['id']);
		$this->assertSame(expected: 'alert-1', actual: $this->controller->snooze(id: 'alert-1', hours: 4)->getData()['id']);
	}//end testAcknowledgeAndSnoozeActAsTheCaller()

	/**
	 * The alert actions, and the service method behind each.
	 *
	 * @return array<string, array{0: string}>
	 */
	public static function alertActions(): array {
		return [
			'acknowledge' => ['acknowledge'],
			'snooze'      => ['snooze'],
		];
	}//end alertActions()

	/**
	 * A missing alert answers 404 and a foreign alert 403.
	 *
	 * @param string $action The controller and service method.
	 *
	 * @dataProvider alertActions
	 *
	 * @return void
	 */
	public function testAlertActionsMapAMissingAndAForeignAlert(string $action): void {
		$this->signIn(isAdmin: false);
		$this->service->method($action)->willReturnOnConsecutiveCalls(
			$this->throwException(exception: new DoesNotExistException(msg: 'gone')),
			$this->throwException(exception: new InvalidArgumentException(message: 'Not your alert'))
		);

		$missing = $this->controller->$action(id: 'alert-1');
		$foreign = $this->controller->$action(id: 'alert-1');

		$this->assertSame(expected: Http::STATUS_NOT_FOUND, actual: $missing->getStatus());
		$this->assertSame(expected: ['message' => 'Alert not found'], actual: $missing->getData());
		$this->assertSame(expected: Http::STATUS_FORBIDDEN, actual: $foreign->getStatus());
		$this->assertSame(expected: ['message' => 'Not your alert'], actual: $foreign->getData());
	}//end testAlertActionsMapAMissingAndAForeignAlert()
}//end class
