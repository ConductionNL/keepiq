<?php

/**
 * Contract tests for generating, listing and reading compliance reports.
 *
 * Compliance reports summarise the whole instance, so they are reserved for
 * the audit area: an anonymous caller and a user without the area get the
 * same 403 before the service runs. The listing carries only the report
 * header, never the report body; a missing report is a 404.
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
use OCA\Keepiq\Controller\ComplianceReportController;
use OCA\Keepiq\Db\ComplianceReport;
use OCA\Keepiq\Service\AdminAreaAuthorizer;
use OCA\Keepiq\Service\ComplianceReportService;
use OCA\Keepiq\Settings\AuditAdminSettings;
use OCP\AppFramework\Db\DoesNotExistException;
use OCP\AppFramework\Http;
use OCP\IRequest;
use OCP\IUser;
use OCP\IUserSession;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

class ComplianceReportControllerReportsTest extends TestCase {

	/**
	 * The mocked compliance report service.
	 *
	 * @var ComplianceReportService&MockObject
	 */
	private ComplianceReportService&MockObject $service;

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
	 * @var ComplianceReportController
	 */
	private ComplianceReportController $controller;

	/**
	 * Build the controller over mocks.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		parent::setUp();
		$this->service = $this->createMock(originalClassName: ComplianceReportService::class);
		$this->session = $this->createMock(originalClassName: IUserSession::class);
		$this->areas = $this->createMock(originalClassName: AdminAreaAuthorizer::class);
		$this->controller = new ComplianceReportController(
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
	 * A stored report.
	 *
	 * @return ComplianceReport
	 */
	private function report(): ComplianceReport {
		$report = new ComplianceReport();
		$report->setId('rep-1');
		$report->setGeneratedBy('alice');
		$report->setGeneratedAt(new DateTime('2026-11-01T09:00:00+00:00'));
		$report->setAppVersion('1.4.0');
		return $report;
	}//end report()

	/**
	 * The report endpoints.
	 *
	 * @return array<string, array{0: callable(ComplianceReportController): mixed}>
	 */
	public static function endpoints(): array {
		return [
			'generate' => [static fn (ComplianceReportController $c) => $c->generate()],
			'index'    => [static fn (ComplianceReportController $c) => $c->index()],
			'show'     => [static fn (ComplianceReportController $c) => $c->show(id: 'rep-1')],
		];
	}//end endpoints()

	/**
	 * A user without the audit area is refused before the service runs.
	 *
	 * @param callable $call The endpoint call.
	 *
	 * @dataProvider endpoints
	 *
	 * @return void
	 */
	public function testANonAdminIsRefused(callable $call): void {
		$this->signIn(isAdmin: false);
		foreach (['generate', 'listReports', 'getReport'] as $method) {
			$this->service->expects($this->never())->method($method);
		}

		$response = $call($this->controller);

		$this->assertSame(expected: Http::STATUS_FORBIDDEN, actual: $response->getStatus());
		$this->assertSame(expected: ['message' => 'Compliance reporting is admin-only'], actual: $response->getData());
	}//end testANonAdminIsRefused()

	/**
	 * An anonymous caller gets the same refusal.
	 *
	 * @param callable $call The endpoint call.
	 *
	 * @dataProvider endpoints
	 *
	 * @return void
	 */
	public function testAnAnonymousCallerIsRefused(callable $call): void {
		$this->session->method('getUser')->willReturn(null);

		$this->assertSame(expected: Http::STATUS_FORBIDDEN, actual: $call($this->controller)->getStatus());
	}//end testAnAnonymousCallerIsRefused()

	/**
	 * Generating records the acting admin and answers 201.
	 *
	 * @return void
	 */
	public function testGenerateRecordsTheActingAdmin(): void {
		$this->signIn(isAdmin: true);
		$this->service->expects($this->once())->method('generate')->with('alice')->willReturn($this->report());

		$response = $this->controller->generate();

		$this->assertSame(expected: Http::STATUS_CREATED, actual: $response->getStatus());
		$this->assertSame(expected: 'rep-1', actual: $response->getData()['id']);
	}//end testGenerateRecordsTheActingAdmin()

	/**
	 * The listing carries only the report header.
	 *
	 * @return void
	 */
	public function testIndexCarriesOnlyTheReportHeader(): void {
		$this->signIn(isAdmin: true);
		$this->service->method('listReports')->willReturn([$this->report()]);

		$this->assertSame(
			expected: [[
				'id' => 'rep-1',
				'generatedBy' => 'alice',
				'generatedAt' => '2026-11-01T09:00:00+00:00',
				'appVersion' => '1.4.0',
			]],
			actual: $this->controller->index()->getData()
		);
	}//end testIndexCarriesOnlyTheReportHeader()

	/**
	 * Show reads a report; a missing one is a 404.
	 *
	 * @return void
	 */
	public function testShow(): void {
		$this->signIn(isAdmin: true);
		$this->service->method('getReport')->willReturnCallback(
			function (string $id): ComplianceReport {
				if ($id !== 'rep-1') {
					throw new DoesNotExistException(msg: 'gone');
				}

				return $this->report();
			}
		);

		$this->assertSame(expected: 'rep-1', actual: $this->controller->show(id: 'rep-1')->getData()['id']);
		$missing = $this->controller->show(id: 'rep-9');
		$this->assertSame(expected: Http::STATUS_NOT_FOUND, actual: $missing->getStatus());
		$this->assertSame(expected: ['message' => 'Report not found'], actual: $missing->getData());
	}//end testShow()
}//end class
