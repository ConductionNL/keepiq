<?php

/**
 * Outcome tests for CertificateController.
 *
 * Every action refuses an anonymous caller with 403, passes the SESSION user
 * (and the admin-area decision) to the lifecycle service, and maps each
 * service refusal to its own status.
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
use OCA\Keepiq\Controller\CertificateController;
use OCA\Keepiq\Db\CertificateMetadata;
use OCA\Keepiq\Service\AdminAreaAuthorizer;
use OCA\Keepiq\Service\CertificateLifecycleService;
use OCA\Keepiq\Settings\AdminSettings;
use OCP\AppFramework\Db\DoesNotExistException;
use OCP\AppFramework\Http;
use OCP\IRequest;
use OCP\IUser;
use OCP\IUserSession;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use RuntimeException;

/**
 * @covers \OCA\Keepiq\Controller\CertificateController
 * @uses   \OCA\Keepiq\Db\CertificateMetadata
 */
class CertificateControllerOutcomesTest extends TestCase {

	/**
	 * The mocked lifecycle service.
	 *
	 * @var CertificateLifecycleService&MockObject
	 */
	private CertificateLifecycleService&MockObject $service;

	/**
	 * The mocked user session.
	 *
	 * @var IUserSession&MockObject
	 */
	private IUserSession&MockObject $session;

	/**
	 * The mocked admin-area authorizer.
	 *
	 * @var AdminAreaAuthorizer&MockObject
	 */
	private AdminAreaAuthorizer&MockObject $areas;

	/**
	 * Build fresh mocks for every test.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		parent::setUp();
		$this->service = $this->createMock(CertificateLifecycleService::class);
		$this->session = $this->createMock(IUserSession::class);
		$this->areas = $this->createMock(AdminAreaAuthorizer::class);
	}//end setUp()

	/**
	 * The controller under test.
	 *
	 * @return CertificateController
	 */
	private function controller(): CertificateController {
		return new CertificateController(
			request: $this->createMock(IRequest::class),
			service: $this->service,
			userSession: $this->session,
			areas: $this->areas,
		);
	}//end controller()

	/**
	 * Sign in as the given user.
	 *
	 * @param string $uid The user id
	 *
	 * @return void
	 */
	private function signIn(string $uid): void {
		$user = $this->createMock(IUser::class);
		$user->method('getUID')->willReturn($uid);
		$this->session->method('getUser')->willReturn($user);
	}//end signIn()

	/**
	 * Every action refuses an anonymous caller without touching the service.
	 *
	 * @return void
	 */
	public function testEveryActionRefusesAnAnonymousCaller(): void {
		$this->session->method('getUser')->willReturn(null);
		$this->service->expects($this->never())->method($this->anything());

		$responses = [
			$this->controller()->inventory(),
			$this->controller()->submitMetadata(secretId: 's-1'),
			$this->controller()->renewalChecklist(secretId: 's-1'),
			$this->controller()->reissueSuite(suiteId: 'suite-1'),
		];

		foreach ($responses as $response) {
			$this->assertSame(Http::STATUS_FORBIDDEN, $response->getStatus());
			$this->assertSame(['message' => 'Unauthenticated'], $response->getData());
		}
	}//end testEveryActionRefusesAnAnonymousCaller()

	/**
	 * The inventory asks the authorizer for the General area and passes the
	 * decision on with the session user.
	 *
	 * @return void
	 */
	public function testInventoryPassesTheSessionUserAndTheAdminDecision(): void {
		$this->signIn('alice');
		$this->areas->expects($this->once())->method('holds')
			->with('alice', AdminSettings::class)
			->willReturn(true);
		$this->service->expects($this->once())->method('inventory')
			->with('alice', true)
			->willReturn(['stored' => [], 'suites' => ['x']]);

		$response = $this->controller()->inventory();

		$this->assertSame(Http::STATUS_OK, $response->getStatus());
		$this->assertSame(['stored' => [], 'suites' => ['x']], $response->getData());
	}//end testInventoryPassesTheSessionUserAndTheAdminDecision()

	/**
	 * A metadata submit forwards every field under its own name and answers
	 * the serialized row.
	 *
	 * @return void
	 */
	public function testSubmitMetadataForwardsEveryFieldAndAnswersTheRow(): void {
		$this->signIn('alice');
		$row = new CertificateMetadata();
		$row->setSecretId('s-1');
		$row->setSubject('CN=example');
		$this->service->expects($this->once())->method('submitMetadata')
			->with(
				's-1',
				'alice',
				[
					'subject' => 'CN=example',
					'issuer' => 'CN=ca',
					'serial' => '01',
					'fingerprintSha256' => 'sha256:ab',
					'notBefore' => '2026-01-01T00:00:00Z',
					'notAfter' => '2027-01-01T00:00:00Z',
				],
			)
			->willReturn($row);

		$response = $this->controller()->submitMetadata(
			secretId: 's-1',
			subject: 'CN=example',
			issuer: 'CN=ca',
			serial: '01',
			fingerprintSha256: 'sha256:ab',
			notBefore: '2026-01-01T00:00:00Z',
			notAfter: '2027-01-01T00:00:00Z',
		);

		$this->assertSame(Http::STATUS_OK, $response->getStatus());
		$this->assertSame('s-1', $response->getData()['secretId']);
		$this->assertSame('CN=example', $response->getData()['subject']);
	}//end testSubmitMetadataForwardsEveryFieldAndAnswersTheRow()

