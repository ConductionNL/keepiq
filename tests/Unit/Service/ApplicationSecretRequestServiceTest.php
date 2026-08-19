<?php

/**
 * Doriath — application-owned secret request tests
 *
 * What these lock down:
 *
 *  - the shell and the request are created together, and a failure after the
 *    shell is written removes it (no orphan Secret in an application's vault)
 *  - the actor recorded is `application:<id>`, which is also what scopes the
 *    pending listing — a value only this service writes, so one application
 *    cannot enumerate another's
 *  - the APPLICATION's write lock is honoured, not a user's
 *  - the signed-proof seam derives the vault from the VERIFIED `iss`, refuses an
 *    application id presented alone, and refuses every verification failure
 *  - guard parity with token issuance, and exactly one application-actor audit
 *    event per creation carrying no field names
 *
 * @category Test
 * @package  OCA\Doriath\Tests\Unit\Service
 *
 * @author    Conduction Development Team <dev@conductio.nl>
 * @copyright 2024 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git-id>
 *
 * @link https://conduction.nl
 */

declare(strict_types=1);

namespace OCA\Doriath\Tests\Unit\Service;

use DateTime;
use InvalidArgumentException;
use OCA\Doriath\Db\Application;
use OCA\Doriath\Db\Secret;
use OCA\Doriath\Db\SecretRequest;
use OCA\Doriath\Db\SecretRequestMapper;
use OCA\Doriath\Event\Audit\AuditEventTypes;
use OCA\Doriath\Service\ApplicationSecretRequestService;
use OCA\Doriath\Service\JwtAuthService;
use OCA\Doriath\Service\SecretRequestOutbox;
use OCA\Doriath\Service\SecretRequestPolicy;
use OCA\Doriath\Service\SecretService;
use OCA\Doriath\Service\WriteLockService;
use OCP\EventDispatcher\IEventDispatcher;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;
use RuntimeException;

/**
 * Tests for session-less, application-keyed secret-request creation.
 */
class ApplicationSecretRequestServiceTest extends TestCase {
	/**
	 * @var SecretRequestMapper&MockObject
	 */
	private SecretRequestMapper&MockObject $mapper;

	/**
	 * @var SecretService&MockObject
	 */
	private SecretService&MockObject $secretService;

	/**
	 * @var ContainerInterface&MockObject
	 */
	private ContainerInterface&MockObject $container;

	/**
	 * Wire the shared doubles.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		$this->mapper = $this->createMock(SecretRequestMapper::class);
		$this->secretService = $this->createMock(SecretService::class);
		$this->container = $this->createMock(ContainerInterface::class);
		$this->container->method('get')->willReturn($this->secretService);
	}//end setUp()

	/**
	 * A pending request fixture.
	 *
	 * @return SecretRequest
	 */
	private function buildPending(): SecretRequest {
		$entity = new SecretRequest();
		$entity->setId('req-1');
		$entity->setSecretId('sec-1');
		$entity->setEncryptionSuiteId('suite-1');
		$entity->setRequestedFields((string)json_encode(['key']));
		$entity->setStatus(SecretRequest::STATUS_PENDING);
		$entity->setToken('tok-good');
		$entity->setCreatedAt(new DateTime());
		return $entity;
	}//end buildPending()

	/**
	 * Build the service under test.
	 *
	 * @param SecretRequestPolicy|null $policy Optional policy override
	 * @param WriteLockService|null $writeLock Optional lock override
	 *
	 * @return ApplicationSecretRequestService
	 */
	private function makeService(
		?SecretRequestPolicy $policy = null,
		?WriteLockService $writeLock = null,
	): ApplicationSecretRequestService {
		return new ApplicationSecretRequestService(
			mapper: $this->mapper,
			policy: ($policy ?? new SecretRequestPolicy(mapper: $this->mapper)),
			outbox: new SecretRequestOutbox(),
			writeLockService: ($writeLock ?? $this->createMock(WriteLockService::class)),
			logger: $this->createMock(LoggerInterface::class),
			container: $this->container,
		);
	}//end makeService()

