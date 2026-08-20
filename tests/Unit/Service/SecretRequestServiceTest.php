<?php

/**
 * Unit tests for SecretRequestService.
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
use OCA\Doriath\Db\EncryptionSuite;
use OCA\Doriath\Db\EncryptionSuiteMapper;
use OCA\Doriath\Db\Application;
use OCA\Doriath\Service\JwtAuthService;
use OCA\Doriath\Event\Audit\AuditEventTypes;
use OCA\Doriath\Db\Secret;
use OCA\Doriath\Db\SecretMapper;
use OCA\Doriath\Db\SecretRequest;
use OCA\Doriath\Db\SecretRequestMapper;
use OCA\Doriath\Exception\ForbiddenException;
use OCA\Doriath\Service\NotificationService;
use OCA\Doriath\Service\SecretRequestOutbox;
use OCA\Doriath\Service\SecretRequestPolicy;
use OCA\Doriath\Service\SecretRequestService;
use OCA\Doriath\Service\SecretRequestSuiteLockService;
use OCA\Doriath\Service\WriteLockService;
use OCP\EventDispatcher\IEventDispatcher;
use OCP\AppFramework\Db\DoesNotExistException;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use OCA\Doriath\Service\SecretService;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;
use RuntimeException;

/**
 * Tests for SecretRequestService.
 */
class SecretRequestServiceTest extends TestCase {

	/**
	 * Service under test.
	 *
	 * @var SecretRequestService
	 */
	private SecretRequestService $service;

	/**
	 * Mock mapper.
	 *
	 * @var SecretRequestMapper
	 */
	private SecretRequestMapper $mapper;

	/**
	 * Suite-lock service under test.
	 *
	 * @var SecretRequestSuiteLockService
	 */
	private SecretRequestSuiteLockService $suiteLockService;

	/**
	 * Set up fixtures.
	 *
	 * @return void
	 */
	/**
	 * Reads the Secret a filled request writes to.
	 *
	 * @var SecretMapper&MockObject
	 */
	private SecretMapper&MockObject $secretMapper;

	/**
	 * Hands out the SecretService double.
	 *
	 * SecretService is resolved from the container at call time rather than
	 * injected, because it declares an optional SecretRequestService of its own
	 * and a constructor dependency would close an autowiring cycle.
	 *
	 * @var ContainerInterface&MockObject
	 */
	private ContainerInterface&MockObject $container;

	/**
	 * The write path a fill is expected to go through.
	 *
	 * @var SecretService&MockObject
	 */
	private SecretService&MockObject $secretService;

	protected function setUp(): void {
		$this->secretMapper = $this->createMock(SecretMapper::class);
		$this->secretService = $this->createMock(SecretService::class);
		$this->container = $this->createMock(ContainerInterface::class);
		$this->container->method('get')->willReturn($this->secretService);

		$this->mapper = $this->createMock(originalClassName: SecretRequestMapper::class);
		$logger = $this->createMock(originalClassName: LoggerInterface::class);
		$this->service = new SecretRequestService(
			mapper: $this->mapper,
			policy: new SecretRequestPolicy(mapper: $this->mapper),
			outbox: new SecretRequestOutbox(),
			logger: $logger,
			writeLockService: $this->createMock(WriteLockService::class),
			secretMapper: $this->secretMapper,
			container: $this->container,
		);

		$this->suiteLockService = new SecretRequestSuiteLockService(
			mapper: $this->mapper,
			logger: $logger,
		);
	}//end setUp()

	/**
	 * Test create generates a token and inserts a pending request.
	 *
	 * @return void
	 */
	public function testCreateGeneratesTokenAndPersists(): void {
		$captured = null;
		$this->mapper->expects($this->once())
			->method('insert')
			->willReturnCallback(
				static function (SecretRequest $entity) use (&$captured) {
					$captured = $entity;
					return $entity;
				}
			);

		$result = $this->service->create(
			secretId: 'sec-1',
			encryptionSuiteId: 'suite-1',
			requestedFields: ['password', 'login'],
			isReRequest: false,
			expiresAt: null,
			userId: 'alice'
		);

		$this->assertSame($captured, $result);
		$this->assertSame('sec-1', $result->getSecretId());
		$this->assertSame('suite-1', $result->getEncryptionSuiteId());
		$this->assertSame(SecretRequest::STATUS_PENDING, $result->getStatus());
		$this->assertFalse($result->getIsReRequest());
		$this->assertSame('["password","login"]', $result->getRequestedFields());
		$this->assertSame(32, strlen($result->getToken()), '32-char hex token expected');
		$this->assertSame('alice', $result->getCreatedBy());
		$this->assertNotNull($result->getCreatedAt());
	}//end testCreateGeneratesTokenAndPersists()

	/**
	 * Test create rejects empty requestedFields.
	 *
	 * @return void
	 */
	public function testCreateRejectsEmptyFields(): void {
		$this->mapper->expects($this->never())->method('insert');

		$this->expectException(InvalidArgumentException::class);
		$this->expectExceptionMessage('requestedFields cannot be empty');

		$this->service->create(
			secretId: 'sec-1',
			encryptionSuiteId: 'suite-1',
			requestedFields: [],
			isReRequest: false,
			expiresAt: null,
			userId: 'alice'
		);
	}//end testCreateRejectsEmptyFields()

	/**
	 * Test approve flips a pending request to fulfilled and sets the timestamp.
	 *
	 * @return void
	 */
	public function testApprovePendingMarksFulfilled(): void {
		$entity = new SecretRequest();
		$entity->setId('req-1');
		$entity->setSecretId('sec-1');
		$entity->setStatus(SecretRequest::STATUS_PENDING);
		$entity->setCreatedBy('alice');

		$this->mapper->expects($this->once())
			->method('findById')
			->with('req-1')
			->willReturn($entity);

		$this->mapper->expects($this->once())
			->method('update')
			->willReturnArgument(0);

		$result = $this->service->approve(requestId: 'req-1', userId: 'alice');

		$this->assertSame(SecretRequest::STATUS_FULFILLED, $result->getStatus());
		$this->assertNotNull($result->getFulfilledAt());
	}//end testApprovePendingMarksFulfilled()

	/**
	 * Test approve rejects requests created by someone else.
	 *
	 * @return void
	 */
	public function testApproveRejectsNonOwner(): void {
		$entity = new SecretRequest();
		$entity->setId('req-1');
		$entity->setStatus(SecretRequest::STATUS_PENDING);
		$entity->setCreatedBy('alice');

		$this->mapper->expects($this->once())
			->method('findById')
			->willReturn($entity);

		$this->mapper->expects($this->never())->method('update');

		$this->expectException(InvalidArgumentException::class);
		$this->expectExceptionMessage('Not authorized');

		$this->service->approve(requestId: 'req-1', userId: 'mallory');
	}//end testApproveRejectsNonOwner()

	/**
	 * Test approve rejects expired requests.
	 *
	 * @return void
	 */
	public function testApproveRejectsExpired(): void {
		$entity = new SecretRequest();
		$entity->setId('req-1');
		$entity->setStatus(SecretRequest::STATUS_PENDING);
		$entity->setCreatedBy('alice');
		$entity->setExpiresAt(new DateTime('2000-01-01T00:00:00+00:00'));

		$this->mapper->expects($this->once())
			->method('findById')
			->willReturn($entity);

		$this->mapper->expects($this->never())->method('update');

		$this->expectException(InvalidArgumentException::class);
		$this->expectExceptionMessage('Request has expired');

		$this->service->approve(requestId: 'req-1', userId: 'alice');
	}//end testApproveRejectsExpired()

