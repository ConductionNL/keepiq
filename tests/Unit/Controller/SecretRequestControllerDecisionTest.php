<?php

/**
 * Contract tests for the SecretRequestController listing and decision actions.
 *
 * A secret request asks someone to fill in a secret. Listing, approving,
 * declining and withdrawing are all scoped by the service to the SESSION
 * user. The controller's contract: anonymous callers are refused, the session
 * user reaches the service, an expiry that is not a timestamp is a 400, a
 * service status code of 403, 404 or 409 is kept, and other refusals map to
 * the status each action documents.
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

use DateTime;
use InvalidArgumentException;
use OCA\Keepiq\Controller\SecretRequestController;
use OCA\Keepiq\Db\SecretRequest;
use OCA\Keepiq\Service\SecretRequestService;
use OCP\AppFramework\Http;
use OCP\IRequest;
use OCP\IUser;
use OCP\IUserSession;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

class SecretRequestControllerDecisionTest extends TestCase {

	/**
	 * The mocked secret request service.
	 *
	 * @var SecretRequestService&MockObject
	 */
	private SecretRequestService&MockObject $service;

	/**
	 * The mocked user session.
	 *
	 * @var IUserSession&MockObject
	 */
	private IUserSession&MockObject $session;

	/**
	 * The controller under test.
	 *
	 * @var SecretRequestController
	 */
	private SecretRequestController $controller;

	/**
	 * Build the controller over mocks.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		parent::setUp();
		$this->service = $this->createMock(originalClassName: SecretRequestService::class);
		$this->session = $this->createMock(originalClassName: IUserSession::class);
		$this->controller = new SecretRequestController(
			request: $this->createMock(originalClassName: IRequest::class),
			service: $this->service,
			session: $this->session,
		);
	}//end setUp()

	/**
	 * Sign alice in on the mocked session.
	 *
	 * @return void
	 */
	private function signIn(): void {
		$user = $this->createMock(originalClassName: IUser::class);
		$user->method('getUID')->willReturn('alice');
		$this->session->method('getUser')->willReturn($user);
	}//end signIn()

	/**
	 * A secret request row.
	 *
	 * @param string $status The request status.
	 *
	 * @return SecretRequest
	 */
	private function secretRequest(string $status): SecretRequest {
		$request = new SecretRequest();
		$request->setId('req-1');
		$request->setStatus($status);
		return $request;
	}//end secretRequest()

	/**
	 * The listing and decision endpoints, called with valid arguments.
	 *
	 * @return array<string, array{0: callable(SecretRequestController): mixed}>
	 */
	public static function endpoints(): array {
		return [
			'index'        => [static fn (SecretRequestController $c) => $c->index()],
			'listBySecret' => [static fn (SecretRequestController $c) => $c->listBySecret(secretId: 's-1')],
			'approve'      => [static fn (SecretRequestController $c) => $c->approve(id: 'req-1')],
			'decline'      => [static fn (SecretRequestController $c) => $c->decline(id: 'req-1')],
			'destroy'      => [static fn (SecretRequestController $c) => $c->destroy(id: 'req-1')],
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
		foreach (['listByUser', 'listBySecret', 'approve', 'decline'] as $method) {
			$this->service->expects($this->never())->method($method);
		}

		$this->assertSame(expected: Http::STATUS_UNAUTHORIZED, actual: $call($this->controller)->getStatus());
	}//end testAnAnonymousCallerIsRefused()

	/**
	 * Both listings read for the session user; a refused secret listing is a 403.
	 *
	 * @return void
	 */
	public function testListingsReadForTheSessionUser(): void {
		$this->signIn();
		$this->service->expects($this->once())->method('listByUser')->with('alice')
			->willReturn([$this->secretRequest(status: 'pending')]);
		$this->service->method('listBySecret')->willReturnCallback(
			function (string $secretId, string $userId): array {
				if ($secretId !== 's-1' || $userId !== 'alice') {
					throw new InvalidArgumentException(message: 'Not your secret');
				}

				return [$this->secretRequest(status: 'approved')];
			}
		);

		$this->assertSame(expected: 'pending', actual: $this->controller->index()->getData()[0]['status']);
		$this->assertSame(expected: 'approved', actual: $this->controller->listBySecret(secretId: 's-1')->getData()[0]['status']);
		$refused = $this->controller->listBySecret(secretId: 's-2');
		$this->assertSame(expected: Http::STATUS_FORBIDDEN, actual: $refused->getStatus());
		$this->assertSame(expected: ['message' => 'Not your secret'], actual: $refused->getData());
	}//end testListingsReadForTheSessionUser()

	/**
	 * Approve, decline and withdraw act as the session user.
	 *
	 * @return void
	 */
	public function testDecisionsActAsTheSessionUser(): void {
		$this->signIn();
		$this->service->expects($this->once())->method('approve')->with('req-1', 'alice')
			->willReturn($this->secretRequest(status: 'approved'));
		$this->service->expects($this->exactly(count: 2))->method('decline')->with('req-1', 'alice')
			->willReturn($this->secretRequest(status: 'declined'));

		$this->assertSame(expected: 'approved', actual: $this->controller->approve(id: 'req-1')->getData()['status']);
		$this->assertSame(expected: 'declined', actual: $this->controller->decline(id: 'req-1')->getData()['status']);
		$withdrawn = $this->controller->destroy(id: 'req-1')->getData();
		$this->assertSame(expected: 'deleted', actual: $withdrawn['status']);
		$this->assertSame(expected: 'declined', actual: $withdrawn['request']['status']);
	}//end testDecisionsActAsTheSessionUser()

	/**
	 * A refused approve or decline is a 400, a refused withdrawal a 403.
	 *
	 * @return void
	 */
	public function testRefusedDecisions(): void {
		$this->signIn();
		$this->service->method('approve')->willThrowException(new InvalidArgumentException(message: 'Already decided'));
		$this->service->method('decline')->willThrowException(new InvalidArgumentException(message: 'Not yours'));

		$this->assertSame(expected: Http::STATUS_BAD_REQUEST, actual: $this->controller->approve(id: 'req-1')->getStatus());
		$this->assertSame(expected: Http::STATUS_BAD_REQUEST, actual: $this->controller->decline(id: 'req-1')->getStatus());
		$this->assertSame(expected: Http::STATUS_FORBIDDEN, actual: $this->controller->destroy(id: 'req-1')->getStatus());
	}//end testRefusedDecisions()

	/**
	 * An expiry that is not a timestamp is a 400 and creates nothing.
	 *
	 * @return void
	 */
	public function testAnInvalidExpiryIsRefused(): void {
		$this->signIn();
		$this->service->expects($this->never())->method('create');

		$response = $this->controller->create(secretId: 's-1', expiresAt: 'next tuesday-ish o\'clock');

		$this->assertSame(expected: Http::STATUS_BAD_REQUEST, actual: $response->getStatus());
		$this->assertSame(expected: ['message' => 'Invalid expiry timestamp'], actual: $response->getData());
	}//end testAnInvalidExpiryIsRefused()

	/**
	 * A valid expiry reaches the service as a moment.
	 *
	 * @return void
	 */
	public function testAValidExpiryReachesTheService(): void {
		$this->signIn();
		$this->service->expects($this->once())->method('create')
			->with(
				's-1',
				'suite-1',
				['password'],
				false,
				$this->callback(callback: static fn (?DateTime $at): bool => $at?->format('c') === '2026-12-01T00:00:00+00:00'),
				'alice'
			)
			->willReturn($this->secretRequest(status: 'pending'));

		$response = $this->controller->create(
			secretId: 's-1',
			encryptionSuiteId: 'suite-1',
			requestedFields: ['password'],
			expiresAt: '2026-12-01T00:00:00+00:00'
		);

		$this->assertSame(expected: Http::STATUS_CREATED, actual: $response->getStatus());
	}//end testAValidExpiryReachesTheService()

	/**
	 * Service status codes on create, and the status the caller sees.
	 *
	 * @return array<string, array{0: int, 1: int}>
	 */
	public static function createCodes(): array {
		return [
			'forbidden kept'  => [403, Http::STATUS_FORBIDDEN],
			'not found kept'  => [404, Http::STATUS_NOT_FOUND],
			'conflict kept'   => [409, Http::STATUS_CONFLICT],
			'other is 400'    => [500, Http::STATUS_BAD_REQUEST],
			'no code is 400'  => [0, Http::STATUS_BAD_REQUEST],
		];
	}//end createCodes()

	/**
	 * A create refusal keeps a 403, 404 or 409 code and maps anything else to 400.
	 *
	 * @param int $code     The exception code the service sets.
	 * @param int $expected The status the caller sees.
	 *
	 * @dataProvider createCodes
	 *
	 * @return void
	 */
	public function testCreateRefusalsKeepTheirStatus(int $code, int $expected): void {
		$this->signIn();
		$this->service->method('create')->willThrowException(new InvalidArgumentException(message: 'Refused', code: $code));

		$response = $this->controller->create(secretId: 's-1');

		$this->assertSame(expected: $expected, actual: $response->getStatus());
		$this->assertSame(expected: ['message' => 'Refused'], actual: $response->getData());
	}//end testCreateRefusalsKeepTheirStatus()
}//end class