	/**
	 * A missing secret answers 404; a refusal answers 403 with its message.
	 *
	 * @return void
	 */
	public function testSubmitMetadataMapsMissingAndRefusedSecrets(): void {
		$this->signIn('alice');
		$this->service->method('submitMetadata')->willReturnOnConsecutiveCalls(
			$this->throwException(new DoesNotExistException('gone')),
			$this->throwException(new InvalidArgumentException('Not a certificate')),
		);

		$missing = $this->controller()->submitMetadata(secretId: 's-1');
		$refused = $this->controller()->submitMetadata(secretId: 's-1');

		$this->assertSame(Http::STATUS_NOT_FOUND, $missing->getStatus());
		$this->assertSame(['message' => 'Secret not found'], $missing->getData());
		$this->assertSame(Http::STATUS_FORBIDDEN, $refused->getStatus());
		$this->assertSame(['message' => 'Not a certificate'], $refused->getData());
	}//end testSubmitMetadataMapsMissingAndRefusedSecrets()

	/**
	 * The renewal checklist answers the service's checklist for the session
	 * user, 404 for a missing secret and 403 for a refusal.
	 *
	 * @return void
	 */
	public function testRenewalChecklistOutcomes(): void {
		$this->signIn('bob');
		$this->service->expects($this->exactly(3))->method('renewalChecklist')
			->with('s-2', 'bob')
			->willReturnOnConsecutiveCalls(
				['origin' => 'external', 'steps' => ['a']],
				$this->throwException(new DoesNotExistException('gone')),
				$this->throwException(new InvalidArgumentException('Not yours')),
			);

		$ok = $this->controller()->renewalChecklist(secretId: 's-2');
		$missing = $this->controller()->renewalChecklist(secretId: 's-2');
		$refused = $this->controller()->renewalChecklist(secretId: 's-2');

		$this->assertSame(Http::STATUS_OK, $ok->getStatus());
		$this->assertSame(['origin' => 'external', 'steps' => ['a']], $ok->getData());
		$this->assertSame(Http::STATUS_NOT_FOUND, $missing->getStatus());
		$this->assertSame(['message' => 'Secret not found'], $missing->getData());
		$this->assertSame(Http::STATUS_FORBIDDEN, $refused->getStatus());
		$this->assertSame(['message' => 'Not yours'], $refused->getData());
	}//end testRenewalChecklistOutcomes()

	/**
	 * A re-issue passes the admin decision and answers the service's row.
	 *
	 * @return void
	 */
	public function testReissueSuitePassesTheAdminDecisionAndAnswersTheRow(): void {
		$this->signIn('carol');
		$this->areas->method('holds')->with('carol', AdminSettings::class)->willReturn(false);
		$this->service->expects($this->once())->method('reissueSuite')
			->with('suite-1', 'carol', false)
			->willReturn(['id' => 'suite-1', 'certificate' => 'PEM']);

		$response = $this->controller()->reissueSuite(suiteId: 'suite-1');

		$this->assertSame(Http::STATUS_OK, $response->getStatus());
		$this->assertSame(['id' => 'suite-1', 'certificate' => 'PEM'], $response->getData());
	}//end testReissueSuitePassesTheAdminDecisionAndAnswersTheRow()

	/**
	 * A re-issue maps a missing suite to 404, a refusal to 403 and a CA
	 * failure to 409, each with its message.
	 *
	 * @return void
	 */
	public function testReissueSuiteMapsEachRefusal(): void {
		$this->signIn('carol');
		$this->service->method('reissueSuite')->willReturnOnConsecutiveCalls(
			$this->throwException(new DoesNotExistException('gone')),
			$this->throwException(new InvalidArgumentException('Not your suite')),
			$this->throwException(new RuntimeException('No CA configured')),
		);

		$missing = $this->controller()->reissueSuite(suiteId: 'suite-1');
		$refused = $this->controller()->reissueSuite(suiteId: 'suite-1');
		$conflict = $this->controller()->reissueSuite(suiteId: 'suite-1');

		$this->assertSame(Http::STATUS_NOT_FOUND, $missing->getStatus());
		$this->assertSame(['message' => 'Suite not found'], $missing->getData());
		$this->assertSame(Http::STATUS_FORBIDDEN, $refused->getStatus());
		$this->assertSame(['message' => 'Not your suite'], $refused->getData());
		$this->assertSame(Http::STATUS_CONFLICT, $conflict->getStatus());
		$this->assertSame(['message' => 'No CA configured'], $conflict->getData());
	}//end testReissueSuiteMapsEachRefusal()
}//end class
