<?php

/**
 * Doriath JWT Authentication Service
 *
 * Exchanges a JWT bearer assertion (RS256, signed by an application's
 * registered private key) for a short-lived opaque access token usable
 * on `/api/v1/app/*` routes. Verification uses the application's
 * EncryptionSuite certificate public key. Replay protection is enforced
 * via a distributed jti cache.
 *
 * @category Service
 * @package  OCA\Doriath\Service
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

namespace OCA\Doriath\Service;

use OCA\Doriath\Db\Application;
use OCA\Doriath\Db\ApplicationMapper;
use OCA\Doriath\Event\Audit\AuditEvent;
use OCA\Doriath\Event\Audit\AuditEventFactory;
use OCA\Doriath\Event\Audit\AuditEventTypes;
use OCP\AppFramework\Db\DoesNotExistException;
use OCP\EventDispatcher\IEventDispatcher;
use OCP\ICacheFactory;
use RuntimeException;

/**
 * Implements the JWT-Bearer "assertion -> access_token" exchange that
 * powers the `/api/v1/token` endpoint plus the access-token validation
 * helper consumed by JwtAuthMiddleware.
 *
 * The JOSE work (deserialising, claim vetting, signature verification)
 * lives in JwtAssertionVerifier and the issuer's verification key comes
 * from ApplicationJwkResolver; what remains here is the exchange policy
 * itself: issuer resolution, jti replay protection, opaque access-token
 * minting and validation.
 */
class JwtAuthService
{
    /**
     * Distributed cache namespace for jti replay protection.
     *
     * @var string
     */
    public const JTI_CACHE_NS = 'doriath_jwt_jti';

    /**
     * Distributed cache namespace for opaque access tokens.
     *
     * @var string
     */
    public const TOKEN_CACHE_NS = 'doriath_jwt_token';

    /**
     * Lifetime of issued access tokens in seconds (5 minutes per spec
     * §4.3).
     *
     * @var int
     */
    public const ACCESS_TOKEN_TTL = 300;

    /**
     * Allowed clock skew between issuer (`iat`) and verifier in seconds.
     *
     * @var int
     */
    public const CLOCK_SKEW_SECONDS = 60;

    /**
     * The expected audience claim ("aud") for assertions targeted at
     * this Doriath instance.
     *
     * @var string
     */
    public const EXPECTED_AUDIENCE = 'doriath';

    /**
     * Constructor for JwtAuthService.
     *
     * @param ApplicationMapper      $applicationMapper The application mapper
     * @param ICacheFactory          $cacheFactory      The cache factory
     * @param JwtAssertionVerifier   $verifier          The JOSE assertion verifier
     * @param ApplicationJwkResolver $keyResolver       The issuer key resolver
     * @param IEventDispatcher|null  $eventDispatcher   The event dispatcher
     * @param AuditEventFactory      $auditEvents       The audit-event factory
     *
     * @return void
     */
    public function __construct(
        private ApplicationMapper $applicationMapper,
        private ICacheFactory $cacheFactory,
        private JwtAssertionVerifier $verifier,
        private ApplicationJwkResolver $keyResolver,
        private ?IEventDispatcher $eventDispatcher=null,
        private AuditEventFactory $auditEvents=new AuditEventFactory(),
    ) {
    }//end __construct()

    /**
     * Dispatch a typed audit event, fail-soft.
     *
     * @param AuditEvent $event The audit event
     *
     * @return void
     *
     * @spec openspec/changes/add-secret-audit-trail/tasks.md#task-3
     */
    private function dispatchAudit(AuditEvent $event): void
    {
        $this->eventDispatcher?->dispatchTyped($event);
    }//end dispatchAudit()

