<?php

/**
 * Unit tests for the trash state in SecretService (vault-trash-and-archive):
 * a purge records its own event, and the list reads one state.
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

use OCA\Keepiq\Db\EncryptionSuiteMapper;
use OCA\Keepiq\Db\Secret;
use OCA\Keepiq\Db\SecretMapper;
use OCA\Keepiq\Event\Audit\AuditEvent;
use OCA\Keepiq\Event\Audit\AuditEventTypes;
use OCA\Keepiq\Service\LinkShareService;
use OCA\Keepiq\Service\MigrationService;
use OCA\Keepiq\Service\SecretService;
use OCA\Keepiq\Service\SecretTypeService;
use OCP\EventDispatcher\IEventDispatcher;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

/**
 * SecretService with the trash and archive state.
 */
class SecretServiceTrashStateTest extends TestCase {
	/** @var SecretMapper&MockObject */
	private SecretMapper $mapper;

	/** @var list<AuditEvent> */
	private array $events = [];

	private SecretService $service;

	/**
	 * Wire the real service with a recording dispatcher.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		$this->mapper = $this->createMock(SecretMapper::class);
		$dispatcher = $this->createMock(IEventDispatcher::class);
		$dispatcher->method('dispatchTyped')->willReturnCallback(
			function ($event): void {
				$this->events[] = $event;
			}
		);

		$this->service = new SecretService(
			mapper: $this->mapper,
			typeService: $this->createMock(SecretTypeService::class),
			suiteMapper: $this->createMock(EncryptionSuiteMapper::class),
			migrationService: $this->createMock(MigrationService::class),
			linkShareService: $this->createMock(LinkShareService::class),
			logger: $this->createMock(LoggerInterface::class),
			eventDispatcher: $dispatcher,
		);

		$secret = new Secret();
		$secret->setId('s-1');
		$secret->setName('Router');
		$secret->setOwnerType('user');
		$secret->setOwnerId('alice');
		$this->mapper->method('findById')->willReturn($secret);
	}//end setUp()

	/**
	 * A retention purge is recorded as a system event with its reason.
	 *
	 * @return void
	 */
	public function testRetentionPurgeIsASystemEvent(): void {
		$this->mapper->expects($this->once())->method('delete');

		$this->service->delete('s-1', 'alice', 'retention');

		$this->assertCount(1, $this->events);
		$this->assertSame(AuditEventTypes::SECRET_PURGED, $this->events[0]->getEventType());
		$this->assertSame(AuditEvent::ACTOR_SYSTEM, $this->events[0]->getActorType());
		$this->assertSame(['reason' => 'retention'], $this->events[0]->getMetadata());
	}//end testRetentionPurgeIsASystemEvent()

	/**
	 * The owner's purge is the owner's event; a plain delete stays secret.deleted.
	 *
	 * @return void
	 */
	public function testOwnerPurgeAndPlainDelete(): void {
		$this->service->delete('s-1', 'alice', 'owner');
		$this->service->delete('s-1', 'alice');

		$this->assertSame(AuditEventTypes::SECRET_PURGED, $this->events[0]->getEventType());
		$this->assertSame('alice', $this->events[0]->getActorId());
		$this->assertSame(AuditEventTypes::SECRET_DELETED, $this->events[1]->getEventType());
	}//end testOwnerPurgeAndPlainDelete()

	/**
	 * The list reads live secrets unless a state is named.
	 *
	 * @return void
	 */
	public function testListReadsOneState(): void {
		$states = [];
		$this->mapper->method('findByOwner')->willReturnCallback(
			function (...$args) use (&$states): array {
				$states[] = $args[8];
				return [];
			}
		);
		$this->mapper->method('countByOwner')->willReturn(0);

		$this->service->list('alice', null, null, 'asc', 1, 50);
		$this->service->list('alice', null, null, 'asc', 1, 50, null, SecretMapper::STATE_TRASHED);

		$this->assertSame([SecretMapper::STATE_LIVE, SecretMapper::STATE_TRASHED], $states);
	}//end testListReadsOneState()
}//end class
