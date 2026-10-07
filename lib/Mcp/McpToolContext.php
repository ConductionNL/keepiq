<?php

/**
 * Keepiq MCP: agent-read audit
 *
 * @category Mcp
 * @package  OCA\Keepiq\Mcp
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

namespace OCA\Keepiq\Mcp;

use OCA\Keepiq\Event\Audit\AuditEventFactory;
use OCA\Keepiq\Event\Audit\AuditEventTypes;
use OCP\EventDispatcher\IEventDispatcher;
use OCP\IUserSession;
use RuntimeException;

/**
 * The invoking principal of a tool call, and the audit record of the call:
 * actor `mcp`, the principal, the tool name and the result count. Never an
 * entry name, subject or value.
 *
 * @spec openspec/specs/mcp-metadata-surface/spec.md#requirement-invocations-are-audited-as-agent-reads
 */
class McpToolContext {

	/**
	 * Constructor.
	 *
	 * @param IUserSession $userSession The session the agent acts in
	 * @param IEventDispatcher $dispatcher The audit event dispatcher
	 * @param AuditEventFactory $events Builds the audit event
	 *
	 * @return void
	 */
	public function __construct(
		private IUserSession $userSession,
		private IEventDispatcher $dispatcher,
		private AuditEventFactory $events = new AuditEventFactory(),
	) {
	}//end __construct()

	/**
	 * The session user: the only principal a tool ever reads for.
	 *
	 * @return string
	 *
	 * @throws RuntimeException Without a session user
	 *
	 * @spec openspec/specs/mcp-metadata-surface/spec.md#requirement-metadata-only-entry-listing-tool
	 */
	public function userId(): string {
		$user = $this->userSession->getUser();
		if ($user === null) {
			throw new RuntimeException('Keepiq MCP tools need a signed-in user');
		}

		return $user->getUID();
	}//end userId()

	/**
	 * Record one tool invocation.
	 *
	 * @param string $userId The principal
	 * @param string $tool The tool name
	 * @param int $resultCount How many rows the tool returned
	 *
	 * @return void
	 *
	 * @spec openspec/specs/mcp-metadata-surface/spec.md#requirement-invocations-are-audited-as-agent-reads
	 */
	public function audit(string $userId, string $tool, int $resultCount): void {
		$this->dispatcher->dispatchTyped(
			$this->events->forMcp(
				actorId: $userId,
				eventType: AuditEventTypes::MCP_TOOL_INVOKED,
				objectType: 'mcp_tool',
				objectId: $tool,
				metadata: ['tool' => $tool, 'resultCount' => $resultCount],
			)
		);
	}//end audit()
}//end class
