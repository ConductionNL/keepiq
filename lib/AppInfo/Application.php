<?php

/**
 * Doriath Application
 *
 * Main application class for the Doriath Nextcloud app.
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

use OCA\Doriath\Event\Audit\AuditEvent;
use OCA\Doriath\Event\EncryptionSuiteRevokedEvent;
use OCA\Doriath\Event\SuiteMigrationCompletedEvent;
use OCA\Doriath\Event\SuiteMigrationStartedEvent;
use OCA\Doriath\Listener\AuditListener;
use OCA\Doriath\Listener\EmergencyAccessSuiteRevocationListener;
use OCA\Doriath\Listener\EmergencyAccessSuiteRotationListener;
use OCA\Doriath\Listener\EncryptionSuiteRevokedListener;
use OCA\Doriath\Listener\SuiteCompromiseListener;
use OCA\Doriath\Listener\SuiteMigrationCompletedListener;
use OCA\Doriath\Listener\SuiteMigrationStartedListener;
use OCA\Doriath\Listener\UserAddedToGroupListener;
use OCA\Doriath\Listener\UserDeletedListener;
use OCA\Doriath\Listener\UserRemovedFromGroupListener;
use OCP\Group\Events\UserAddedEvent;
use OCP\Group\Events\UserRemovedEvent;
use OCP\User\Events\UserDeletedEvent;
use OCA\Doriath\Middleware\JwtAuthMiddleware;
use OCA\Doriath\Notification\DoriathNotifier;
use OCA\Doriath\Search\SecretSearchProvider;
use OCA\OpenRegister\AppHost\Bootstrap;
use OCP\AppFramework\App;
use OCP\AppFramework\Bootstrap\IBootContext;
use OCP\AppFramework\Bootstrap\IBootstrap;
use OCP\AppFramework\Bootstrap\IRegistrationContext;

/**
 * Main application class for the Doriath Nextcloud app.
 */
class Application extends App implements IBootstrap
{
    public const APP_ID = 'doriath';

    /**
     * Constructor for the Application class.
     *
     * @return void
     */
    public function __construct()
    {
        parent::__construct(appName: self::APP_ID);
    }//end __construct()