	/**
	 * Test approve rejects requests that are not pending.
	 *
	 * @return void
	 */
	public function testApproveRejectsAlreadyFulfilled(): void {
		$entity = new SecretRequest();
		$entity->setId('req-1');
		$entity->setStatus(SecretRequest::STATUS_FULFILLED);
		$entity->setCreatedBy('alice');

		$this->mapper->expects($this->once())
			->method('findById')
			->willReturn($entity);

		$this->mapper->expects($this->never())->method('update');

		$this->expectException(InvalidArgumentException::class);
		$this->expectExceptionMessage('not pending');

		$this->service->approve(requestId: 'req-1', userId: 'alice');
	}//end testApproveRejectsAlreadyFulfilled()

	/**
	 * Test decline flips a pending request to declined.
	 *
	 * @return void
	 */
	public function testDeclinePendingMarksDeclined(): void {
		$entity = new SecretRequest();
		$entity->setId('req-1');
		$entity->setStatus(SecretRequest::STATUS_PENDING);
		$entity->setCreatedBy('alice');

		$this->mapper->expects($this->once())
			->method('findById')
			->willReturn($entity);

		$this->mapper->expects($this->once())
			->method('update')
			->willReturnArgument(0);

		$result = $this->service->decline(requestId: 'req-1', userId: 'alice');

		$this->assertSame(SecretRequest::STATUS_DECLINED, $result->getStatus());
	}//end testDeclinePendingMarksDeclined()

	/**
	 * Test 404 when the request does not exist.
	 *
	 * @return void
	 */
	public function testApproveThrowsWhenNotFound(): void {
		$this->mapper->expects($this->once())
			->method('findById')
			->willThrowException(new DoesNotExistException('nope'));

		$this->expectException(InvalidArgumentException::class);
		$this->expectExceptionMessage('Request not found');

		$this->service->approve(requestId: 'missing', userId: 'alice');
	}//end testApproveThrowsWhenNotFound()

	/**
	 * getByToken returns the entity for a healthy pending request.
	 *
	 * @return void
	 */
	public function testGetByTokenReturnsPendingEntity(): void {
		$entity = $this->buildPending();

		$this->mapper->expects($this->once())
			->method('findByToken')
			->with('tok-good')
			->willReturn($entity);

		$this->assertSame($entity, $this->service->getByToken(token: 'tok-good'));
	}//end testGetByTokenReturnsPendingEntity()

	/**
	 * getByToken throws 404 when no row exists.
	 *
	 * @return void
	 */
	public function testGetByTokenThrows404OnUnknown(): void {
		$this->mapper->method('findByToken')->willThrowException(new DoesNotExistException('nope'));

		$this->expectException(InvalidArgumentException::class);
		$this->expectExceptionCode(404);

		$this->service->getByToken(token: 'tok-bogus');
	}//end testGetByTokenThrows404OnUnknown()

	/**
	 * getByToken throws 423 for locked requests.
	 *
	 * @return void
	 */
	public function testGetByTokenThrows423OnLocked(): void {
		$entity = $this->buildPending();
		$entity->setStatus(SecretRequest::STATUS_LOCKED);
		$this->mapper->method('findByToken')->willReturn($entity);

		$this->expectException(InvalidArgumentException::class);
		$this->expectExceptionCode(423);

		$this->service->getByToken(token: 'tok-locked');
	}//end testGetByTokenThrows423OnLocked()

	/**
	 * getByToken throws 410 for fulfilled requests.
	 *
	 * @return void
	 */
	public function testGetByTokenThrows410OnFulfilled(): void {
		$entity = $this->buildPending();
		$entity->setStatus(SecretRequest::STATUS_FULFILLED);
		$this->mapper->method('findByToken')->willReturn($entity);

		$this->expectException(InvalidArgumentException::class);
		$this->expectExceptionCode(410);

		$this->service->getByToken(token: 'tok-done');
	}//end testGetByTokenThrows410OnFulfilled()

	/**
	 * getByToken throws 408 when the request has expired.
	 *
	 * @return void
	 */
	public function testGetByTokenThrows408OnExpired(): void {
		$entity = $this->buildPending();
		$entity->setExpiresAt(new DateTime('2020-01-01'));
		$this->mapper->method('findByToken')->willReturn($entity);

		$this->expectException(InvalidArgumentException::class);
		$this->expectExceptionCode(408);

		$this->service->getByToken(token: 'tok-stale');
	}//end testGetByTokenThrows408OnExpired()

	/**
	 * fill flips status to fulfilled + dispatches request_fulfilled.
	 *
	 * @return void
	 */
	public function testFillMarksFulfilledAndNotifies(): void {
		$entity = $this->buildPending();
		$entity->setRequestedFields(json_encode(['key', 'login']));

		// First lookup via getByToken, second atomic re-read.
		$this->mapper->method('findByToken')->willReturn($entity);
		$this->mapper->method('findById')->willReturn($entity);

		$this->mapper->expects($this->once())
			->method('update')
			->willReturnArgument(0);

		$notifier = $this->createMock(originalClassName: NotificationService::class);
		$notifier->expects($this->once())
			->method('notify')
			->with(
				$this->equalTo('request_fulfilled'),
				$this->equalTo('requester'),
				$this->arrayHasKey('secret_id'),
				$this->equalTo('secret'),
				$this->equalTo('sec-1'),
			)
			->willReturn(true);

		$logger = $this->createMock(originalClassName: LoggerInterface::class);
		$service = new SecretRequestService(
			mapper: $this->mapper,
			policy: new SecretRequestPolicy(mapper: $this->mapper),
			outbox: new SecretRequestOutbox(notificationService: $notifier),
			logger: $logger,
			writeLockService: $this->createMock(WriteLockService::class),
			secretMapper: $this->secretMapper,
			container: $this->container,
		);

		// The linked Secret, user-owned.
		$secret = new Secret();
		$secret->setId('sec-1');
		$secret->setOwnerType('user');
		$secret->setOwnerId('requester');
		$this->secretMapper->method('findById')->willReturn($secret);

		// The point of the fix: the submitted ciphertext reaches the Secret,
		// through SecretService so the version snapshot and the
		// possibly-compromised clearing both happen. It previously went nowhere
		// and the request was marked fulfilled regardless.
		$this->secretService->expects($this->once())
			->method('update')
			->with(
				'sec-1',
				['key' => 'CIPHER_KEY', 'login' => 'CIPHER_LOGIN'],
				'requester'
			)
			->willReturn($secret);

		$result = $service->fill(
			token: 'tok-good',
			encryptedFields: ['key' => 'CIPHER_KEY', 'login' => 'CIPHER_LOGIN'],
		);

		$this->assertSame(SecretRequest::STATUS_FULFILLED, $result->getStatus());
		$this->assertNotNull($result->getFulfilledAt());
	}//end testFillMarksFulfilledAndNotifies()

