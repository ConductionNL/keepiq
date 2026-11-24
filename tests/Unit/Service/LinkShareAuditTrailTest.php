<?php

/**
 * LinkShareAuditTrail: the audit event each step in a link share's life
 * records, with its actor, object and metadata.
 *
 * @category Test
 * @package  OCA\Keepiq\Tests\Unit\Service
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @link https://conduction.nl
 */

declare(strict_types=1);

namespace OCA\Keepiq\Tests\Unit\Service;

use OCA\Keepiq\Db\Secret;
use OCA\Keepiq\Event\Audit\AuditEvent;
use OCA\Keepiq\Service\ApplicationSecretUpdateRun;
use OCA\Keepiq\Service\LinkShareAuditTrail;
use OCP\EventDispatcher\IEventDispatcher;
use PHPUnit\Framework\TestCase;

/**
 * The link share audit trail.
 */
class LinkShareAuditTrailTest extends TestCase {
	/**
	 * The events dispatched so far.
	 *
	 * @var array<int, AuditEvent>
	 */
	private array $events = [];

	/**
	 * A trail whose dispatcher records each event.
	 *
	 * @return LinkShareAuditTrail
	 */
	private function trail(): LinkShareAuditTrail {
		$dispatcher = $this->createMock(originalClassName: IEventDispatcher::class);
		$dispatcher->method('dispatchTyped')->willReturnCallback(
			function (AuditEvent $event): void {
				$this->events[] = $event;
			}
		);
		return new LinkShareAuditTrail(eventDispatcher: $dispatcher);
	}//end trail()

	/**
	 * The parts of the last dispatched event.
	 *
	 * @return array{0: string, 1: string|null, 2: string, 3: string, 4: string|null, 5: array<string, mixed>}
	 */
	private function last(): array {
		$event = end($this->events);
		$this->assertInstanceOf(expected: AuditEvent::class, actual: $event);
		return [
			$event->getActorType(),
			$event->getActorId(),
			$event->getEventType(),
			$event->getObjectType(),
			$event->getObjectId(),
			$event->getMetadata(),
		];
	}//end last()

	/**
	 * Creating a link records its owner, whether it has a password and when it expires.
	 *
	 * @return void
	 */
	public function testACreationIsRecordedForTheOwner(): void {
		$this->trail()->recordCreated(userId: 'anne', linkShareId: 'ls-1', hasPassword: true, expiresAtIso: '2026-12-01T00:00:00+00:00');
		[$actorType, $actorId, $type, $objectType, $objectId, $metadata] = $this->last();
		$this->assertSame(expected: ['anne', 'link_share.created', 'link_share', 'ls-1'], actual: [$actorId, $type, $objectType, $objectId]);
		$this->assertSame(expected: ['hasPassword' => true, 'expiresAt' => '2026-12-01T00:00:00+00:00'], actual: $metadata);
		$this->assertNotSame(expected: '', actual: $actorType);
	}//end testACreationIsRecordedForTheOwner()

	/**
	 * Visits by the link's holder are recorded without a user, with the reason of a failure.
	 *
	 * @return void
	 */
	public function testVisitsAreRecordedForTheLinkVisitor(): void {
		$trail = $this->trail();
		$trail->recordAccessed(linkShareId: 'ls-1');
		$accessed = $this->last();
		$trail->recordAccessFailed(linkShareId: 'ls-1');
		$failed = $this->last();
		$trail->recordAutoDeleted(linkShareId: 'ls-1');
		$deleted = $this->last();
		$this->assertSame(expected: ['link_share.accessed', []], actual: [$accessed[2], $accessed[5]]);
		$this->assertSame(expected: ['link_share.access_failed', ['reason' => 'invalid_password']], actual: [$failed[2], $failed[5]]);
		$this->assertSame(expected: ['link_share.auto_deleted', ['reason' => 'too_many_failed_attempts']], actual: [$deleted[2], $deleted[5]]);
		$this->assertSame(expected: $accessed[0], actual: $failed[0]);
		$this->assertNotSame(expected: 'anne', actual: $accessed[1]);
	}//end testVisitsAreRecordedForTheLinkVisitor()

	/**
	 * A visitor's event has another actor type than an owner's.
	 *
	 * @return void
	 */
	public function testAVisitorIsNotRecordedAsAUser(): void {
		$trail = $this->trail();
		$trail->recordRevoked(userId: 'anne', linkShareId: 'ls-1');
		$revoked = $this->last();
		$trail->recordAccessed(linkShareId: 'ls-1');
		$visited = $this->last();
		$this->assertSame(expected: ['anne', 'link_share.revoked', []], actual: [$revoked[1], $revoked[2], $revoked[5]]);
		$this->assertNotSame(expected: $revoked[0], actual: $visited[0]);
	}//end testAVisitorIsNotRecordedAsAUser()

	/**
	 * Without a dispatcher nothing is recorded and nothing fails.
	 *
	 * @return void
	 */
	public function testWithoutADispatcherNothingHappens(): void {
		$trail = new LinkShareAuditTrail();
		$trail->recordCreated(userId: 'anne', linkShareId: 'ls-1', hasPassword: false, expiresAtIso: null);
		$trail->recordRevoked(userId: 'anne', linkShareId: 'ls-1');
		$this->assertSame(expected: [], actual: $this->events);
	}//end testWithoutADispatcherNothingHappens()

	/**
	 * An application's update run knows which fields it sends and whether the key changed.
	 *
	 * @return void
	 */
	public function testAnApplicationUpdateRunTracksTheKey(): void {
		$secret = new Secret();
		$secret->setKey('key-1');
		$run = new ApplicationSecretUpdateRun(secret: $secret, data: ['key' => 'key-2', 'login' => null], applicationId: 'app-1', now: new \DateTime());
		$this->assertTrue(condition: $run->sends(field: 'login'));
		$this->assertFalse(condition: $run->sends(field: 'totp'));
		$this->assertFalse(condition: $run->keyChanged());
		$secret->setKey('key-2');
		$this->assertTrue(condition: $run->keyChanged());
		$this->assertSame(expected: 'key-1', actual: $run->preUpdate->getKey());
		// A row without a version locks on one no stored row has, so its write is refused.
		$this->assertSame(expected: 0, actual: $run->base->getTimestamp());
		$secret->setUpdatedAt(new \DateTime('2026-01-01T00:00:00Z'));
		$read = new ApplicationSecretUpdateRun(secret: $secret, data: [], applicationId: 'app-1', now: new \DateTime());
		$this->assertEquals(expected: new \DateTime('2026-01-01T00:00:00Z'), actual: $read->base);
	}//end testAnApplicationUpdateRunTracksTheKey()
}//end class
