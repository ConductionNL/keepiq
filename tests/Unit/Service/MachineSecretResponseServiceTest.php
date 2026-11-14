<?php

/**
 * Unit tests for the HTTP shaping of MachineSecretResponseService (leases, ETag, audit).
 *
 * @category Test
 * @package  OCA\Keepiq\Tests\Unit\Service
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

namespace OCA\Keepiq\Tests\Unit\Service;

use DateTime;
use OCA\Keepiq\Db\MachineLease;
use OCA\Keepiq\Db\Secret;
use OCA\Keepiq\Event\Audit\AuditEvent;
use OCA\Keepiq\Event\Audit\AuditEventFactory;
use OCA\Keepiq\Event\Audit\AuditEventTypes;
use OCA\Keepiq\Service\LeaseService;
use OCA\Keepiq\Service\MachineSecretEnvelopeService;
use OCA\Keepiq\Service\MachineSecretResponseService;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\Response;
use OCP\EventDispatcher\IEventDispatcher;
use OCP\IRequest;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\UsesClass;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use ReflectionProperty;

/**
 * Envelope responses of the machine secret API.
 */
#[CoversClass(MachineSecretResponseService::class)]
#[UsesClass(Secret::class)]
#[UsesClass(MachineLease::class)]
#[UsesClass(AuditEvent::class)]
#[UsesClass(AuditEventFactory::class)]
class MachineSecretResponseServiceTest extends TestCase {
	private IRequest&MockObject $request;

	private MachineSecretEnvelopeService&MockObject $envelopes;

	private IEventDispatcher&MockObject $dispatcher;

	private LeaseService&MockObject $leases;

	/**
	 * The request headers by name.
	 *
	 * @var array<string,string>
	 */
	private array $headers = [];

	/**
	 * The request params by name.
	 *
	 * @var array<string,mixed>
	 */
	private array $params = [];

	/**
	 * Wire the mocks.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		parent::setUp();
		$this->request = $this->createMock(originalClassName: IRequest::class);
		$this->request->method('getHeader')->willReturnCallback(fn (string $name): string => $this->headers[$name] ?? '');
		$this->request->method('getParam')->willReturnCallback(fn (string $key, $default = null) => $this->params[$key] ?? $default);
		$this->envelopes = $this->createMock(originalClassName: MachineSecretEnvelopeService::class);
		$this->envelopes->method('etag')->willReturn('"v1"');
		$this->envelopes->method('serialize')->willReturn(['apiVersion' => 'v1', 'value' => 'x']);
		$this->dispatcher = $this->createMock(originalClassName: IEventDispatcher::class);
		$this->leases = $this->createMock(originalClassName: LeaseService::class);
	}//end setUp()

	/**
	 * The secret being fetched.
	 *
	 * @return Secret
	 */
	private function secret(): Secret {
		$secret = new Secret();
		$secret->setId('secret-1');
		$secret->setName('db password');

		return $secret;
	}//end secret()

	/**
	 * A service with or without the lease service.
	 *
	 * @param bool $withLeases Whether leases are wired
	 *
	 * @return MachineSecretResponseService
	 */
	private function service(bool $withLeases = true): MachineSecretResponseService {
		return new MachineSecretResponseService(
			request: $this->request,
			envelopeService: $this->envelopes,
			eventDispatcher: $this->dispatcher,
			leaseService: $withLeases === true ? $this->leases : null,
		);
	}//end service()

	/**
	 * The headers the service set on the response itself.
	 *
	 * Response::getHeaders() merges server defaults through the container,
	 * which a unit test does not boot, so read the response's own bag.
	 *
	 * @param Response $response The response
	 *
	 * @return array<string,string>
	 */
	private function headersOf(Response $response): array {
		return (new ReflectionProperty(Response::class, 'headers'))->getValue($response);
	}//end headersOf()

	/**
	 * A blocked lease refuses the fetch with 403 and audits nothing.
	 *
	 * @return void
	 */
	public function testBlockedLeaseRefusesTheFetch(): void {
		$this->leases->method('fetchBlocked')->with('app-1', 'secret-1')->willReturn(true);
		$this->leases->expects($this->never())->method('grantOrReuse');
		$this->dispatcher->expects($this->never())->method('dispatchTyped');

		$response = $this->service()->envelope(secret: $this->secret(), applicationId: 'app-1');

		$this->assertSame(Http::STATUS_FORBIDDEN, $response->getStatus());
		$this->assertSame(['message' => 'Lease revoked — access to this secret is blocked until re-granted'], $response->getData());
		$this->assertArrayNotHasKey('ETag', $this->headersOf(response: $response));
	}//end testBlockedLeaseRefusesTheFetch()

