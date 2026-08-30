<?php

/**
 * Unit tests for the SecretService in-process application-vault seam.
 *
 * @category Test
 * @package  OCA\Keepiq\Tests\Unit\Service
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

namespace OCA\Keepiq\Tests\Unit\Service;

use OCA\Keepiq\Db\EncryptionSuiteMapper;
use OCA\Keepiq\Db\GroupShareMapper;
use OCA\Keepiq\Db\Secret;
use OCA\Keepiq\Db\SecretDelegationMapper;
use OCA\Keepiq\Db\SecretMapper;
use OCA\Keepiq\Event\Audit\AuditEvent;
use OCA\Keepiq\Event\Audit\AuditEventTypes;
use OCA\Keepiq\Service\LinkShareService;
use OCA\Keepiq\Service\MigrationService;
use OCA\Keepiq\Service\SecretRequestService;
use OCA\Keepiq\Service\SecretService;
use OCA\Keepiq\Service\SecretTypeService;
use OCA\Keepiq\Service\ShareService;
use OCP\AppFramework\Db\DoesNotExistException;
use OCP\EventDispatcher\IEventDispatcher;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

/**
 * Tests deleteByApplication / getByNameForApplication — the in-process
 * application-vault seam (own-vault scoped, idempotent delete with no
 * existence oracle, read-by-name that never guesses, audit parity with
 * the machine paths). Spec: application-secret-delete.
 *
 * @SuppressWarnings(PHPMD.TooManyPublicMethods) One test per spec scenario.
 */
class SecretServiceApplicationVaultTest extends TestCase {

	/**
	 * The mocked secret mapper.
	 *
	 * @var SecretMapper&MockObject
	 */
	private SecretMapper $mapper;

	/**
	 * The mocked link-share service (cascade).
	 *
	 * @var LinkShareService&MockObject
	 */
	private LinkShareService $linkShareService;

	/**
	 * The mocked secret-request service (cascade).
	 *
	 * @var SecretRequestService&MockObject
	 */
	private SecretRequestService $secretRequestService;

	/**
	 * The mocked share service (cascade).
	 *
	 * @var ShareService&MockObject
	 */
	private ShareService $shareService;

	/**
	 * The mocked group-share mapper (cascade).
	 *
	 * @var GroupShareMapper&MockObject
	 */
	private GroupShareMapper $groupShareMapper;

	/**
	 * The mocked secret-delegation mapper (cascade).
	 *
	 * @var SecretDelegationMapper&MockObject
	 */
	private SecretDelegationMapper $secretDelegationMapper;

	/**
	 * The mocked logger.
	 *
	 * @var LoggerInterface&MockObject
	 */
	private LoggerInterface $logger;

	/**
	 * The mocked event dispatcher.
	 *
	 * @var IEventDispatcher&MockObject
	 */
	private IEventDispatcher $dispatcher;

	/**
	 * The audit events captured from the dispatcher.
	 *
	 * @var AuditEvent[]
	 */
	private array $events = [];

	/**
	 * The service under test.
	 *
	 * @var SecretService
	 */
	private SecretService $service;

	/**
	 * Wire the service with mocks, all cascade dependencies present, and an
	 * event dispatcher that records every dispatched audit event.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		$this->mapper = $this->createMock(SecretMapper::class);
		$this->linkShareService = $this->createMock(LinkShareService::class);
		$this->secretRequestService = $this->createMock(SecretRequestService::class);
		$this->shareService = $this->createMock(ShareService::class);
		$this->groupShareMapper = $this->createMock(GroupShareMapper::class);
		$this->secretDelegationMapper = $this->createMock(SecretDelegationMapper::class);
		$this->logger = $this->createMock(LoggerInterface::class);
		$this->dispatcher = $this->createMock(IEventDispatcher::class);

		$this->events = [];
		$this->dispatcher->method('dispatchTyped')->willReturnCallback(
			function (AuditEvent $event): void {
				$this->events[] = $event;
			}
		);

		$this->service = new SecretService(
			mapper: $this->mapper,
			typeService: $this->createMock(SecretTypeService::class),
			suiteMapper: $this->createMock(EncryptionSuiteMapper::class),
			migrationService: $this->createMock(MigrationService::class),
			linkShareService: $this->linkShareService,
			logger: $this->logger,
			secretRequestService: $this->secretRequestService,
			shareService: $this->shareService,
			groupShareMapper: $this->groupShareMapper,
			secretDelegationMapper: $this->secretDelegationMapper,
			eventDispatcher: $this->dispatcher,
		);
	}//end setUp()

	/**
	 * Build a secret owned by the given vault.
	 *
	 * @param string $id The secret id
	 * @param string $ownerType The owner type
	 * @param string $ownerId The owner id
	 * @param string $name The plaintext name
	 *
	 * @return Secret
	 */
	private function makeSecret(
		string $id = 's-1',
		string $ownerType = 'application',
		string $ownerId = 'app-1',
		string $name = '00000000-0000-0000-0000-000000000000',
	): Secret {
		$secret = new Secret();
		$secret->setId($id);
		$secret->setName($name);
		$secret->setKey('KEY-CIPHERTEXT');
		$secret->setLogin('LOGIN-CIPHERTEXT');
		$secret->setAdditionalFields('AF-CIPHERTEXT');
		$secret->setOwnerType($ownerType);
		$secret->setOwnerId($ownerId);
		$secret->setEncryptionSuiteId('suite-1');
		return $secret;
	}//end makeSecret()

