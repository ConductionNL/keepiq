<?php

/**
 * Unit tests for AuditEvent.
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
use OCP\EventDispatcher\Event;
use PHPUnit\Framework\TestCase;

/**
 * Tests for AuditEvent.
 *
 * @covers \OCA\Keepiq\Event\Audit\AuditEvent
 */
class AuditEventTest extends TestCase {
	/**
	 * Builds an event through the named constructor with every optional argument set.
	 *
	 * @return void
	 */
	public function testForUserCarriesEveryArgument(): void {
		$event = AuditEvent::forUser('alice', 'secret.updated', 'secret', 's-1', 'Mail', ['changedFields' => ['key']]);

		$this->assertSame(AuditEvent::ACTOR_USER, $event->getActorType());
		$this->assertSame('user', $event->getActorType());
		$this->assertSame('alice', $event->getActorId());
		$this->assertSame('secret.updated', $event->getEventType());
		$this->assertSame('secret', $event->getObjectType());
		$this->assertSame('s-1', $event->getObjectId());
		$this->assertSame('Mail', $event->getObjectName());
		$this->assertSame(['changedFields' => ['key']], $event->getMetadata());
		$this->assertInstanceOf(Event::class, $event);
	}//end testForUserCarriesEveryArgument()

	/**
	 * Optional object id, name and metadata default to null, null and an empty list.
	 *
	 * @return void
	 */
	public function testOptionalArgumentsDefault(): void {
		$event = AuditEvent::forUser('alice', 'secret.read', 'secret');

		$this->assertNull($event->getObjectId());
		$this->assertNull($event->getObjectName());
		$this->assertSame([], $event->getMetadata());
	}//end testOptionalArgumentsDefault()

	/**
	 * The application and MCP constructors keep the actor id under their own actor type.
	 *
	 * @return void
	 */
	public function testApplicationAndMcpActorsKeepTheirId(): void {
		$app = AuditEvent::forApplication('app-7', 'application.used', 'application', 'app-7', 'Robot', ['x' => 1]);
		$mcp = AuditEvent::forMcp('bob', 'mcp.read', 'tool', 'list', 'List', ['count' => 3]);

		$this->assertSame('application', $app->getActorType());
		$this->assertSame('app-7', $app->getActorId());
		$this->assertSame('application.used', $app->getEventType());
		$this->assertSame('application', $app->getObjectType());
		$this->assertSame('app-7', $app->getObjectId());
		$this->assertSame('Robot', $app->getObjectName());
		$this->assertSame(['x' => 1], $app->getMetadata());
		$this->assertSame('mcp', $mcp->getActorType());
		$this->assertSame('bob', $mcp->getActorId());
		$this->assertSame('mcp.read', $mcp->getEventType());
		$this->assertSame('tool', $mcp->getObjectType());
		$this->assertSame('list', $mcp->getObjectId());
		$this->assertSame('List', $mcp->getObjectName());
		$this->assertSame(['count' => 3], $mcp->getMetadata());
	}//end testApplicationAndMcpActorsKeepTheirId()

	/**
	 * System and link-visitor events have no actor id.
	 *
	 * @return void
	 */
	public function testSystemAndLinkVisitorHaveNoActorId(): void {
		$system = AuditEvent::forSystem('secret.purged', 'secret', 's-2', 'Old', ['reason' => 'retention']);
		$link   = AuditEvent::forLinkVisitor('link.viewed', 'link_share', 'l-1', 'Shared', ['ip' => 'x']);

		$this->assertSame('system', $system->getActorType());
		$this->assertNull($system->getActorId());
		$this->assertSame('secret.purged', $system->getEventType());
		$this->assertSame('secret', $system->getObjectType());
		$this->assertSame('s-2', $system->getObjectId());
		$this->assertSame('Old', $system->getObjectName());
		$this->assertSame(['reason' => 'retention'], $system->getMetadata());
		$this->assertSame('link_visitor', $link->getActorType());
		$this->assertNull($link->getActorId());
		$this->assertSame('link.viewed', $link->getEventType());
		$this->assertSame('link_share', $link->getObjectType());
		$this->assertSame('l-1', $link->getObjectId());
		$this->assertSame('Shared', $link->getObjectName());
		$this->assertSame(['ip' => 'x'], $link->getMetadata());
	}//end testSystemAndLinkVisitorHaveNoActorId()

	/**
	 * The public constructor accepts any actor type verbatim.
	 *
	 * @return void
	 */
	public function testConstructorKeepsArgumentsVerbatim(): void {
		$event = new AuditEvent('custom', null, 'x.y', 'thing');

		$this->assertSame('custom', $event->getActorType());
		$this->assertNull($event->getActorId());
		$this->assertSame('x.y', $event->getEventType());
		$this->assertSame('thing', $event->getObjectType());
		$this->assertNull($event->getObjectId());
	}//end testConstructorKeepsArgumentsVerbatim()
}//end class
