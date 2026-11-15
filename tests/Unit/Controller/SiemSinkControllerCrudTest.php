<?php

/**
 * Contract tests for SIEM sink management.
 *
 * Sinks receive the audit stream, so managing them is reserved for the audit
 * area: an anonymous caller and a user without the area are refused with the
 * same 403. The request parameters are normalised before they reach the
 * service: create fills the defaults, update forwards only what was sent,
 * and the secrets are always forwarded so the service can keep or replace
 * them.
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
use OCA\Keepiq\Controller\SiemSinkController;
use OCA\Keepiq\Db\SiemSink;
use OCA\Keepiq\Service\AdminAreaAuthorizer;
use OCA\Keepiq\Service\SiemService;
use OCA\Keepiq\Settings\AuditAdminSettings;
use OCP\AppFramework\Db\DoesNotExistException;
use OCP\AppFramework\Http;
use OCP\IRequest;
use OCP\IUser;
use OCP\IUserSession;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

class SiemSinkControllerCrudTest extends TestCase {

	/**
	 * The request parameters the mocked request answers.
	 *
	 * @var array<string, mixed>
	 */
	private array $params = [];

	/**
	 * The mocked SIEM service.
	 *
	 * @var SiemService&MockObject
	 */
	private SiemService&MockObject $service;

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
	 * @var SiemSinkController
	 */
	private SiemSinkController $controller;

	/**
	 * Build the controller over mocks.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		parent::setUp();
		$request = $this->createMock(originalClassName: IRequest::class);
		$request->method('getParam')->willReturnCallback(fn (string $key) => ($this->params[$key] ?? null));
		$this->service = $this->createMock(originalClassName: SiemService::class);
		$this->session = $this->createMock(originalClassName: IUserSession::class);
		$this->areas = $this->createMock(originalClassName: AdminAreaAuthorizer::class);
		$this->controller = new SiemSinkController(
			request: $request,
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
	 * A sink row.
	 *
	 * @return SiemSink
	 */
	private function sink(): SiemSink {
		$sink = new SiemSink();
		$sink->setId('sink-1');
		$sink->setName('Splunk');
		return $sink;
	}//end sink()

	/**
	 * Every management endpoint.
	 *
	 * @return array<string, array{0: callable(SiemSinkController): mixed}>
	 */
	public static function endpoints(): array {
		return [
			'index'   => [static fn (SiemSinkController $c) => $c->index()],
			'create'  => [static fn (SiemSinkController $c) => $c->create()],
			'update'  => [static fn (SiemSinkController $c) => $c->update(id: 'sink-1')],
			'destroy' => [static fn (SiemSinkController $c) => $c->destroy(id: 'sink-1')],
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
		foreach (['listSinks', 'createSink', 'updateSink', 'deleteSink'] as $method) {
			$this->service->expects($this->never())->method($method);
		}

		$response = $call($this->controller);

		$this->assertSame(expected: Http::STATUS_FORBIDDEN, actual: $response->getStatus());
		$this->assertSame(expected: ['message' => 'SIEM sink management is admin-only'], actual: $response->getData());
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
		$this->areas->expects($this->never())->method('holds');

		$this->assertSame(expected: Http::STATUS_FORBIDDEN, actual: $call($this->controller)->getStatus());
	}//end testAnAnonymousCallerIsRefused()

	/**
	 * An admin lists the sinks.
	 *
	 * @return void
	 */
	public function testIndexListsTheSinks(): void {
		$this->signIn(isAdmin: true);
		$this->service->method('listSinks')->willReturn([$this->sink()]);

		$this->assertSame(expected: 'Splunk', actual: $this->controller->index()->getData()[0]['name']);
	}//end testIndexListsTheSinks()

	/**
	 * Create fills the defaults for what was not sent.
	 *
	 * @return void
	 */
	public function testCreateFillsTheDefaults(): void {
		$this->signIn(isAdmin: true);
		$this->params = ['name' => 'Splunk', 'type' => 'https', 'endpoint' => 'https://siem.example', 'hmacSecret' => 's3cret'];
		$this->service->expects($this->once())->method('createSink')->with(
			'alice',
			[
				'name' => 'Splunk',
				'type' => 'https',
				'endpoint' => 'https://siem.example',
				'tls' => true,
				'hmacSecret' => 's3cret',
				'categoryFilter' => [],
				'queueCap' => 1000,
				'enabled' => true,
				'format' => 'json',
				'credential' => '',
				'connectorOptions' => [],
			]
		)->willReturn($this->sink());

		$this->assertSame(expected: Http::STATUS_CREATED, actual: $this->controller->create()->getStatus());
	}//end testCreateFillsTheDefaults()

	/**
	 * Create reads booleans, numbers and lists from their request forms.
	 *
	 * @return void
	 */
	public function testCreateNormalisesTypedParameters(): void {
		$this->signIn(isAdmin: true);
		$this->params = [
			'name' => 'Syslog',
			'tls' => 'false',
			'enabled' => '0',
			'queueCap' => '50',
			'categoryFilter' => ['auth', 'share'],
			'format' => 'cef',
			'connectorOptions' => 'not-a-list',
		];
		$this->service->expects($this->once())->method('createSink')->with(
			'alice',
			$this->callback(
				callback: static fn (array $p): bool => $p['tls'] === false
					&& $p['enabled'] === false
					&& $p['queueCap'] === 50
					&& $p['categoryFilter'] === ['auth', 'share']
					&& $p['format'] === 'cef'
					&& $p['connectorOptions'] === []
			)
		)->willReturn($this->sink());

		$this->controller->create();
	}//end testCreateNormalisesTypedParameters()

	/**
	 * A refused create is a 400 with the service's message.
	 *
	 * @return void
	 */
	public function testARefusedCreateIs400(): void {
		$this->signIn(isAdmin: true);
		$this->service->method('createSink')->willThrowException(new InvalidArgumentException(message: 'Endpoint must be https'));

		$response = $this->controller->create();

		$this->assertSame(expected: Http::STATUS_BAD_REQUEST, actual: $response->getStatus());
		$this->assertSame(expected: ['message' => 'Endpoint must be https'], actual: $response->getData());
	}//end testARefusedCreateIs400()

	/**
	 * Update forwards only what was sent, plus the secrets.
	 *
	 * @return void
	 */
	public function testUpdateForwardsOnlyWhatWasSent(): void {
		$this->signIn(isAdmin: true);
		$this->params = ['name' => '', 'enabled' => 'true', 'queueCap' => 'many'];
		$this->service->expects($this->once())->method('updateSink')
			->with('alice', 'sink-1', ['hmacSecret' => '', 'credential' => '', 'enabled' => true])
			->willReturn($this->sink());

		$this->assertSame(expected: 'sink-1', actual: $this->controller->update(id: 'sink-1')->getData()['id']);
	}//end testUpdateForwardsOnlyWhatWasSent()

	/**
	 * Update and delete map a missing sink to 404; a refused update is a 400.
	 *
	 * @return void
	 */
	public function testMissingSinksAndRefusedUpdates(): void {
		$this->signIn(isAdmin: true);
		$this->service->method('updateSink')->willReturnOnConsecutiveCalls(
			$this->throwException(exception: new DoesNotExistException(msg: 'gone')),
			$this->throwException(exception: new InvalidArgumentException(message: 'Bad format'))
		);
		$this->service->method('deleteSink')->willThrowException(new DoesNotExistException(msg: 'gone'));

		$this->assertSame(expected: Http::STATUS_NOT_FOUND, actual: $this->controller->update(id: 'sink-9')->getStatus());
		$this->assertSame(expected: Http::STATUS_BAD_REQUEST, actual: $this->controller->update(id: 'sink-1')->getStatus());
		$this->assertSame(expected: ['message' => 'Sink not found'], actual: $this->controller->destroy(id: 'sink-9')->getData());
	}//end testMissingSinksAndRefusedUpdates()

	/**
	 * Delete runs as the admin.
	 *
	 * @return void
	 */
	public function testDestroyRunsAsTheAdmin(): void {
		$this->signIn(isAdmin: true);
		$this->service->expects($this->once())->method('deleteSink')->with('alice', 'sink-1');

		$this->assertSame(expected: ['deleted' => true], actual: $this->controller->destroy(id: 'sink-1')->getData());
	}//end testDestroyRunsAsTheAdmin()
}//end class
