<?php

/**
 * Doriath SIEM Forward Listener
 *
 * Forwards every dispatched AuditEvent to the SIEM queue
 * (siem-audit-export §2.1). Fail-soft by contract: an enqueue failure
 * is logged at error level and MUST NOT roll back the audited
 * operation (§2.2). Payloads are rebuilt through the audit whitelist —
 * strict subsets of sanitized entries.
 *
 * @category Listener
 * @package  OCA\Doriath\Listener
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

namespace OCA\Doriath\Listener;

use OCA\Doriath\Event\Audit\AuditEvent;
use OCA\Doriath\Service\SiemService;
use OCP\EventDispatcher\Event;
use OCP\EventDispatcher\IEventListener;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * Forward audit events to configured SIEM sinks.
 *
 * @implements IEventListener<AuditEvent>
 */
class SiemForwardListener implements IEventListener
{
    /**
     * Constructor for SiemForwardListener.
     *
     * @param SiemService     $siemService The SIEM service
     * @param LoggerInterface $logger      The logger
     *
     * @return void
     */
    public function __construct(
        private SiemService $siemService,
        private LoggerInterface $logger,
    ) {
    }//end __construct()

    /**
     * Handle a dispatched audit event (fail-soft, §2.2).
     *
     * @param Event $event The dispatched event
     *
     * @return void
     *
     * @spec openspec/specs/siem-audit-export/spec.md#requirement-reliable-background-delivery
     */
    public function handle(Event $event): void
    {
        if ($event instanceof AuditEvent === false) {
            return;
        }

        try {
            $payload = $this->siemService->buildPayload(event: $event);
            if ($payload !== null) {
                $this->siemService->enqueue(payload: $payload);
            }
        } catch (Throwable $exception) {
            // Fail-soft: forwarding must never break the audited action.
            $this->logger->error(
                'Doriath: SIEM forward failed: '.$exception->getMessage(),
                ['app' => 'doriath']
            );
        }
    }//end handle()
}//end class