	/**
	 * An application-owned secret goes through the application write path.
	 *
	 * SecretService::update() hard-requires `ownerType === 'user'`, so an
	 * application-owned request — the entire point of application-initiated
	 * requests — cannot use it. Routing by owner type is what keeps that case
	 * working rather than throwing a Forbidden the recipient cannot act on.
	 *
	 * @return void
	 *
	 * @spec openspec/specs/secret-requests/spec.md#requirement-fill-in-via-link
	 */
	public function testFillOnAnApplicationOwnedSecretUsesTheApplicationPath(): void {
		$entity = $this->buildPending();
		$entity->setRequestedFields(json_encode(['key']));

		$this->mapper->method('findByToken')->willReturn($entity);
		$this->mapper->method('findById')->willReturn($entity);
		$this->mapper->method('update')->willReturnArgument(0);

		$secret = new Secret();
		$secret->setId('sec-1');
		$secret->setOwnerType('application');
		$secret->setOwnerId('app-1');
		$this->secretMapper->method('findById')->willReturn($secret);

		$this->secretService->expects($this->once())
			->method('updateByApplication')
			->with('sec-1', ['key' => 'CIPHER'], 'app-1')
			->willReturn($secret);
		$this->secretService->expects($this->never())->method('update');

		$service = new SecretRequestService(
			mapper: $this->mapper,
			policy: new SecretRequestPolicy(mapper: $this->mapper),
			outbox: new SecretRequestOutbox(),
			logger: $this->createMock(LoggerInterface::class),
			writeLockService: $this->createMock(WriteLockService::class),
			secretMapper: $this->secretMapper,
			container: $this->container,
		);

		$result = $service->fill(token: 'tok-good', encryptedFields: ['key' => 'CIPHER']);

		$this->assertSame(SecretRequest::STATUS_FULFILLED, $result->getStatus());
	}//end testFillOnAnApplicationOwnedSecretUsesTheApplicationPath()

	/**
	 * A field with nowhere to be stored is refused, not silently dropped.
	 *
	 * `requested_fields` is free-form JSON, so a request can name anything.
	 * Accepting such a name and storing it nowhere is the defect being fixed;
	 * refusing keeps the request fillable once the caller corrects it.
	 *
	 * @return void
	 *
	 * @spec openspec/specs/secret-requests/spec.md#requirement-fill-in-via-link
	 */
	public function testFillRefusesAFieldThatCannotBeStored(): void {
		$entity = $this->buildPending();
		// A name that is neither reserved nor deliverable as an additional
		// member, because it was sent as its own top-level ciphertext key.
		$entity->setRequestedFields(json_encode(['additionalFields']));

		$this->mapper->method('findByToken')->willReturn($entity);
		$this->mapper->method('findById')->willReturn($entity);

		// Neither the status nor the secret may move.
		$this->mapper->expects($this->never())->method('update');
		$this->secretService->expects($this->never())->method('update');

		$service = new SecretRequestService(
			mapper: $this->mapper,
			policy: new SecretRequestPolicy(mapper: $this->mapper),
			outbox: new SecretRequestOutbox(),
			logger: $this->createMock(LoggerInterface::class),
			writeLockService: $this->createMock(WriteLockService::class),
			secretMapper: $this->secretMapper,
			container: $this->container,
		);

		$this->expectException(InvalidArgumentException::class);

		$service->fill(token: 'tok-good', encryptedFields: ['api-key' => 'CIPHER']);
	}//end testFillRefusesAFieldThatCannotBeStored()

	/**
	 * Every field a Secret supports is requestable, each in its own bucket.
	 *
	 * `key` is ciphertext, `url` is plaintext metadata the owner searches on,
	 * and any other requested name is a member of the single encrypted
	 * additionalFields blob. Encrypting `url` would put ciphertext in a
	 * searchable column.
	 *
	 * @return void
	 *
	 * @spec openspec/specs/secret-requests/spec.md#requirement-requestable-fields
	 */
	public function testFillStoresCiphertextPlaintextAndTheAdditionalBlob(): void {
		$entity = $this->buildPending();
		$entity->setRequestedFields(json_encode(['key', 'url', 'api-interface-id']));

		$this->mapper->method('findByToken')->willReturn($entity);
		$this->mapper->method('findById')->willReturn($entity);
		$this->mapper->method('update')->willReturnArgument(0);

		$secret = new Secret();
		$secret->setId('sec-1');
		$secret->setOwnerType('user');
		$secret->setOwnerId('requester');
		$this->secretMapper->method('findById')->willReturn($secret);

		$this->secretService->expects($this->once())
			->method('update')
			->with(
				'sec-1',
				[
					'key' => 'CIPHER_KEY',
					'additionalFields' => 'CIPHER_BLOB',
					// Stored as given: plaintext, so it stays searchable.
					'url' => 'https://example.test/api',
				],
				'requester'
			)
			->willReturn($secret);

		$service = $this->makeFillService();

		$result = $service->fill(
			token: 'tok-good',
			encryptedFields: ['key' => 'CIPHER_KEY', 'additionalFields' => 'CIPHER_BLOB'],
			plainFields: ['url' => 'https://example.test/api'],
		);

		$this->assertSame(SecretRequest::STATUS_FULFILLED, $result->getStatus());
	}//end testFillStoresCiphertextPlaintextAndTheAdditionalBlob()

	/**
	 * A plaintext metadata field sent as ciphertext is refused.
	 *
	 * @return void
	 *
	 * @spec openspec/specs/secret-requests/spec.md#requirement-requestable-fields
	 */
	public function testFillRefusesPlaintextMetadataSentAsCiphertext(): void {
		$entity = $this->buildPending();
		$entity->setRequestedFields(json_encode(['url']));

		$this->mapper->method('findByToken')->willReturn($entity);
		$this->mapper->method('findById')->willReturn($entity);
		$this->mapper->expects($this->never())->method('update');
		$this->secretService->expects($this->never())->method('update');

		$service = $this->makeFillService();

		$this->expectException(InvalidArgumentException::class);

		// Encrypted, which would land ciphertext in a searchable column.
		$service->fill(token: 'tok-good', encryptedFields: ['url' => 'CIPHER_URL']);
	}//end testFillRefusesPlaintextMetadataSentAsCiphertext()

	/**
	 * An additional member is satisfied by the blob arriving, and no more.
	 *
	 * The server never decrypts (ADR-003), so it cannot confirm a named member
	 * is inside. The spec states that limitation rather than implying a
	 * guarantee; this pins the behaviour so nobody later "fixes" it by
	 * inspecting the blob.
	 *
	 * @return void
	 *
	 * @spec openspec/specs/secret-requests/spec.md#requirement-requestable-fields
	 */
	public function testAdditionalMembersAreSatisfiedByTheBlobAlone(): void {
		$entity = $this->buildPending();
		$entity->setRequestedFields(json_encode(['api-key', 'api-interface-id']));

		$this->mapper->method('findByToken')->willReturn($entity);
		$this->mapper->method('findById')->willReturn($entity);
		$this->mapper->method('update')->willReturnArgument(0);

		$secret = new Secret();
		$secret->setId('sec-1');
		$secret->setOwnerType('user');
		$secret->setOwnerId('requester');
		$this->secretMapper->method('findById')->willReturn($secret);
		$this->secretService->method('update')->willReturn($secret);

		$service = $this->makeFillService();

		// Two members requested, one blob submitted — accepted.
		$result = $service->fill(
			token: 'tok-good',
			encryptedFields: ['additionalFields' => 'CIPHER_BLOB'],
		);

		$this->assertSame(SecretRequest::STATUS_FULFILLED, $result->getStatus());
	}//end testAdditionalMembersAreSatisfiedByTheBlobAlone()












