<?php

/**
 * Contract tests for the EmergencyAccessController actions.
 *
 * Emergency access hands a vault to someone else, so every action is bound
 * to the SESSION user: the grantor designates, revokes and declines, the
 * grantee requests and fetches the envelope. Anonymous callers are refused,
 * designating and revoking need a vault key proof, the recovery envelope is
 * never echoed back on designation, and each service refusal keeps its own
 * status.
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
use OCA\Keepiq\Attribute\VaultKeyProofRequired;
use OCA\Keepiq\Controller\EmergencyAccessController;
use OCA\Keepiq\Db\EmergencyContact;
use OCA\Keepiq\Exception\ForbiddenException;
use OCA\Keepiq\Exception\NotFoundException;
use OCA\Keepiq\Service\EmergencyAccessService;
use OCA\Keepiq\Service\EmergencyDesignation;
use OCA\Keepiq\Service\VaultKeyProofService;
use OCP\AppFramework\Http;
use OCP\IRequest;
use OCP\IUser;
use OCP\IUserSession;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use ReflectionMethod;

class EmergencyAccessControllerActionsTest extends TestCase {

	/**
	 * The mocked emergency access service.
	 *
	 * @var EmergencyAccessService&MockObject
	 */
	private EmergencyAccessService&MockObject $service;

	/**
	 * The mocked user session.
	 *
	 * @var IUserSession&MockObject
	 */
	private IUserSession&MockObject $session;

	/**
	 * The controller under test.
	 *
	 * @var EmergencyAccessController
	 */
	private EmergencyAccessController $controller;

	/**
	 * Build the controller over mocks.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		parent::setUp();
		$this->service = $this->createMock(originalClassName: EmergencyAccessService::class);
		$this->session = $this->createMock(originalClassName: IUserSession::class);
		$this->controller = new EmergencyAccessController(
			request: $this->createMock(originalClassName: IRequest::class),
			service: $this->service,
			userSession: $this->session,
		);
	}//end setUp()

	/**
	 * Sign a user in on the mocked session.
	 *
	 * @param string $uid The user id.
	 *
	 * @return void
	 */
	private function signIn(string $uid): void {
		$user = $this->createMock(originalClassName: IUser::class);
		$user->method('getUID')->willReturn($uid);
		$this->session->method('getUser')->willReturn($user);
	}//end signIn()

	/**
	 * An emergency contact in a state.
	 *
	 * @param string $state The contact state.
	 *
	 * @return EmergencyContact
	 */
	private function contact(string $state): EmergencyContact {
		$contact = new EmergencyContact();
		$contact->setId('ec-1');
		$contact->setGrantorUserId('alice');
		$contact->setGranteeUserId('bob');
		$contact->setState($state);
		$contact->setRecoveryEnvelope('sealed-vault-key');
		return $contact;
	}//end contact()

	/**
	 * Every endpoint, called with valid arguments.
	 *
	 * @return array<string, array{0: callable(EmergencyAccessController): mixed}>
	 */
	public static function endpoints(): array {
		return [
			'index'              => [static fn (EmergencyAccessController $c) => $c->index()],
			'incoming'           => [static fn (EmergencyAccessController $c) => $c->incoming()],
			'granteeCertificate' => [static fn (EmergencyAccessController $c) => $c->granteeCertificate(granteeUserId: 'bob')],
			'create'             => [static fn (EmergencyAccessController $c) => $c->create(granteeUserId: 'bob', waitPeriodDays: 7, recoveryEnvelope: 'x')],
			'destroy'            => [static fn (EmergencyAccessController $c) => $c->destroy(id: 'ec-1')],
			'request'            => [static fn (EmergencyAccessController $c) => $c->request(id: 'ec-1')],
			'decline'            => [static fn (EmergencyAccessController $c) => $c->decline(id: 'ec-1')],
			'envelope'           => [static fn (EmergencyAccessController $c) => $c->envelope(id: 'ec-1')],
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
		$methods = ['listForGrantor', 'listForGrantee', 'getGranteeCertificate', 'designate', 'revoke', 'request', 'decline', 'fetchEnvelope'];
		foreach ($methods as $method) {
			$this->service->expects($this->never())->method($method);
		}

		$response = $call($this->controller);

		$this->assertSame(expected: Http::STATUS_UNAUTHORIZED, actual: $response->getStatus());
		$this->assertSame(expected: ['message' => 'Unauthorized'], actual: $response->getData());
	}//end testAnAnonymousCallerIsRefused()

	/**
	 * Designating and revoking need a vault key proof bound to their arguments.
	 *
	 * @return void
	 */
	public function testDesignateAndRevokeNeedAVaultKeyProof(): void {
		$create = (new ReflectionMethod(EmergencyAccessController::class, 'create'))
			->getAttributes(VaultKeyProofRequired::class);
		$destroy = (new ReflectionMethod(EmergencyAccessController::class, 'destroy'))
			->getAttributes(VaultKeyProofRequired::class);

		$this->assertCount(expectedCount: 1, haystack: $create);
		$this->assertSame(expected: ['granteeUserId', 'waitPeriodDays', 'recoveryEnvelope'], actual: $create[0]->newInstance()->getBinds());
		$this->assertSame(expected: VaultKeyProofService::PURPOSE_EMERGENCY_DESIGNATE, actual: $create[0]->getArguments()['purpose']);
		$this->assertCount(expectedCount: 1, haystack: $destroy);
		$this->assertSame(expected: ['id'], actual: $destroy[0]->newInstance()->getBinds());
		$this->assertSame(expected: VaultKeyProofService::PURPOSE_EMERGENCY_DESTROY, actual: $destroy[0]->getArguments()['purpose']);
	}//end testDesignateAndRevokeNeedAVaultKeyProof()

	/**
	 * A designation is made by the session user and the envelope is not echoed.
	 *
	 * @return void
	 */
	public function testCreateDesignatesAsTheSessionUserWithoutEchoingTheEnvelope(): void {
		$this->signIn(uid: 'alice');
		$this->service->expects($this->once())->method('designate')
			->with($this->callback(
				callback: static fn (EmergencyDesignation $d): bool => $d->grantorUserId === 'alice'
					&& $d->granteeUserId === 'bob'
					&& $d->waitPeriodDays === 3
					&& $d->accessLevel === 'takeover'
					&& $d->recoveryEnvelope === 'sealed-vault-key'
			))
			->willReturn($this->contact(state: 'granted'));

		$response = $this->controller->create(
			granteeUserId: 'bob',
			waitPeriodDays: 3,
			recoveryEnvelope: 'sealed-vault-key',
			accessLevel: 'takeover'
		);

		$this->assertSame(expected: Http::STATUS_CREATED, actual: $response->getStatus());
		$this->assertTrue(condition: $response->getData()['hasEnvelope']);
		$this->assertStringNotContainsString(needle: 'sealed-vault-key', haystack: (string)json_encode($response->getData()));
	}//end testCreateDesignatesAsTheSessionUserWithoutEchoingTheEnvelope()

	/**
	 * A refused designation answers 400.
	 *
	 * @return void
	 */
	public function testARefusedDesignationAnswers400(): void {
		$this->signIn(uid: 'alice');
		$this->service->method('designate')->willThrowException(new InvalidArgumentException(message: 'Cannot designate yourself'));

		$response = $this->controller->create(granteeUserId: 'alice', waitPeriodDays: 7, recoveryEnvelope: 'x');

		$this->assertSame(expected: Http::STATUS_BAD_REQUEST, actual: $response->getStatus());
		$this->assertSame(expected: ['message' => 'Cannot designate yourself'], actual: $response->getData());
	}//end testARefusedDesignationAnswers400()

	/**
	 * The grantee certificate lookup answers the service result, and 400 when refused.
	 *
	 * @return void
	 */
	public function testGranteeCertificate(): void {
		$this->signIn(uid: 'alice');
		$this->service->method('getGranteeCertificate')->willReturnCallback(
			static function (string $granteeUserId): array {
				if ($granteeUserId !== 'bob') {
					throw new InvalidArgumentException(message: 'No encryption suite');
				}

				return ['suiteId' => 's-1', 'certificate' => 'PEM'];
			}
		);

		$this->assertSame(
			expected: ['suiteId' => 's-1', 'certificate' => 'PEM'],
			actual: $this->controller->granteeCertificate(granteeUserId: 'bob')->getData()
		);
		$this->assertSame(expected: Http::STATUS_BAD_REQUEST, actual: $this->controller->granteeCertificate(granteeUserId: 'carol')->getStatus());
	}//end testGranteeCertificate()

	/**
	 * Revoke, request and decline act as the session user in their own role.
	 *
	 * @return void
	 */
	public function testActionsRunAsTheSessionUser(): void {
		$this->signIn(uid: 'alice');
		$this->service->expects($this->once())->method('revoke')->with('alice', 'ec-1');
		$this->service->expects($this->once())->method('request')->with('alice', 'ec-1')
			->willReturn($this->contact(state: 'requested'));
		$this->service->expects($this->once())->method('decline')->with('alice', 'ec-1')
			->willReturn($this->contact(state: 'granted'));
		$this->service->expects($this->once())->method('fetchEnvelope')->with('alice', 'ec-1')
			->willReturn('sealed-vault-key');

		$this->assertSame(expected: ['status' => 'revoked'], actual: $this->controller->destroy(id: 'ec-1')->getData());
		$this->assertSame(expected: 'requested', actual: $this->controller->request(id: 'ec-1')->getData()['state']);
		$this->assertSame(expected: 'granted', actual: $this->controller->decline(id: 'ec-1')->getData()['state']);
		$this->assertSame(expected: ['recoveryEnvelope' => 'sealed-vault-key'], actual: $this->controller->envelope(id: 'ec-1')->getData());
	}//end testActionsRunAsTheSessionUser()

	/**
	 * The actions that read a contact, and the service method behind each.
	 *
	 * @return array<string, array{0: string, 1: string}>
	 */
	public static function contactActions(): array {
		return [
			'destroy' => ['destroy', 'revoke'],
			'request' => ['request', 'request'],
			'decline' => ['decline', 'decline'],
		];
	}//end contactActions()

	/**
	 * A missing contact answers 404 and a foreign one 403.
	 *
	 * @param string $endpoint      The controller method.
	 * @param string $serviceMethod The service method it calls.
	 *
	 * @dataProvider contactActions
	 *
	 * @return void
	 */
	public function testMissingAndForeignContactsAreRefused(string $endpoint, string $serviceMethod): void {
		$this->signIn(uid: 'mallory');
		$this->service->method($serviceMethod)->willReturnOnConsecutiveCalls(
			$this->throwException(exception: new NotFoundException(message: 'gone')),
			$this->throwException(exception: new ForbiddenException(message: 'Not your contact'))
		);

		$missing = $this->controller->$endpoint(id: 'ec-1');
		$foreign = $this->controller->$endpoint(id: 'ec-1');

		$this->assertSame(expected: Http::STATUS_NOT_FOUND, actual: $missing->getStatus());
		$this->assertSame(expected: ['message' => 'Not found'], actual: $missing->getData());
		$this->assertSame(expected: Http::STATUS_FORBIDDEN, actual: $foreign->getStatus());
		$this->assertSame(expected: ['message' => 'Not your contact'], actual: $foreign->getData());
	}//end testMissingAndForeignContactsAreRefused()

	/**
	 * The envelope is refused with 403 before the wait period has passed.
	 *
	 * @return void
	 */
	public function testTheEnvelopeIsRefusedWhenNotYetReleased(): void {
		$this->signIn(uid: 'bob');
		$this->service->method('fetchEnvelope')->willThrowException(new ForbiddenException(message: 'Wait period not over'));

		$response = $this->controller->envelope(id: 'ec-1');

		$this->assertSame(expected: Http::STATUS_FORBIDDEN, actual: $response->getStatus());
		$this->assertSame(expected: ['message' => 'Wait period not over'], actual: $response->getData());
	}//end testTheEnvelopeIsRefusedWhenNotYetReleased()
}//end class
