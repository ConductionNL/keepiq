<?php

/**
 * Contract tests for the Audit admin endpoints (admin-public-api §1.5).
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

use DateTime;
use InvalidArgumentException;
use OCA\Keepiq\Controller\AdminAuditController;
use OCA\Keepiq\Db\ComplianceReport;
use OCA\Keepiq\Db\SiemSink;
use OCA\Keepiq\Service\AuditService;
use OCA\Keepiq\Service\ComplianceReportService;
use OCA\Keepiq\Service\SiemService;
use OCP\AppFramework\Db\DoesNotExistException;
use OCP\IRequest;
use OCP\IUser;
use OCP\IUserSession;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

/**
 * Audit events, compliance reports and SIEM sinks through the admin API.
 */
class AdminAuditControllerTest extends TestCase {
	/** @var AuditService&MockObject */
	private AuditService $audit;

	/** @var ComplianceReportService&MockObject */
	private ComplianceReportService $reports;

	/** @var SiemService&MockObject */
	private SiemService $siem;

	/**
	 * Fresh doubles.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		$this->audit = $this->createMock(AuditService::class);
		$this->reports = $this->createMock(ComplianceReportService::class);
		$this->siem = $this->createMock(SiemService::class);
	}//end setUp()

	/**
	 * The controller, signed in as `svc-audit`.
	 *
	 * @param array<string,mixed> $params The request parameters
	 *
	 * @return AdminAuditController
	 */
	private function controller(array $params = []): AdminAuditController {
		$user = $this->createStub(IUser::class);
		$user->method('getUID')->willReturn('svc-audit');
		$session = $this->createStub(IUserSession::class);
		$session->method('getUser')->willReturn($user);
		$request = $this->createStub(IRequest::class);
		$request->method('getParam')->willReturnCallback(static fn (string $key, mixed $default = null): mixed => $params[$key] ?? $default);
		$request->method('getParams')->willReturn($params);

		return new AdminAuditController(
			request: $request,
			audit: $this->audit,
			reports: $this->reports,
			siem: $this->siem,
			userSession: $session,
		);
	}//end controller()

	/**
	 * Audit events pass every filter to the screen's query.
	 *
	 * @return void
	 *
	 * @spec openspec/specs/admin-api/spec.md#requirement-admin-api-returns-metadata-only
	 */
	public function testEventsPassTheFilters(): void {
		$page = ['results' => [], 'total' => 0];
		$this->audit->expects($this->once())->method('adminQuery')
			->with(['eventType' => 'share.granted', 'actor' => 'alice', 'objectType' => null, 'objectId' => null, 'from' => '2026-10-01', 'to' => null], 2, 10)
			->willReturn($page);

		$response = $this->controller()->events(eventType: 'share.granted', actor: 'alice', from: '2026-10-01', page: 2, limit: 10);

		$this->assertSame($page, $response->getData());
	}//end testEventsPassTheFilters()

	/**
	 * Reports list their metadata, generation is 201, an unknown report is 404.
	 *
	 * @return void
	 *
	 * @spec openspec/specs/admin-api/spec.md#requirement-admin-api-returns-metadata-only
	 */
	public function testComplianceReports(): void {
		$report = new ComplianceReport();
		$report->setId('rep-1');
		$report->setGeneratedBy('svc-audit');
		$report->setGeneratedAt(new DateTime('2026-10-02T10:00:00+00:00'));
		$report->setAppVersion('0.3.4');
		$this->reports->method('listReports')->willReturn([$report]);
		$this->reports->expects($this->once())->method('generate')->with('svc-audit')->willReturn($report);
		$this->reports->method('getReport')->willThrowException(new DoesNotExistException('no'));

		$list = $this->controller()->reports()->getData();
		$this->assertSame(['id' => 'rep-1', 'generatedBy' => 'svc-audit', 'generatedAt' => '2026-10-02T10:00:00+00:00', 'appVersion' => '0.3.4'], $list[0]);
		$this->assertSame(201, $this->controller()->generateReport()->getStatus());
		$this->assertSame(404, $this->controller()->showReport(id: 'nope')->getStatus());
	}//end testComplianceReports()

	/**
	 * A sink's HMAC secret and connector credential never leave the server
	 * (spec "Admin API returns metadata only").
	 *
	 * @return void
	 *
	 * @spec openspec/specs/admin-api/spec.md#requirement-admin-api-returns-metadata-only
	 */
	public function testSinksCarryNoSecret(): void {
		$sink = new SiemSink();
		$sink->setId('sink-1');
		$sink->setName('soc');
		$sink->setType('webhook');
		$sink->setEndpoint('https://soc.example/hook');
		$sink->setHmacSecretEnc('ENCRYPTED-HMAC');
		$sink->setCredentialEnc('ENCRYPTED-CREDENTIAL');
		$this->siem->method('listSinks')->willReturn([$sink]);

		$json = (string)json_encode($this->controller()->sinks()->getData());

		$this->assertStringContainsString('sink-1', $json);
		$this->assertStringNotContainsString('ENCRYPTED-HMAC', $json);
		$this->assertStringNotContainsString('ENCRYPTED-CREDENTIAL', $json);
	}//end testSinksCarryNoSecret()

	/**
	 * Sink writes: a refused create is 400, an unknown sink is 404 on update
	 * and delete, a delete reports it.
	 *
	 * @return void
	 *
	 * @spec openspec/specs/admin-api/spec.md#requirement-admin-api-returns-metadata-only
	 */
	public function testSinkWrites(): void {
		$this->siem->method('createSink')->willThrowException(new InvalidArgumentException('endpoint is required'));
		$this->siem->method('updateSink')->willThrowException(new DoesNotExistException('no'));
		$this->siem->expects($this->exactly(2))->method('deleteSink')->with('svc-audit', $this->anything())
			->willReturnCallback(static function (string $admin, string $id): void {
				if ($id === 'nope') {
					throw new DoesNotExistException('no');
				}
			});

		$this->assertSame(400, $this->controller(params: ['name' => 'x'])->createSink()->getStatus());
		$this->assertSame(404, $this->controller()->updateSink(id: 'nope')->getStatus());
		$this->assertSame(['deleted' => true], $this->controller()->destroySink(id: 'sink-1')->getData());
		$this->assertSame(404, $this->controller()->destroySink(id: 'nope')->getStatus());
	}//end testSinkWrites()
}//end class