	/**
	 * Filter the captured events by type.
	 *
	 * @param string $eventType The audit event type
	 *
	 * @return AuditEvent[]
	 */
	private function eventsOfType(string $eventType): array {
		return array_values(
			array_filter(
				$this->events,
				static fn (AuditEvent $e): bool => $e->getEventType() === $eventType
			)
		);
	}//end eventsOfType()

	/**
	 * 2.1 Own-vault delete: the mapper deletes the loaded entity and exactly
	 * one secret.deleted event fires with the application as actor.
	 *
	 * @return void
	 */
	public function testDeleteByApplicationOwnVaultDeletesAndAuditsOnce(): void {
		$secret = $this->makeSecret();
		$this->mapper->method('findById')->willReturn($secret);
		$this->mapper->expects($this->once())->method('delete')
			->with($this->identicalTo($secret));

		$this->service->deleteByApplication(secretId: 's-1', applicationId: 'app-1');

		$deleted = $this->eventsOfType(AuditEventTypes::SECRET_DELETED);
		$this->assertCount(1, $deleted);
		$this->assertSame(AuditEvent::ACTOR_APPLICATION, $deleted[0]->getActorType());
		$this->assertSame('app-1', $deleted[0]->getActorId());
		$this->assertSame('s-1', $deleted[0]->getObjectId());
		$this->assertSame($secret->getName(), $deleted[0]->getObjectName());
	}//end testDeleteByApplicationOwnVaultDeletesAndAuditsOnce()

	/**
	 * 2.2 A secret owned by another application is a silent no-op: nothing
	 * deleted, no exception, zero audit dispatches.
	 *
	 * @return void
	 */
	public function testDeleteByApplicationCrossVaultApplicationNoOp(): void {
		$this->mapper->method('findById')
			->willReturn($this->makeSecret(ownerId: 'app-2'));
		$this->mapper->expects($this->never())->method('delete');
		$this->linkShareService->expects($this->never())->method('deleteBySecretId');

		$this->service->deleteByApplication(secretId: 's-1', applicationId: 'app-1');

		$this->assertCount(0, $this->events);
	}//end testDeleteByApplicationCrossVaultApplicationNoOp()

	/**
	 * 2.3 A user-owned secret is the same silent no-op — indistinguishable
	 * from a nonexistent id, zero audit dispatches.
	 *
	 * @return void
	 */
	public function testDeleteByApplicationUserOwnedSecretNoOp(): void {
		$this->mapper->method('findById')
			->willReturn($this->makeSecret(ownerType: 'user', ownerId: 'alice'));
		$this->mapper->expects($this->never())->method('delete');
		$this->linkShareService->expects($this->never())->method('deleteBySecretId');

		$this->service->deleteByApplication(secretId: 's-1', applicationId: 'app-1');

		$this->assertCount(0, $this->events);
	}//end testDeleteByApplicationUserOwnedSecretNoOp()

	/**
	 * 2.4 A nonexistent id returns silently — and a double delete stays a
	 * silent no-op both times (idempotency).
	 *
	 * @return void
	 */
	public function testDeleteByApplicationNonexistentIdIsIdempotent(): void {
		$this->mapper->method('findById')
			->willThrowException(new DoesNotExistException('none'));
		$this->mapper->expects($this->never())->method('delete');

		$this->service->deleteByApplication(secretId: 'gone', applicationId: 'app-1');
		$this->service->deleteByApplication(secretId: 'gone', applicationId: 'app-1');

		$this->assertCount(0, $this->events);
	}//end testDeleteByApplicationNonexistentIdIsIdempotent()