	/**
	 * Build a service wired for the fill tests.
	 *
	 * @return SecretRequestService
	 */
	private function makeFillService(): SecretRequestService {
		return new SecretRequestService(
			mapper: $this->mapper,
			policy: new SecretRequestPolicy(mapper: $this->mapper),
			outbox: new SecretRequestOutbox(),
			logger: $this->createMock(LoggerInterface::class),
			writeLockService: $this->createMock(WriteLockService::class),
			secretMapper: $this->secretMapper,
			container: $this->container,
		);
	}//end makeFillService()

	/**
	 * A failed write leaves the request pending, not fulfilled and empty.
	 *
	 * Ordering is the guarantee: values are persisted before the status flip,
	 * so a throw from the write path cannot leave a request reporting success
	 * with nothing stored — which is precisely the state the old code produced
	 * on every single fill.
	 *
	 * @return void
	 *
	 * @spec openspec/specs/secret-requests/spec.md#requirement-fill-in-via-link
	 */
	public function testFillLeavesTheRequestPendingWhenTheWriteFails(): void {
		$entity = $this->buildPending();
		$entity->setRequestedFields(json_encode(['key']));

		$this->mapper->method('findByToken')->willReturn($entity);
		$this->mapper->method('findById')->willReturn($entity);
		$this->mapper->expects($this->never())->method('update');

		$secret = new Secret();
		$secret->setId('sec-1');
		$secret->setOwnerType('user');
		$secret->setOwnerId('requester');
		$this->secretMapper->method('findById')->willReturn($secret);

		$this->secretService->method('update')
			->willThrowException(new InvalidArgumentException(message: 'vault is write-locked'));

		$service = new SecretRequestService(
			mapper: $this->mapper,
			policy: new SecretRequestPolicy(mapper: $this->mapper),
			outbox: new SecretRequestOutbox(),
			logger: $this->createMock(LoggerInterface::class),
			writeLockService: $this->createMock(WriteLockService::class),
			secretMapper: $this->secretMapper,
			container: $this->container,
		);

		try {
			$service->fill(token: 'tok-good', encryptedFields: ['key' => 'CIPHER']);
			$this->fail('Expected the write failure to propagate');
		} catch (InvalidArgumentException) {
			// The request must still be usable.
			$this->assertSame(SecretRequest::STATUS_PENDING, $entity->getStatus());
			$this->assertNull($entity->getFulfilledAt());
		}
	}//end testFillLeavesTheRequestPendingWhenTheWriteFails()

	/**
	 * fill rejects payloads that omit a required field.
	 *
	 * @return void
	 */
	public function testFillRejectsMissingField(): void {
		$entity = $this->buildPending();
		$entity->setRequestedFields(json_encode(['key', 'login']));
		$this->mapper->method('findByToken')->willReturn($entity);

		$this->expectException(InvalidArgumentException::class);
		$this->expectExceptionMessage('Missing required field: login');

		$this->service->fill(token: 'tok-good', encryptedFields: ['key' => 'X']);
	}//end testFillRejectsMissingField()

	/**
	 * fill rejects empty values for required fields.
	 *
	 * @return void
	 */
	public function testFillRejectsEmptyValue(): void {
		$entity = $this->buildPending();
		$entity->setRequestedFields(json_encode(['key']));
		$this->mapper->method('findByToken')->willReturn($entity);

		$this->expectException(InvalidArgumentException::class);
		$this->expectExceptionMessage('Empty value for field: key');

		$this->service->fill(token: 'tok-good', encryptedFields: ['key' => '']);
	}//end testFillRejectsEmptyValue()

	/**
	 * lockByEncryptionSuiteId delegates to the mapper.
	 *
	 * @return void
	 */
	public function testLockByEncryptionSuiteIdDelegates(): void {
		$this->mapper->expects($this->once())
			->method('lockByEncryptionSuiteId')
			->with('suite-old')
			->willReturn(4);

		$this->assertSame(4, $this->suiteLockService->lockByEncryptionSuiteId(encryptionSuiteId: 'suite-old'));
	}//end testLockByEncryptionSuiteIdDelegates()

	/**
	 * unlockAndUpdateSuite delegates to the mapper.
	 *
	 * @return void
	 */
	public function testUnlockAndUpdateSuiteDelegates(): void {
		$this->mapper->expects($this->once())
			->method('unlockAndUpdateSuite')
			->with('suite-old', 'suite-new')
			->willReturn(2);

		$this->assertSame(
			2,
			$this->suiteLockService->unlockAndUpdateSuite(
				oldEncryptionSuiteId: 'suite-old',
				newEncryptionSuiteId: 'suite-new',
			)
		);
	}//end testUnlockAndUpdateSuiteDelegates()

	/**
	 * unlockAndUpdateSuite rejects identical suite IDs.
	 *
	 * @return void
	 */
	public function testUnlockAndUpdateSuiteRejectsSameSuite(): void {
		$this->expectException(RuntimeException::class);

		$this->suiteLockService->unlockAndUpdateSuite(
			oldEncryptionSuiteId: 'suite-x',
			newEncryptionSuiteId: 'suite-x',
		);
	}//end testUnlockAndUpdateSuiteRejectsSameSuite()

	/**
	 * listBySecret returns all requests when the caller owns the secret.
	 *
	 * @return void
	 */
	public function testListBySecretReturnsRequestsForOwner(): void {
		$secretMapper = $this->createMock(originalClassName: SecretMapper::class);
		$secret = new Secret();
		$secret->setId('sec-1');
		$secret->setOwnerType('user');
		$secret->setOwnerId('owner');

		$secretMapper->method('findById')->with('sec-1')->willReturn($secret);

		$requests = [$this->buildPending()];
		$this->mapper->expects(matcher: $this->once())
			->method('findBySecretId')
			->with('sec-1')
			->willReturn($requests);

		$logger = $this->createMock(originalClassName: LoggerInterface::class);
		$service = new SecretRequestService(
			mapper: $this->mapper,
			policy: new SecretRequestPolicy(mapper: $this->mapper, secretMapper: $secretMapper),
			outbox: new SecretRequestOutbox(),
			logger: $logger,
			writeLockService: $this->createMock(WriteLockService::class),
			secretMapper: $this->secretMapper,
			container: $this->container,
		);

		$result = $service->listBySecret(secretId: 'sec-1', userId: 'owner');
		$this->assertSame(expected: $requests, actual: $result);
	}//end testListBySecretReturnsRequestsForOwner()

	/**
	 * listBySecret rejects callers who do not own the secret.
	 *
	 * @return void
	 */
	public function testListBySecretRejectsNonOwner(): void {
		$secretMapper = $this->createMock(originalClassName: SecretMapper::class);
		$secret = new Secret();
		$secret->setId('sec-1');
		$secret->setOwnerType('user');
		$secret->setOwnerId('owner');
		$secretMapper->method('findById')->willReturn($secret);

		$this->mapper->expects(matcher: $this->never())->method('findBySecretId');

		$logger = $this->createMock(originalClassName: LoggerInterface::class);
		$service = new SecretRequestService(
			mapper: $this->mapper,
			policy: new SecretRequestPolicy(mapper: $this->mapper, secretMapper: $secretMapper),
			outbox: new SecretRequestOutbox(),
			logger: $logger,
			writeLockService: $this->createMock(WriteLockService::class),
			secretMapper: $this->secretMapper,
			container: $this->container,
		);

		$this->expectException(InvalidArgumentException::class);
		$service->listBySecret(secretId: 'sec-1', userId: 'someone-else');
	}//end testListBySecretRejectsNonOwner()

