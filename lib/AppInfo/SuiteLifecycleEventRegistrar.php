<?php

/**
 * Doriath suite-lifecycle event registrar
 *
 * Binds every listener that reacts to an EncryptionSuite changing state:
 * compromise-recovery migration start/finish, suite revocation, and the
 * emergency-access envelopes those two invalidate.
 *
 * @category AppInfo
 * @package  OCA\Doriath\AppInfo
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

namespace OCA\Doriath\AppInfo;

use OCA\Doriath\Event\EncryptionSuiteRevokedEvent;
use OCA\Doriath\Event\SuiteMigrationCompletedEvent;
use OCA\Doriath\Event\SuiteMigrationStartedEvent;
use OCA\Doriath\Listener\EmergencyAccessSuiteRevocationListener;
use OCA\Doriath\Listener\EmergencyAccessSuiteRotationListener;
use OCA\Doriath\Listener\EncryptionSuiteRevokedListener;
use OCA\Doriath\Listener\SuiteCompromiseListener;
use OCA\Doriath\Listener\SuiteMigrationCompletedListener;
use OCA\Doriath\Listener\SuiteMigrationStartedListener;
use OCP\AppFramework\Bootstrap\IRegistrationContext;

/**
 * Wires the EncryptionSuite lifecycle listener graph.
 *
 * The three suite events fan out to more than one listener each, and the
 * ORDER of the bindings is not significant — Nextcloud's dispatcher invokes
 * every registered listener for an event and a failure in one is contained by
 * that listener, not by this registration.
 *
 * Grouped as one registrar because all six listeners share a single trigger
 * family (a suite started migrating, finished migrating, or was revoked) and
 * a single invariant: no ciphertext may survive a suite it can no longer be
 * decrypted under.
 */
final class SuiteLifecycleEventRegistrar
{
    /**
     * Bind the suite-lifecycle listeners to their events.
     *
     * @param IRegistrationContext $context The registration context
     *
     * @return void
     *
     * @spec openspec/specs/encryption-suites/spec.md
     */
    public function register(IRegistrationContext $context): void
    {
        // Compromise-recovery: lock SecretRequests when migration starts and
        // unlock + re-suite them when it completes
        // (implement-secret-requests §6.1-6.3).
        $context->registerEventListener(
            event: SuiteMigrationStartedEvent::class,
            listener: SuiteMigrationStartedListener::class
        );
        $context->registerEventListener(
            event: SuiteMigrationCompletedEvent::class,
            listener: SuiteMigrationCompletedListener::class
        );

        // Implement-user-sharing §8 — sharing-graph reactions to suite
        // revocation and post-migration possibly-compromised flagging.
        $context->registerEventListener(
            event: EncryptionSuiteRevokedEvent::class,
            listener: EncryptionSuiteRevokedListener::class
        );
        $context->registerEventListener(
            event: SuiteMigrationCompletedEvent::class,
            listener: SuiteCompromiseListener::class
        );

        // Emergency access — invalidate/clear recovery envelopes on a grantor's
        // suite rotation (compromise recovery) or revocation, and invalidate
        // envelopes to a grantee whose suite is revoked (add-emergency-access §3).
        $context->registerEventListener(
            event: SuiteMigrationCompletedEvent::class,
            listener: EmergencyAccessSuiteRotationListener::class
        );
        $context->registerEventListener(
            event: EncryptionSuiteRevokedEvent::class,
            listener: EmergencyAccessSuiteRevocationListener::class
        );

    }//end register()
}//end class