	/**
	 * 2.5 A single real deletion dispatches exactly one audit event and
	 * invokes every sharing-graph cascade helper (design D4).
	 *
	 * @return void
	 */
	public function testDeleteByApplicationCascadesAndAuditsExactlyOnce(): void {
		$secret = $this->makeSecret();
		$this->mapper->method('findById')->willReturn($secret);

		$this->linkShareService->expects($this->once())
			->method('deleteBySecretId')->with('s-1');
		$this->secretRequestService->expects($this->once())
			->method('deleteAllForSecret')->with('s-1');
		$this->shareService->expects($this->once())
			->method('deleteAllForSecret')->with('s-1');
		$this->groupShareMapper->expects($this->once())
			->method('deleteBySecret')->with('s-1');
		$this->secretDelegationMapper->expects($this->once())
			->method('deleteBySecret')->with('s-1');
		$this->mapper->expects($this->once())->method('delete');

		$this->service->deleteByApplication(secretId: 's-1', applicationId: 'app-1');

		$this->assertCount(1, $this->events);
		$this->assertSame(AuditEventTypes::SECRET_DELETED, $this->events[0]->getEventType());
	}//end testDeleteByApplicationCascadesAndAuditsExactlyOnce()

	/**
	 * 2.6 Read hit: the same entity instance is returned with its ciphertext
	 * fields untouched, plus exactly one application.secret_retrieved event
	 * with the application as actor.
	 *
	 * @return void
	 */
	public function testGetByNameForApplicationHitReturnsCiphertextIntact(): void {
		$secret = $this->makeSecret();
		$this->mapper->method('findByName')->willReturn([$secret]);

		$result = $this->service->getByNameForApplication(
			name: $secret->getName(),
			applicationId: 'app-1'
		);

		$this->assertSame($secret, $result);
		$this->assertSame('KEY-CIPHERTEXT', $result->getKey());
		$this->assertSame('LOGIN-CIPHERTEXT', $result->getLogin());
		$this->assertSame('AF-CIPHERTEXT', $result->getAdditionalFields());

		$retrieved = $this->eventsOfType(AuditEventTypes::APPLICATION_SECRET_RETRIEVED);
		$this->assertCount(1, $retrieved);
		$this->assertCount(1, $this->events);
		$this->assertSame(AuditEvent::ACTOR_APPLICATION, $retrieved[0]->getActorType());
		$this->assertSame('app-1', $retrieved[0]->getActorId());
		$this->assertSame('s-1', $retrieved[0]->getObjectId());
		$this->assertSame($secret->getName(), $retrieved[0]->getObjectName());
	}//end testGetByNameForApplicationHitReturnsCiphertextIntact()

	/**
	 * 2.7 Read miss: an empty result set returns null with zero audit
	 * dispatches — and the mapper query is owner-keyed to the calling
	 * application's vault (nonexistent and cross-vault are the same case).
	 *
	 * @return void
	 */
	public function testGetByNameForApplicationMissReturnsNullOwnerKeyed(): void {
		$captured = null;
		$this->mapper->expects($this->once())->method('findByName')
			->willReturnCallback(
				function (
					string $ownerType,
					string $ownerId,
					string $name,
					?string $folderId = null,
				) use (&$captured): array {
					$captured = [$ownerType, $ownerId, $name, $folderId];
					return [];
				}
			);

		$result = $this->service->getByNameForApplication(
			name: 'not-there',
			applicationId: 'app-1'
		);

		$this->assertNull($result);
		$this->assertCount(0, $this->events);
		$this->assertSame(['application', 'app-1', 'not-there', null], $captured);
	}//end testGetByNameForApplicationMissReturnsNullOwnerKeyed()

	/**
	 * 2.8 Read ambiguity: two same-named matches return null with exactly
	 * one warning and zero audit dispatches — neither candidate is guessed.
	 *
	 * @return void
	 */
	public function testGetByNameForApplicationAmbiguousNeverGuesses(): void {
		$this->mapper->method('findByName')->willReturn(
			[
				$this->makeSecret(id: 's-1'),
				$this->makeSecret(id: 's-2'),
			]
		);
		$this->logger->expects($this->once())->method('warning');

		$result = $this->service->getByNameForApplication(
			name: '00000000-0000-0000-0000-000000000000',
			applicationId: 'app-1'
		);

		$this->assertNull($result);
		$this->assertCount(0, $this->events);
	}//end testGetByNameForApplicationAmbiguousNeverGuesses()
}//end class