	/**
	 * An application creates a request in its own vault with no user session.
	 *
	 * The shell is created here because an application has nothing to point at
	 * yet, and the actor recorded is the application rather than a user — which
	 * is also what scopes the listing.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/application-secret-request-creation/specs/secret-requests/spec.md#requirement-session-less-application-initiated-request-creation
	 */
	public function testCreateForApplicationVaultCreatesShellAndRequest(): void {
		$policy = $this->createMock(SecretRequestPolicy::class);
		$policy->method('requireApplicationSuiteId')->willReturn('suite-app-1');

		$shell = new Secret();
		$shell->setId('sec-shell');
		$shell->setOwnerType('application');
		$shell->setOwnerId('app-1');
		// Assert the ARGUMENTS, not just the call. The shell is keyless by
		// design, so `allowUnfilled` must be true or SecretService refuses it and
		// the whole surface 400s — which is precisely what happened in a live
		// instance while this test passed against a bare willReturn().
		$this->secretService->expects($this->once())
			->method('createByApplication')
			->with(
				$this->callback(
					// No name was supplied, so the shell falls back to its label;
					// the point of the assertion is the EMPTY key travelling with
					// allowUnfilled below.
					static fn (array $data): bool => $data['name'] === 'Unfilled request'
						&& $data['key'] === ''
				),
				'app-1',
				true
			)
			->willReturn($shell);

		$captured = null;
		$this->mapper->expects($this->once())
			->method('insert')
			->willReturnCallback(
				static function (SecretRequest $entity) use (&$captured) {
					$captured = $entity;
					return $entity;
				}
			);

		$writeLock = $this->createMock(WriteLockService::class);
		// The APPLICATION's lock, not a user's.
		$writeLock->expects($this->once())
			->method('assertNotWriteLocked')
			->with('app-1', 'application');

		$service = new ApplicationSecretRequestService(
			mapper: $this->mapper,
			policy: $policy,
			outbox: new SecretRequestOutbox(),
			logger: $this->createMock(LoggerInterface::class),
			writeLockService: $writeLock,
			container: $this->container,
		);

		$result = $service->createForApplicationVault(
			applicationId: 'app-1',
			requestedFields: ['key', 'url'],
		);

		$this->assertSame('sec-shell', $result->getSecretId());
		$this->assertSame(SecretRequest::STATUS_PENDING, $result->getStatus());
		$this->assertNotSame('', $result->getToken());
		// Prefixed so an application id is never read as a Nextcloud user id.
		$this->assertSame('application:app-1', $captured->getCreatedBy());
	}//end testCreateForApplicationVaultCreatesShellAndRequest()

	/**
	 * A failure after the shell is written leaves no orphan Secret.
	 *
	 * An empty shell in an application's vault is indistinguishable from a real
	 * unfilled credential, so it must not survive a failed creation.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/application-secret-request-creation/specs/secret-requests/spec.md#requirement-session-less-application-initiated-request-creation
	 */
	public function testCreateForApplicationVaultRemovesTheShellOnFailure(): void {
		$policy = $this->createMock(SecretRequestPolicy::class);
		$policy->method('requireApplicationSuiteId')->willReturn('suite-app-1');

		$shell = new Secret();
		$shell->setId('sec-shell');
		$this->secretService->method('createByApplication')->willReturn($shell);

		$this->mapper->method('insert')
			->willThrowException(new RuntimeException('insert failed'));

		$this->secretService->expects($this->once())
			->method('deleteByApplication')
			->with('sec-shell', 'app-1');

		$service = new ApplicationSecretRequestService(
			mapper: $this->mapper,
			policy: $policy,
			outbox: new SecretRequestOutbox(),
			logger: $this->createMock(LoggerInterface::class),
			writeLockService: $this->createMock(WriteLockService::class),
			container: $this->container,
		);

		$this->expectException(RuntimeException::class);

		$service->createForApplicationVault(applicationId: 'app-1', requestedFields: ['key']);
	}//end testCreateForApplicationVaultRemovesTheShellOnFailure()

	/**
	 * An empty requestedFields is refused before anything is written.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/application-secret-request-creation/specs/secret-requests/spec.md#requirement-session-less-application-initiated-request-creation
	 */
	public function testCreateForApplicationVaultRefusesAnEmptyFieldList(): void {
		$this->secretService->expects($this->never())->method('createByApplication');
		$this->mapper->expects($this->never())->method('insert');

		$service = $this->makeService();

		$this->expectException(InvalidArgumentException::class);

		$service->createForApplicationVault(applicationId: 'app-1', requestedFields: []);
	}//end testCreateForApplicationVaultRefusesAnEmptyFieldList()

	/**
	 * The pending list is scoped by the unforgeable created_by prefix.
	 *
	 * Fulfilled rows are excluded, and one application cannot enumerate
	 * another's because the prefix is a value only this service writes.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/application-secret-request-creation/specs/secret-requests/spec.md#requirement-session-less-application-initiated-request-creation
	 */
	public function testListPendingForApplicationVaultScopesAndFilters(): void {
		$pending = $this->buildPending();
		$pending->setCreatedBy('application:app-1');

		$fulfilled = $this->buildPending();
		$fulfilled->setCreatedBy('application:app-1');
		$fulfilled->setStatus(SecretRequest::STATUS_FULFILLED);

		$this->mapper->expects($this->once())
			->method('findByCreatedBy')
			->with('application:app-1')
			->willReturn([$pending, $fulfilled]);

		$rows = $this->makeService()->listPendingForApplicationVault(applicationId: 'app-1');

		$this->assertCount(1, $rows);
		$this->assertSame(SecretRequest::STATUS_PENDING, $rows[0]->getStatus());
	}//end testListPendingForApplicationVaultScopesAndFilters()