	/**
	 * listBySecret throws when the Secret does not exist.
	 *
	 * @return void
	 */
	public function testListBySecretRejectsUnknownSecret(): void {
		$secretMapper = $this->createMock(originalClassName: SecretMapper::class);
		$secretMapper->method('findById')->willThrowException(new DoesNotExistException(msg: 'gone'));

		$this->mapper->expects(matcher: $this->never())->method('findBySecretId');

		$logger = $this->createMock(originalClassName: LoggerInterface::class);
		$service = new SecretRequestService(
			mapper: $this->mapper,
			policy: new SecretRequestPolicy(mapper: $this->mapper, secretMapper: $secretMapper),
			outbox: new SecretRequestOutbox(),
			logger: $logger,
			writeLockService: $this->createMock(WriteLockService::class),
			secretMapper: $this->secretMapper,
			container: $this->container,
		);

		$this->expectException(InvalidArgumentException::class);
		$service->listBySecret(secretId: 'sec-missing', userId: 'owner');
	}//end testListBySecretRejectsUnknownSecret()

	/**
	 * listBySecret fails closed when the SecretMapper bind is missing.
	 *
	 * @return void
	 */
	public function testListBySecretFailsClosedWithoutSecretMapper(): void {
		$this->expectException(InvalidArgumentException::class);
		$this->service->listBySecret(secretId: 'sec-1', userId: 'owner');
	}//end testListBySecretFailsClosedWithoutSecretMapper()

	/**
	 * Build a baseline pending request for getByToken/fill assertions.
	 *
	 * @return SecretRequest
	 */
	private function buildPending(): SecretRequest {
		$entity = new SecretRequest();
		$entity->setId('req-1');
		$entity->setSecretId('sec-1');
		$entity->setEncryptionSuiteId('suite-1');
		$entity->setToken('tok-good');
		$entity->setRequestedFields('["key"]');
		$entity->setStatus(SecretRequest::STATUS_PENDING);
		$entity->setCreatedBy('requester');
		$entity->setCreatedAt(new DateTime());

		return $entity;
	}//end buildPending()

	/**
	 * The write lock covers ALL THREE creation paths, not only create().
	 *
	 * The review on #219 read createForApplication() and createReRequest() as
	 * unguarded, since neither calls assertNotWriteLocked itself. They inherit
	 * it by delegating to create() — but "inherits it today" is exactly the
	 * kind of claim that a later refactor breaks silently, and inconsistent
	 * enforcement of a lock is close to no lock. So it is pinned here rather
	 * than argued.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/restore-suite-migration-loop/specs/encryption-suites/spec.md#requirement-migration-covers-every-suite-bound-store
	 */
	public function testCreateForApplicationIsRefusedWhileWriteLocked(): void {
		$mapper = $this->createMock(SecretRequestMapper::class);
		$suiteMapper = $this->createMock(EncryptionSuiteMapper::class);

		$writeLock = $this->createMock(WriteLockService::class);
		$writeLock->expects($this->once())
			->method('assertNotWriteLocked')
			->willThrowException(new ForbiddenException(message: 'migration in progress'));

		// The refusal must land BEFORE anything is written.
		$mapper->expects($this->never())->method('insert');

		$service = new SecretRequestService(
			mapper: $mapper,
			policy: new SecretRequestPolicy(mapper: $mapper, suiteMapper: $suiteMapper),
			outbox: new SecretRequestOutbox(),
			logger: $this->createMock(LoggerInterface::class),
			writeLockService: $writeLock,
			secretMapper: $this->secretMapper,
			container: $this->container,
		);

		$this->expectException(ForbiddenException::class);

		$service->createForApplication(
			secretId: 'sec-1',
			applicationId: 'app-1',
			requestedFields: ['key'],
			expiresAt: null,
			userId: 'requester',
		);
	}//end testCreateForApplicationIsRefusedWhileWriteLocked()

	/**
	 * The same for a re-request, which is the path a user hits mid-migration.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/restore-suite-migration-loop/specs/encryption-suites/spec.md#requirement-migration-covers-every-suite-bound-store
	 */
	public function testCreateReRequestIsRefusedWhileWriteLocked(): void {
		$mapper = $this->createMock(SecretRequestMapper::class);
		$suiteMapper = $this->createMock(EncryptionSuiteMapper::class);

		$secret = new Secret();
		$secret->setId('sec-1');
		$secret->setOwnerType('user');
		$secret->setOwnerId('requester');
		$secret->setEncryptionSuiteId('suite-1');

		$writeLock = $this->createMock(WriteLockService::class);
		$writeLock->expects($this->once())
			->method('assertNotWriteLocked')
			->willThrowException(new ForbiddenException(message: 'migration in progress'));

		$mapper->expects($this->never())->method('insert');

		$policy = $this->createMock(SecretRequestPolicy::class);
		$policy->method('requireReRequestableSecret')->willReturn($secret);

		$service = new SecretRequestService(
			mapper: $mapper,
			policy: $policy,
			outbox: new SecretRequestOutbox(),
			logger: $this->createMock(LoggerInterface::class),
			writeLockService: $writeLock,
			secretMapper: $this->secretMapper,
			container: $this->container,
		);

		$this->expectException(ForbiddenException::class);

		$service->createReRequest(
			secretId: 'sec-1',
			requestedFields: ['key'],
			expiresAt: null,
			userId: 'requester',
		);
	}//end testCreateReRequestIsRefusedWhileWriteLocked()

	/**
	 * createForApplication resolves the application's active suite and persists the row.
	 *
	 * @return void
	 */
	public function testCreateForApplicationResolvesSuite(): void {
		$mapper = $this->createMock(SecretRequestMapper::class);
		$suiteMapper = $this->createMock(EncryptionSuiteMapper::class);
		$logger = $this->createMock(LoggerInterface::class);

		$suite = new EncryptionSuite();
		$suite->setId('app-suite-1');
		$suite->setOwnerType('application');
		$suite->setOwnerId('app-1');

		$suiteMapper->expects($this->once())
			->method('findActiveByOwner')
			->with('application', 'app-1')
			->willReturn($suite);

		$captured = null;
		$mapper->expects($this->once())
			->method('insert')
			->willReturnCallback(
				static function (SecretRequest $entity) use (&$captured) {
					$captured = $entity;
					return $entity;
				}
			);

		$service = new SecretRequestService(
			mapper: $mapper,
			policy: new SecretRequestPolicy(mapper: $mapper, suiteMapper: $suiteMapper),
			outbox: new SecretRequestOutbox(),
			logger: $logger,
			writeLockService: $this->createMock(WriteLockService::class),
			secretMapper: $this->secretMapper,
			container: $this->container,
		);

		$result = $service->createForApplication(
			secretId: 'sec-1',
			applicationId: 'app-1',
			requestedFields: ['key'],
			expiresAt: null,
			userId: 'requester',
		);

		$this->assertSame($captured, $result);
		$this->assertSame('app-suite-1', $result->getEncryptionSuiteId());
		$this->assertSame('sec-1', $result->getSecretId());
		$this->assertFalse($result->getIsReRequest());
	}//end testCreateForApplicationResolvesSuite()

