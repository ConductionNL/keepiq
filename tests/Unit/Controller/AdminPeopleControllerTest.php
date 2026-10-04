<?php

/**
 * Contract tests for the People admin endpoints (admin-public-api §1.2).
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

use InvalidArgumentException;
use OCA\Keepiq\Controller\AdminPeopleController;
use OCA\Keepiq\Db\EncryptionSuite;
use OCA\Keepiq\Db\EncryptionSuiteMapper;
use OCA\Keepiq\Service\TeamFolderService;
use OCP\IRequest;
use OCP\IUser;
use OCP\IUserSession;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

/**
 * Suite listing carries metadata only; offboarding runs the screen's service.
 */
class AdminPeopleControllerTest extends TestCase {
	/** @var EncryptionSuiteMapper&MockObject */
	private EncryptionSuiteMapper $suites;

	/** @var TeamFolderService&MockObject */
	private TeamFolderService $teamFolders;

	/**
	 * Fresh doubles.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		$this->suites = $this->createMock(EncryptionSuiteMapper::class);
		$this->teamFolders = $this->createMock(TeamFolderService::class);
	}//end setUp()

	/**
	 * The controller, signed in as `helpdesk`.
	 *
	 * @return AdminPeopleController
	 */
	private function controller(): AdminPeopleController {
		$user = $this->createStub(IUser::class);
		$user->method('getUID')->willReturn('helpdesk');
		$session = $this->createStub(IUserSession::class);
		$session->method('getUser')->willReturn($user);

		return new AdminPeopleController(
			request: $this->createStub(IRequest::class),
			suites: $this->suites,
			teamFolders: $this->teamFolders,
			userSession: $session,
		);
	}//end controller()

	/**
	 * A suite row has id, owner, status and dates, and never the private key
	 * or the certificate (spec "Suite listing carries no key material").
	 *
	 * @return void
	 *
	 * @spec openspec/specs/admin-api/spec.md#requirement-admin-api-covers-the-administration-jobs
	 */
	public function testSuitesCarryNoKeyMaterial(): void {
		$suite = new EncryptionSuite();
		$suite->setId('suite-1');
		$suite->setOwnerType('user');
		$suite->setOwnerId('alice');
		$suite->setStatus('active');
		$suite->setPrivateKey('ENCRYPTED-PRIVATE-KEY-BLOB');
		$suite->setCertificate('-----BEGIN CERTIFICATE-----');
		$this->suites->expects($this->once())->method('findAllActiveWithLimit')->with(500, 0)->willReturn([$suite]);

		$data = $this->controller()->suites(limit: 9999, offset: -3)->getData();

		$this->assertSame(500, $data['limit']);
		$this->assertSame(0, $data['offset']);
		$this->assertSame('suite-1', $data['results'][0]['id']);
		$this->assertSame('alice', $data['results'][0]['ownerId']);
		$this->assertArrayNotHasKey('privateKey', $data['results'][0]);
		$this->assertArrayNotHasKey('certificate', $data['results'][0]);
		$this->assertStringNotContainsString('PRIVATE-KEY', (string)json_encode($data));
	}//end testSuitesCarryNoKeyMaterial()

	/**
	 * Offboarding runs the screen's service as the caller and returns its summary.
	 *
	 * @return void
	 *
	 * @spec openspec/specs/admin-api/spec.md#requirement-admin-api-covers-the-administration-jobs
	 */
	public function testOffboardingRunsTheScreensService(): void {
		$summary = ['revoked' => 2, 'removedMemberships' => 1, 'transferred' => 3, 'skipped' => []];
		$this->teamFolders->expects($this->once())->method('offboard')->with('carol', 'dave', 'helpdesk')->willReturn($summary);

		$response = $this->controller()->offboard(leavingUserId: 'carol', successorUserId: 'dave');

		$this->assertSame(200, $response->getStatus());
		$this->assertSame($summary, $response->getData());
	}//end testOffboardingRunsTheScreensService()

	/**
	 * A refused offboarding answers 400 with the service's message.
	 *
	 * @return void
	 *
	 * @spec openspec/specs/admin-api/spec.md#requirement-admin-api-covers-the-administration-jobs
	 */
	public function testARefusedOffboardingAnswers400(): void {
		$this->teamFolders->method('offboard')->willThrowException(new InvalidArgumentException('Successor must differ from the leaving user'));

		$response = $this->controller()->offboard(leavingUserId: 'carol', successorUserId: 'carol');

		$this->assertSame(400, $response->getStatus());
		$this->assertSame(['message' => 'Successor must differ from the leaving user'], $response->getData());
	}//end testARefusedOffboardingAnswers400()
}//end class