	/**
	 * A valid signed proof creates the request in the ISSUER's vault.
	 *
	 * The application id comes from the verified `iss`, never from an argument,
	 * so a caller cannot choose whose vault receives the request.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/application-secret-request-creation/specs/secret-requests/spec.md#requirement-session-less-application-initiated-request-creation
	 */
	public function testSignedProofCreatesInTheIssuersVault(): void {
		$app = new Application();
		$app->setId('app-issuer');

		$jwt = $this->createMock(JwtAuthService::class);
		$jwt->expects($this->once())
			->method('verifyAssertion')
			->with('signed.jwt.here')
			->willReturn($app);

		$policy = $this->createMock(SecretRequestPolicy::class);
		$policy->method('requireApplicationSuiteId')->willReturn('suite-issuer');

		$shell = new Secret();
		$shell->setId('sec-shell');
		$this->secretService->method('createByApplication')->willReturn($shell);

		$captured = null;
		$this->mapper->method('insert')->willReturnCallback(
			static function (SecretRequest $e) use (&$captured) {
				$captured = $e;
				return $e;
			}
		);

		$result = $this->makeProofService(jwt: $jwt, policy: $policy)
			->createForApplicationBySignedProof(
				assertion: 'signed.jwt.here',
				requestedFields: ['key'],
			);

		$this->assertSame(SecretRequest::STATUS_PENDING, $result->getStatus());
		// The vault is the verified issuer's.
		$this->assertSame('application:app-issuer', $captured->getCreatedBy());
	}//end testSignedProofCreatesInTheIssuersVault()

	/**
	 * An application id alone is not authority.
	 *
	 * An id is a public identifier: accepting one would let any code in the
	 * process create requests in any application's vault. Refused before
	 * verification is even attempted, and nothing is written.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/application-secret-request-creation/specs/secret-requests/spec.md#requirement-session-less-application-initiated-request-creation
	 */
	public function testAppIdAloneIsRefused(): void {
		$jwt = $this->createMock(JwtAuthService::class);
		$jwt->expects($this->never())->method('verifyAssertion');

		$this->secretService->expects($this->never())->method('createByApplication');
		$this->mapper->expects($this->never())->method('insert');

		$this->expectException(RuntimeException::class);

		$this->makeProofService(jwt: $jwt)->createForApplicationBySignedProof(
			assertion: '   ',
			requestedFields: ['key'],
		);
	}//end testAppIdAloneIsRefused()

	/**
	 * Every verification failure refuses the creation and writes nothing.
	 *
	 * Bad signature, replayed jti, an inactive issuer and a proof signed by a
	 * key other than the registered one all surface from
	 * JwtAuthService::verifyAssertion(), which this seam delegates to rather
	 * than reimplementing — so they are asserted here as one contract: a
	 * throwing verification never reaches the write.
	 *
	 * @param string $reason The verification failure being simulated
	 *
	 * @return void
	 *
	 * @dataProvider provideVerificationFailures
	 *
	 * @spec openspec/changes/application-secret-request-creation/specs/secret-requests/spec.md#requirement-session-less-application-initiated-request-creation
	 */
	public function testVerificationFailuresRefuseCreation(string $reason): void {
		$jwt = $this->createMock(JwtAuthService::class);
		$jwt->method('verifyAssertion')
			->willThrowException(new RuntimeException(message: $reason));

		$this->secretService->expects($this->never())->method('createByApplication');
		$this->mapper->expects($this->never())->method('insert');

		$this->expectException(RuntimeException::class);
		$this->expectExceptionMessage($reason);

		$this->makeProofService(jwt: $jwt)->createForApplicationBySignedProof(
			assertion: 'tampered.jwt.here',
			requestedFields: ['key'],
		);
	}//end testVerificationFailuresRefuseCreation()

	/**
	 * The verification failures the seam must refuse.
	 *
	 * @return array<string,array<int,string>>
	 */
	public static function provideVerificationFailures(): array {
		return [
			'invalid signature' => ['Signature verification failed'],
			'replayed jti' => ['Assertion jti replayed'],
			'wrong certificate' => ['Signature verification failed'],
			'inactive issuer' => ['Issuer application is not active'],
			'unknown issuer' => ['Unknown issuer'],
			'expired assertion' => ['Assertion exp is in the past'],
		];
	}//end provideVerificationFailures()