	/**
	 * createForApplication throws when the application has no active suite.
	 *
	 * @return void
	 */
	public function testCreateForApplicationRejectsMissingSuite(): void {
		$mapper = $this->createMock(SecretRequestMapper::class);
		$suiteMapper = $this->createMock(EncryptionSuiteMapper::class);
		$logger = $this->createMock(LoggerInterface::class);

		$suiteMapper->expects($this->once())
			->method('findActiveByOwner')
			->willThrowException(new DoesNotExistException('no suite'));

		$mapper->expects($this->never())->method('insert');

		$service = new SecretRequestService(
			mapper: $mapper,
			policy: new SecretRequestPolicy(mapper: $mapper, suiteMapper: $suiteMapper),
			outbox: new SecretRequestOutbox(),
			logger: $logger,
			writeLockService: $this->createMock(WriteLockService::class),
			secretMapper: $this->secretMapper,
			container: $this->container,
		);

		$this->expectException(InvalidArgumentException::class);
		$this->expectExceptionMessage('No active EncryptionSuite for application app-1');

		$service->createForApplication(
			secretId: 'sec-1',
			applicationId: 'app-1',
			requestedFields: ['key'],
			expiresAt: null,
			userId: 'requester',
		);
	}//end testCreateForApplicationRejectsMissingSuite()

	/**
	 * createReRequest reuses the secret's suite + flags isReRequest.
	 *
	 * @return void
	 */
	public function testCreateReRequestReusesSecretSuite(): void {
		$mapper = $this->createMock(SecretRequestMapper::class);
		$secretMapper = $this->createMock(SecretMapper::class);
		$logger = $this->createMock(LoggerInterface::class);

		$secret = new Secret();
		$secret->setId('sec-1');
		$secret->setOwnerType('user');
		$secret->setOwnerId('alice');
		$secret->setEncryptionSuiteId('user-suite-1');

		$secretMapper->expects($this->once())
			->method('findById')
			->with('sec-1')
			->willReturn($secret);

		// No pending request exists → continue.
		$mapper->expects($this->once())
			->method('findPendingBySecretId')
			->with('sec-1')
			->willThrowException(new DoesNotExistException('none'));

		$captured = null;
		$mapper->expects($this->once())
			->method('insert')
			->willReturnCallback(
				static function (SecretRequest $entity) use (&$captured) {
					$captured = $entity;
					return $entity;
				}
			);

		$service = new SecretRequestService(
			mapper: $mapper,
			policy: new SecretRequestPolicy(mapper: $mapper, secretMapper: $secretMapper),
			outbox: new SecretRequestOutbox(),
			logger: $logger,
			writeLockService: $this->createMock(WriteLockService::class),
			secretMapper: $this->secretMapper,
			container: $this->container,
		);

		$result = $service->createReRequest(
			secretId: 'sec-1',
			requestedFields: ['key'],
			expiresAt: null,
			userId: 'alice',
		);

		$this->assertSame($captured, $result);
		$this->assertSame('user-suite-1', $result->getEncryptionSuiteId());
		$this->assertTrue($result->getIsReRequest());
	}//end testCreateReRequestReusesSecretSuite()

	/**
	 * createReRequest rejects a non-owner caller.
	 *
	 * @return void
	 */
	public function testCreateReRequestRejectsNonOwner(): void {
		$mapper = $this->createMock(SecretRequestMapper::class);
		$secretMapper = $this->createMock(SecretMapper::class);
		$logger = $this->createMock(LoggerInterface::class);

		$secret = new Secret();
		$secret->setId('sec-1');
		$secret->setOwnerId('bob');

		$secretMapper->expects($this->once())
			->method('findById')
			->willReturn($secret);

		$mapper->expects($this->never())->method('insert');

		$service = new SecretRequestService(
			mapper: $mapper,
			policy: new SecretRequestPolicy(mapper: $mapper, secretMapper: $secretMapper),
			outbox: new SecretRequestOutbox(),
			logger: $logger,
			writeLockService: $this->createMock(WriteLockService::class),
			secretMapper: $this->secretMapper,
			container: $this->container,
		);

		$this->expectException(InvalidArgumentException::class);
		$this->expectExceptionMessage('Only the secret owner may create a re-request');

		$service->createReRequest(
			secretId: 'sec-1',
			requestedFields: ['key'],
			expiresAt: null,
			userId: 'alice',
		);
	}//end testCreateReRequestRejectsNonOwner()