    /**
     * Exchange a JWT bearer assertion for a short-lived opaque access
     * token.
     *
     * The assertion MUST be a Compact-Serialized JWS signed RS256
     * (preferred) or ES256 with the application's registered private
     * key. Required claims: iss (application id), aud="doriath",
     * exp (>now), iat (<=now+CLOCK_SKEW), jti (unique within TTL).
     *
     * @param string $assertion The JWS compact serialization
     *
     * @return array{access_token:string,token_type:string,expires_in:int}
     *
     * @throws RuntimeException When the assertion is malformed, has
     *                          invalid claims, fails signature
     *                          verification, or replays a known jti.
     *
     * @spec openspec/changes/add-secret-audit-trail/tasks.md#task-3.7
     */
    public function exchangeAssertion(string $assertion): array
    {
        if ($assertion === '') {
            throw new RuntimeException(message: 'Assertion is empty');
        }

        $claims = $this->verifier->readAcceptableClaims(assertion: $assertion);

        $jtiCache = $this->cacheFactory->createDistributed(self::JTI_CACHE_NS);
        $jti      = (string) $claims['jti'];
        if ($jtiCache->hasKey($jti) === true) {
            throw new RuntimeException(message: 'Assertion jti replayed');
        }

        $application = $this->loadActiveIssuer(issuer: (string) $claims['iss']);

        $this->verifier->verifySignature(
            assertion: $assertion,
            jwk: $this->keyResolver->forApplication(application: $application)
        );

        // Store jti to prevent replay during max assertion lifetime.
        $jtiCache->set($jti, true, self::ACCESS_TOKEN_TTL);

        return $this->issueAccessToken(application: $application);
    }//end exchangeAssertion()

    /**
     * Resolve the `iss` claim to an active registered application.
     *
     * @param string $issuer The `iss` claim (an application id)
     *
     * @return Application
     *
     * @throws RuntimeException When the issuer is unknown or inactive.
     */
    private function loadActiveIssuer(string $issuer): Application
    {
        try {
            $application = $this->applicationMapper->findById($issuer);
        } catch (DoesNotExistException) {
            throw new RuntimeException(message: 'Unknown issuer');
        }

        if ($application->isActive() === false) {
            throw new RuntimeException(message: 'Issuer application is not active');
        }

        return $application;
    }//end loadActiveIssuer()

    /**
     * Mint and cache an opaque access token bound to the application id.
     *
     * @param Application $application The verified issuing application
     *
     * @return array{access_token:string,token_type:string,expires_in:int}
     */
    private function issueAccessToken(Application $application): array
    {
        $accessToken = bin2hex(random_bytes(32));
        $tokenCache  = $this->cacheFactory->createDistributed(self::TOKEN_CACHE_NS);
        $tokenCache->set($accessToken, $application->getId(), self::ACCESS_TOKEN_TTL);

        $this->dispatchAudit(
            event: $this->auditEvents->forApplication(
                actorId: $application->getId(),
                eventType: AuditEventTypes::APPLICATION_TOKEN_ISSUED,
                objectType: 'application',
                objectId: $application->getId(),
                objectName: $application->getName(),
            )
        );

        return [
            'access_token' => $accessToken,
            'token_type'   => 'Bearer',
            'expires_in'   => self::ACCESS_TOKEN_TTL,
        ];
    }//end issueAccessToken()

    /**
     * Validate an opaque access token and resolve the bound application.
     *
     * @param string $accessToken The opaque access token from the
     *                            `Authorization: Bearer <token>` header.
     *
     * @return Application|null The bound application, or null when the
     *                          token is unknown, expired, or the
     *                          application is no longer active.
     */
    public function validateAccessToken(string $accessToken): ?Application
    {
        if ($accessToken === '') {
            return null;
        }

        $tokenCache    = $this->cacheFactory->createDistributed(self::TOKEN_CACHE_NS);
        $applicationId = $tokenCache->get($accessToken);

        if (is_string($applicationId) === false || $applicationId === '') {
            return null;
        }

        try {
            $application = $this->applicationMapper->findById($applicationId);
        } catch (DoesNotExistException) {
            return null;
        }

        if ($application->isActive() === false) {
            return null;
        }

        return $application;
    }//end validateAccessToken()
}//end class
