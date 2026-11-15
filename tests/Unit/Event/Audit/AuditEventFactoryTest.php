<?php

/**
 * Unit tests for AuditEventFactory.
 *
 * @category Test
 * @package  OCA\Keepiq\Tests\Unit\Event\Audit
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

namespace OCA\Keepiq\Tests\Unit\Event\Audit;

use OCA\Keepiq\Event\Audit\AuditEvent;
use OCA\Keepiq\Event\Audit\AuditEventFactory;
use PHPUnit\Framework\TestCase;

/**
 * Tests for AuditEventFactory.
 *
 * @covers \OCA\Keepiq\Event\Audit\AuditEventFactory
 * @uses   \OCA\Keepiq\Event\Audit\AuditEvent
 */
class AuditEventFactoryTest extends TestCase {
	/**
	 * The factory under test.
	 *
	 * @var AuditEventFactory
	 */
	private AuditEventFactory $factory;

	/**
	 * Set up the factory.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		parent::setUp();
		$this->factory = new AuditEventFactory();
	}//end setUp()

	/**
	 * Read an event's fields after the actor in a fixed order.
	 *
	 * @param AuditEvent $event The event.
	 *
	 * @return array<int, mixed>
	 */
	private function fields(AuditEvent $event): array {
		return [$event->getEventType(), $event->getObjectType(), $event->getObjectId(), $event->getObjectName(), $event->getMetadata()];
	}//end fields()

	/**
	 * Each actor-bearing method produces the matching actor type and passes every argument on.
	 *
	 * @return void
	 */
	public function testActorMethodsPassEveryArgumentOn(): void {
		$user = $this->factory->forUser('alice', 'e.user', 'secret', 'id-1', 'Name 1', ['a' => 1]);
		$app  = $this->factory->forApplication('app', 'e.app', 'application', 'id-2', 'Name 2', ['b' => 2]);
		$mcp  = $this->factory->forMcp('bob', 'e.mcp', 'tool', 'id-3', 'Name 3', ['c' => 3]);

		$this->assertSame(AuditEvent::ACTOR_USER, $user->getActorType());
		$this->assertSame('alice', $user->getActorId());
		$this->assertSame(['e.user', 'secret', 'id-1', 'Name 1', ['a' => 1]], $this->fields($user));
		$this->assertSame(AuditEvent::ACTOR_APPLICATION, $app->getActorType());
		$this->assertSame('app', $app->getActorId());
		$this->assertSame(['e.app', 'application', 'id-2', 'Name 2', ['b' => 2]], $this->fields($app));
		$this->assertSame(AuditEvent::ACTOR_MCP, $mcp->getActorType());
		$this->assertSame('bob', $mcp->getActorId());
		$this->assertSame(['e.mcp', 'tool', 'id-3', 'Name 3', ['c' => 3]], $this->fields($mcp));
	}//end testActorMethodsPassEveryArgumentOn()

	/**
	 * System and link-visitor events carry no actor id and pass the rest on.
	 *
	 * @return void
	 */
	public function testActorlessMethodsPassEveryArgumentOn(): void {
		$system = $this->factory->forSystem('e.sys', 'secret', 'id-4', 'Name 4', ['d' => 4]);
		$link   = $this->factory->forLinkVisitor('e.link', 'link_share', 'id-5', 'Name 5', ['e' => 5]);

		$this->assertSame(AuditEvent::ACTOR_SYSTEM, $system->getActorType());
		$this->assertNull($system->getActorId());
		$this->assertSame(['e.sys', 'secret', 'id-4', 'Name 4', ['d' => 4]], $this->fields($system));
		$this->assertSame(AuditEvent::ACTOR_LINK_VISITOR, $link->getActorType());
		$this->assertNull($link->getActorId());
		$this->assertSame(['e.link', 'link_share', 'id-5', 'Name 5', ['e' => 5]], $this->fields($link));
	}//end testActorlessMethodsPassEveryArgumentOn()

	/**
	 * Optional arguments default the same way as the event's own constructors.
	 *
	 * @return void
	 */
	public function testOptionalArgumentsDefault(): void {
		$events = [
			$this->factory->forUser('alice', 'e', 'o'),
			$this->factory->forApplication('app', 'e', 'o'),
			$this->factory->forMcp('bob', 'e', 'o'),
			$this->factory->forSystem('e', 'o'),
			$this->factory->forLinkVisitor('e', 'o'),
		];

		foreach ($events as $event) {
			$this->assertSame(['e', 'o', null, null, []], $this->fields($event));
		}
	}//end testOptionalArgumentsDefault()

	/**
	 * Each call builds a new event.
	 *
	 * @return void
	 */
	public function testEachCallBuildsANewEvent(): void {
		$first  = $this->factory->forSystem('e', 'o');
		$second = $this->factory->forSystem('e', 'o');

		$this->assertNotSame($first, $second);
	}//end testEachCallBuildsANewEvent()
}//end class