	/**
	 * createReRequest rejects when a pending request is already open.
	 *
	 * @return void
	 */
	public function testCreateReRequestRejectsExistingPending(): void {
		$mapper = $this->createMock(SecretRequestMapper::class);
		$secretMapper = $this->createMock(SecretMapper::class);
		$logger = $this->createMock(LoggerInterface::class);

		$secret = new Secret();
		$secret->setId('sec-1');
		$secret->setOwnerId('alice');
		$secret->setEncryptionSuiteId('user-suite-1');

		$secretMapper->expects($this->once())
			->method('findById')
			->willReturn($secret);

		$pending = new SecretRequest();
		$pending->setId('req-existing');
		$mapper->expects($this->once())
			->method('findPendingBySecretId')
			->willReturn($pending);

		$mapper->expects($this->never())->method('insert');

		$service = new SecretRequestService(
			mapper: $mapper,
			policy: new SecretRequestPolicy(mapper: $mapper, secretMapper: $secretMapper),
			outbox: new SecretRequestOutbox(),
			logger: $logger,
			writeLockService: $this->createMock(WriteLockService::class),
			secretMapper: $this->secretMapper,
			container: $this->container,
		);

		$this->expectException(InvalidArgumentException::class);
		$this->expectExceptionMessage('A pending request already exists for this secret');

		$service->createReRequest(
			secretId: 'sec-1',
			requestedFields: ['key'],
			expiresAt: null,
			userId: 'alice',
		);
	}//end testCreateReRequestRejectsExistingPending()
	/**
	 * A FRESH request creates its own unfilled Secret and links to it.
	 *
	 * @return void
	 *
	 * @spec openspec/specs/secret-requests/spec.md#requirement-create-secret-request
	 */
	public function testFreshRequestCreatesItsOwnPlaceholder(): void {
		$shell = new Secret();
		$shell->setId('sec-placeholder');
		$shell->setEncryptionSuiteId('suite-9');
		$shell->setOwnerType('user');
		$shell->setOwnerId('alice');

		// The placeholder must be created keyless, and ONLY with the explicit
		// opt-in — the whole point is that the requester supplies no value.
		$this->secretService->expects($this->once())
			->method('create')
			->with(
				$this->callback(
					static fn (array $data): bool => $data['key'] === ''
						&& $data['name'] === 'Supplier API key'
				),
				'alice',
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

		$request = $this->service->createForUserVault(
			userId: 'alice',
			requestedFields: ['key', 'url'],
			name: 'Supplier API key',
		);

		$this->assertSame('sec-placeholder', $captured->getSecretId());
		// Read off the created Secret, never from a caller parameter.
		$this->assertSame('suite-9', $captured->getEncryptionSuiteId());
		$this->assertFalse($captured->getIsReRequest());
		$this->assertSame(SecretRequest::STATUS_PENDING, $captured->getStatus());
		$this->assertNotSame('', (string)$request->getToken());
	}//end testFreshRequestCreatesItsOwnPlaceholder()

	/**
	 * A failed request creation leaves no orphan placeholder behind.
	 *
	 * @return void
	 *
	 * @spec openspec/specs/secret-requests/spec.md#requirement-create-secret-request
	 */
	public function testFreshRequestRollsBackThePlaceholderOnFailure(): void {
		$shell = new Secret();
		$shell->setId('sec-doomed');
		$shell->setEncryptionSuiteId('suite-9');
		$this->secretService->method('create')->willReturn($shell);

		$this->mapper->method('insert')->willThrowException(new RuntimeException('db down'));

		// The shell exists only to receive this request, so it must not survive.
		$this->secretService->expects($this->once())
			->method('delete')
			->with('sec-doomed', 'alice');

		$this->expectException(RuntimeException::class);
		$this->service->createForUserVault(
			userId: 'alice',
			requestedFields: ['key'],
			name: 'doomed',
		);
	}//end testFreshRequestRollsBackThePlaceholderOnFailure()

	/**
	 * An empty field list is refused before any Secret is created.
	 *
	 * @return void
	 *
	 * @spec openspec/specs/secret-requests/spec.md#requirement-create-secret-request
	 */
	public function testFreshRequestRefusesAnEmptyFieldList(): void {
		$this->secretService->expects($this->never())->method('create');

		$this->expectException(InvalidArgumentException::class);
		$this->service->createForUserVault(userId: 'alice', requestedFields: []);
	}//end testFreshRequestRefusesAnEmptyFieldList()

	/**
	 * Revoking a fresh request deletes the placeholder it created.
	 *
	 * @return void
	 *
	 * @spec openspec/specs/secrets/spec.md#requirement-unfilled-request-placeholder
	 */
	public function testRevokeDeletesTheUnfilledPlaceholder(): void {
		$entity = $this->makePending('req-1', 'sec-empty');
		$this->mapper->method('findById')->willReturn($entity);
		$this->mapper->method('update')->willReturnArgument(0);

		$empty = new Secret();
		$empty->setId('sec-empty');
		$empty->setKey('');
		$empty->setOwnerType('user');
		$empty->setOwnerId('alice');
		$this->secretMapper->method('findById')->willReturn($empty);

		$this->secretService->expects($this->once())
			->method('delete')
			->with('sec-empty', 'alice');

		$this->service->decline(requestId: 'req-1', userId: 'alice');
	}//end testRevokeDeletesTheUnfilledPlaceholder()

	/**
	 * Revoking must NEVER delete a Secret that holds a value.
	 *
	 * This is the hazard the emptiness discriminator exists for: a plain request
	 * also carries `isReRequest === false` while targeting a Secret the USER
	 * chose, so keying the delete on that flag would destroy real credentials.
	 *
	 * @return void
	 *
	 * @spec openspec/specs/secrets/spec.md#requirement-unfilled-request-placeholder
	 */
	public function testRevokeNeverDeletesAFilledSecret(): void {
		$entity = $this->makePending('req-2', 'sec-filled');
		$this->mapper->method('findById')->willReturn($entity);
		$this->mapper->method('update')->willReturnArgument(0);

		$filled = new Secret();
		$filled->setId('sec-filled');
		$filled->setKey('CIPHERTEXT');
		$filled->setOwnerType('user');
		$filled->setOwnerId('alice');
		$this->secretMapper->method('findById')->willReturn($filled);

		$this->secretService->expects($this->never())->method('delete');

		$this->service->decline(requestId: 'req-2', userId: 'alice');
	}//end testRevokeNeverDeletesAFilledSecret()

	/**
	 * A placeholder in someone else's vault is never touched by this path.
	 *
	 * @return void
	 *
	 * @spec openspec/specs/secrets/spec.md#requirement-unfilled-request-placeholder
	 */
	public function testRevokeDoesNotReachAcrossAnOwnershipBoundary(): void {
		$entity = $this->makePending('req-3', 'sec-app');
		$this->mapper->method('findById')->willReturn($entity);
		$this->mapper->method('update')->willReturnArgument(0);

		$appOwned = new Secret();
		$appOwned->setId('sec-app');
		$appOwned->setKey('');
		$appOwned->setOwnerType('application');
		$appOwned->setOwnerId('app-1');
		$this->secretMapper->method('findById')->willReturn($appOwned);

		$this->secretService->expects($this->never())->method('delete');

		$this->service->decline(requestId: 'req-3', userId: 'alice');
	}//end testRevokeDoesNotReachAcrossAnOwnershipBoundary()

	/**
	 * Build a pending request owned by alice.
	 *
	 * @param string $id The request id
	 * @param string $secretId The linked Secret id
	 *
	 * @return SecretRequest
	 */
	private function makePending(string $id, string $secretId): SecretRequest {
		$entity = new SecretRequest();
		$entity->setId($id);
		$entity->setSecretId($secretId);
		$entity->setStatus(SecretRequest::STATUS_PENDING);
		$entity->setCreatedBy('alice');
		$entity->setToken('tok-' . $id);

		return $entity;
	}//end makePending()

	/**
	 * Two fresh requests each get their own Secret.
	 *
	 * @return void
	 *
	 * @spec openspec/specs/secret-requests/spec.md#requirement-create-secret-request
	 */
	public function testTwoFreshRequestsDoNotShareAPlaceholder(): void {
		$first = new Secret();
		$first->setId('sec-a');
		$first->setEncryptionSuiteId('suite-9');
		$second = new Secret();
		$second->setId('sec-b');
		$second->setEncryptionSuiteId('suite-9');

		$this->secretService->method('create')->willReturnOnConsecutiveCalls($first, $second);

		$seen = [];
		$this->mapper->method('insert')->willReturnCallback(
			static function (SecretRequest $entity) use (&$seen) {
				$seen[] = $entity->getSecretId();
				return $entity;
			}
		);

		$this->service->createForUserVault(userId: 'alice', requestedFields: ['key'], name: 'one');
		$this->service->createForUserVault(userId: 'alice', requestedFields: ['key'], name: 'two');

		$this->assertSame(['sec-a', 'sec-b'], $seen);
		$this->assertCount(2, array_unique($seen));
	}//end testTwoFreshRequestsDoNotShareAPlaceholder()

	/**
	 * A missing userId is refused before any Secret is created.
	 *
	 * @return void
	 *
	 * @spec openspec/specs/secret-requests/spec.md#requirement-create-secret-request
	 */
	public function testFreshRequestRefusesAnEmptyUserId(): void {
		$this->secretService->expects($this->never())->method('create');

		$this->expectException(InvalidArgumentException::class);
		$this->service->createForUserVault(userId: '', requestedFields: ['key']);
	}//end testFreshRequestRefusesAnEmptyUserId()

	/**
	 * A rollback that itself fails must not mask the original error.
	 *
	 * This path decides whether a failed request leaves an orphan placeholder
	 * behind. If the cleanup throws and that throw escapes, the caller sees a
	 * confusing "could not delete" instead of the real reason the request failed,
	 * and the orphan is still there either way.
	 *
	 * @return void
	 *
	 * @spec openspec/specs/secret-requests/spec.md#requirement-create-secret-request
	 */
	public function testAFailingRollbackDoesNotMaskTheOriginalError(): void {
		$shell = new Secret();
		$shell->setId('sec-doomed');
		$shell->setEncryptionSuiteId('suite-9');
		$this->secretService->method('create')->willReturn($shell);
		$this->mapper->method('insert')->willThrowException(new RuntimeException('db down'));
		$this->secretService->method('delete')
			->willThrowException(new RuntimeException('cleanup also failed'));

		try {
			$this->service->createForUserVault(
				userId: 'alice',
				requestedFields: ['key'],
				name: 'doomed',
			);
			$this->fail('the original failure must surface');
		} catch (RuntimeException $e) {
			$this->assertSame('db down', $e->getMessage(), 'the ORIGINAL error must survive');
		}
	}//end testAFailingRollbackDoesNotMaskTheOriginalError()

	/**
	 * Revoking when the linked Secret is already gone is not an error.
	 *
	 * The request is still revoked; there is simply nothing left to clean up.
	 * Throwing here would turn a successful revoke into a failure the user has to
	 * retry against a Secret that no longer exists.
	 *
	 * @return void
	 *
	 * @spec openspec/specs/secrets/spec.md#requirement-unfilled-request-placeholder
	 */
	public function testRevokeSurvivesAMissingSecret(): void {
		$entity = $this->makePending('req-gone', 'sec-vanished');
		$this->mapper->method('findById')->willReturn($entity);
		$this->mapper->method('update')->willReturnArgument(0);
		$this->secretMapper->method('findById')
			->willThrowException(new DoesNotExistException('gone'));

		$this->secretService->expects($this->never())->method('delete');

		$this->assertSame(
			SecretRequest::STATUS_DECLINED,
			$this->service->decline(requestId: 'req-gone', userId: 'alice')->getStatus()
		);
	}//end testRevokeSurvivesAMissingSecret()

	/**
	 * A failing placeholder delete must not fail the revoke.
	 *
	 * An orphan empty Secret is untidy; a revoke the user believes did not work is
	 * worse, because they will try again on a request that is already declined.
	 *
	 * @return void
	 *
	 * @spec openspec/specs/secrets/spec.md#requirement-unfilled-request-placeholder
	 */
	public function testRevokeSurvivesAFailingPlaceholderDelete(): void {
		$entity = $this->makePending('req-stubborn', 'sec-empty');
		$this->mapper->method('findById')->willReturn($entity);
		$this->mapper->method('update')->willReturnArgument(0);

		$empty = new Secret();
		$empty->setId('sec-empty');
		$empty->setKey('');
		$empty->setOwnerType('user');
		$empty->setOwnerId('alice');
		$this->secretMapper->method('findById')->willReturn($empty);
		$this->secretService->method('delete')
			->willThrowException(new RuntimeException('locked'));

		$this->assertSame(
			SecretRequest::STATUS_DECLINED,
			$this->service->decline(requestId: 'req-stubborn', userId: 'alice')->getStatus()
		);
	}//end testRevokeSurvivesAFailingPlaceholderDelete()

	/**
	 * Build a keyless but genuinely filled Secret owned by alice.
	 *
	 * @param string $id The secret id
	 *
	 * @return Secret
	 */
	private function keylessSecret(string $id): Secret {
		$secret = new Secret();
		$secret->setId($id);
		$secret->setKey('');
		$secret->setOwnerType('user');
		$secret->setOwnerId('alice');

		return $secret;
	}//end keylessSecret()

	/**
	 * A Secret holding only a login must survive a revoke.
	 *
	 * `key` is not a mandatory member of `requestedFields`, so a requester can ask
	 * for a login alone. Filling that request writes `login` and leaves `key`
	 * empty, which an emptiness test keyed on `key` alone reads as "never filled".
	 * The delete is hard and takes the version history with it, so getting this
	 * wrong destroys the very credential the request existed to collect.
	 *
	 * @return void
	 *
	 * @spec openspec/specs/secrets/spec.md#requirement-unfilled-request-placeholder
	 */
	public function testRevokeNeverDeletesASecretHoldingOnlyALogin(): void {
		$entity = $this->makePending('req-login', 'sec-login');
		$this->mapper->method('findById')->willReturn($entity);
		$this->mapper->method('update')->willReturnArgument(0);

		$secret = $this->keylessSecret('sec-login');
		$secret->setLogin('CIPHERTEXT-LOGIN');
		$this->secretMapper->method('findById')->willReturn($secret);

		$this->secretService->expects($this->never())->method('delete');

		$this->service->decline(requestId: 'req-login', userId: 'alice');
	}//end testRevokeNeverDeletesASecretHoldingOnlyALogin()

	/**
	 * A Secret holding only additional fields must survive a revoke.
	 *
	 * Custom members are one encrypted blob (ADR-003), so this is the case where a
	 * requester asked for something the schema does not name at all — and where
	 * the deleted ciphertext could hold any number of credentials.
	 *
	 * @return void
	 *
	 * @spec openspec/specs/secrets/spec.md#requirement-unfilled-request-placeholder
	 */
	public function testRevokeNeverDeletesASecretHoldingOnlyAdditionalFields(): void {
		$entity = $this->makePending('req-extra', 'sec-extra');
		$this->mapper->method('findById')->willReturn($entity);
		$this->mapper->method('update')->willReturnArgument(0);

		$secret = $this->keylessSecret('sec-extra');
		$secret->setAdditionalFields('CIPHERTEXT-BLOB');
		$this->secretMapper->method('findById')->willReturn($secret);

		$this->secretService->expects($this->never())->method('delete');

		$this->service->decline(requestId: 'req-extra', userId: 'alice');
	}//end testRevokeNeverDeletesASecretHoldingOnlyAdditionalFields()

	/**
	 * A Secret holding only a url must survive a revoke.
	 *
	 * `url` is plaintext and requestable (SecretRequestPolicy::PLAINTEXT_FIELDS),
	 * and `createForUserVault()` never sets it, so a real fresh placeholder always
	 * has it null. A url present therefore means somebody put it there.
	 *
	 * @return void
	 *
	 * @spec openspec/specs/secrets/spec.md#requirement-unfilled-request-placeholder
	 */
	public function testRevokeNeverDeletesASecretHoldingOnlyAUrl(): void {
		$entity = $this->makePending('req-url', 'sec-url');
		$this->mapper->method('findById')->willReturn($entity);
		$this->mapper->method('update')->willReturnArgument(0);

		$secret = $this->keylessSecret('sec-url');
		$secret->setUrl('https://vault.example.org/login');
		$this->secretMapper->method('findById')->willReturn($secret);

		$this->secretService->expects($this->never())->method('delete');

		$this->service->decline(requestId: 'req-url', userId: 'alice');
	}//end testRevokeNeverDeletesASecretHoldingOnlyAUrl()

	/**
	 * A truly empty placeholder is still deleted.
	 *
	 * The counterpart to the three tests above: widening the emptiness test must
	 * not disable the cleanup it guards.
	 *
	 * @return void
	 *
	 * @spec openspec/specs/secrets/spec.md#requirement-unfilled-request-placeholder
	 */
	public function testRevokeStillDeletesATrulyEmptyPlaceholder(): void {
		$entity = $this->makePending('req-empty', 'sec-empty-2');
		$this->mapper->method('findById')->willReturn($entity);
		$this->mapper->method('update')->willReturnArgument(0);
		$this->secretMapper->method('findById')->willReturn($this->keylessSecret('sec-empty-2'));

		$this->secretService->expects($this->once())
			->method('delete')
			->with('sec-empty-2', 'alice');

		$this->service->decline(requestId: 'req-empty', userId: 'alice');
	}//end testRevokeStillDeletesATrulyEmptyPlaceholder()

}//end class