	/**
	 * Build a service whose container hands out a given JwtAuthService double.
	 *
	 * @param JwtAuthService&MockObject $jwt The verification double
	 * @param SecretRequestPolicy|null $policy Optional policy override
	 *
	 * @return ApplicationSecretRequestService
	 */
	private function makeProofService(
		JwtAuthService&MockObject $jwt,
		?SecretRequestPolicy $policy = null,
	): ApplicationSecretRequestService {
		$container = $this->createMock(ContainerInterface::class);
		$container->method('get')->willReturnCallback(
			fn (string $id) => ($id === JwtAuthService::class ? $jwt : $this->secretService)
		);

		return new ApplicationSecretRequestService(
			mapper: $this->mapper,
			policy: ($policy ?? new SecretRequestPolicy(mapper: $this->mapper)),
			outbox: new SecretRequestOutbox(),
			logger: $this->createMock(LoggerInterface::class),
			writeLockService: $this->createMock(WriteLockService::class),
			container: $container,
		);
	}//end makeProofService()

	/**
	 * Creation is refused when the application has no ACTIVE suite.
	 *
	 * Guard parity with token issuance, and inherited rather than reimplemented:
	 * `requireApplicationSuiteId` resolves through `findActiveByOwner`, so a
	 * revoked or compromised suite is simply not found. A new suite status would
	 * fail closed for the same reason.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/application-secret-request-creation/specs/secret-store-api/spec.md#requirement-machine-request-creation-hardening-and-audit
	 */
	public function testCreationRefusedWhenTheSuiteIsNotActive(): void {
		$policy = $this->createMock(SecretRequestPolicy::class);
		$policy->method('requireApplicationSuiteId')
			->willThrowException(
				new InvalidArgumentException(message: 'No active EncryptionSuite for application app-1')
			);

		// Nothing may be written, and no shell may be left behind.
		$this->secretService->expects($this->never())->method('createByApplication');
		$this->mapper->expects($this->never())->method('insert');

		$service = new ApplicationSecretRequestService(
			mapper: $this->mapper,
			policy: $policy,
			outbox: new SecretRequestOutbox(),
			logger: $this->createMock(LoggerInterface::class),
			writeLockService: $this->createMock(WriteLockService::class),
			container: $this->container,
		);

		$this->expectException(InvalidArgumentException::class);

		$service->createForApplicationVault(applicationId: 'app-1', requestedFields: ['key']);
	}//end testCreationRefusedWhenTheSuiteIsNotActive()

	/**
	 * Exactly one audit event per creation, with the application as actor.
	 *
	 * The metadata carries the field COUNT and not the names: a name such as
	 * `aws-secret-access-key` is not a value, so the audit spec's forbidden-key
	 * list does not catch it, but it still tells a reader what kind of
	 * credential this is.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/application-secret-request-creation/specs/secret-store-api/spec.md#requirement-machine-request-creation-hardening-and-audit
	 */
	public function testCreationEmitsExactlyOneApplicationAuditEvent(): void {
		$policy = $this->createMock(SecretRequestPolicy::class);
		$policy->method('requireApplicationSuiteId')->willReturn('suite-app-1');

		$shell = new Secret();
		$shell->setId('sec-shell');
		$this->secretService->method('createByApplication')->willReturn($shell);
		$this->mapper->method('insert')->willReturnArgument(0);

		$dispatched = [];
		$dispatcher = $this->createMock(IEventDispatcher::class);
		$dispatcher->method('dispatchTyped')->willReturnCallback(
			static function (object $event) use (&$dispatched): void {
				$dispatched[] = $event;
			}
		);

		$service = new ApplicationSecretRequestService(
			mapper: $this->mapper,
			policy: $policy,
			outbox: new SecretRequestOutbox(eventDispatcher: $dispatcher),
			logger: $this->createMock(LoggerInterface::class),
			writeLockService: $this->createMock(WriteLockService::class),
			container: $this->container,
		);

		$service->createForApplicationVault(
			applicationId: 'app-1',
			requestedFields: ['key', 'url', 'api-key'],
		);

		$this->assertCount(1, $dispatched);
		$event = $dispatched[0];
		$this->assertSame(AuditEventTypes::APPLICATION_SECRET_REQUEST_CREATED, $event->getEventType());
		$this->assertSame('app-1', $event->getActorId());
		$this->assertSame('application', $event->getActorType());
		$metadata = $event->getMetadata();
		$this->assertSame(3, $metadata['requestedFieldCount']);
		// Field NAMES are not recorded.
		$this->assertStringNotContainsString('api-key', (string)json_encode($metadata));
	}//end testCreationEmitsExactlyOneApplicationAuditEvent()
}//end class
