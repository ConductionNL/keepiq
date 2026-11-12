<?php

/**
 * Contract tests for RecoveryAdminController.
 *
 * Every endpoint is gated by the people admin setting, and the two that
 * change the recovery set-up also require password confirmation. The
 * controller reports the active key without its certificate, records a
 * settings change in the audit trail with the acting admin, and maps a
 * refused policy and a missing key to 400 and 404.
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
use OCA\Keepiq\Controller\RecoveryAdminController;
use OCA\Keepiq\Db\RecoveryKey;
use OCA\Keepiq\Exception\NotFoundException;
use OCA\Keepiq\Service\RecoveryAudit;
use OCA\Keepiq\Service\RecoveryEnrolmentService;
use OCA\Keepiq\Service\RecoveryKeyService;
use OCA\Keepiq\Service\RecoveryPolicyService;
use OCA\Keepiq\Settings\PeopleAdminSettings;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\Attribute\AuthorizedAdminSetting;
use OCP\AppFramework\Http\Attribute\PasswordConfirmationRequired;
use OCP\IRequest;
use OCP\IUser;
use OCP\IUserSession;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use ReflectionMethod;

class RecoveryAdminControllerTest extends TestCase {

	/**
	 * The mocked policy service.
	 *
	 * @var RecoveryPolicyService&MockObject
	 */
	private RecoveryPolicyService&MockObject $policy;

	/**
	 * The mocked key service.
	 *
	 * @var RecoveryKeyService&MockObject
	 */
	private RecoveryKeyService&MockObject $keys;

	/**
	 * The mocked enrolment service.
	 *
	 * @var RecoveryEnrolmentService&MockObject
	 */
	private RecoveryEnrolmentService&MockObject $enrolments;

	/**
	 * The mocked audit recorder.
	 *
	 * @var RecoveryAudit&MockObject
	 */
	private RecoveryAudit&MockObject $audit;

	/**
	 * The controller under test.
	 *
	 * @var RecoveryAdminController
	 */
	private RecoveryAdminController $controller;

	/**
	 * Build the controller over mocks with an admin signed in.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		parent::setUp();
		$this->policy = $this->createMock(originalClassName: RecoveryPolicyService::class);
		$this->keys = $this->createMock(originalClassName: RecoveryKeyService::class);
		$this->enrolments = $this->createMock(originalClassName: RecoveryEnrolmentService::class);
		$this->audit = $this->createMock(originalClassName: RecoveryAudit::class);
		$admin = $this->createMock(originalClassName: IUser::class);
		$admin->method('getUID')->willReturn('root');
		$session = $this->createMock(originalClassName: IUserSession::class);
		$session->method('getUser')->willReturn($admin);

		$this->controller = new RecoveryAdminController(
			request: $this->createMock(originalClassName: IRequest::class),
			policy: $this->policy,
			keys: $this->keys,
			enrolments: $this->enrolments,
			audit: $this->audit,
			userSession: $session,
		);
	}//end setUp()

	/**
	 * Every endpoint and whether it changes the recovery set-up.
	 *
	 * @return array<string, array{0: string, 1: bool}>
	 */
	public static function endpoints(): array {
		return [
			'show'      => ['show', false],
			'update'    => ['update', true],
			'retireKey' => ['retireKey', true],
			'enrolled'  => ['enrolled', false],
		];
	}//end endpoints()

	/**
	 * Each endpoint is admin-gated; the writes also need the password again.
	 *
	 * @param string $method    The controller method.
	 * @param bool   $isWriting Whether it changes the recovery set-up.
	 *
	 * @dataProvider endpoints
	 *
	 * @return void
	 */
	public function testEndpointsAreAdminGatedAndWritesNeedThePassword(string $method, bool $isWriting): void {
		$reflection = new ReflectionMethod(RecoveryAdminController::class, $method);
		$gate = $reflection->getAttributes(AuthorizedAdminSetting::class);

		$this->assertCount(expectedCount: 1, haystack: $gate);
		$this->assertSame(expected: ['settings' => PeopleAdminSettings::class], actual: $gate[0]->getArguments());
		$this->assertSame(
			expected: $isWriting,
			actual: $reflection->getAttributes(PasswordConfirmationRequired::class) !== []
		);
	}//end testEndpointsAreAdminGatedAndWritesNeedThePassword()

	/**
	 * Show reports the active key's public facts, never its certificate.
	 *
	 * @return void
	 */
	public function testShowReportsTheActiveKeyWithoutItsCertificate(): void {
		$key = new RecoveryKey();
		$key->setId('key-1');
		$key->setFingerprint('AB:CD');
		$key->setCertificate('-----BEGIN CERTIFICATE-----');
		$key->setCreatedBy('root');
		$key->setCreatedAt(new DateTime('2026-11-01T10:00:00+00:00'));
		$this->keys->method('activeKey')->willReturn($key);
		$this->policy->method('settings')->willReturn(['policy' => 'optional', 'officers' => [], 'threshold' => 1]);

		$data = $this->controller->show()->getData();

		$this->assertSame(expected: 'optional', actual: $data['policy']);
		$this->assertSame(
			expected: [
				'id' => 'key-1',
				'fingerprint' => 'AB:CD',
				'createdBy' => 'root',
				'createdAt' => '2026-11-01T10:00:00+00:00',
			],
			actual: $data['activeKey']
		);
	}//end testShowReportsTheActiveKeyWithoutItsCertificate()

	/**
	 * Show reports no active key as null.
	 *
	 * @return void
	 */
	public function testShowWithoutAnActiveKey(): void {
		$this->keys->method('activeKey')->willReturn(null);
		$this->policy->method('settings')->willReturn(['policy' => 'off']);

		$this->assertSame(expected: ['policy' => 'off', 'activeKey' => null], actual: $this->controller->show()->getData());
	}//end testShowWithoutAnActiveKey()

	/**
	 * An accepted policy change is audited with the acting admin and the officer count.
	 *
	 * @return void
	 */
	public function testUpdateAuditsTheChangeWithTheActingAdmin(): void {
		$settings = ['policy' => 'required', 'officers' => ['alice', 'bob'], 'threshold' => 2];
		$this->policy->expects($this->once())->method('update')
			->with('required', ['alice', 'bob'], 2)->willReturn($settings);
		$this->audit->expects($this->once())->method('record')->with(
			'root',
			RecoveryAudit::SETTINGS_CHANGED,
			'settings',
			['policy' => 'required', 'threshold' => 2, 'officerCount' => 2]
		);

		$response = $this->controller->update(policy: 'required', officers: ['alice', 'bob'], threshold: 2);

		$this->assertSame(expected: Http::STATUS_OK, actual: $response->getStatus());
		$this->assertSame(expected: $settings, actual: $response->getData());
	}//end testUpdateAuditsTheChangeWithTheActingAdmin()

	/**
	 * A refused policy answers 400 and records nothing.
	 *
	 * @return void
	 */
	public function testARefusedPolicyAnswers400AndIsNotAudited(): void {
		$this->policy->method('update')
			->willThrowException(new InvalidArgumentException(message: 'policy must be off, optional or required'));
		$this->audit->expects($this->never())->method('record');

		$response = $this->controller->update(policy: 'sometimes');

		$this->assertSame(expected: Http::STATUS_BAD_REQUEST, actual: $response->getStatus());
		$this->assertSame(expected: ['message' => 'policy must be off, optional or required'], actual: $response->getData());
	}//end testARefusedPolicyAnswers400AndIsNotAudited()

	/**
	 * Retiring a key names the acting admin.
	 *
	 * @return void
	 */
	public function testRetireKeyNamesTheActingAdmin(): void {
		$this->keys->expects($this->once())->method('retire')->with('key-1', 'root');

		$this->assertSame(expected: ['status' => 'retired'], actual: $this->controller->retireKey(id: 'key-1')->getData());
	}//end testRetireKeyNamesTheActingAdmin()

	/**
	 * Retiring a missing key answers 404.
	 *
	 * @return void
	 */
	public function testRetireAMissingKeyAnswers404(): void {
		$this->keys->method('retire')->willThrowException(new NotFoundException(message: 'gone'));

		$response = $this->controller->retireKey(id: 'key-1');

		$this->assertSame(expected: Http::STATUS_NOT_FOUND, actual: $response->getStatus());
		$this->assertSame(expected: ['message' => 'Not found'], actual: $response->getData());
	}//end testRetireAMissingKeyAnswers404()

	/**
	 * Enrolled reports the enrolment service's answer for the suite.
	 *
	 * @return void
	 */
	public function testEnrolledAsksForTheSuite(): void {
		$this->enrolments->expects($this->once())->method('isSuiteEnrolled')->with('suite-9')->willReturn(true);

		$this->assertSame(expected: ['enrolled' => true], actual: $this->controller->enrolled(suiteId: 'suite-9')->getData());
	}//end testEnrolledAsksForTheSuite()
}//end class
