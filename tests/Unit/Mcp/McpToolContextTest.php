<?php

/**
 * Unit tests for the MCP tool context: principal and audit.
 *
 * @category Test
 * @package  OCA\Keepiq\Tests\Unit\Mcp
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

namespace OCA\Keepiq\Tests\Unit\Mcp;

use OCA\Keepiq\Event\Audit\AuditEvent;
use OCA\Keepiq\Event\Audit\AuditEventTypes;
use OCA\Keepiq\Mcp\McpToolContext;
use OCP\EventDispatcher\IEventDispatcher;
use OCP\IUser;
use OCP\IUserSession;
use PHPUnit\Framework\TestCase;
use RuntimeException;

/**
 * McpToolContext resolves the session user and dispatches one audit event per call.
 *
 * @covers \OCA\Keepiq\Mcp\McpToolContext
 * @uses   \OCA\Keepiq\Event\Audit\AuditEventFactory
 * @uses   \OCA\Keepiq\Event\Audit\AuditEvent
 */
class McpToolContextTest extends TestCase {

	/**
	 * The session user's uid is the principal.
	 *
	 * @return void
	 */
	public function testUserIdIsTheSessionUid(): void {
		$user = $this->createMock(IUser::class);
		$user->method('getUID')->willReturn('carol');
		$session = $this->createMock(IUserSession::class);
		$session->expects($this->once())->method('getUser')->willReturn($user);

		$context = new McpToolContext(userSession: $session, dispatcher: $this->createMock(IEventDispatcher::class));
		$this->assertSame('carol', $context->userId());
	}//end testUserIdIsTheSessionUid()

	/**
	 * Without a session user the call is refused, and nothing is audited.
	 *
	 * @return void
	 */
	public function testNoSessionUserThrows(): void {
		$session = $this->createMock(IUserSession::class);
		$session->method('getUser')->willReturn(null);
		$dispatcher = $this->createMock(IEventDispatcher::class);
		$dispatcher->expects($this->never())->method('dispatchTyped');

		$this->expectException(RuntimeException::class);
		$this->expectExceptionMessage('Keepiq MCP tools need a signed-in user');
		(new McpToolContext(userSession: $session, dispatcher: $dispatcher))->userId();
	}//end testNoSessionUserThrows()

	/**
	 * The audit event is an mcp actor event naming the tool and the count only.
	 *
	 * @return void
	 */
	public function testAuditDispatchesOneMcpEvent(): void {
		$captured = [];
		$dispatcher = $this->createMock(IEventDispatcher::class);
		$dispatcher->expects($this->once())->method('dispatchTyped')
			->willReturnCallback(static function (object $event) use (&$captured): void {
				$captured[] = $event;
			});

		$context = new McpToolContext(userSession: $this->createMock(IUserSession::class), dispatcher: $dispatcher);
		$context->audit(userId: 'dave', tool: 'expiryReport', resultCount: 7);

		$this->assertCount(1, $captured);
		$event = $captured[0];
		$this->assertInstanceOf(AuditEvent::class, $event);
		$this->assertSame(AuditEvent::ACTOR_MCP, $event->getActorType());
		$this->assertSame('dave', $event->getActorId());
		$this->assertSame(AuditEventTypes::MCP_TOOL_INVOKED, $event->getEventType());
		$this->assertSame('mcp_tool', $event->getObjectType());
		$this->assertSame('expiryReport', $event->getObjectId());
		$this->assertNull($event->getObjectName());
		$this->assertSame(['tool' => 'expiryReport', 'resultCount' => 7], $event->getMetadata());
	}//end testAuditDispatchesOneMcpEvent()
}//end class