    /**
     * Register event listeners and services.
     *
     * @param IRegistrationContext $context The registration context
     *
     * @return void
     *
     * @SuppressWarnings(PHPMD.UnusedFormalParameter)
     */
    public function register(IRegistrationContext $context): void
    {
        include_once __DIR__.'/../../vendor/autoload.php';

        // Adopt the OpenRegister AppHost engine (ADR-040 / ADR-022). One call wires
        // the boilerplate plumbing the fleet shares: the dashboard/preferences/
        // settings controllers, the settings + action-auth services, the install
        // repair steps, the admin-settings panel + section, the manifest-driven
        // deep-link listener, and the observability controllers (health + metrics).
        //
        // Every registration is a lazy service closure, so a disabled/absent
        // OpenRegister never fatals Nextcloud bootstrap — an aliased route simply
        // surfaces a 5xx and /api/health reports the degraded state.
        //
        // Doriath then RE-REGISTERS its three domain-divergent plumbing classes
        // after this call so the concrete leaf classes win over the generic
        // aliases (see the override block below): SettingsService (register.d
        // fragment merge + admin/user-preference split, ADR-037), SettingsController
        // (admin/user settings split + #[AuthorizedAdminSetting(AdminSettings::class)]),
        // and InitializeSettings (domain default-config seeding). The remaining
        // boilerplate — health, metrics, deep links, admin-settings panel and
        // section — is fully owned by the engine. Zero-knowledge (ADR-003) is
        // untouched: no AppHost generic ever sees a plaintext secret.
        Bootstrap::register($context, self::APP_ID, ['namespace' => 'OCA\\Doriath']);

        // Override the generic aliases with Doriath's domain-divergent concretes.
        $context->registerService(
            \OCA\Doriath\Service\SettingsService::class,
            static fn ($c) => new \OCA\Doriath\Service\SettingsService(
                appConfig: $c->get(\OCP\IAppConfig::class),
                config: $c->get(\OCP\IConfig::class),
                appManager: $c->get(\OCP\App\IAppManager::class),
                container: $c,
                groupManager: $c->get(\OCP\IGroupManager::class),
                userSession: $c->get(\OCP\IUserSession::class),
                logger: $c->get(\Psr\Log\LoggerInterface::class),
            )
        );
        $context->registerService(
            \OCA\Doriath\Controller\SettingsController::class,
            static fn ($c) => new \OCA\Doriath\Controller\SettingsController(
                request: $c->get(\OCP\IRequest::class),
                settingsService: $c->get(\OCA\Doriath\Service\SettingsService::class),
                userSession: $c->get(\OCP\IUserSession::class),
            )
        );
        $context->registerService(
            \OCA\Doriath\Repair\InitializeSettings::class,
            static fn ($c) => new \OCA\Doriath\Repair\InitializeSettings(
                settingsService: $c->get(\OCA\Doriath\Service\SettingsService::class),
                appConfig: $c->get(\OCP\IAppConfig::class),
                logger: $c->get(\Psr\Log\LoggerInterface::class),
            )
        );

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

        // Implement-user-sharing §8 — sharing-graph reactions to
        // group membership churn, suite revocation, and
        // post-migration possibly-compromised flagging.
        $context->registerEventListener(
            event: UserAddedEvent::class,
            listener: UserAddedToGroupListener::class
        );
        $context->registerEventListener(
            event: UserRemovedEvent::class,
            listener: UserRemovedFromGroupListener::class
        );
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

        // Secret-export-gdpr D4 — cascade-delete all of a user's Doriath data
        // when their Nextcloud account is removed, so vault data never outlives
        // its account. The cascade is idempotent and shares its implementation
        // with the in-app GDPR Art. 17 deletion flow.
        $context->registerEventListener(
            event: UserDeletedEvent::class,
            listener: UserDeletedListener::class
        );

        // Add-secret-audit-trail §2.6 — the single AuditListener turns every
        // dispatched AuditEvent into an append-only doriath_audit_log row. The
        // listener is fail-soft: a record failure is logged at error level and
        // never propagates into the audited business operation.
        $context->registerEventListener(
            event: AuditEvent::class,
            listener: AuditListener::class
        );

        // The three secret-export-gdpr events are this change's scoped consumer
        // (design D2/D5). They belong to the secret-export-gdpr change; bind the
        // same AuditListener to them only when that capability's event classes
        // are present, so this registration never references a missing class.
        foreach ([
            'OCA\\Doriath\\Event\\SecretExportedEvent',
            'OCA\\Doriath\\Event\\GdprExportPerformedEvent',
            'OCA\\Doriath\\Event\\AccountDataDeletedEvent',
        ] as $exportEventClass) {
            if (class_exists($exportEventClass) === true) {
                $context->registerEventListener(
                    event: $exportEventClass,
                    listener: AuditListener::class
                );
            }
        }

        // Register the Nextcloud unified search provider for secrets. It
        // queries unencrypted name/url metadata only and needs no vault
        // session (ADR-003).
        $context->registerSearchProvider(SecretSearchProvider::class);

        // Register the notifier responsible for rendering sharing,
        // secret-request and application-management notification subjects.
        $context->registerNotifierService(DoriathNotifier::class);

        // Register the JWT-Bearer middleware for application-authenticated
        // routes. Fires only on ApplicationApiController subclasses; session
        // controllers pass through untouched.
        $context->registerMiddleware(JwtAuthMiddleware::class);

        // Domain repair steps (BootstrapCertificateAuthority, InitializeSettings,
        // SeedSecretTypes, the Seed* development data steps) are registered via
        // info.xml <repair-steps>. InitializeSettings is the Doriath concrete
        // re-registered above (domain default-config seeding); the rest are
        // crypto/seed domain steps owned by the app.
    }//end register()

    /**
     * Boot the application.
     *
     * @param IBootContext $context The boot context
     *
     * @return void
     *
     * @SuppressWarnings(PHPMD.UnusedFormalParameter)
     */
    public function boot(IBootContext $context): void
    {
    }//end boot()
}//end class
