<?php

/**
 * Unit tests for the SIEM sink write paths of AdminAuditController.
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
use OCA\Keepiq\Controller\AdminAuditController;
use OCA\Keepiq\Db\SiemSink;
use OCA\Keepiq\Kernel\Contracts\AuditServiceInterface;
use OCA\Keepiq\Kernel\Contracts\ComplianceReportServiceInterface;
use OCA\Keepiq\Kernel\Contracts\SiemServiceInterface;
use OCA\Keepiq\Service\Siem\SiemSinkRequest;
use OCP\AppFramework\Http;
use OCP\IRequest;
use OCP\IUser;
use OCP\IUserSession;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\UsesClass;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

/**
 * Sink create/update map a service refusal to 400 and return the saved sink otherwise.
 */
#[CoversClass(AdminAuditController::class)]
#[UsesClass(SiemSinkRequest::class)]
#[UsesClass(SiemSink::class)]
class AdminAuditControllerSinkWriteTest extends TestCase {
	/** @var SiemServiceInterface&MockObject */
	private SiemServiceInterface $siem;

	/**
	 * Fresh doubles.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		$this->siem = $this->createMock(SiemServiceInterface::class);
	}//end setUp()

	/**
	 * The controller, signed in as `admin`, over a request with the given params.
	 *
	 * @param array<string,mixed> $params The request params
	 *
	 * @return AdminAuditController
	 */
	private function controller(array $params = []): AdminAuditController {
		$user = $this->createStub(IUser::class);
		$user->method('getUID')->willReturn('admin');
		$session = $this->createStub(IUserSession::class);
		$session->method('getUser')->willReturn($user);
		$request = $this->createStub(IRequest::class);
		$request->method('getParam')->willReturnCallback(static fn (string $key): mixed => ($params[$key] ?? null));

		return new AdminAuditController(
			request: $request,
			audit: $this->createStub(AuditServiceInterface::class),
			reports: $this->createStub(ComplianceReportServiceInterface::class),
			siem: $this->siem,
			userSession: $session,
		);
	}//end controller()

	/**
	 * A create the service refuses is a 400 carrying its reason.
	 *
	 * @return void
	 */
	public function testCreateSinkReturnsBadRequestOnRefusal(): void {
		$this->siem->expects($this->once())
			->method('createSink')
			->with('admin', $this->callback(static fn (array $p): bool => $p['name'] === 'soc' && $p['format'] === 'json'))
			->willThrowException(new InvalidArgumentException('Endpoint must use https'));

		$response = $this->controller(params: ['name' => 'soc', 'endpoint' => 'http://x'])->createSink();

		$this->assertSame(Http::STATUS_BAD_REQUEST, $response->getStatus());
		$this->assertSame(['message' => 'Endpoint must use https'], $response->getData());
	}//end testCreateSinkReturnsBadRequestOnRefusal()

	/**
	 * An update the service refuses is a 400 carrying its reason.
	 *
	 * @return void
	 */
	public function testUpdateSinkReturnsBadRequestOnRefusal(): void {
		$this->siem->expects($this->once())
			->method('updateSink')
			->with('admin', 'sink-1', $this->anything())
			->willThrowException(new InvalidArgumentException('queueCap out of range'));

		$response = $this->controller(params: ['queueCap' => 0])->updateSink(id: 'sink-1');

		$this->assertSame(Http::STATUS_BAD_REQUEST, $response->getStatus());
		$this->assertSame(['message' => 'queueCap out of range'], $response->getData());
	}//end testUpdateSinkReturnsBadRequestOnRefusal()

	/**
	 * A successful update returns the saved sink with only the supplied fields passed on.
	 *
	 * @return void
	 */
	public function testUpdateSinkReturnsTheSavedSink(): void {
		$sink = new SiemSink();
		$sink->setId('7');
		$sink->setName('soc-renamed');

		$this->siem->expects($this->once())
			->method('updateSink')
			->with('admin', 'sink-7', ['hmacSecret' => '', 'credential' => '', 'name' => 'soc-renamed'])
			->willReturn($sink);

		$response = $this->controller(params: ['name' => 'soc-renamed'])->updateSink(id: 'sink-7');

		$this->assertSame(Http::STATUS_OK, $response->getStatus());
		$this->assertSame('7', $response->getData()['id']);
		$this->assertSame('soc-renamed', $response->getData()['name']);
		$this->assertFalse($response->getData()['hasCredential']);
	}//end testUpdateSinkReturnsTheSavedSink()
}//end class