	/**
	 * A plain fetch serves the envelope, its ETag and lease headers, and audits it.
	 *
	 * @return void
	 */
	public function testFetchServesEnvelopeWithLeaseHeadersAndAudits(): void {
		$lease = new MachineLease();
		$lease->setId('lease-9');
		$lease->setExpiresAt(new DateTime('2030-01-02T03:04:05+00:00'));
		$this->leases->method('fetchBlocked')->willReturn(false);
		$this->leases->expects($this->once())->method('grantOrReuse')
			->with('app-1', 'secret-1', null)
			->willReturn($lease);
		$this->dispatcher->expects($this->once())->method('dispatchTyped')->with(
			$this->callback(static function (AuditEvent $event): bool {
				return $event->getEventType() === AuditEventTypes::APPLICATION_SECRET_RETRIEVED
					&& $event->getActorType() === AuditEvent::ACTOR_APPLICATION
					&& $event->getActorId() === 'app-1'
					&& $event->getObjectType() === 'secret'
					&& $event->getObjectId() === 'secret-1'
					&& $event->getObjectName() === 'db password';
			})
		);

		$response = $this->service()->envelope(secret: $this->secret(), applicationId: 'app-1');
		$headers = $this->headersOf(response: $response);

		$this->assertSame(Http::STATUS_OK, $response->getStatus());
		$this->assertSame(['apiVersion' => 'v1', 'value' => 'x'], $response->getData());
		$this->assertSame('"v1"', $headers['ETag']);
		$this->assertSame('lease-9', $headers['Doriath-Lease-Id']);
		$this->assertSame('2030-01-02T03:04:05+00:00', $headers['Doriath-Lease-Expires']);
	}//end testFetchServesEnvelopeWithLeaseHeadersAndAudits()

	/**
	 * A numeric lease_ttl parameter is passed as the requested TTL.
	 *
	 * @return void
	 */
	public function testRequestedTtlIsPassedToTheLease(): void {
		$this->params['lease_ttl'] = '600';
		$this->leases->method('fetchBlocked')->willReturn(false);
		$this->leases->expects($this->once())->method('grantOrReuse')
			->with('app-1', 'secret-1', 600)
			->willReturn(new MachineLease());

		$this->service()->envelope(secret: $this->secret(), applicationId: 'app-1');
	}//end testRequestedTtlIsPassedToTheLease()

	/**
	 * An empty lease_ttl parameter means no requested TTL.
	 *
	 * @return void
	 */
	public function testEmptyTtlMeansNoRequestedTtl(): void {
		$this->params['lease_ttl'] = '';
		$this->leases->method('fetchBlocked')->willReturn(false);
		$this->leases->expects($this->once())->method('grantOrReuse')
			->with('app-1', 'secret-1', null)
			->willReturn(new MachineLease());

		$this->service()->envelope(secret: $this->secret(), applicationId: 'app-1');
	}//end testEmptyTtlMeansNoRequestedTtl()

	/**
	 * Without a lease service there are no lease headers.
	 *
	 * @return void
	 */
	public function testWithoutLeaseServiceThereAreNoLeaseHeaders(): void {
		$response = $this->service(withLeases: false)->envelope(secret: $this->secret(), applicationId: 'app-1');
		$headers = $this->headersOf(response: $response);

		$this->assertSame(Http::STATUS_OK, $response->getStatus());
		$this->assertSame('"v1"', $headers['ETag']);
		$this->assertArrayNotHasKey('Doriath-Lease-Id', $headers);
		$this->assertArrayNotHasKey('Doriath-Lease-Expires', $headers);
	}//end testWithoutLeaseServiceThereAreNoLeaseHeaders()

	/**
	 * Matching If-None-Match values answer 304 without a body or an audit row.
	 *
	 * @return array<string,array{string}>
	 */
	public static function matchingHeaders(): array {
		return [
			'exact' => ['"v1"'],
			'wildcard' => [' * '],
			'weak' => ['W/"v1"'],
			'in a list' => ['"v0", "v1"'],
		];
	}//end matchingHeaders()

	/**
	 * A matching If-None-Match answers 304.
	 *
	 * @param string $header The If-None-Match header value
	 *
	 * @return void
	 *
	 * @dataProvider matchingHeaders
	 */
	public function testMatchingIfNoneMatchIsNotModified(string $header): void {
		$this->headers['If-None-Match'] = $header;
		$lease = new MachineLease();
		$lease->setId('lease-9');
		$this->leases->method('fetchBlocked')->willReturn(false);
		$this->leases->method('grantOrReuse')->willReturn($lease);
		$this->dispatcher->expects($this->never())->method('dispatchTyped');
		$this->envelopes->expects($this->never())->method('serialize');

		$response = $this->service()->envelope(secret: $this->secret(), applicationId: 'app-1');
		$headers = $this->headersOf(response: $response);

		$this->assertSame(Http::STATUS_NOT_MODIFIED, $response->getStatus());
		$this->assertSame([], $response->getData());
		$this->assertSame('"v1"', $headers['ETag']);
		$this->assertSame('lease-9', $headers['Doriath-Lease-Id']);
		$this->assertSame('', $headers['Doriath-Lease-Expires']);
	}//end testMatchingIfNoneMatchIsNotModified()

	/**
	 * A stale If-None-Match serves the full envelope.
	 *
	 * @return void
	 */
	public function testStaleIfNoneMatchServesTheEnvelope(): void {
		$this->headers['If-None-Match'] = '"v0", W/"v2"';
		$this->dispatcher->expects($this->once())->method('dispatchTyped');

		$response = $this->service(withLeases: false)->envelope(secret: $this->secret(), applicationId: 'app-1');

		$this->assertSame(Http::STATUS_OK, $response->getStatus());
		$this->assertSame(['apiVersion' => 'v1', 'value' => 'x'], $response->getData());
	}//end testStaleIfNoneMatchServesTheEnvelope()
}//end class
