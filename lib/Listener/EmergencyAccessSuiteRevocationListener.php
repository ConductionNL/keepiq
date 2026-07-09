<?php

/**
 * Doriath EmergencyAccessSuiteRevocationListener
 *
 * Listens for EncryptionSuiteRevokedEvent and invalidates the emergency-access
 * envelopes affected by the revocation (add-emergency-access §3.2 / design D6):
 *  - envelopes escrowing the revoked suite's private key (the suite owner was a
 *    grantor) are CLEARED — the key is void,
 *  - envelopes encrypted TO the revoked suite's certificate (the owner was a
 *    grantee) are INVALIDATED — the grantee can no longer open them.
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

use OCA\Doriath\Event\EncryptionSuiteRevokedEvent;
use OCA\Doriath\Service\EmergencyAccessService;
use OCP\EventDispatcher\Event;
use OCP\EventDispatcher\IEventListener;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * Clear/invalidate emergency-access envelopes on suite revocation.
 *
 * @implements IEventListener<EncryptionSuiteRevokedEvent>
 *
 * @spec openspec/changes/add-emergency-access/specs/emergency-access/spec.md#requirement-envelope-invalidation-on-key-change
 */
class EmergencyAccessSuiteRevocationListener implements IEventListener
{
    /**
     * Constructor.
     *
     * @param EmergencyAccessService $service The emergency-access service
     * @param LoggerInterface        $logger  The logger
     *
     * @return void
     */
    public function __construct(
        private EmergencyAccessService $service,
        private LoggerInterface $logger,
    ) {
    }//end __construct()

    /**
     * Handle the EncryptionSuiteRevokedEvent.
     *
     * @param Event $event The dispatched event
     *
     * @return void
     *
     * @spec openspec/changes/add-emergency-access/specs/emergency-access/spec.md#requirement-envelope-invalidation-on-key-change
     */
    public function handle(Event $event): void
    {
        if ($event instanceof EncryptionSuiteRevokedEvent === false) {
            return;
        }

        if ($event->getOwnerType() !== 'user') {
            return;
        }

        $suiteId = $event->getSuiteId();

        try {
            $this->service->clearForGrantorRevocation(grantorSuiteId: $suiteId);
            $this->service->invalidateForGranteeRevocation(granteeSuiteId: $suiteId);
        } catch (Throwable $e) {
            $this->logger->error('Doriath: emergency-access revocation cleanup failed: '.$e->getMessage());
        }
    }//end handle()
}//end class
